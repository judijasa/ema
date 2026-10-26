<?php

namespace Ema\Cli\Command;

use Ema\Cli\AbstractCommand;
use Ema\Cli\Args;
use Ema\Cli\Error;
use Ema\Cli\Support\Connection;
use Ema\Cli\Support\Process;
use Ema\Cli\Support\ProdServer;
use Ema\Cli\Support\Repo;
use Ema\Cli\Support\Sql;

/**
 * `ema create srv/<name>-<GUID>` — provision a prod database instance and
 * create the database + apply its schema. Mirrors the bash `_cmd_create` +
 * `_cmd_create_replica`.
 */
final class Create extends AbstractCommand
{
    public function name(): string
    {
        return 'create';
    }

    public function help(): string
    {
        return <<<'TXT'
Usage: ema create srv/<name>-<GUID> [flags]

Provision a prod database from a database package: ensure the package's own
MariaDB instance exists and is running (datadir, /etc/<instance>/my.cnf, an
auto-picked TCP port, the mariadb@<instance> systemd unit), then create the
database (dbname/charset/collation) and apply the schema-package dependency
graph in topological order. On success the connectivity section (SERVER/PORT/
MYSQL_UNIX_PORT/DBNAME under an [<instance>] header — the header is the
instance, DBNAME the schema it serves) is printed for the operator to record
in the consumer's manual reuter.ini. Schema only — users/grants are consumer
policy and are never created by ema.

Always operates on prod (build a sandbox with 'ema sandbox
srv/<name>-<GUID>'). Create-only: refuses when the database already exists
(delete it first over the section's socket). There is no reapply/upgrade
surface — change a database with imperative SQL or delete + recreate.

Flags:
  -n, --dry-run     Print the SQL that would run, without provisioning or running it
  --from-snapshot <path>
                    (replica only) restore this mariadb-backup backup instead of
                    applying schema — required for a type=replica package
  -q, --quiet       Suppress non-error output
  -v, --verbose     Print the action to stderr

Replica: a package with type: 'replica' declares replica_of: <primary>
(the primary's instance) and dbname: <primary's schema>. It is built from the
primary's shipped snapshot (--from-snapshot <path>) and attached over the
low-priv 'replication' account — the schema is the primary's, so no schema
packages are applied. See doc/system/replica-bootstrap.md for the manual
bootstrap.
TXT;
    }

    protected function execute(array $args): int
    {
        $parsed = Args::parse('--dry-run -n -q --quiet -v --verbose', '--from-snapshot', $args);
        $target = $parsed->positional[0] ?? '';
        if ($target === '') {
            fwrite(STDERR, "Error: must provide a target (e.g., ema create srv/<name>-<GUID>)\n");
            echo $this->help() . "\n";
            return 1;
        }

        Repo::assertRoot();

        if (!str_starts_with($target, 'srv/')) {
            fwrite(STDERR, "Error: target must be srv/<name>-<GUID> (got '$target')\n");
            echo $this->help() . "\n";
            return 1;
        }

        $ref = rtrim(substr($target, 4), '/');
        $defs = \srv_definition($ref);
        $db = $defs['db'];
        $instance = Repo::instanceName($ref);
        $dbname = $db['dbname'];
        if ($dbname === '') {
            throw new Error("srv/$ref/default.php has no dbname (return an \\Ema\\Config\\DatabaseConfig with dbname set)");
        }

        if ($db['type'] === 'replica') {
            return $this->createReplica(
                $instance,
                $dbname,
                $db['replica_of'] ?? '',
                $db['replica_ssl_verify_server_cert'] ? '1' : '0',
                $parsed,
            );
        }

        return $this->createPrimary($ref, $instance, $dbname, $defs, $parsed);
    }

    /**
     * @param array{
     *   dir: string,
     *   db: array{
     *     dbname: string,
     *     charset: string,
     *     collation: string,
     *     type: string,
     *     replica_of: string|null,
     *     binlog: bool,
     *     binlog_expire_days: int|null,
     *     replica_ssl_verify_server_cert: bool,
     *   },
     *   dependencies: list<string>,
     * } $defs
     */
    private function createPrimary(string $ref, string $instance, string $dbname, array $defs, Args $args): int
    {
        $dryRun = $args->flag('--dry-run', '-n', 'dry_run');
        $quiet = $args->flag('--quiet', '-q', 'quiet');
        $verbose = $args->flag('--verbose', '-v', 'verbose');

        $db = $defs['db'];
        $srvDir = $defs['dir'];
        $deps = $defs['dependencies'];
        $binlog = $db['binlog'] ? 'yes' : 'no';
        $expire = (string) ($db['binlog_expire_days'] ?? 7);

        // Build the bootstrap + dependency SQL up front (no connection needed),
        // so --dry-run can print it without provisioning anything.
        $bootstrapFile = self::tempFile('ema_create_');
        Sql::buildSrvBootstrapSql($srvDir, $bootstrapFile);
        $applyFile = '';
        if (count($deps) > 0) {
            $applyFile = self::tempFile('ema_create_');
            Sql::buildSchemaSql($dbname, $applyFile, $deps);
        }

        if ($dryRun) {
            echo self::readFile($bootstrapFile);
            if ($applyFile !== '') {
                echo self::readFile($applyFile);
            }
            @unlink($bootstrapFile);
            if ($applyFile !== '') {
                @unlink($applyFile);
            }
            return 0;
        }

        $provisioned = ProdServer::instanceEnsure($instance, $quiet, $binlog, $expire);
        $conn = Connection::forUnixSocket($provisioned['socket']);
        $conn->mariadbArgs();
        if ($conn->databaseExists($dbname)) {
            @unlink($bootstrapFile);
            if ($applyFile !== '') {
                @unlink($applyFile);
            }
            throw new Error("database '$dbname' already exists — ema create is create-only (drop it first: DROP DATABASE $dbname)");
        }
        if ($verbose) {
            fwrite(STDERR, "Creating database: $dbname\n");
        }
        $conn->runRootSql('', $bootstrapFile);
        if ($applyFile !== '') {
            $conn->runRootSql($dbname, $applyFile);
        }
        @unlink($bootstrapFile);
        if ($applyFile !== '') {
            @unlink($applyFile);
        }
        if (!$quiet) {
            echo "Created database: $dbname\n";
        }
        ProdServer::printValues($instance, $dbname, $provisioned['port'], $provisioned['socket']);
        return 0;
    }

    private function createReplica(
        string $instance,
        string $dbname,
        string $replicaOf,
        string $sslVerify,
        Args $args,
    ): int {
        $fromSnapshot = $args->option('--from-snapshot');
        $dryRun = $args->flag('--dry-run', '-n', 'dry_run');
        $quiet = $args->flag('--quiet', '-q', 'quiet');
        $verbose = $args->flag('--verbose', '-v', 'verbose');

        if ($replicaOf === '') {
            throw new Error("srv package for '$instance' is type=replica but has no replica_of (primary instance required)");
        }

        // Declaration gate: the replica serves the primary's schema under its
        // name, so its dbname must equal the primary package's dbname.
        $primaryDirs = glob("srv/{$replicaOf}-*", GLOB_ONLYDIR) ?: [];
        if (count($primaryDirs) === 0) {
            throw new Error("replica_of '$replicaOf' — no primary package srv/{$replicaOf}-<GUID> found");
        }
        if (count($primaryDirs) !== 1) {
            throw new Error("replica_of '$replicaOf' is ambiguous (multiple srv/{$replicaOf}-<GUID> packages)");
        }
        $primaryRef = basename($primaryDirs[0]);
        $primaryDefs = \srv_definition($primaryRef);
        $primaryDbname = $primaryDefs['db']['dbname'];
        if ($dbname !== $primaryDbname) {
            throw new Error("replica '$instance' declares dbname '$dbname' but primary '$replicaOf' serves '$primaryDbname' — a replica serves the primary's schema under its name");
        }

        // Gate 1: the shipped snapshot is a required, explicit input.
        if ($fromSnapshot === '') {
            throw new Error("'$instance' is a replica (type=replica) — 'ema create' requires --from-snapshot <path>\n       Run the manual bootstrap first (see doc/system/replica-bootstrap.md).");
        }
        if (!is_dir($fromSnapshot)) {
            throw new Error("--from-snapshot '$fromSnapshot' is not a directory (expected a mariadb-backup backup)");
        }

        // Gate 2: the snapshot must carry a binlog file/pos.
        $snapshotInfo = "$fromSnapshot/mariadb_backup_info";
        if (!is_file($snapshotInfo)) {
            $snapshotInfo = "$fromSnapshot/xtrabackup_info";
        }
        if (!is_file($snapshotInfo)) {
            throw new Error("'$fromSnapshot' is not a mariadb-backup backup (missing mariadb_backup_info/xtrabackup_info)");
        }
        $info = file_get_contents($snapshotInfo);
        $info = $info === false ? '' : $info;
        $snapshotFile = '';
        $snapshotPos = '';
        if (preg_match("/filename '([^']*)'/", $info, $m) === 1) {
            $snapshotFile = $m[1];
        }
        if (preg_match("/position '([0-9]*)'/", $info, $m) === 1) {
            $snapshotPos = $m[1];
        }
        if ($snapshotFile === '' || $snapshotPos === '') {
            throw new Error("snapshot '$fromSnapshot' records no binlog file/pos — the primary must have binlog enabled");
        }

        // Read the primary's section for the replica's MASTER_* endpoint.
        $primary = Connection::loadSection(ProdServer::reuterFile(), $replicaOf);
        $masterHost = $primary->server !== '' ? $primary->server : 'localhost';
        $masterPort = $primary->port;
        if ($masterPort === '') {
            throw new Error("primary section '[$replicaOf]' has no PORT — replication needs the primary's TCP endpoint");
        }

        if ($dryRun) {
            echo "Replica build (dry-run):\n"
                . "  replica:   $instance (schema $dbname)\n"
                . "  primary:   $replicaOf\n"
                . "  snapshot:  $fromSnapshot\n"
                . "  resume:    $snapshotFile:$snapshotPos\n"
                . "  read_only: 1\n"
                . "  CHANGE MASTER TO MASTER_HOST='$masterHost', MASTER_PORT=$masterPort, MASTER_USER='replication', MASTER_LOG_FILE='$snapshotFile', MASTER_LOG_POS=$snapshotPos, MASTER_SSL_VERIFY_SERVER_CERT=$sslVerify;\n";
            return 0;
        }

        $user = ProdServer::user();
        if (!ProdServer::userExists($user)) {
            throw new Error("system user '$user' does not exist (override with EMA_PROD_USER)");
        }
        $conffile = ProdServer::confFile($instance);
        if (is_file($conffile)) {
            throw new Error("instance '$instance' already exists ($conffile present) — ema create is create-only");
        }
        $datadir = ProdServer::data($instance);
        $unit = ProdServer::unit($instance);
        ProdServer::requireUnit();
        $replicaConf = "read_only = 1\nserver_id = " . ProdServer::replicaServerId($instance);

        // Prepare the snapshot once (skip when already prepared: backup_type is
        // 'log-applied').
        $checkpoints = "$fromSnapshot/mariadb_backup_checkpoints";
        if (!is_file($checkpoints)) {
            $checkpoints = "$fromSnapshot/xtrabackup_checkpoints";
        }
        $backupType = '';
        if (is_file($checkpoints)) {
            $cpContent = file_get_contents($checkpoints);
            if ($cpContent !== false) {
                $backupType = self::checkpointField($cpContent);
            }
        }
        if ($backupType !== 'log-applied') {
            if ($verbose) {
                fwrite(STDERR, "Preparing snapshot: $fromSnapshot\n");
            }
            $prepare = Process::run(['mariadb-backup', '--prepare', "--target-dir=$fromSnapshot"]);
            if ($prepare['code'] !== 0) {
                throw new Error("mariadb-backup --prepare failed for '$fromSnapshot'");
            }
        }

        // Provision the replica instance + restore the snapshot into its (empty)
        // datadir. Auto-pick a port; retry only a lost start race with a fresh
        // port (never re-pick a running instance's port).
        $port = '';
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $port = ProdServer::freePort();
            @mkdir($datadir, 0777, true);
            ProdServer::chownRecursive(ProdServer::base() . "/$instance", $user);
            ProdServer::writeConf($instance, $port, $user, $replicaConf, 'no');
            if (!is_file("$datadir/ibdata1")) {
                if ($verbose) {
                    fwrite(STDERR, "Restoring snapshot into: $datadir\n");
                }
                $copyBack = Process::run(['mariadb-backup', '--copy-back', "--target-dir=$fromSnapshot", "--datadir=$datadir"]);
                if ($copyBack['code'] !== 0) {
                    throw new Error("mariadb-backup --copy-back failed into '$datadir'");
                }
                ProdServer::chownRecursive(ProdServer::base() . "/$instance", $user);
            }
            $enable = Process::run(['systemctl', 'enable', '--now', $unit]);
            if ($enable['code'] === 0 && ProdServer::waitListening($unit, $port)) {
                break;
            }
            fwrite(STDERR, "Warning: replica instance $unit did not come up on port $port; retrying with a new port\n");
            Process::run(['systemctl', 'stop', $unit]);
            @unlink($conffile);
            $port = '';
        }
        if ($port === '') {
            throw new Error("could not start replica instance $instance after 3 attempts");
        }

        // Attach the replica to the primary over the low-priv replication
        // account (never root): resume from the snapshot's file/pos, then
        // START SLAVE.
        $socket = ProdServer::sock($instance);
        $conn = Connection::forUnixSocket($socket);
        $conn->mariadbArgs();
        $replicaFile = self::tempFile('ema_replica_');
        if (file_put_contents(
            $replicaFile,
            "CHANGE MASTER TO MASTER_HOST='$masterHost', MASTER_PORT=$masterPort, MASTER_USER='replication', MASTER_LOG_FILE='$snapshotFile', MASTER_LOG_POS=$snapshotPos, MASTER_SSL_VERIFY_SERVER_CERT=$sslVerify;\n"
            . "START SLAVE;\n",
        ) === false) {
            @unlink($replicaFile);
            throw new Error("cannot write $replicaFile");
        }
        if ($verbose) {
            fwrite(STDERR, "Attaching replica '$instance' to primary '$replicaOf'...\n");
        }
        try {
            $conn->runRootSql('', $replicaFile);
        } catch (Error $e) {
            @unlink($replicaFile);
            throw new Error("failed to configure/start replication for '$instance'");
        }
        @unlink($replicaFile);

        // Gate 3: both replication threads must reach Yes.
        $status = '';
        $slaveIo = '';
        $slaveSql = '';
        for ($i = 0; $i < 600; $i++) {
            $status = Process::run([Connection::dbms(), '-u', 'root', "--socket=$socket", '-e', 'SHOW SLAVE STATUS\G'])['stdout'];
            $slaveIo = self::slaveField($status, 'Slave_IO_Running');
            $slaveSql = self::slaveField($status, 'Slave_SQL_Running');
            if ($slaveIo === 'Yes' && $slaveSql === 'Yes') {
                break;
            }
            if ($slaveIo === 'No') {
                break;
            }
            usleep(100000);
        }
        if ($slaveIo !== 'Yes' || $slaveSql !== 'Yes') {
            $lastIoErrno = self::slaveField($status, 'Last_IO_Errno');
            $lastIoError = self::slaveField($status, 'Last_IO_Error');
            $lastSqlErrno = self::slaveField($status, 'Last_SQL_Errno');
            $lastSqlError = self::slaveField($status, 'Last_SQL_Error');
            $slaveIo = $slaveIo !== '' ? $slaveIo : 'unset';
            $slaveSql = $slaveSql !== '' ? $slaveSql : 'unset';
            $lastIoErrno = $lastIoErrno !== '' ? $lastIoErrno : '0';
            $lastIoError = $lastIoError !== '' ? $lastIoError : 'none';
            $lastSqlErrno = $lastSqlErrno !== '' ? $lastSqlErrno : '0';
            $lastSqlError = $lastSqlError !== '' ? $lastSqlError : 'none';
            throw new Error("replica '$instance' not replicating (Slave_IO_Running='$slaveIo', Slave_SQL_Running='$slaveSql') — Last_IO_Errno: $lastIoErrno; Last_IO_Error: $lastIoError; Last_SQL_Errno: $lastSqlErrno; Last_SQL_Error: $lastSqlError (see doc/system/replica-bootstrap.md)");
        }

        if (!$quiet) {
            echo "Created replica: $instance (of $replicaOf)\n";
        }
        ProdServer::printValues($instance, $dbname, $port, $socket);
        return 0;
    }

    private static function tempFile(string $prefix): string
    {
        $path = tempnam(sys_get_temp_dir(), $prefix);
        if ($path === false) {
            throw new Error('cannot create a temporary file');
        }
        return $path;
    }

    private static function readFile(string $path): string
    {
        $content = file_get_contents($path);
        return $content === false ? '' : $content;
    }

    /** awk -F: '$2' over a SHOW SLAVE STATUS\G field (truncates at a 2nd colon). */
    private static function slaveField(string $output, string $key): string
    {
        foreach (preg_split('/\r?\n/', $output) ?: [] as $line) {
            if (str_contains($line, $key)) {
                $parts = explode(':', $line);
                return trim($parts[1] ?? '');
            }
        }
        return '';
    }

    /** awk -F= 'backup_type = X' -> X (trimmed), from a backup checkpoints file. */
    private static function checkpointField(string $content): string
    {
        foreach (preg_split('/\r?\n/', $content) ?: [] as $line) {
            if (preg_match('/^[[:space:]]*backup_type[[:space:]]*=/', $line) === 1) {
                $parts = explode('=', $line, 2);
                return trim($parts[1] ?? '');
            }
        }
        return '';
    }
}
