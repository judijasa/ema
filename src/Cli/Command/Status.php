<?php

namespace Ema\Cli\Command;

use Ema\Cli\AbstractCommand;
use Ema\Cli\Args;
use Ema\Cli\Support\ProdServer;
use Ema\Cli\Support\Repo;
use Ema\Cli\Support\SandboxServer;

/**
 * `ema status` — list local sandbox + prod instances with state. Mirrors the
 * bash `_cmd_status` + `_status_table` + `_status_sandbox` + `_status_prod`.
 */
final class Status extends AbstractCommand
{
    public function name(): string
    {
        return 'status';
    }

    public function help(): string
    {
        return <<<'TXT'
Usage: ema status [flags]

List the local instances with state, from on-disk evidence (never a
connection config):

  sandbox instances: each var/sandbox/<name>-<guid>/ instance — its server
                     up/down (pid file), the age of its reuter.ini, and the
                     instance path
  prod instances:    each $EMA_PROD_BASE/<name>/ instance provisioned on this
                     host — its server up/down (the mariadb@<name> systemd
                     unit) and the instance path

The trailing path is the address the sandbox lifecycle verbs
(start/stop/restart/gc) take.

Flags:
  -v, --verbose     Verbose output to stderr
TXT;
    }

    protected function execute(array $args): int
    {
        // -q/-v are accepted (for parity with _parse_flags) but unused: the
        // bash status verb parses them and then ignores them.
        Args::parse('-q --quiet -v --verbose', '', $args);
        Repo::assertRoot();

        echo "Sandbox instances:\n";
        $this->statusSandbox();
        echo "\nProd instances:\n";
        $this->statusProd();
        return 0;
    }

    private function statusSandbox(): void
    {
        if (!is_dir('var/sandbox')) {
            echo "None\n";
            return;
        }
        $keys = SandboxServer::listKeys();
        if ($keys === []) {
            echo "None\n";
            return;
        }

        $rows = [];
        foreach ($keys as $key) {
            $state = SandboxServer::isUp($key) ? 'up' : 'dn';
            $rows[] = [$state, SandboxServer::age(SandboxServer::ini($key)), SandboxServer::dir($key)];
        }
        $this->statusTable($rows);
    }

    private function statusProd(): void
    {
        $names = ProdServer::instanceNames();
        if ($names === []) {
            echo "None\n";
            return;
        }

        $rows = [];
        foreach ($names as $name) {
            $state = ProdServer::isActive($name) ? 'up' : 'dn';
            $rows[] = [$state, '-', ProdServer::base() . "/$name"];
        }
        $this->statusTable($rows);
    }

    /**
     * @param list<array{string, string, string}> $rows
     */
    private function statusTable(array $rows): void
    {
        printf("%-2s  %-6s  %s\n", 'ST', 'AGE', 'PATH');
        foreach ($rows as [$state, $age, $path]) {
            printf("%-2s  %-6s  %s\n", $state, $age, $path);
        }
    }
}
