<?php

namespace Ema\Cli\Support;

use Ema\Cli\Error;

/**
 * Repo-root and package-naming helpers mirroring the bash `_assert_repo_root`
 * and `_instance_name` helpers (plus the `ls -d <root>/<prefix>-*` collision
 * check shared by the scaffold verbs).
 */
final class Repo
{
    /**
     * Mirror of `_assert_repo_root`: ema operates from a project root, which
     * is identified by the presence of a local pkg/ directory.
     */
    public static function assertRoot(): void
    {
        if (!is_dir('pkg')) {
            throw new Error("pkg/ not found — run ema from your project root");
        }
    }

    /**
     * Mirror of `_instance_name`: strip a trailing -<GUID> (dash + 16 chars)
     * from a package/dir key, leaving the human name.
     */
    public static function instanceName(string $key): string
    {
        if (strlen($key) > 16 && $key[strlen($key) - 17] === '-') {
            return substr($key, 0, -17);
        }
        return $key;
    }

    /**
     * Mirror of `ls -d <root>/<prefix>-*`: true when a package already exists
     * under $root with the given <name>- prefix.
     */
    public static function prefixExists(string $root, string $prefix): bool
    {
        foreach (glob("$root/$prefix-*") ?: [] as $_) {
            return true;
        }
        return false;
    }
}
