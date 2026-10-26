<?php

namespace Ema\Cli\Command;

use Ema\Cli\AbstractCommand;
use Ema\Cli\Args;
use Ema\Cli\Error;
use Ema\Cli\Support\ProdServer;
use Ema\Cli\Support\Repo;

/**
 * `ema values <instance>` — print a prod instance's connectivity section for
 * recording in the consumer's manual reuter.ini. Mirrors the bash `_cmd_values`.
 */
final class Values extends AbstractCommand
{
    public function name(): string
    {
        return 'values';
    }

    public function help(): string
    {
        return <<<'TXT'
Usage: ema values <instance>

Print the [<instance>] connectivity section (SERVER/PORT/MYSQL_UNIX_PORT/DBNAME)
of an existing prod instance, for recording in the consumer's manual reuter.ini.
Recovery helper: the same section `ema create` emits on success.

Prod-only. Errors when no instance exists for <instance>.
TXT;
    }

    protected function execute(array $args): int
    {
        $parsed = Args::parse('-q --quiet', '', $args);
        $instance = $parsed->positional[0] ?? '';
        if ($instance === '') {
            fwrite(STDERR, "Error: must provide an instance name (e.g., ema values mydb)\n");
            echo $this->help() . "\n";
            return 1;
        }

        Repo::assertRoot();

        $conffile = ProdServer::confFile($instance);
        if (!is_file($conffile)) {
            throw new Error("no instance for '$instance' ($conffile not found)");
        }
        $port = ProdServer::confPort($conffile);
        if ($port === '') {
            throw new Error("no port in $conffile");
        }
        $dbname = ProdServer::srvDbnameByName($instance);
        ProdServer::printValues($instance, $dbname, $port, ProdServer::sock($instance));
        return 0;
    }
}
