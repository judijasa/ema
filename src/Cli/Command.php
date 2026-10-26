<?php

namespace Ema\Cli;

/**
 * A CLI verb. Mirrors the bash `_cmd_*` dispatch: the Application resolves the
 * first argument to a Command, then hands it the remaining arguments verbatim.
 */
interface Command
{
    /**
     * The verb name as typed on the command line (e.g. "schema").
     */
    public function name(): string;

    /**
     * Per-command help, shown by `<name> --help`.
     */
    public function help(): string;

    /**
     * Run the verb. Receives the arguments after the command name; returns the
     * process exit code.
     *
     * @param list<string> $args
     */
    public function run(array $args): int;
}
