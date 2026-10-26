<?php

namespace Ema\Cli\Command;

use Ema\Cli\AbstractCommand;
use Ema\Cli\Error;
use Ema\Cli\Support\Guid;
use Ema\Cli\Support\Repo;

/**
 * `ema database <name>` — create srv/<name>-<GUID>/ with default.php + upgrade.sql.
 * Mirrors the bash `_cmd_database`.
 */
final class Database extends AbstractCommand
{
    public function name(): string
    {
        return 'database';
    }

    public function help(): string
    {
        return <<<'TXT'
Usage: ema database <name>

Create a database package srv/<name>-<GUID>/ (default.php + upgrade.sql).
default.php carries the default definition (dbname, charset/collation);
upgrade.sql is the database bootstrap with {{dbname}}/{{charset}}/{{collation}}
placeholders filled from default.php by 'ema sandbox srv/<name>-<GUID>' (dev)
and 'ema create srv/<name>-<GUID>' (prod). Schema only — users/grants are
consumer policy and never part of a database package.
TXT;
    }

    protected function execute(array $args): int
    {
        $name = $args[0] ?? '';
        if ($name === '') {
            fwrite(STDERR, "Error: must provide a database name (e.g., ema database mydb)\n");
            echo $this->help() . "\n";
            return 1;
        }

        Repo::assertRoot();

        if (Repo::prefixExists('srv', $name)) {
            throw new Error("Database with prefix '$name' already exists in srv/.");
        }

        $dir = $name . '-' . Guid::generate('D0');
        mkdir("srv/$dir", 0777, true);

        $sql = <<<SQL
-- ema database bootstrap for $name, consumed by
-- `ema sandbox srv/$dir` (dev) and
-- `ema create srv/$dir` (prod). Placeholders are filled from
-- default.php defaults: {{dbname}}, {{charset}}, {{collation}}.
SET check_constraint_checks = OFF;
DROP DATABASE IF EXISTS {{dbname}};
CREATE OR REPLACE DATABASE {{dbname}}
COMMENT 'ema $name database'
CHARACTER SET = '{{charset}}'
COLLATE = '{{collation}}';
SQL;
        file_put_contents("srv/$dir/upgrade.sql", $sql . "\n");

        $default = <<<PHP
<?php
// Default database definition for $name. Non-secret defaults shared by dev
// and prod (the connection file carries the endpoint). ema creates schema
// only — users/grants are consumer policy and never live here.
return new \Ema\Config\DatabaseConfig(
    dbname: '$name',
);
PHP;
        file_put_contents("srv/$dir/default.php", $default . "\n");

        echo "Created srv/$dir\n";
        return 0;
    }
}
