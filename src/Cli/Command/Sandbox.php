<?php

namespace Ema\Cli\Command;

use Ema\Cli\AbstractCommand;
use Ema\Cli\Args;
use Ema\Cli\Error;
use Ema\Cli\Support\Repo;
use Ema\Cli\Support\SandboxServer;

/**
 * `ema sandbox <target>` — build a disposable per-instance sandbox. Mirrors
 * the bash `_cmd_sandbox` + `_sandbox_srv` + `_sandbox_pkg` + `_sandbox_build`.
 */
final class Sandbox extends AbstractCommand
{
    public function name(): string
    {
        return 'sandbox';
    }

    public function help(): string
    {
        return <<<'TXT'
Usage: ema sandbox <target> [flags]

Build a disposable per-instance sandbox for a database or schema package.
Each sandbox owns its MariaDB instance (datadir/socket/pid under
var/sandbox/<name>-<guid>/).

Targets:
  srv/<name>-<GUID>   Create the instance + database (bootstrap from the srv
                      package's upgrade.sql) + apply the schema-package deps
                      in topological order.
  pkg/<pkg>-<GUID>    Synthesize a default database (zero deps) and apply the
                      pkg's dependency graph.

A type=replica srv package is refused — a replica has no schema of its own
(its schema arrives from the primary's snapshot).

Create-only: re-running against an existing instance refuses ("already built —
delete it to rebuild"). Schema only — users/grants are consumer policy and are
never created by ema.

When the interactive shell exits, ema asks whether to remove the instance
(default: remove). Answering yes stops the server and deletes
var/sandbox/<key>; answering no keeps it; with -n/--no-shell the shell is not
opened, so no question is asked.

Flags:
  -n, --no-shell      Print 'ema mdb <db>' instead of opening the shell
  -q, --quiet         Suppress non-error output
TXT;
    }

    protected function execute(array $args): int
    {
        $parsed = Args::parse('-n --no-shell -q --quiet -v --verbose', '', $args);
        $target = $parsed->positional[0] ?? '';
        if ($target === '') {
            fwrite(STDERR, "Error: must provide a target (e.g., ema sandbox srv/test-D01B9X95PUIWQ60Q)\n");
            echo $this->help() . "\n";
            return 1;
        }

        Repo::assertRoot();

        if (str_starts_with($target, 'srv/')) {
            return $this->sandboxSrv($target, $parsed);
        }
        if (str_starts_with($target, 'pkg/')) {
            return $this->sandboxPkg($target, $parsed);
        }

        fwrite(STDERR, "Error: target must be srv/<name>-<GUID> or pkg/<pkg>-<GUID> (got '$target')\n");
        echo $this->help() . "\n";
        return 1;
    }

    private function sandboxSrv(string $target, Args $args): int
    {
        $ref = substr($target, 4);
        $defs = \srv_definition($ref);
        $db = $defs['db'];
        $type = $db['type'];
        if ($type === 'replica') {
            throw new Error("srv/$ref is type=replica — a replica has no schema of its own (its schema arrives from the primary's snapshot); sandbox a primary package instead");
        }
        $dbname = $db['dbname'];
        if ($dbname === '') {
            throw new Error("srv/$ref/default.php has no dbname (return an \\Ema\\Config\\DatabaseConfig with dbname set)");
        }
        SandboxServer::build(
            SandboxServer::instanceKey($target),
            $dbname,
            $defs['dir'],
            $defs['dependencies'],
            $args,
        );
        return 0;
    }

    private function sandboxPkg(string $target, Args $args): int
    {
        $key = SandboxServer::instanceKey($target);
        $dbname = Repo::instanceName($key);
        SandboxServer::build($key, $dbname, '', [$key], $args);
        return 0;
    }
}
