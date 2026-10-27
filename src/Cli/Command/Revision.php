<?php

namespace Ema\Cli\Command;

use Ema\Cli\AbstractCommand;
use Ema\Cli\Error;
use Ema\Cli\Support\Guid;
use Ema\Cli\Support\Repo;

/**
 * `ema revision pkg/<name>-<GUID>` — scaffold a one-shot revision package.
 * Mirrors the bash `_cmd_revision` + `_revision_wire`.
 */
final class Revision extends AbstractCommand
{
    public function name(): string
    {
        return 'revision';
    }

    public function help(): string
    {
        return <<<'TXT'
Usage: ema revision pkg/<name>-<GUID>

Scaffold a one-shot revision for a local schema package: create
rev/<date>-<name>-R0<GUID>/ (default.php + upgrade.sql), chain it to the
package's current revision head, and rewire the package's default.php
dependencies to point at the new head (the prior head is removed). The
package's first revision copies its upgrade.sql verbatim (creating the
tables); later revisions start with an empty upgrade.sql for the alteration.
Revisions are applied exactly once per database by 'ema upgrade
srv/<name>-<GUID>'.
TXT;
    }

    protected function execute(array $args): int
    {
        $target = $args[0] ?? '';
        if ($target === '') {
            fwrite(STDERR, "Error: must provide a schema package (e.g., ema revision pkg/<name>-<GUID>)\n");
            echo $this->help() . "\n";
            return 1;
        }

        if (!str_starts_with($target, 'pkg/')) {
            fwrite(STDERR, "Error: target must be pkg/<name>-<GUID> (got '$target')\n");
            echo $this->help() . "\n";
            return 1;
        }

        Repo::assertRoot();

        $ref = rtrim(substr($target, 4), '/');
        $pkgDir = \find_package($ref);
        if ($pkgDir === null) {
            throw new Error("schema package '$ref' not found");
        }

        $pkgRoot = realpath('pkg');
        if ($pkgRoot === false || !str_starts_with($pkgDir, $pkgRoot . '/')) {
            throw new Error("'$ref' is not a local pkg/ package — only local schemas can be revised");
        }

        $name = Repo::instanceName($ref);
        $guid = Guid::generate('R0');
        $date = date('Y-m-d');
        $revDir = "rev/$date-$name-$guid";

        if (file_exists($revDir)) {
            throw new Error("revision '$revDir' already exists");
        }

        $head = '';
        foreach (\extract_dependencies($ref) as $dep) {
            if (\find_revision_package($dep) !== null) {
                $head = $dep;
                break;
            }
        }

        mkdir($revDir, 0777, true);

        if ($head === '') {
            $default = "<?php\nreturn new \\Ema\\Config\\RevisionConfig();\n";
        } else {
            $default = "<?php\nreturn new \\Ema\\Config\\RevisionConfig(\n    dependencies: ['$head'],\n);\n";
        }
        file_put_contents("$revDir/default.php", $default);

        // First revision bootstraps from the schema package's declarative DDL
        // (verbatim copy, mirroring `scm revision`); later revisions start with
        // an empty upgrade.sql for the operator's alteration.
        if ($head === '' && is_file("$pkgDir/upgrade.sql")) {
            $sql = file_get_contents("$pkgDir/upgrade.sql");
            if ($sql === false) {
                throw new Error("cannot read $pkgDir/upgrade.sql");
            }
        } else {
            $sql = '';
        }
        if (file_put_contents("$revDir/upgrade.sql", $sql) === false) {
            throw new Error("cannot write $revDir/upgrade.sql");
        }

        $this->rewire("$pkgDir/default.php", $head, "$date-$name-$guid");

        echo "Created $revDir\n";
        return 0;
    }

    /**
     * Mirror of `_revision_wire`: point the package's dependencies at the new
     * revision head, replacing the prior head (or appending/inserting on the
     * package's first revision).
     */
    private function rewire(string $file, string $oldHead, string $newHead): void
    {
        if (!is_file($file) || !is_readable($file)) {
            throw new Error("cannot read $file");
        }
        $content = file_get_contents($file);
        if ($content === false) {
            throw new Error("cannot read $file");
        }

        $replaced = false;
        if ($oldHead !== '') {
            $count = 0;
            $content = str_replace("'$oldHead'", "'$newHead'", $content, $count);
            $replaced = $count > 0;
        } elseif (preg_match('/dependencies\s*:\s*\[(.*?)\]/s', $content, $m, PREG_OFFSET_CAPTURE) === 1) {
            $inner = rtrim($m[1][0]);
            $inner = preg_replace('/,\s*$/', '', $inner) ?? $inner;
            $inner = ($inner === '') ? "'$newHead'" : "$inner, '$newHead'";
            $content = substr_replace($content, "dependencies: [$inner]", $m[0][1], strlen($m[0][0]));
            $replaced = true;
        } elseif (preg_match('/PackageConfig\s*\(/s', $content, $m, PREG_OFFSET_CAPTURE) === 1) {
            $insertAt = $m[0][1] + strlen($m[0][0]);
            $content = substr_replace($content, "\n    dependencies: ['$newHead'],\n", $insertAt, 0);
            $replaced = true;
        }

        if (!$replaced) {
            throw new Error("could not rewire $file (unexpected default.php shape)");
        }

        if (file_put_contents($file, $content) === false) {
            throw new Error("cannot write $file");
        }
    }
}
