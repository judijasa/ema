<?php

namespace Ema\Cli\Command;

use Ema\Cli\AbstractCommand;
use Ema\Cli\Error;
use Ema\Cli\Support\Repo;

/**
 * `ema deps <root-pkg>` — topological order of schema packages under <root-pkg>,
 * one per line. Mirrors the bash `_cmd_deps`.
 */
final class Deps extends AbstractCommand
{
    public function name(): string
    {
        return 'deps';
    }

    public function help(): string
    {
        return <<<'TXT'
Usage: ema deps <root-pkg>

Print the schema packages under <root-pkg> in topological order, one per line
(the apply order used by 'ema sandbox pkg/<root-pkg>').
TXT;
    }

    protected function execute(array $args): int
    {
        $rootPkg = $args[0] ?? '';
        if ($rootPkg === '') {
            fwrite(STDERR, "Error: must provide a root package (e.g., ema deps mypkg-HASH)\n");
            echo $this->help() . "\n";
            return 1;
        }

        Repo::assertRoot();

        if (\find_package($rootPkg) === null) {
            throw new Error("schema package '$rootPkg' not found");
        }

        $graph = \build_dependency_graph($rootPkg);
        echo implode(PHP_EOL, $graph->topological_sort()) . PHP_EOL;

        return 0;
    }
}
