<?php

namespace Ema\Cli\Support;

/**
 * GUID generation, mirroring the bash `_gen_guid`: a type prefix (e.g. "S0",
 * "D0", "R0") plus 14 random A-Z0-9 characters (16 total). Uses random_int()
 * (CSPRNG) instead of /dev/urandom + tr.
 */
final class Guid
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';

    public static function generate(string $prefix): string
    {
        $suffix = '';
        $max = strlen(self::ALPHABET) - 1;
        for ($i = 0; $i < 14; $i++) {
            $suffix .= self::ALPHABET[random_int(0, $max)];
        }
        return $prefix . $suffix;
    }
}
