# ema

Package manager for MariaDB.

## Connectivity and machine mode

`ema` resolves a database to its `[<dbname>]` section (the section header is
the database name). `EMA_TARGET` is a **binary flag** telling ema whether the
connection is a sandbox database or a prod database — it is *not* a
connection-file selector:

- `EMA_TARGET` unset or `sandbox` (default): the connection is a per-instance
  sandbox, one `var/sandbox/<name>-<guid>/reuter.ini` per database.
- `EMA_TARGET=prod`: the connection file is `REUTER_INI` (fallback
  `etc/reuter.ini`; copy `etc/reuter.ini.template` to create it).

The flag gates sandbox-vs-prod behavior: the CLI client user (`root` for
sandboxes, `DBUSER`/`$USER` for prod), the destructive-safety ladder, and the
server lifecycle commands. Prod file resolution stays a separate env var
(`REUTER_INI`), so `EMA_TARGET` never selects a file. Prod machines run
`EMA_TARGET=prod` by default because the deployed `.env` carries it; ema never
reads `.env` itself, so the running shell needs it in scope
(`set -a; . .env`).

Each sandbox owns its MariaDB instance (its own datadir/socket/pid/port under
`var/sandbox/<name>-<guid>/`). This makes the "one db per instance" rule
structural: MariaDB users/roles are instance-level, so the per-database
bootstrap (`DROP/CREATE USER … @'{{servername}}'`) is only safe when the
instance holds exactly one database.

Connections use the selected section's `MYSQL_UNIX_PORT` (local socket) when
the section defines it, otherwise `SERVER`/`PORT` (TCP). The section is
authoritative: an exported `MYSQL_UNIX_PORT` in the shell does not override a
section that lacks the key. On the prod DB host, each `[<dbname>]` section
carries `MYSQL_UNIX_PORT` so `ema mariadb <name>` (run as root over the
socket) works, while the app layer keeps reading `.env` and stays on TCP.

The prod `[<dbname>]` sections are supplied by the host's deployment tooling;
`ema` needs no extra config to reach them (the transport, e.g. ZeroTier, is
just whatever `SERVER` resolves to).

### The `srv/<name>-<GUID>/default.php` definition

A database package's `default.php` carries the **default** definition — the
dbname, charset/collation, `servername`, and the `admin`/`reader` users with
their grants — plus its schema-package `$dependencies`. The connection file
overrides the machine-local parts (endpoint + secrets); `default.php` holds
the shape and non-secret defaults so dev and prod share one emit path.
Passwords are instance-generated and never live in `default.php`.

```php
$db = array(
    'dbname' => 'test',
    'charset' => 'utf8',
    'collation' => 'utf8_spanish_ci',
    'servername' => 'localhost',
    'users' => array(
        'admin'  => 'SELECT, INSERT, UPDATE, DELETE',
        'reader' => 'SELECT',
    ),
);
$dependencies = array('demo-1F2E3D4C5B6A7980');
```

The sibling `upgrade.sql` is the bootstrap (create database + users + grants),
with `{{dbname}}`/`{{charset}}`/`{{collation}}`/`{{servername}}`/
`{{admin_password}}`/`{{reader_password}}` placeholders filled from
`default.php` defaults + instance-generated secrets.

## Command reference

Shell and database lifecycle (each sandbox owns its instance under `var/sandbox/`):

| Command | Purpose |
|---|---|
| `ema sandbox srv/<name>-<GUID>` | Build/reapply a per-instance sandbox for a database package |
| `ema sandbox pkg/<pkg>-<GUID>` | Build/reapply a sandbox for a schema package (synthesized db) |
| `ema mariadb <db> [args...]` | Open a MariaDB shell against a database's section |
| `ema start` / `ema stop` / `ema restart` | Start/stop/restart sandbox instance(s) (sandbox only) |
| `ema status` | List instances/sections: up/down, endpoint, age |
| `ema drop <db>` | Delete a database (sandbox: whole instance; prod: DROP DATABASE) |
| `ema gc` | Remove stopped sandbox instances under `var/sandbox/` (sandbox only) |

Schema packages:

| Command | Purpose |
|---|---|
| `ema schema <name>` | Create a new schema package in `pkg/` |
| `ema database <name>` | Create an `srv/<name>-<GUID>/` package (default definition) |
| `ema inspect <root-pkg>` | Show the schema dependency graph (wrapper over `src/sort_schemas.php`) |
| `ema deps <root-pkg>` | List schema packages in topological order |

Every command supports `--help`. Flags share one parser with `EMA_*` env
fallbacks: `-f/--force` (`EMA_FORCE`), `-q/--quiet` (`EMA_QUIET`),
`-v/--verbose` (`EMA_VERBOSE`), `-n/--no-shell` (`EMA_NO_SHELL`); short
flags bundle (`-nq`).

## Lifecycle & destruction safety

`ema sandbox` builds (or reapplies) a sandbox and then opens a shell
(`ema mariadb <db>`); pass `-n/--no-shell` to print the command instead.
Non-terminal stdin (pipes, CI) always prints the command, so scripts never
block. Raw SQL over a built sandbox uses stdin redirection —
`ema mariadb <db> < file.sql` — there is no dedicated `apply` verb.

The destructive bootstrap (create instance + database + users + grants) runs
only on the **first build**. Re-running `ema sandbox <target>` against an
existing instance is non-destructive: bootstrap is skipped and the dependency
graph's `upgrade.sql` is reapplied in topological order — never `DROP`.

Destructive operations follow a safety ladder:

- `ema drop <db>` on a sandbox prompts "remove sandbox database …? (Y/n)" —
  default **Yes**. The deletion unit is the whole instance
  (`var/sandbox/<name>-<guid>/`), not a database inside a shared server.
- `ema drop <db>` on a prod target refuses: "not a sandbox database … use
  `--force`".
- `ema drop <db> --force` on prod prompts "remove database …? (y/N)" —
  default **No**, then runs `DROP DATABASE` as root over the resolved
  socket/TCP (root/sudo fallback).
- `ema gc` removes **stopped** sandbox instances (`ema stop` first) under
  `var/sandbox/`; running instances are skipped.
- `ema start` / `stop` / `restart` manage sandbox instance servers only; prod
  servers are managed by the host's deployment tooling.

## Standalone template usage

This repo is dual-role. It is a **tool consumed** by other projects —
consumers `require judijasa/ema` via Composer, which provides both the
`ema` CLI and `init-cluster.sh` (the isolated MariaDB dev-init) at
`vendor/bin/` — and it is also a **standalone, forkable template** that behaves like
its own consumer: the `ema sandbox` workflow runs from inside this repo
exactly as it would in a consumer, with only the data being this repo's own
(`pkg/`, `srv/`, `var/sandbox/`, `etc/reuter.ini`).

The one structural difference vs. an external consumer: ema does not consume
itself through Composer — its Makefile and shell invoke the local copies
(the in-tree `ema` script and `src/`); `bin/dev/init-cluster.sh` is also
shipped (via Composer `bin`) for consumers' own data-dir init. **ema owns the mechanism
(the CLI and the MariaDB dev-init); the consumer (or this repo, standalone)
owns the data and policy.**

### Standalone dev init

1. Clone, enter the dev shell, and build the `test` database sandbox (nix
   provides the toolchain; `ema sandbox` initializes its own per-instance
   MariaDB, then applies the database bootstrap + schema dependencies — no
   composer, no `.env`, no machines.ini, no git-hooks):

   ```bash
   git clone <repo> ema
   cd ema
   nix develop
   ema sandbox srv/test-D01B9X95PUIWQ60Q -n
   ```

2. Or build a schema-package sandbox (a synthesized default database + the
   package's dependency graph):

   ```bash
   ema sandbox pkg/demo-1F2E3D4C5B6A7980 -n
   ```

Inspect the result interactively:

```bash
ema mariadb test
SHOW TABLES;
```

`ema sandbox` is deliberately simpler than a consumer's: it needs no
`composer install`, no `.env` (it reads the per-instance
`var/sandbox/<name>-<guid>/reuter.ini` or `$REUTER_INI` / `etc/reuter.ini`,
never `.env`), no machines.ini, and no git-hooks. The only generic piece
consumers reuse is the isolated MariaDB cluster init (`init-cluster.sh`).

## Reuse contract (for consumers)

ema is a plain Composer package (`judijasa/ema`). A consumer adds a VCS
`repositories` entry for this repo and `require`s it (directly, or
transitively through an intermediate package):

```json
{
    "repositories": [
        { "type": "vcs", "url": "<ema git url>" }
    ],
    "require": {
        "judijasa/ema": "dev-main"
    }
}
```

Composer installs two reusable artifacts at `vendor/bin/`:

| Artifact | Purpose |
|---|---|
| `ema` | the MariaDB package-manager CLI |
| `init-cluster.sh` | the isolated MariaDB data-dir init (`mariadb-install-db` only — no daemon; `ema sandbox` starts instances), fully parameterized (data-dir/pid-file/socket) |

Consumers call `init-cluster.sh` from their own `make dev-init` with their
own paths, instead of keeping a duplicate copy. Everything else (`.env`
generation, `composer install` order, git-hooks) is consumer policy and
stays in the consumer.

### Schema package lookup (multi-provider)

`ema inspect` / `ema deps` / `ema sandbox` resolve a schema package
(`<name>-<GUID>`) across, in order:

1. the consumer's local `pkg/` (read + write — `ema schema` writes only here);
2. ema's own `pkg/`;
3. every installed Composer package that ships a `pkg/` subdirectory
   (discovered via `vendor/composer/installed.php`).

GUIDs make a match across roots unambiguous, so a consumer can depend on
schema packages shipped by *any* installed provider — not just ema — and ema
can arrive transitively.

### One-way direction (ema is the terminal provider)

ema's `pkg/` packages serve as **dependencies of schema packages shipped by
other repos**. Every cross-repo dependency edge points *into* ema: ema never
depends on another repo's `pkg/`, and its `composer.json` carries no
`require`/`require-dev`/`repositories` entries for sibling repos — it only
ships `pkg/`. This is structural, not asserted: ema requires nothing
external, so a consumer can depend on ema without ema depending back.

Consumers declare `judijasa/ema` directly and reference its packages by
`<name>-<GUID>`; the multi-provider lookup above resolves them from the
installed package's `pkg/` without ema knowing any specific provider's name.
