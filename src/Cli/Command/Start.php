<?php

namespace Ema\Cli\Command;

use Ema\Cli\AbstractCommand;
use Ema\Cli\Args;
use Ema\Cli\Support\Repo;
use Ema\Cli\Support\SandboxServer;

/**
 * `ema start [<path>]` — start sandbox instance server(s). Mirrors the bash
 * `_cmd_start`.
 */
final class Start extends AbstractCommand
{
    public function name(): string
    {
        return 'start';
    }

    public function help(): string
    {
        return <<<'TXT'
Usage: ema start [<path>] [flags]

Start sandbox instance server(s) (--skip-networking). With no path, all
instances under var/sandbox/ are started. Sandbox instances only.

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
                SandboxServer::start($key, $quiet);
            }
        }
        return 0;
    }
}
