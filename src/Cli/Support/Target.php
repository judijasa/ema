<?php

namespace Ema\Cli\Support;

use Ema\Cli\Error;

/**
 * EMA_TARGET resolution, mirroring the bash `_target` / `_is_sandbox`: a binary
 * sandbox/prod flag. Unset/empty means prod (the app layer's convention);
 * sandbox is explicit; anything else is an error.
 */
final class Target
{
    public static function resolve(): string
    {
        $env = getenv('EMA_TARGET');
        if ($env === false || $env === '' || $env === 'prod') {
            return 'prod';
        }
        if ($env === 'sandbox') {
            return 'sandbox';
        }
        throw new Error("EMA_TARGET must be 'sandbox' or 'prod' (got '$env')");
    }

    public static function isSandbox(): bool
    {
        return self::resolve() === 'sandbox';
    }
}
