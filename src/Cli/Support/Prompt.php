<?php

namespace Ema\Cli\Support;

/**
 * The schematic `prompt_yesno` primitive: read a y/N or Y/n line from STDIN;
 * an empty or EOF answer falls back to the default.
 */
final class Prompt
{
    public static function yesNo(string $prompt, string $default = 'no'): bool
    {
        $yesDefault = in_array(strtolower($default), ['yes', 'y'], true);
        fwrite(STDOUT, $prompt . ($yesDefault ? ' (Y/n) ' : ' (y/N) '));

        $answer = fgets(STDIN);
        if ($answer === false) {
            return $yesDefault;
        }

        $answer = strtolower(trim($answer));
        if ($answer === 'y' || $answer === 'yes') {
            return true;
        }
        if ($answer === 'n' || $answer === 'no') {
            return false;
        }
        return $yesDefault;
    }
}
