<?php

namespace Ema\Cli\Command;

use Ema\Cli\AbstractCommand;
use Ema\Cli\Error;
use Ema\Cli\Support\Guid;
use Ema\Cli\Support\Repo;

/**
 * `ema schema <name>` — create pkg/<name>-<GUID>/ with default.php + upgrade.sql.
 * Mirrors the bash `_cmd_schema`.
 */
final class Schema extends AbstractCommand
{
    public function name(): string
    {
        return 'schema';
    }

    public function help(): string
    {
        return <<<'TXT'
Usage: ema schema <name>

Create a new schema package pkg/<name>-<GUID>/ with default.php and upgrade.sql.
TXT;
    }

    protected function execute(array $args): int
    {
        $prefix = $args[0] ?? '';
        if ($prefix === '') {
            fwrite(STDERR, "Error: must provide a name for the schema\n");
            echo $this->help() . "\n";
            return 1;
        }

        Repo::assertRoot();

        if (Repo::prefixExists('pkg', $prefix)) {
            throw new Error("Schema with prefix '$prefix' already exists in pkg/.");
        }

        $dir = $prefix . '-' . Guid::generate('S0');
        mkdir("pkg/$dir", 0777, true);
        touch("pkg/$dir/upgrade.sql");
        file_put_contents("pkg/$dir/default.php", "<?php\nreturn new \\Ema\\Config\\PackageConfig();\n");

        echo "Directory created: pkg/$dir\n";
        return 0;
    }
}
