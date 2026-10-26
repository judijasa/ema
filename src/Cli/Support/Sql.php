<?php

namespace Ema\Cli\Support;

use Ema\Cli\Error;
use Ema\Config\DatabaseConfig;

/**
 * SQL builders, mirroring the bash `_build_schema_sql` / `_build_srv_bootstrap_sql`
 * / `_build_synth_bootstrap_sql` helpers. Each writes the generated SQL to
 * <outfile>; schema/package lookup uses sort_schemas.php in-process (no jq).
 */
final class Sql
{
    /**
     * Topo-sort the package graph rooted at $rootPkgs, concatenate each
     * package's upgrade.sql, wrap it in FK guards, and fill {{dbname}}.
     *
     * @param list<string> $rootPkgs
     */
    public static function buildSchemaSql(string $dbname, string $outfile, array $rootPkgs): void
    {
        $graph = \build_dependency_graph_roots($rootPkgs);
        $schemas = $graph->topological_sort();

        $sql = '';
        foreach ($schemas as $schema) {
            $dir = \find_package($schema);
            if ($dir === null) {
                throw new Error("schema package '$schema' not found");
            }
            if (is_file("$dir/upgrade.sql")) {
                $content = file_get_contents("$dir/upgrade.sql");
                if ($content !== false) {
                    $sql .= $content . "\n";
                }
            }
        }

        $out = "SET FOREIGN_KEY_CHECKS=0;\n"
            . str_replace('{{dbname}}', $dbname, $sql)
            . "SET FOREIGN_KEY_CHECKS=1;\n";
        self::write($outfile, $out);
    }

    /**
     * Read srv/<name>-<GUID>/upgrade.sql and fill {{dbname}}/{{charset}}/
     * {{collation}} from default.php defaults.
     */
    public static function buildSrvBootstrapSql(string $srvDir, string $outfile): void
    {
        try {
            $config = require "$srvDir/default.php";
        } catch (\Throwable $e) {
            throw new Error("$srvDir/default.php: " . $e->getMessage());
        }
        if (!$config instanceof DatabaseConfig) {
            throw new Error("$srvDir/default.php must return an \\Ema\\Config\\DatabaseConfig");
        }
        $template = file_get_contents("$srvDir/upgrade.sql");
        if ($template === false) {
            throw new Error("cannot read $srvDir/upgrade.sql");
        }
        $out = str_replace(
            ['{{dbname}}', '{{charset}}', '{{collation}}'],
            [$config->dbname, $config->charset, $config->collation],
            $template,
        );
        self::write($outfile, $out);
    }

    /**
     * Synthesize a default bootstrap for a pkg/ sandbox (no srv package): a
     * default database with the given charset/collation. Database DDL only.
     */
    public static function buildSynthBootstrapSql(
        string $dbname,
        string $charset,
        string $collation,
        string $outfile,
    ): void {
        $out = "SET check_constraint_checks = OFF;\n"
            . "DROP DATABASE IF EXISTS $dbname;\n"
            . "CREATE OR REPLACE DATABASE $dbname\n"
            . "COMMENT 'ema $dbname database'\n"
            . "CHARACTER SET = '$charset'\n"
            . "COLLATE = '$collation';\n";
        self::write($outfile, $out);
    }

    /**
     * Apply already-topo-sorted revisions to $dbname, once each, tracked in
     * ema_revision. Mirrors the bash `_apply_revisions`: create the tracking
     * table idempotently, skip already-recorded GUIDs, run each pending
     * revision's upgrade.sql, then insert a tracking row. Prints "Applied <rev>"
     * per newly applied revision; skipped revisions are silent unless $verbose.
     *
     * @param list<string> $revisions
     */
    public static function applyRevisions(Connection $conn, string $dbname, array $revisions, bool $verbose): void
    {
        $tracking = tempnam(sys_get_temp_dir(), 'ema_upgrade_');
        if ($tracking === false) {
            throw new Error('cannot create a temporary file');
        }
        self::write(
            $tracking,
            "CREATE TABLE IF NOT EXISTS ema_revision (\n"
            . "    guid       VARCHAR(16)  NOT NULL,\n"
            . "    name       VARCHAR(255) NOT NULL,\n"
            . "    applied_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,\n"
            . "    PRIMARY KEY (guid)\n"
            . ");\n",
        );
        $conn->runRootSql($dbname, $tracking);
        @unlink($tracking);

        $applied = $conn->runRootEval($dbname, 'SELECT guid FROM ema_revision;');
        $appliedGuids = [];
        foreach (preg_split('/\r?\n/', $applied) ?: [] as $guid) {
            if ($guid !== '') {
                $appliedGuids[$guid] = true;
            }
        }

        foreach ($revisions as $rev) {
            if ($rev === '') {
                continue;
            }
            $guid = substr($rev, -16);
            if (isset($appliedGuids[$guid])) {
                if ($verbose) {
                    fwrite(STDERR, "skip $rev (already applied)\n");
                }
                continue;
            }
            $dir = \find_revision_package($rev);
            if ($dir === null) {
                throw new Error("revision '$rev' not found under rev/");
            }
            if ($verbose) {
                fwrite(STDERR, "apply $rev\n");
            }
            $conn->runRootSql($dbname, "$dir/upgrade.sql");
            $conn->runRootEval($dbname, "INSERT INTO ema_revision (guid, name) VALUES ('$guid', '$rev');");
            echo "Applied $rev\n";
        }
    }

    private static function write(string $outfile, string $content): void
    {
        if (file_put_contents($outfile, $content) === false) {
            throw new Error("cannot write $outfile");
        }
    }
}
