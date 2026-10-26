<?php

namespace Ema\Cli;

/**
 * Top-level dispatch, mirroring the bash `case "$1" in ... esac`. Resolves the
 * first argument to a registered Command; handles the global help/version and
 * the unknown-command error.
 */
final class Application
{
    /** @var array<string, Command> */
    private array $commands = [];

    public function register(Command $command): self
    {
        $this->commands[$command->name()] = $command;
        return $this;
    }

    /**
     * @param list<string> $argv Arguments including the command name at index 0.
     */
    public function run(array $argv): int
    {
        if ($argv === []) {
            echo Help::main() . "\n";
            return 0;
        }

        $name = $argv[0];

        if (in_array($name, ['-h', '--help', 'help'], true)) {
            echo Help::main() . "\n";
            return 0;
        }

        if (in_array($name, ['-v', '--version'], true)) {
            echo Help::version() . "\n";
            return 0;
        }

        if (!isset($this->commands[$name])) {
            fwrite(STDERR, "Error: Unknown command or option: $name\n");
            echo Help::main() . "\n";
            return 1;
        }

        try {
            return $this->commands[$name]->run(array_slice($argv, 1));
        } catch (Error $e) {
            fwrite(STDERR, "Error: " . $e->getMessage() . "\n");
            return 1;
        }
    }
}
