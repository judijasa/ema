<?php

namespace Ema\Cli;

/**
 * Top-level help text and version string, reproduced verbatim from the bash
 * `show_help` / `show_version` functions. A nowdoc keeps `$USER` literal.
 */
final class Help
{
    public const PROGRAM_NAME = 'ema';
    public const VERSION = '1.0.0';

    /**
     * Top-level help. Does not include a trailing newline (callers append one).
     */
    public static function main(): string
    {
        return <<<'TXT'
Usage: ema <command> [arguments]

Database lifecycle & shell:
  create srv/<name>-<GUID>    Provision the DB instance + create a prod database
  values <db>                 Print a prod database's instance connectivity (recovery)
  sandbox srv/<name>-<GUID>   Build a disposable per-instance sandbox (create-only)
  sandbox pkg/<pkg>-<GUID>    Build a disposable sandbox for a schema package
  mdb <db> [args...]          Open a MariaDB shell against a database's section
  start [<path>]              Start sandbox instance(s) (var/sandbox)
  stop [<path>]               Stop sandbox instance(s)
  restart [<path>]            Restart sandbox instance(s)
  status                      List local sandbox + prod instances with state
  gc [<path>]                 Remove stopped sandbox instances under var/sandbox

Schema packages:
  schema <name>               Create a new schema package in pkg/
  database <name>             Create srv/<name>-<GUID>/ package (default definition)
  inspect <root-pkg>          Show the schema dependency graph
  deps <root-pkg>             List schema packages in topological order
  revision pkg/<name>-<GUID>  Scaffold a new revision package in rev/
  upgrade srv/<name>-<GUID>   Apply pending revisions to an existing database

Options:
  -h, --help             Show this help message and exit
  -v, --version          Show program version

Run 'ema <command> --help' for per-command help.

Environment:
  EMA_TARGET             sandbox or prod (default) — connection flag for the
                         dbname-addressed verb (mdb)
  REUTER_INI             Prod reuter.ini path (used when EMA_TARGET=prod)
  DBUSER                 CLI client user for a prod TCP connection (default: $USER);
                         required when the instance is not on this host
  DBPASS                 Password for DBUSER, forwarded to the client as
                         MYSQL_PWD; unset leaves the password to the client's
                         own sources (MYSQL_PWD, ~/.my.cnf)
  EMA_PROD_BASE          Prod instance base dir (default: /var/lib/mariadb)
  EMA_PROD_USER          System user the prod instance runs as (default: mysql)
  EMA_PROD_BIND          Prod instance bind address (default: 0.0.0.0)
  EMA_SERVER             SERVER value emitted for prod sections (default: host overlay IP)
  EMA_SSL_CA             CA the instance verifies client certificates against,
                         overriding etc/ema.conf (ssl-ca)
  EMA_SSL_CRL            CRL the instance checks client certificates against,
                         overriding etc/ema.conf (ssl-crl)
  EMA_FORCE/EMA_QUIET/EMA_VERBOSE/EMA_NO_SHELL
                         Env fallbacks for the -f/-q/-v/-n flags
TXT;
    }

    /**
     * Version string, matching `show_version` ("ema version 1.0.0").
     */
    public static function version(): string
    {
        return self::PROGRAM_NAME . ' version ' . self::VERSION;
    }
}
