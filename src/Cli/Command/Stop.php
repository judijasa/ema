<?php

namespace Ema\Cli\Command;

use Ema\Cli\AbstractCommand;
use Ema\Cli\Args;
use Ema\Cli\Support\Repo;
use Ema\Cli\Support\SandboxServer;

/**
 * `ema stop [<path>]` — stop sandbox instance server(s). Mirrors the bash
 * `_cmd_stop`.
 */
final class Stop extends AbstractCommand
{
    public function name(): string
    {
        return 'stop';
    }

    public function help(): string
    {
        return <<<'TXT'
Usage: ema stop [<path>] [flags]

Stop sandbox instance server(s): mariadb-admin shutdown over each instance's
socket, falling back to SIGTERM/SIGKILL. With no path, all instances are
stopped. Sandbox instances only.

Address one instance by its path (var/sandbox/<name>-<guid>, as printed by
'ema status').

Flags:
  -q, --quiet       Suppress output
TXT;
    }

    protected function execute(array $args): int
    {
        $parsed = Args::parse('-q --quiet', '', $args);
        $quiet = $parsed->flag('--quiet', '-q', 'quiet');
        Repo::assertRoot();

        $ref = $parsed->positional[0] ?? '';
        foreach (SandboxServer::lifecycleKeys($ref) as $key) {
            if ($key !== '') {
                SandboxServer::stop($key, $quiet);
            }
        }
        return 0;
    }
}
