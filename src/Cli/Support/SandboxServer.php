<?php

namespace Ema\Cli\Support;

use Ema\Cli\Args;
use Ema\Cli\Error;

/**
 * Per-instance sandbox server (var/sandbox/<name>-<guid>/): paths, start/stop,
 * status, and the create-only build driver. Mirrors the bash sandbox helpers
 * (_sandbox_*, _resolve_instance, _resolve_instance_path, _lifecycle_keys,
 * _list_sandbox_keys, _write_sandbox_ini, _sandbox_build, _maybe_open_shell).
 */
final class SandboxServer
{
    public static function dir(string $key): string
    {
        return "var/sandbox/$key";
    }

    public static function data(string $key): string
    {
        return "var/sandbox/$key/data";
    }

    public static function pid(string $key): string
    {
        return "var/sandbox/$key/mysql.pid";
    }

    public static function sock(string $key): string
    {
        return "var/sandbox/$key/mysql.sock";
    }

    public static function ini(string $key): string
    {
        return "var/sandbox/$key/reuter.ini";
    }

    /**
     * Normalize a target ref (<name>-<GUID>, or an srv/... or pkg/... path) to
     * the bare instance key <name>-<GUID> (trailing slash tolerated).
     */
    public static function instanceKey(string $ref): string
    {
        return basename(rtrim($ref, '/'));
    }

    /**
     * Resolve a name-addressed ref (a bare dbname, or a full <name>-<GUID>) to
     * its sandbox instance key. Returns null when none matches; errors on
     * ambiguity.
     */
    public static function resolveInstance(string $ref): ?string
    {
        $key = self::instanceKey($ref);
        if (is_dir(self::dir($key))) {
            return $key;
        }
        $matches = glob("var/sandbox/{$key}-*", GLOB_ONLYDIR) ?: [];
        if (count($matches) === 1) {
            return basename($matches[0]);
        }
        if (count($matches) > 1) {
            throw new Error("multiple sandboxes match '$ref' (use a full <name>-<GUID>)");
        }
        return null;
    }

    /**
     * Resolve a path-addressed ref (var/sandbox/<name>-<guid>) to its key.
     */
    public static function resolveInstancePath(string $ref): string
    {
        $ref = rtrim($ref, '/');
        if (!str_starts_with($ref, 'var/sandbox/') && !str_contains($ref, '/var/sandbox/')) {
            throw new Error(
                "not a sandbox instance path: $ref\n  Instances are addressed by path — copy one from 'ema status'."
            );
        }
        $key = basename($ref);
        if (!is_dir(self::dir($key))) {
            throw new Error("no such sandbox instance: $ref");
        }
        return $key;
    }

    public static function pidValue(string $key): string
    {
        $pidfile = self::pid($key);
        if (!is_file($pidfile)) {
            return '';
        }
        $pid = file_get_contents($pidfile);
        return $pid === false ? '' : trim($pid);
    }

    public static function isUp(string $key): bool
    {
        $pid = self::pidValue($key);
        return $pid !== '' && Process::alive((int) $pid);
    }

    public static function start(string $key, bool $quiet): void
    {
        if (self::isUp($key)) {
            if (!$quiet) {
                echo "already running (pid " . self::pidValue($key) . ")\n";
            }
            return;
        }
        if (!is_dir(self::data($key))) {
            throw new Error("no data dir for sandbox '$key' — build it first ('ema sandbox ...')");
        }
        @mkdir('var/log', 0777, true);

        // mysqld resolves RELATIVE --datadir/--pid-file/--socket against its own
        // basedir, not the CWD — absolutize (mariadb-install-db has the same quirk).
        $cwd = (string) getcwd();
        Process::start([
            'mysqld',
            '--datadir=' . $cwd . '/' . self::data($key),
            '--pid-file=' . $cwd . '/' . self::pid($key),
            '--socket=' . $cwd . '/' . self::sock($key),
            '--skip-networking',
        ], 'var/log/mariadb.log');

        $pid = '';
        for ($i = 0; $i < 50; $i++) {
            $pid = self::pidValue($key);
            if ($pid !== '' && Process::alive((int) $pid)) {
                break;
            }
            usleep(100000);
        }
        if ($pid === '' || !Process::alive((int) $pid)) {
            throw new Error("MariaDB server failed to start for '$key' (see var/log/mariadb.log)");
        }
        if (!$quiet) {
            echo "started (pid $pid)\n";
        }
    }

    public static function stop(string $key, bool $quiet): void
    {
        $pid = self::pidValue($key);
        if ($pid === '' || !Process::alive((int) $pid)) {
            if (!$quiet) {
                echo "not running\n";
            }
            return;
        }
        $pidInt = (int) $pid;

        $shutdown = Process::run(['mariadb-admin', '--skip-ssl', '-u', 'root', '--socket=' . self::sock($key), 'shutdown']);
        if ($shutdown['code'] !== 0) {
            Process::signal($pidInt, 15); // SIGTERM
        }
        for ($i = 0; $i < 100; $i++) {
            if (!Process::alive($pidInt)) {
                break;
            }
            usleep(100000);
        }
        if (Process::alive($pidInt)) {
            fwrite(STDERR, "Error: server did not stop gracefully — sending SIGKILL\n");
            Process::signal($pidInt, 9); // SIGKILL
            usleep(200000);
        }
        if (!$quiet) {
            echo "stopped\n";
        }
    }

    public static function age(string $path): string
    {
        if (!file_exists($path)) {
            return '-';
        }
        $diff = time() - (int) filemtime($path);
        if ($diff < 60) {
            return $diff . 's';
        }
        if ($diff < 3600) {
            return (int) ($diff / 60) . 'm';
        }
        if ($diff < 86400) {
            return (int) ($diff / 3600) . 'h';
        }
        return (int) ($diff / 86400) . 'd';
    }

    /**
     * @return list<string>
     */
    public static function listKeys(): array
    {
        if (!is_dir('var/sandbox')) {
            return [];
        }
        $keys = [];
        foreach (glob('var/sandbox/*', GLOB_ONLYDIR) ?: [] as $dir) {
            $keys[] = basename($dir);
        }
        return $keys;
    }

    /**
     * The instance keys a lifecycle command operates on: one key when a path is
     * given, otherwise every sandbox instance.
     *
     * @return list<string>
     */
    public static function lifecycleKeys(string $ref): array
    {
        if ($ref !== '') {
            return [self::resolveInstancePath($ref)];
        }
        return self::listKeys();
    }

    public static function writeIni(string $key, string $dbname): void
    {
        $instance = Repo::instanceName($key);
        $ini = self::ini($key);
        @mkdir(dirname($ini), 0777, true);
        $content = "; Generated by ema sandbox — machine-local (git-ignored).\n"
            . "; One [<instance>] section per sandbox instance; the header IS the instance,\n"
            . "; and DBNAME is the schema the instance serves.\n"
            . "[$instance]\n"
            . "SERVER=localhost\n"
            . "PORT=0\n"
            . "DBNAME=$dbname\n"
            . "MYSQL_UNIX_PORT=" . self::sock($key) . "\n";
        if (file_put_contents($ini, $content) === false) {
            throw new Error("cannot write $ini");
        }
    }

    /**
     * Shared sandbox build driver (create-only). <srvDir> is the srv/ package
     * dir (bootstrap read from its upgrade.sql), or '' to synthesize a default
     * bootstrap. <deps> are the schema-package graph roots.
     *
     * @param list<string> $deps
     */
    public static function build(string $key, string $dbname, string $srvDir, array $deps, Args $args): void
    {
        if ($key === '') {
            throw new Error('target has no instance key (expected srv/<name>-<GUID> or pkg/<pkg>-<GUID>)');
        }
        if (is_dir(self::data($key))) {
            throw new Error("sandbox '$key' already built — delete it to rebuild.");
        }

        @mkdir(self::dir($key), 0777, true);
        self::writeIni($key, $dbname);
        @mkdir(self::data($key), 0777, true);

        $cwd = (string) getcwd();
        $install = Process::run([
            'mariadb-install-db',
            '--auth-root-authentication-method=normal',
            '--skip-test-db',
            '--datadir=' . $cwd . '/' . self::data($key),
            '--pid-file=' . $cwd . '/' . self::pid($key),
        ]);
        if ($install['code'] !== 0) {
            throw new Error("mariadb-install-db failed for '$key'");
        }

        self::start($key, true);

        $conn = Connection::loadSection(self::ini($key), Repo::instanceName($key));
        $conn->mariadbArgs();

        $sqlFile = tempnam(sys_get_temp_dir(), 'ema_bootstrap_');
        if ($sqlFile === false) {
            throw new Error('cannot create a temporary file');
        }
        if ($srvDir !== '') {
            Sql::buildSrvBootstrapSql($srvDir, $sqlFile);
        } else {
            Sql::buildSynthBootstrapSql($dbname, 'utf8', 'utf8_spanish_ci', $sqlFile);
        }
        $conn->runRootSql('', $sqlFile);
        @unlink($sqlFile);

        if (count($deps) > 0) {
            $conn->applyPkgGraph($dbname, $deps);
        }

        self::maybeOpenShell(Repo::instanceName($key), $key, $args, $conn);
    }

    /**
     * After a successful init, open the shell unless -n/--no-shell; otherwise
     * print the command instead. The shell runs as a child (not exec) so ema can
     * ask whether to delete the instance once it exits.
     */
    public static function maybeOpenShell(string $instance, string $key, Args $args, Connection $conn): void
    {
        $noShell = $args->flag('--no-shell', '-n', 'no_shell');
        $quiet = $args->flag('--quiet', '-q', 'quiet');
        if ($noShell) {
            echo "Run: EMA_TARGET=sandbox ema mdb $instance\n";
        } elseif (stream_isatty(STDIN)) {
            echo "Opening shell for $instance (use -n/--no-shell to skip)...\n";
            self::runSandboxShell($key, $quiet, $conn, []);
        } else {
            echo "Run: EMA_TARGET=sandbox ema mdb $instance\n";
        }
    }

    /**
     * Run an interactive client against the loaded sandbox instance as a child,
     * then ask whether to remove the instance.
     *
     * @param list<string> $clientArgs
     */
    public static function runSandboxShell(string $key, bool $quiet, Connection $conn, array $clientArgs): int
    {
        $code = Process::passthru(array_merge([Connection::dbms(), '-u', 'root'], $conn->mariadbArgs(), $clientArgs));
        if (!self::removeOrKeep($key, $quiet)) {
            $code = 1;
        }
        return $code;
    }

    /**
     * After an interactive sandbox shell exits, ask whether to delete the
     * instance (default: delete). Returns false only when the instance is still
     * running and could not be removed.
     */
    public static function removeOrKeep(string $key, bool $quiet): bool
    {
        if (!Prompt::yesNo("Remove sandbox instance '$key'?", 'yes')) {
            if (!$quiet) {
                echo "Kept var/sandbox/$key (remove with 'ema gc var/sandbox/$key').\n";
            }
            return true;
        }
        self::stop($key, true);
        if (self::isUp($key)) {
            fwrite(STDERR, "Error: sandbox '$key' is still running — not removing it\n");
            return false;
        }
        self::removeTree(self::dir($key));
        if (!$quiet) {
            echo "Removed var/sandbox/$key.\n";
        }
        return true;
    }

    public static function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = "$dir/$item";
            if (is_dir($path) && !is_link($path)) {
                self::removeTree($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
