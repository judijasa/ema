<?php

namespace Ema\Cli\Command;

use Ema\Cli\AbstractCommand;
use Ema\Cli\Args;
use Ema\Cli\Error;
use Ema\Cli\Support\Connection;
use Ema\Cli\Support\ProdServer;
use Ema\Cli\Support\Repo;
use Ema\Cli\Support\Sql;

/**
 * `ema upgrade srv/<name>-<GUID>` — apply pending revisions to an existing
 * database, once each, in dependency order. Mirrors the bash `_cmd_upgrade` +
 * `_apply_revisions`.
 */
final class Upgrade extends AbstractCommand
{
    public function name(): string
    {
        return 'upgrade';
    }

    public function help(): string
    {
        return <<<'TXT'
Usage: ema upgrade srv/<name>-<GUID> [-q] [-v]

Apply pending revisions to an existing database, once each, in dependency
order. Discovers the revisions reachable from the target srv/ schema graph
(revision refs in package `dependencies`), topo-sorts them over each revision's
own prior-revision `dependencies`, then for every revision whose guid is not yet
recorded in ema_revision runs its upgrade.sql and inserts a tracking row.
Schema packages are NOT applied. The prod instance (and its database) must
already exist — run 'ema create srv/<name>-<GUID>' first.
TXT;
    }

    protected function execute(array $args): int
    {
        $parsed = Args::parse('-q --quiet -v --verbose', '', $args);
        $quiet = $parsed->flag('--quiet', '-q', 'quiet');
        $verbose = $parsed->flag('--verbose', '-v', 'verbose');
        $target = $parsed->positional[0] ?? '';
        if ($target === '') {
            fwrite(STDERR, "Error: must provide a target (e.g., ema upgrade srv/<name>-<GUID>)\n");
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
        $instance = Repo::instanceName($ref);
        $dbname = $defs['db']['dbname'];
        if ($dbname === '') {
            throw new Error("srv/$ref/default.php has no dbname");
        }

        $revisions = \build_revision_graph_roots($defs['dependencies'])->topological_sort();
        if (count($revisions) === 0) {
            if (!$quiet) {
                echo "No pending revisions.\n";
            }
            return 0;
        }

        // ema upgrade never provisions: the prod instance and database must exist.
        $conffile = ProdServer::confFile($instance);
        if (!is_file($conffile)) {
            throw new Error("no prod instance for '$instance' ($conffile missing) — run 'ema create srv/$ref' first");
        }
        $provisioned = ProdServer::instanceEnsure($instance, $quiet);
        $conn = Connection::forUnixSocket($provisioned['socket']);
        $conn->mariadbArgs();
        if (!$conn->databaseExists($dbname)) {
            throw new Error("database '$dbname' does not exist on instance '$instance' — run 'ema create srv/$ref' first");
        }
        Sql::applyRevisions($conn, $dbname, $revisions, $verbose);
        if (!$quiet) {
            echo "Upgrade complete for $dbname.\n";
        }
        return 0;
    }
}
