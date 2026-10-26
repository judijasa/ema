<?php

namespace Ema\Cli\Command;

use Ema\Cli\AbstractCommand;
use Ema\Cli\Error;
use Ema\Cli\Support\Repo;

/**
 * `ema inspect <root-pkg>` — print the dependency graph + topological ordering.
 * Mirrors the bash `_cmd_inspect`, which ran `php sort_schemas.php <root-pkg>`
 * as a separate process; here the same output is produced in-process.
 */
final class Inspect extends AbstractCommand
{
    public function name(): string
    {
        return 'inspect';
    }

    public function help(): string
    {
        return <<<'TXT'
Usage: ema inspect <root-pkg>

Inspect a schema package: print its dependency graph and topological ordering
(CLI wrapper over src/sort_schemas.php).
TXT;
    }

    protected function execute(array $args): int
    {
        $rootPkg = $args[0] ?? '';
        if ($rootPkg === '') {
            fwrite(STDERR, "Error: must provide a root package (e.g., ema inspect mypkg-HASH)\n");
            echo $this->help() . "\n";
            return 1;
        }

        Repo::assertRoot();

        if (\find_package($rootPkg) === null) {
            throw new Error("schema package '$rootPkg' not found");
        }

        $graph = \build_dependency_graph($rootPkg);

        echo "Dependency Graph:\n";
        foreach ($graph->adjacency_list as $node => $deps) {
            echo "$node -> " . implode(", ", $deps) . "\n";
        }

        echo "\nTopological Ordering:\n";
        echo implode(", ", $graph->topological_sort()) . "\n";

        return 0;
    }
}
