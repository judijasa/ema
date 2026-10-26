<?php

namespace Ema\Cli\Command;

use Ema\Cli\AbstractCommand;
use Ema\Cli\Error;
use Ema\Cli\Support\Connection;
use Ema\Cli\Support\Process;
use Ema\Cli\Support\ProdServer;
use Ema\Cli\Support\Repo;
use Ema\Cli\Support\SandboxServer;
use Ema\Cli\Support\Target;

/**
 * `ema mdb <db> [args...]` — open a MariaDB shell against a database's section.
 * Mirrors the bash `_cmd_mariadb` + `_offhost_connect`.
 */
final class Mariadb extends AbstractCommand
{
    public function name(): string
    {
        return 'mdb';
    }

    public function help(): string
    {
        return <<<'TXT'
Usage: ema mdb <db> [args...]

Open a MariaDB shell against a database's section. Extra args are passed
through to the client (e.g. ema mdb test -e "SHOW TABLES;"). Raw SQL
from a file uses stdin redirection: ema mdb <db> < file.sql

With EMA_TARGET=sandbox, an interactive shell asks whether to remove the
instance when it exits (default: remove). Scripted invocations (extra args or
redirected stdin) never prompt.

The section is reached wherever its instance lives: on the instance's own host
the section's socket is used as root (unix_socket auth); anywhere else the
section's SERVER/PORT is used over TCP as DBUSER, which is required off-host.
Off-host the password comes from DBPASS (forwarded to the client as MYSQL_PWD),
else from the client's own sources (MYSQL_PWD, ~/.my.cnf); ema never asks for one
itself, so a passwordless account connects and any other must be fed one.
See doc/system/mariadb-remote.md.
TXT;
    }

    protected function execute(array $args): int
    {
        Repo::assertRoot();

        $clientArgs = $args;
        $dbname = '';
        if ($clientArgs !== [] && !str_starts_with($clientArgs[0], '-')) {
            $dbname = $clientArgs[0];
            $clientArgs = array_slice($clientArgs, 1);
        }

        $sandboxKey = '';
        if (Target::isSandbox()) {
            $key = SandboxServer::resolveInstance($dbname);
            if ($key === null || $key === '') {
                throw new Error("no sandbox instance for '$dbname' (run 'ema sandbox srv/<name>-<GUID>' first)");
            }
            $sandboxKey = $key;
            $conn = Connection::loadSection(SandboxServer::ini($key), $dbname);
        } else {
            $conn = Connection::loadSection(ProdServer::reuterFile(), $dbname);
        }

        if ($sandboxKey !== '') {
            $conn->mariadbArgs();
            $conn->setUser('root');
        } elseif (ProdServer::instanceDirExists($dbname)) {
            $conn->mariadbArgs();
            $conn->setUser(self::dbUser());
            $conn->forwardDbpass();
            $conn->forwardSslVerify();
        } else {
            $conn->offhostConnect();
            $conn->forwardDbpass();
            $conn->forwardSslVerify();
        }

        // An interactive sandbox shell runs as a child so ema survives the
        // client's exit and asks whether to remove the instance.
        if ($sandboxKey !== '' && $clientArgs === [] && stream_isatty(STDIN)) {
            return SandboxServer::runSandboxShell($sandboxKey, self::envBool('EMA_QUIET'), $conn, [$conn->dbname]);
        }

        $cmd = array_merge(
            [Connection::dbms(), '-u', $conn->user()],
            $conn->clientArgs(),
            [$conn->dbname],
            $clientArgs,
        );
        return Process::passthru($cmd);
    }

    private static function dbUser(): string
    {
        $user = getenv('DBUSER');
        if ($user !== false && $user !== '') {
            return $user;
        }
        $user = getenv('USER');
        return $user === false ? '' : $user;
    }

    private static function envBool(string $name): bool
    {
        $value = getenv($name);
        return $value !== false && in_array(strtolower($value), ['1', 'yes', 'true', 'on'], true);
    }
}
