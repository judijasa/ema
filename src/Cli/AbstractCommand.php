<?php

namespace Ema\Cli;

/**
 * Shared verb skeleton: every command handles its own `--help` the same way
 * (print the verb's help text and succeed), so this centralizes that preamble
 * and leaves each verb to implement only `execute()`.
 */
abstract class AbstractCommand implements Command
{
    /**
     * @param list<string> $args
     */
    final public function run(array $args): int
    {
        if (Args::isHelp($args)) {
            echo $this->help() . "\n";
            return 0;
        }
        return $this->execute($args);
    }

    /**
     * Run the verb. `args[0]` is the verb's first argument (the command name
     * has already been consumed by Application).
     *
     * @param list<string> $args
     */
    abstract protected function execute(array $args): int;
}
