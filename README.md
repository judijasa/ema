# ema

Package manager for MariaDB.

## Connectivity and machine mode

`ema` reads its connection file by machine mode, and resolves a database to
its `[<dbname>]` section (the section header is the database name):
- `EMA_MODE=dev` (default) reads `var/reuter.local.ini` — the dev
  sandbox, generated and updated by `ema init db <name>`.
- any other `EMA_MODE` (e.g. `prod`) reads `REUTER_INI` (fallback
  `etc/reuter.ini`; copy `etc/reuter.ini.template` to create it).

`EMA_MODE` is also the mode switch for `ema init db` / `ema init tables`,
not just a file selector. `dev` mode creates/updates `var/reuter.local.ini`
and refuses root. Any other value (`prod`) never writes a connection file:
the `[<dbname>]` section must already exist (on prod, gen-reuter writes it
at deploy) and a missing section is a loud error. Prod machines run prod
mode by default only because the deployed `.env` carries `EMA_MODE=prod`;
ema never reads `.env` itself, so the running shell needs it in scope
(`set -a; . .env`) — without it the `dev` fallback misfires on a prod
server.

Connections use the selected section's `MYSQL_UNIX_PORT` (local socket) when
the section defines it, otherwise `SERVER`/`PORT` (TCP). The section is
authoritative: an exported `MYSQL_UNIX_PORT` in the shell does not override a
section that lacks the key. On the prod DB host, each `[<dbname>]` section
carries `MYSQL_UNIX_PORT` so `ema init db <name>` (run as root over the
socket) works, while the app layer keeps reading `.env` and stays on TCP.
`DBUSER` (env) is the client user for prod targets.

The prod `[<dbname>]` sections are supplied by the host's deployment tooling;
`ema` needs no extra config to reach them (the transport, e.g. ZeroTier, is
just whatever `SERVER` resolves to).

## Command reference

Shell and database lifecycle (the dev sandbox server lives in `var/mariadb`):

| Command | Purpose |
|---|---|
| `ema mariadb <db> [args...]` | Open a MariaDB shell against a database's section |
| `ema start` / `ema stop` / `ema restart` | Start/stop/restart the dev sandbox server (dev only) |
| `ema status` | List connection sections: up/down, endpoint, age, provenance file |
| `ema drop <db>` | Drop a database (DROP DATABASE over the resolved section) |
| `ema gc` | Remove stopped sandbox databases under `var/mariadb` (dev only) |

Schema packages:

| Command | Purpose |
|---|---|
| `ema schema <name>` | Create a new schema package in `pkg/` |
| `ema database <name>` | Create an `srv/<name>.sql` stub + `[<name>]` section |
| `ema init db <name>` | Create database, users, and grants (reads `srv/<name>.sql`) |
| `ema init tables <root-pkg> <db>` | Apply all schema packages in topological order |
| `ema inspect <root-pkg>` | Show the schema dependency graph (wrapper over `src/sort_schemas.php`) |
| `ema deps <root-pkg>` | List schema packages in topological order |

Every command supports `--help`. Flags share one parser with `EMA_*` env
fallbacks: `-f/--force` (`EMA_FORCE`), `-q/--quiet` (`EMA_QUIET`),
`-v/--verbose` (`EMA_VERBOSE`), `-n/--no-shell` (`EMA_NO_SHELL`); short
flags bundle (`-nq`).

## Lifecycle & destruction safety

`ema init db` / `ema init tables` open a shell (`ema mariadb <db>`) when
finished; pass `-n/--no-shell` to print the command instead. Non-terminal
stdin (pipes, CI) always prints the command, so scripts never block.

Destructive operations follow a safety ladder. The temp/non-temp signal is
the section's provenance file — no extra metadata:

- `ema drop <db>` on a sandbox section (`var/reuter.local.ini`) prompts
  "remove sandbox database …? (Y/n)" — default **Yes**.
- `ema drop <db>` on a prod section (`reuter.ini`) refuses: "not a sandbox
  database … use `--force`".
- `ema drop <db> --force` on a prod section prompts "remove database …?
  (y/N)" — default **No**.
- `ema drop` runs `DROP DATABASE` as root over the resolved socket/TCP
  (root/sudo fallback, same as `init db`), so a running server is fine.
- `ema gc` requires a **stopped** dev server (`ema stop` first) and removes
  the `var/mariadb/data/<name>` directories of sandbox sections.
- `ema start` / `stop` / `restart` manage the dev sandbox server only; prod
  servers are managed by the host's deployment tooling.

## Standalone template usage

This repo is dual-role. It is a **tool consumed** by other projects —
consumers `require judijasa/ema` via Composer, which provides both the
`ema` CLI and `init-cluster.sh` (the isolated MariaDB dev-init) at
`vendor/bin/` — and it is also a **standalone, forkable template** that behaves like
its own consumer: `make dev-init` and the `ema init db` / `ema init tables`
workflows run from inside this repo exactly as they would in a consumer,
with only the data being this repo's own (`pkg/`, `srv/`,
`var/reuter.local.ini`, `etc/reuter.ini`).

The one structural difference vs. an external consumer: ema does not consume
itself through Composer — its Makefile and shell invoke the local copies
(the in-tree `ema` script, `bin/dev/init-cluster.sh`, `src/`), and those same
files are the artifacts Composer installs. **ema owns the mechanism
(the CLI and the MariaDB dev-init); the consumer (or this repo, standalone)
owns the data and policy.**

### Standalone dev init

1. Clone, enter the dev shell, and build the sandbox (asserts nix, creates
   `var/`, and initializes + starts an isolated MariaDB — no composer, no
   `.env`, no machines.ini, no git-hooks):

   ```bash
   git clone <repo> ema
   cd ema
   nix develop
   make dev-init
   ```

2. Create the dev connection file and the `test` database (both git-ignored,
   generated by `ema init db`):

   ```bash
   ema init db test -n
   ```

3. Apply the schema packages in dependency order:

   ```bash
   ema init tables demo-1F2E3D4C5B6A7980 test -n
   ```

Inspect the result interactively:

```bash
ema mariadb test
SHOW TABLES;
```

`make dev-init` here is deliberately simpler than a consumer's: it needs no
`composer install`, no `.env` (it reads `var/reuter.local.ini` or
`$REUTER_INI` / `etc/reuter.ini`, never `.env`), no machines.ini, and no
git-hooks. The only generic step is the isolated MariaDB cluster init — and
that step (`init-cluster.sh`) is the single piece consumers reuse.

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
| `init-cluster.sh` | the isolated MariaDB dev-init (`mariadb-install-db` + start `mysqld`), fully parameterized (data-dir/pid-file/socket) |

Consumers call `init-cluster.sh` from their own `make dev-init` with their
own paths, instead of keeping a duplicate copy. Everything else (`.env`
generation, `composer install` order, git-hooks) is consumer policy and
stays in the consumer.

### Schema package lookup (multi-provider)

`ema inspect` / `ema deps` / `ema init tables` resolve a schema package
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
