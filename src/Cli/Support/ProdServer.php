<?php

namespace Ema\Cli\Support;

use Ema\Cli\Error;

/**
 * Prod per-database instance helpers: paths, config writing, provisioning
 * (create/values/upgrade), replication, the host ssl-ca/ssl-crl read, and
 * name->dbname resolution. Mirrors the bash `_prod_*` path/unit/conf helpers,
 * `_srv_dbname_by_name`, `_host_conf_get`/`_EMA_SSL_CA`/`_EMA_SSL_CRL`,
 * `_get_free_port`/`_port_free`/`_prod_server_addr`/`_prod_conf_port`/
 * `_replica_server_id`, and `_prod_instance_ensure`/`_prod_write_conf`/
 * `_prod_print_values`.
 */
final class ProdServer
{
    public static function base(): string
    {
        $base = getenv('EMA_PROD_BASE');
        return ($base === false || $base === '') ? '/var/lib/mariadb' : $base;
    }

    public static function reuterFile(): string
    {
        $ini = getenv('REUTER_INI');
        return ($ini === false || $ini === '') ? 'etc/reuter.ini' : $ini;
    }

    public static function unit(string $name): string
    {
        return "mariadb@$name";
    }

    public static function instanceDirExists(string $name): bool
    {
        return is_dir(self::base() . "/$name");
    }

    public static function isActive(string $name): bool
    {
        $run = Process::run(['systemctl', 'is-active', '--quiet', self::unit($name)]);
        return $run['code'] === 0;
    }

    /**
     * The prod instances provisioned on THIS host, from their on-disk evidence
     * (a $base/<name>/ dir holding a data/ datadir) — never from etc/reuter.ini.
     *
     * @return list<string>
     */
    public static function instanceNames(): array
    {
        $base = self::base();
        if (!is_dir($base)) {
            return [];
        }
        $names = [];
        foreach (glob("$base/*", GLOB_ONLYDIR) ?: [] as $dir) {
            if (is_dir("$dir/data")) {
                $names[] = basename($dir);
            }
        }
        return $names;
    }

    // --- per-instance paths ---------------------------------------------------

    public static function data(string $name): string
    {
        return self::base() . "/$name/data";
    }

    public static function sock(string $name): string
    {
        return self::base() . "/$name/mysql.sock";
    }

    public static function pid(string $name): string
    {
        return self::base() . "/$name/mysql.pid";
    }

    public static function log(string $name): string
    {
        return self::base() . "/$name/mariadb.log";
    }

    public static function binlog(string $name): string
    {
        return self::base() . "/$name/binlog";
    }

    public static function confBase(): string
    {
        $dir = getenv('EMA_PROD_CONF_DIR');
        return ($dir === false || $dir === '') ? '/etc' : $dir;
    }

    public static function confFile(string $name): string
    {
        return self::confBase() . "/$name/my.cnf";
    }

    public static function user(): string
    {
        $user = getenv('EMA_PROD_USER');
        return ($user === false || $user === '') ? 'mysql' : $user;
    }

    public static function bind(): string
    {
        $bind = getenv('EMA_PROD_BIND');
        return ($bind === false || $bind === '') ? '0.0.0.0' : $bind;
    }

    public static function unitFile(): string
    {
        return '/etc/systemd/system/mariadb@.service';
    }

    /**
     * Resolve a bare instance name (<name> from srv/<name>-<GUID>) to the
     * database (schema) it serves: its package's `dbname`. A primary declares
     * dbname = its instance; a replica declares dbname = the primary's schema.
     * Falls back to the instance name itself when no single package declares it
     * (or the package fails to load). Mirrors `_srv_dbname_by_name`.
     */
    public static function srvDbnameByName(string $instance): string
    {
        $dirs = glob("srv/{$instance}-*", GLOB_ONLYDIR) ?: [];
        if (count($dirs) === 1 && is_dir($dirs[0])) {
            $dir = 'srv/' . basename($dirs[0]);
            try {
                $config = require "$dir/default.php";
            } catch (\Throwable $e) {
                return $instance;
            }
            if ($config instanceof \Ema\Config\DatabaseConfig) {
                $dbname = $config->dbname;
                if ($dbname !== '') {
                    return $dbname;
                }
            }
        }
        return $instance;
    }

    // --- host-level ssl config (etc/ema.conf, EMA_SSL_CA/EMA_SSL_CRL) --------

    /**
     * The CA the instance's [mysqld] verifies CLIENT certificates against.
     * Host-level (never per instance); EMA_SSL_CA overrides, else the merged
     * etc/ema.default.conf + etc/ema.conf. Empty emits no ssl-ca line.
     */
    public static function sslCa(): string
    {
        $env = getenv('EMA_SSL_CA');
        $ca = ($env !== false && $env !== '') ? $env : self::hostConfGet('ssl-ca');
        if ($ca !== '' && !str_starts_with($ca, '/')) {
            throw new Error("ssl-ca '$ca' is not an absolute path (etc/ema.conf)");
        }
        return $ca;
    }

    /**
     * The CRL the instance checks CLIENT certificates against. Only meaningful
     * together with ssl-ca, so it is validated against it. EMA_SSL_CRL
     * overrides, else the merged host config. Empty emits no ssl-crl line.
     */
    public static function sslCrl(): string
    {
        $env = getenv('EMA_SSL_CRL');
        $crl = ($env !== false && $env !== '') ? $env : self::hostConfGet('ssl-crl');
        if ($crl !== '' && !str_starts_with($crl, '/')) {
            throw new Error("ssl-crl '$crl' is not an absolute path (etc/ema.conf)");
        }
        if ($crl !== '' && self::sslCa() === '') {
            throw new Error('ssl-crl is set but ssl-ca is not (etc/ema.conf) — a CRL is meaningless without a CA');
        }
        return $crl;
    }

    /**
     * The merged host conf read: etc/ema.default.conf (tracked fallback) then
     * etc/ema.conf (the git-ignored override). An override that defines the key
     * wins — even empty, which explicitly clears a defaulted value.
     */
    private static function hostConfGet(string $key): string
    {
        $val = null;
        foreach (['etc/ema.default.conf', 'etc/ema.conf'] as $f) {
            if (!is_file($f)) {
                continue;
            }
            $cnf = @parse_ini_file($f);
            if (is_array($cnf) && array_key_exists($key, $cnf)) {
                $value = $cnf[$key];
                $val = is_scalar($value) ? (string) $value : null;
            }
        }
        return $val ?? '';
    }

    // --- ports / server address ----------------------------------------------

    /**
     * Pick a free TCP port via a kernel-assigned ephemeral bind (bind port 0,
     * read the port, release). Inherently racy: the caller re-picks when a
     * start loses the race.
     */
    public static function freePort(): string
    {
        $sock = @stream_socket_server('tcp://0.0.0.0:0', $errno, $errstr);
        if ($sock === false) {
            throw new Error("cannot bind an ephemeral port: $errstr");
        }
        $name = stream_socket_get_name($sock, false);
        fclose($sock);
        if ($name === false) {
            throw new Error('cannot bind an ephemeral port');
        }
        return substr($name, strrpos($name, ':') + 1);
    }

    /** True when nothing is listening on TCP <port> (a fresh bind succeeds). */
    public static function portFree(int $port): bool
    {
        $sock = @stream_socket_server('tcp://0.0.0.0:' . $port, $errno, $errstr);
        if ($sock === false) {
            return false;
        }
        fclose($sock);
        return true;
    }

    /**
     * The address prod sections advertise (SERVER): EMA_SERVER overrides;
     * otherwise the first global IPv4 on a ZeroTier interface (zt*), then the
     * first global IPv4, then localhost.
     */
    public static function serverAddr(): string
    {
        $env = getenv('EMA_SERVER');
        if ($env !== false && $env !== '') {
            return $env;
        }
        if (!self::inPath('ip')) {
            return 'localhost';
        }
        $out = Process::run(['ip', '-4', '-o', 'addr', 'show', 'scope', 'global']);
        if ($out['code'] !== 0) {
            return 'localhost';
        }
        $lines = preg_split('/\r?\n/', $out['stdout']) ?: [];
        foreach ($lines as $line) {
            $parts = preg_split('/\s+/', trim($line)) ?: [];
            $ifname = $parts[1] ?? '';
            if (str_starts_with($ifname, 'zt')) {
                $addr = $parts[3] ?? '';
                if ($addr !== '') {
                    return self::stripCidr($addr);
                }
            }
        }
        foreach ($lines as $line) {
            $parts = preg_split('/\s+/', trim($line)) ?: [];
            $addr = $parts[3] ?? '';
            if ($addr !== '') {
                return self::stripCidr($addr);
            }
        }
        return 'localhost';
    }

    /** Read the persisted port from an instance defaults file. */
    public static function confPort(string $confFile): string
    {
        $content = file_get_contents($confFile);
        if ($content === false) {
            return '';
        }
        foreach (preg_split('/\r?\n/', $content) ?: [] as $line) {
            if (preg_match('/^[[:space:]]*port[[:space:]]*=/', $line) === 1) {
                $parts = explode('=', $line, 2);
                $value = $parts[1] ?? '';
                return preg_replace('/\s+/', '', $value) ?? '';
            }
        }
        return '';
    }

    /**
     * A stable, distinct server_id for a replica, derived from its name
     * (primary instances leave the server default of 1).
     */
    public static function replicaServerId(string $name): int
    {
        $n = (int) ((crc32($name) % 2147483646) + 1);
        if ($n === 1) {
            $n = 2;
        }
        return $n;
    }

    // --- provisioning ---------------------------------------------------------

    /**
     * Write the per-instance defaults file (idempotent). <extra>, when
     * non-empty, is appended verbatim (replica read_only/server_id). <binlog>
     * ("yes"/"no") writes the binary-log block; <expire> is the retention in
     * days. The host-level ssl-ca/ssl-crl are written when set.
     */
    public static function writeConf(
        string $db,
        string $port,
        string $user,
        string $extra = '',
        string $binlog = 'no',
        string $expire = '7',
    ): void {
        // Consume-time check: the CA/CRL must exist when written into the
        // instance (shape was checked at read; the file is installed out of band).
        $ca = self::sslCa();
        $crl = self::sslCrl();
        if ($ca !== '' && !is_file($ca)) {
            throw new Error("ssl-ca '$ca' does not exist (etc/ema.conf)");
        }
        if ($crl !== '' && !is_file($crl)) {
            throw new Error("ssl-crl '$crl' does not exist (etc/ema.conf)");
        }

        $dir = self::confBase() . "/$db";
        @mkdir($dir, 0777, true);

        $content = "[mysqld]\n"
            . "datadir      = " . self::data($db) . "\n"
            . "socket       = " . self::sock($db) . "\n"
            . "pid-file     = " . self::pid($db) . "\n"
            . "log-error    = " . self::log($db) . "\n"
            . "user         = $user\n"
            . "port         = $port\n"
            . "bind-address = " . self::bind() . "\n";
        if ($ca !== '') {
            $content .= "ssl-ca       = $ca\n";
        }
        if ($crl !== '') {
            $content .= "ssl-crl      = $crl\n";
        }
        if ($binlog === 'yes') {
            $content .= "log_bin          = " . self::binlog($db) . "\n"
                . "binlog_format    = ROW\n"
                . "expire_logs_days = $expire\n";
        }
        if ($extra !== '') {
            $content .= $extra . "\n";
        }

        $confFile = self::confFile($db);
        if (file_put_contents($confFile, $content) === false) {
            throw new Error("cannot write $confFile");
        }
        @chmod($confFile, 0644);
    }

    /**
     * The mariadb@.service template is a static file installed by the host's
     * provisioning, NOT authored here. ema only asserts it exists before
     * touching anything.
     */
    public static function requireUnit(): void
    {
        $unitFile = self::unitFile();
        if (!is_file($unitFile)) {
            throw new Error(
                "systemd unit '$unitFile' not found\n"
                . "The mariadb@.service template is host-provisioned (a static unit\n"
                . "installed by the deploy/provision layer), not authored by ema — install\n"
                . "it before running ema create."
            );
        }
    }

    /** Wait (bounded) until <unit> is active AND accepting TCP on <port>. */
    public static function waitListening(string $unit, string $port): bool
    {
        for ($i = 0; $i < 50; $i++) {
            $active = Process::run(['systemctl', 'is-active', '--quiet', $unit])['code'] === 0;
            if ($active && !self::portFree((int) $port)) {
                return true;
            }
            if ($i > 5 && !$active) {
                return false;
            }
            usleep(100000);
        }
        return false;
    }

    /**
     * Ensure the per-database instance exists and is running. Idempotent: an
     * existing defaults file is authoritative (its port is never re-picked).
     * On first provision the port is auto-picked and the instance is
     * initialized + started; a lost start race re-picks a new port.
     *
     * @return array{port: string, socket: string}
     */
    public static function instanceEnsure(string $db, bool $quiet, string $binlog = 'no', string $expire = '7'): array
    {
        $datadir = self::data($db);
        $conffile = self::confFile($db);
        $unit = self::unit($db);
        $user = self::user();
        $socket = self::sock($db);

        if (!self::userExists($user)) {
            throw new Error("system user '$user' does not exist (override with EMA_PROD_USER)");
        }

        self::requireUnit();

        if (is_file($conffile)) {
            $port = self::confPort($conffile);
            if ($port === '') {
                throw new Error("no port in $conffile");
            }
            if (self::isActive($db)) {
                if (!$quiet) {
                    echo "    Instance $unit already running (port $port).\n";
                }
                return ['port' => $port, 'socket' => $socket];
            }
            if (!$quiet) {
                echo "    Starting instance $unit (port $port)...\n";
            }
            Process::run(['systemctl', 'start', $unit]);
            if (!self::waitListening($unit, $port)) {
                throw new Error("instance $unit did not accept connections on port $port");
            }
            return ['port' => $port, 'socket' => $socket];
        }

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $port = self::freePort();
            @mkdir($datadir, 0777, true);
            self::chownRecursive(self::base() . "/$db", $user);
            self::writeConf($db, $port, $user, '', $binlog, $expire);
            if (!self::dirNonEmpty($datadir)) {
                if (!$quiet) {
                    echo "    Initializing MariaDB datadir for '$db'...\n";
                }
                $install = Process::run(['mariadb-install-db', '--skip-test-db', "--user=$user", "--datadir=$datadir"]);
                if ($install['code'] !== 0) {
                    throw new Error("mariadb-install-db failed for '$db'");
                }
            }
            $enable = Process::run(['systemctl', 'enable', '--now', $unit]);
            if ($enable['code'] === 0 && self::waitListening($unit, $port)) {
                if (!$quiet) {
                    echo "    Instance $unit created (port $port).\n";
                }
                return ['port' => $port, 'socket' => $socket];
            }
            fwrite(STDERR, "Warning: instance $unit did not come up on port $port; retrying with a new port\n");
            Process::run(['systemctl', 'stop', $unit]);
            @unlink($conffile);
        }

        throw new Error("could not start instance $unit after 3 attempts");
    }

    /**
     * Print the reuter.ini section shape for a prod instance, for the operator
     * to record in the consumer's manual reuter.ini.
     */
    public static function printValues(string $instance, string $dbname, string $port, string $sock): void
    {
        echo "\nRecord this section in reuter.ini:\n\n"
            . "[$instance]\n"
            . 'SERVER=' . self::serverAddr() . "\n"
            . "PORT=$port\n"
            . "MYSQL_UNIX_PORT=$sock\n"
            . "DBNAME=$dbname\n";
    }

    public static function userExists(string $user): bool
    {
        return Process::run(['id', '-u', $user])['code'] === 0;
    }

    public static function chownRecursive(string $path, string $user): void
    {
        Process::run(['chown', '-R', "$user:$user", $path]);
    }

    private static function dirNonEmpty(string $dir): bool
    {
        if (!is_dir($dir)) {
            return false;
        }
        $items = scandir($dir);
        if ($items === false) {
            return false;
        }
        foreach ($items as $item) {
            if ($item !== '.' && $item !== '..') {
                return true;
            }
        }
        return false;
    }

    private static function inPath(string $bin): bool
    {
        $path = getenv('PATH');
        if ($path === false || $path === '') {
            return false;
        }
        foreach (explode(':', $path) as $dir) {
            $candidate = ($dir === '') ? $bin : $dir . '/' . $bin;
            if (is_file($candidate) && is_executable($candidate)) {
                return true;
            }
        }
        return false;
    }

    private static function stripCidr(string $addr): string
    {
        $pos = strpos($addr, '/');
        return $pos === false ? $addr : substr($addr, 0, $pos);
    }
}
