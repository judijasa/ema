<?php

namespace Ema\Cli\Support;

use Ema\Cli\Error;

/**
 * A loaded reuter.ini section: the connection endpoint (SERVER/PORT/
 * MYSQL_UNIX_PORT) plus the schema the instance serves (DBNAME). Mirrors the
 * bash `_load_section` / `_load_target` / `_mariadb_args` state and the
 * root/sudo SQL helpers that run against it.
 */
final class Connection
{
    public string $server = '';
    public string $port = '';
    public string $dbname = '';
    public string $mysqlUnixPort = '';
    public string $sslVerifyServerCert = '';

    /** @var list<string> */
    private array $mariadbArgs = [];

    private bool $mariadbTcp = false;

    private string $mariadbUser = '';

    private function __construct()
    {
    }

    /**
     * A connection addressed by its own unix socket (the prod instance's own
     * socket, resolved before its reuter.ini section exists). Used by
     * `create`/`upgrade` to reach the instance as root over unix_socket auth,
     * mirroring the bash `MYSQL_UNIX_PORT=$_PROD_SOCKET; _mariadb_args` pair.
     */
    public static function forUnixSocket(string $socket, string $dbname = ''): self
    {
        $self = new self();
        $self->mysqlUnixPort = $socket;
        $self->dbname = $dbname;
        return $self;
    }

    /**
     * Load the [<section>] of <reuter> into this connection. The header IS the
     * instance; DBNAME is the schema it serves (falling back to the header).
     */
    public static function loadSection(string $reuter, string $section): self
    {
        if ($section === '') {
            throw new Error('an instance name is required (the section header is the instance)');
        }
        if (!is_file($reuter)) {
            throw new Error(
                "$reuter not found (sandbox: run 'ema sandbox ...'; prod: record the section from 'ema values <instance>')"
            );
        }
        $cnf = parse_ini_file($reuter, true);
        if (!is_array($cnf) || !isset($cnf[$section])) {
            throw new Error("section '$section' not found in $reuter");
        }
        $sectionCnf = $cnf[$section];
        if (!is_array($sectionCnf)) {
            throw new Error("section '$section' not found in $reuter");
        }

        $self = new self();
        $self->server = self::iniValue($sectionCnf, 'SERVER');
        $self->port = self::iniValue($sectionCnf, 'PORT');
        $self->dbname = self::iniValue($sectionCnf, 'DBNAME');
        $self->mysqlUnixPort = self::iniValue($sectionCnf, 'MYSQL_UNIX_PORT');
        $self->sslVerifyServerCert = self::iniValue($sectionCnf, 'SSL_VERIFY_SERVER_CERT');
        if ($self->dbname === '') {
            $self->dbname = $section;
        }
        return $self;
    }

    /**
     * @param array<mixed, mixed> $section
     */
    private static function iniValue(array $section, string $key): string
    {
        $value = $section[$key] ?? null;
        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * The client's transport args for the loaded section: its socket when it
     * has one, else its TCP endpoint. Records which protocol was chosen.
     *
     * @return list<string>
     */
    public function mariadbArgs(): array
    {
        if ($this->mysqlUnixPort !== '') {
            $this->mariadbTcp = false;
            $this->mariadbArgs = ['--socket=' . $this->mysqlUnixPort, '--skip-ssl'];
        } else {
            $this->mariadbTcp = true;
            $this->mariadbArgs = ['-h', $this->server];
            if ($this->port !== '') {
                $this->mariadbArgs[] = '-P';
                $this->mariadbArgs[] = $this->port;
            }
        }
        return $this->mariadbArgs;
    }

    public function isTcp(): bool
    {
        return $this->mariadbTcp;
    }

    public function setUser(string $user): void
    {
        $this->mariadbUser = $user;
    }

    public function user(): string
    {
        return $this->mariadbUser;
    }

    /**
     * The client transport args assembled by mariadbArgs()/offhostConnect(),
     * including any SSL posture appended by forwardSslVerify(). Callers use
     * this instead of re-deriving from the section keys.
     *
     * @return list<string>
     */
    public function clientArgs(): array
    {
        return $this->mariadbArgs;
    }

    /**
     * DBUSER connections take DBPASS, forwarded as the client's own MYSQL_PWD
     * (env, never argv). Unset supplies nothing, leaving the password to the
     * client's own sources.
     */
    public function forwardDbpass(): void
    {
        $dbpass = getenv('DBPASS');
        if ($dbpass !== false && $dbpass !== '') {
            putenv('MYSQL_PWD=' . $dbpass);
            $_ENV['MYSQL_PWD'] = $dbpass;
        }
    }

    /**
     * State the section's server-verification posture to the client over TCP.
     * Must be called after mariadbArgs() (it reads the recorded protocol).
     */
    public function forwardSslVerify(): void
    {
        if (!$this->mariadbTcp) {
            return;
        }
        $posture = strtolower(trim($this->sslVerifyServerCert));
        if (in_array($posture, ['0', 'false', 'no', 'off'], true)) {
            $this->mariadbArgs[] = '--skip-ssl-verify-server-cert';
            return;
        }
        $sslDir = getenv('SSL_DIR');
        if ($sslDir === false || $sslDir === '') {
            return;
        }
        $this->mariadbArgs[] = '--ssl-ca=' . $sslDir . '/ca.crt';
        $this->mariadbArgs[] = '--ssl-verify-server-cert';
    }

    /**
     * Off-host prod connection: the instance is not on this host, so the
     * section's TCP endpoint is the only way in (its MYSQL_UNIX_PORT names a
     * socket on the instance's own host, not here). DBUSER is required — ema
     * never reaches an off-host instance as root.
     */
    public function offhostConnect(): void
    {
        if ($this->server === '') {
            throw new Error("section '$this->dbname' has no SERVER — cannot reach it from off-host");
        }
        if ($this->port === '') {
            throw new Error("section '$this->dbname' has no PORT — reaching it from off-host needs its TCP endpoint");
        }
        $user = getenv('DBUSER');
        if ($user === false || $user === '') {
            throw new Error("section '$this->dbname' is not on this host; set DBUSER to the SQL user to connect as");
        }
        $this->mariadbTcp = true;
        $this->mariadbArgs = ['-h', $this->server, '-P', $this->port];
        $this->mariadbUser = $user;
        fwrite(STDERR, "$this->dbname is not on this host; connecting over tcp to $this->server:$this->port as $user\n");
    }

    /**
     * Run a SQL file as root against the loaded connection, falling back to sudo
     * for system installs where the root socket requires elevation. The default
     * database is $dbname (empty string = no default database). Throws on failure.
     */
    public function runRootSql(string $dbname, string $sqlFile): void
    {
        $sql = file_get_contents($sqlFile);
        if ($sql === false) {
            throw new Error("cannot read $sqlFile");
        }
        $args = $this->rootArgs($dbname);
        $dbms = self::dbms();

        $first = Process::run(array_merge([$dbms], $args), [], $sql);
        if ($first['code'] === 0) {
            return;
        }

        $sudo = Process::run(array_merge(['sudo', 'env', 'PATH=' . (string) getenv('PATH'), 'MYSQL_UNIX_PORT=' . $this->mysqlUnixPort, $dbms], $args), [], $sql);
        if ($sudo['code'] !== 0) {
            $msg = trim($sudo['stderr']);
            throw new Error($msg !== '' ? $msg : "failed to run SQL against '$dbname'");
        }
    }

    /**
     * Run a single SQL statement as root and return its result set (one value
     * per line), mirroring `_run_root_eval`.
     */
    public function runRootEval(string $dbname, string $sql): string
    {
        $args = array_merge(['-N'], $this->rootArgs($dbname), ['-e', $sql]);
        $dbms = self::dbms();

        $first = Process::run(array_merge([$dbms], $args));
        if ($first['code'] === 0) {
            return $first['stdout'];
        }
        $sudo = Process::run(array_merge(['sudo', 'env', 'PATH=' . (string) getenv('PATH'), 'MYSQL_UNIX_PORT=' . $this->mysqlUnixPort, $dbms], $args));
        return $sudo['stdout'];
    }

    /**
     * True when database <dbname> exists on the resolved connection
     * (information_schema.SCHEMATA). SELECT only.
     */
    public function databaseExists(string $dbname): bool
    {
        $sql = "SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = '$dbname';";
        return trim($this->runRootEval('', $sql)) !== '';
    }

    /**
     * Apply the schema-package dependency graph rooted at $rootPkgs to $dbname
     * (non-destructive: CREATE OR REPLACE, no DROP).
     *
     * @param list<string> $rootPkgs
     */
    public function applyPkgGraph(string $dbname, array $rootPkgs): void
    {
        $sqlFile = self::tempFile('ema_schema_');
        Sql::buildSchemaSql($dbname, $sqlFile, $rootPkgs);
        $this->runRootSql($dbname, $sqlFile);
        @unlink($sqlFile);
    }

    /** The MariaDB client binary (DBMS, default mariadb). */
    public static function dbms(): string
    {
        $dbms = getenv('DBMS');
        return ($dbms === false || $dbms === '') ? 'mariadb' : $dbms;
    }

    /** @return list<string> */
    private function rootArgs(string $dbname): array
    {
        $args = array_merge(['-u', 'root'], $this->mariadbArgs());
        if ($dbname !== '') {
            $args[] = $dbname;
        }
        return $args;
    }

    private static function tempFile(string $prefix): string
    {
        $path = tempnam(sys_get_temp_dir(), $prefix);
        if ($path === false) {
            throw new Error('cannot create a temporary file');
        }
        return $path;
    }
}
