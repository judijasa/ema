<?php

namespace Ema\Cli\Command;

use Ema\Cli\AbstractCommand;
use Ema\Cli\Args;
use Ema\Cli\Support\Repo;
use Ema\Cli\Support\SandboxServer;

/**
 * `ema restart [<path>]` — restart sandbox instance server(s). Mirrors the
 * bash `_cmd_restart`.
 */
final class Restart extends AbstractCommand
{
    public function name(): string
    {
        return 'restart';
    }

    public function help(): string
    {
        return <<<'TXT'
Usage: ema restart [<path>] [flags]

Restart sandbox instance server(s). Sandbox instances only.

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
            if ($key === '') {
                continue;
            }
            SandboxServer::stop($key, $quiet);
            SandboxServer::start($key, $quiet);
        }
        return 0;
    }
}
