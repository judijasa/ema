<?php

namespace Ema\Cli;

/**
 * Flag/option parser mirroring the bash `_parse_flags` / `_get_flag` /
 * `_get_option` helpers, including the `EMA_*` env fallbacks. Dependency-free
 * and standalone (no autoloader in the runtime path), like sort_schemas.php.
 */
final class Args
{
    /**
     * @param list<string>        $positional Positional arguments (non-flag tokens).
     * @param list<string>        $flags      Flag tokens present on the command line.
     * @param array<string,string> $options    Option token => value.
     */
    private function __construct(
        public readonly array $positional,
        public readonly array $flags,
        public readonly array $options,
    ) {
    }

    /**
     * Parse argv against a flag spec and an option spec, both space-separated
     * token lists (e.g. "--dry-run -n -q" and "--from-snapshot").
     *
     * @param string       $flagsSpec
     * @param string       $optionsSpec
     * @param list<string> $argv
     */
    public static function parse(string $flagsSpec, string $optionsSpec, array $argv): self
    {
        $flags = self::splitSpec($flagsSpec);
        $options = self::splitSpec($optionsSpec);

        $positional = [];
        $seenFlags = [];
        $seenOptions = [];

        $args = $argv;
        while ($args !== []) {
            $arg = array_shift($args);

            if ($arg === '--') {
                // Everything after `--` is positional, even if it looks like a flag.
                foreach ($args as $rest) {
                    $positional[] = $rest;
                }
                break;
            }

            if ($arg === '' || $arg[0] !== '-') {
                $positional[] = $arg;
                continue;
            }

            if (in_array($arg, $flags, true)) {
                $seenFlags[] = $arg;
                continue;
            }

            if (in_array($arg, $options, true)) {
                if ($args === []) {
                    throw new Error("expected value for $arg");
                }
                $seenOptions[$arg] = (string) array_shift($args);
                continue;
            }

            // Bundled short flags: -abc -> -a -b -c (re-queued and re-parsed).
            if (preg_match('/^-[a-zA-Z0-9]{2,36}$/', $arg) === 1) {
                $split = [];
                $len = strlen($arg);
                for ($i = 1; $i < $len; $i++) {
                    $split[] = '-' . $arg[$i];
                }
                $args = array_merge($split, $args);
                continue;
            }

            throw new Error("unexpected arg: $arg");
        }

        return new self($positional, $seenFlags, $seenOptions);
    }

    /**
     * Mirror of `_is_help`: true when the first argument asks for help.
     *
     * @param list<string> $args
     */
    public static function isHelp(array $args): bool
    {
        return in_array($args[0] ?? '', ['help', '-h', '--help', '-H', '-help'], true);
    }

    /**
     * Mirror of `_get_flag`: "yes"/"no" over flag names and EMA_* env fallbacks.
     * A name starting with `-` checks the parsed flags; a bare name checks
     * EMA_<UPPER> with `_env_bool` semantics.
     */
    public function flag(string ...$names): bool
    {
        foreach ($names as $name) {
            if ($name !== '' && $name[0] === '-') {
                if (in_array($name, $this->flags, true)) {
                    return true;
                }
            } else {
                $env = getenv(self::envName($name));
                if ($env !== false && self::envBool($env)) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Mirror of `_get_option`: parsed value first, then EMA_* fallback, then
     * default.
     */
    public function option(string $name, string $default = ''): string
    {
        if (array_key_exists($name, $this->options)) {
            return $this->options[$name];
        }
        if ($name !== '' && $name[0] !== '-') {
            $env = getenv(self::envName($name));
            if ($env !== false && $env !== '') {
                return $env;
            }
        }
        return $default;
    }

    /**
     * @return list<string>
     */
    private static function splitSpec(string $spec): array
    {
        if (trim($spec) === '') {
            return [];
        }
        return preg_split('/\s+/', trim($spec)) ?: [];
    }

    private static function envName(string $name): string
    {
        return 'EMA_' . strtoupper(str_replace('-', '_', $name));
    }

    /**
     * Mirror of `_env_bool`: 1/yes/true/on (case-insensitive) are true.
     */
    private static function envBool(string $value): bool
    {
        return in_array(strtolower($value), ['1', 'yes', 'true', 'on'], true);
    }
}
