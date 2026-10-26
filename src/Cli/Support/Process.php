<?php

namespace Ema\Cli\Support;

use Ema\Cli\Error;

/**
 * Subprocess runner. Invocations are argv arrays (never a shell string), so an
 * argument can never be re-interpreted by a shell — the PHP equivalent of the
 * bash script's direct binary calls (mysqld, mariadb, mariadb-admin,
 * mariadb-install-db, systemctl, sudo, ip, ...).
 */
final class Process
{
    /** @var list<resource> Background processes kept referenced until shutdown. */
    private static array $background = [];

    /**
     * Run a command, capturing its stdout/stderr and exit code.
     *
     * @param list<string>          $cmd
     * @param array<string, string> $env  Extra env vars merged over the inherited environment.
     * @return array{code: int, stdout: string, stderr: string}
     */
    public static function run(array $cmd, array $env = [], ?string $stdin = null): array
    {
        $proc = proc_open($cmd, self::pipes(), $pipes, null, self::environment($env));
        if (!is_resource($proc)) {
            throw new Error('failed to run: ' . implode(' ', $cmd));
        }
        if ($stdin !== null) {
            fwrite($pipes[0], $stdin);
        }
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);

        return [
            'code' => $code,
            'stdout' => $stdout === false ? '' : $stdout,
            'stderr' => $stderr === false ? '' : $stderr,
        ];
    }

    /**
     * Run a command in the foreground, wired to the process's own stdio (the
     * interactive `mariadb` client, `systemctl`, ...). Returns the exit code.
     *
     * @param list<string>          $cmd
     * @param array<string, string> $env
     */
    public static function passthru(array $cmd, array $env = [], ?string $stdinFile = null): int
    {
        $spec = [
            0 => $stdinFile !== null ? ['file', $stdinFile, 'r'] : ['file', 'php://stdin', 'r'],
            1 => ['file', 'php://stdout', 'w'],
            2 => ['file', 'php://stderr', 'w'],
        ];
        $proc = proc_open($cmd, $spec, $pipes, null, self::environment($env));
        if (!is_resource($proc)) {
            throw new Error('failed to run: ' . implode(' ', $cmd));
        }
        return proc_close($proc);
    }

    /**
     * Start a command detached in the background, appending its stdout/stderr to
     * $logFile when given. Returns the child PID.
     *
     * @param list<string> $cmd
     */
    public static function start(array $cmd, ?string $logFile = null): int
    {
        $out = $logFile !== null ? ['file', $logFile, 'a'] : ['file', '/dev/null', 'a'];
        $spec = [
            0 => ['file', '/dev/null', 'r'],
            1 => $out,
            2 => $out,
        ];
        $proc = proc_open($cmd, $spec, $pipes, null, self::environment([]));
        if (!is_resource($proc)) {
            throw new Error('failed to start: ' . implode(' ', $cmd));
        }
        $status = proc_get_status($proc);
        $pid = $status['pid'];
        self::$background[] = $proc;
        return $pid;
    }

    /**
     * The number of background children still referenced. The references are
     * the keep-alive: dropping one would proc_close (and block on) that child,
     * so the array is held for the process lifetime and only ever counted here.
     */
    public static function backgroundCount(): int
    {
        return count(self::$background);
    }

    /**
     * True when a process with $pid exists (kill -0).
     */
    public static function alive(int $pid): bool
    {
        if (function_exists('posix_kill')) {
            return posix_kill($pid, 0);
        }
        return self::run(['kill', '-0', (string) $pid])['code'] === 0;
    }

    /**
     * Send a signal to a process.
     */
    public static function signal(int $pid, int $sig): void
    {
        if (function_exists('posix_kill')) {
            @posix_kill($pid, $sig);
            return;
        }
        self::run(['kill', '-' . $sig, (string) $pid]);
    }

    /** @return array<int, list<string>> */
    private static function pipes(): array
    {
        return [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
    }

    /**
     * @param array<string, string> $extra
     * @return array<string, string>
     */
    private static function environment(array $extra): array
    {
        $env = getenv();
        foreach ($extra as $k => $v) {
            $env[$k] = $v;
        }
        return $env;
    }
}
