<?php

namespace Ema\Cli\Command;

use Ema\Cli\AbstractCommand;
use Ema\Cli\Args;
use Ema\Cli\Error;
use Ema\Cli\Support\Repo;
use Ema\Cli\Support\SandboxServer;

/**
 * `ema gc [<path>]` — remove stopped sandbox instances. Mirrors the bash
 * `_cmd_gc`.
 */
final class Gc extends AbstractCommand
{
    public function name(): string
    {
        return 'gc';
    }

    public function help(): string
    {
        return <<<'TXT'
Usage: ema gc [<path>] [flags]

Remove stopped sandbox instances under var/sandbox/ (sandbox instances only).

With a path (var/sandbox/<name>-<guid>, as printed by 'ema status') only that
instance is considered; with no argument every instance is. Each one whose
server is not running is removed in whole (datadir/socket/pid/reuter.ini);
running instances are left alone.

Flags:
  -q, --quiet       Suppress output
TXT;
    }

    protected function execute(array $args): int
    {
        $parsed = Args::parse('-q --quiet -v --verbose', '', $args);
        $quiet = $parsed->flag('--quiet', '-q', 'quiet');
        $verbose = $parsed->flag('--verbose', '-v', 'verbose');
        Repo::assertRoot();

        $ref = $parsed->positional[0] ?? '';
        if ($ref !== '') {
            $one = SandboxServer::resolveInstancePath($ref);
            if (SandboxServer::isUp($one)) {
                throw new Error("var/sandbox/$one is running — 'ema stop var/sandbox/$one' first.");
            }
            SandboxServer::removeTree(SandboxServer::dir($one));
            if (!$quiet) {
                echo "removed\n";
            }
            return 0;
        }

        if (!is_dir('var/sandbox')) {
            if (!$quiet) {
                echo "Nothing to gc.\n";
            }
            return 0;
        }

        $found = false;
        foreach (SandboxServer::listKeys() as $key) {
            if (SandboxServer::isUp($key)) {
                if ($verbose) {
                    fwrite(STDERR, "skipping running instance '$key'\n");
                }
                continue;
            }
            $found = true;
            SandboxServer::removeTree(SandboxServer::dir($key));
            if (!$quiet) {
                echo "removed\n";
            }
        }
        if (!$found && !$quiet) {
            echo "Nothing to gc.\n";
        }
        return 0;
    }
}
