# ema

Package manager for MariaDB.

## Connectivity and machine mode

`ema` resolves a database to its `[<dbname>]` section (the section header is
the database name). `EMA_TARGET` is a **binary flag** telling ema whether the
connection is a sandbox database or a prod database — it is *not* a
connection-file selector:

- `EMA_TARGET=sandbox`: the connection is a per-instance sandbox, one
  `var/sandbox/<name>-<guid>/reuter.ini` per database.
- `EMA_TARGET` unset or `prod` (default): the connection file is `REUTER_INI`
  (fallback `etc/reuter.ini`; copy `etc/reuter.ini.template` to create it).

The flag decides where the one **dbname-addressed** verb looks: `ema mariadb
<db>` takes nothing but a database name, so it picks between the per-instance
sandbox ini and the prod file — along with the CLI client user (`root` for
sandboxes, `DBUSER`/`$USER` for prod). Nothing else consults it. Verbs that are
inherently one side resolve their own target: `ema sandbox` (build) loads its
instance's ini, `ema create` / `ema values` always operate on the prod instance,
and `ema start` / `stop` / `restart` / `gc` operate on `var/sandbox/` by
construction. Verbs that address an instance **by path** need no flag either:
`var/sandbox/<name>-<guid>/` is the sandbox namespace and `$EMA_PROD_BASE/<db>`
the prod one, so the side falls out of the path — `ema status` prints both sides
and is where those paths come from. Unset/empty means prod, matching the app
layer's convention, so dev machines set `EMA_TARGET=sandbox` explicitly — the
dev shell no longer exports it (sandbox is opt-in). ema never reads `.env`
itself, so the running shell needs the value in scope (`set -a; . .env`).

Each sandbox owns its MariaDB instance (its own datadir/socket/pid/port under
`var/sandbox/<name>-<guid>/`), which keeps disposable databases isolated —
recreating a sandbox can never collide with pre-existing instance state. ema
provisions **schema only**: its bootstrap emits database DDL (create the
database with its charset/collation) and applies schema-package dependencies;
it never creates users or grants. User and service-account provisioning is
consumer policy and runs as a separate operation in the consumer's own repo.
Sandboxes are create-only: `ema sandbox <target>` refuses when the instance
already exists (`ema gc var/sandbox/<name>-<guid>` first, then rebuild).

Connections use the selected section's `MYSQL_UNIX_PORT` (local socket) when
the section defines it, otherwise `SERVER`/`PORT` (TCP). The section is
authoritative: an exported `MYSQL_UNIX_PORT` in the shell does not override a
section that lacks the key. On the prod DB host, each `[<dbname>]` section
carries `MYSQL_UNIX_PORT` so `ema mariadb <name>` (run as root over the
socket) works, while the app layer keeps reading `.env` and stays on TCP.

`ema mariadb <db>` follows its section to the instance: when the prod instance
exists on this host (`$EMA_PROD_BASE/<db>/`, the path `ema status` prints) the
section's socket is used as root; when it does not, the section's
`SERVER`/`PORT` is used over TCP as `DBUSER` (required off-host), with `DBPASS`
forwarded to the client as `MYSQL_PWD` when set. Without `DBPASS` the password
is the client's own business: `MYSQL_PWD` and `~/.my.cnf` are honoured. ema
itself never prompts, never reaches an off-host instance as root and opens no
SSH path, so a section whose instance lives elsewhere — e.g. a primary's
`SERVER`/`PORT`-only section recorded on the replica host — is reachable from any
host that holds it. See `doc/system/mariadb-remote.md`.

Prod databases get their own MariaDB instance, named after the database:
`ema create srv/<name>-<GUID>` provisions it (datadir/socket, an auto-picked
TCP port, started under the host's `mariadb@<db>` systemd unit) and then
creates the database and applies its schema. On success it emits the `[<dbname>]`
connectivity section (`SERVER`/`PORT`/`MYSQL_UNIX_PORT`) for the
operator to record in the consumer's manual reuter.ini; `ema values <db>`
re-prints those values (recovery). The transport (e.g. ZeroTier) is whatever
`SERVER` resolves to.

### The `srv/<name>-<GUID>/default.php` definition

A database package's `default.php` **returns a typed config object** — the
**default** definition: dbname, charset/collation and the schema-package
`dependencies`. The type is `Ema\Config\DatabaseConfig` (`src/Config/`): the
constructor signature is the field/default list, and the cross-field
constraints (replica keys, binlog retention) are asserted in it, so a
mis-shaped definition fails at load time instead of being read as an empty
default. Types are also checked statically by PHPStan (`composer install`,
then `vendor/bin/phpstan analyse` — also wired into the pre-commit hook). Dev
and prod share one emit path: `ema sandbox srv/<name>-<GUID>` (dev) and
`ema create srv/<name>-<GUID>` (prod) both read the same default; the
connection file (`REUTER_INI` / `etc/reuter.ini`, or the per-instance
`var/sandbox/.../reuter.ini`) supplies the machine-local endpoint. There are
no secrets in ema: `default.php` holds no passwords, and the connection file
carries endpoint keys only.

```php
return new \Ema\Config\DatabaseConfig(
    dbname: 'test',
    dependencies: ['demo-1F2E3D4C5B6A7980'],
);
```

The sibling `upgrade.sql` is the database bootstrap, with
`{{dbname}}`/`{{charset}}`/`{{collation}}` placeholders filled from
`default.php` defaults. Schema only — users/grants are consumer policy and
never appear in a database package.

### Read replicas

A database package may instead declare `type: 'replica'` with
`replica_of: <primary>`. `ema create srv/<name>-<GUID> --from-snapshot
<path>` then restores the primary's shipped snapshot and attaches the replica
over a low-priv `replication` account — no schema apply, `read_only=1`, and
`replicate-rewrite-db=<primary>-><replica>`. The manual bootstrap that precedes
it is documented in `doc/system/replica-bootstrap.md`.

A replica package may opt into verifying the primary's server certificate with
`replica_ssl_verify_server_cert: true`. The key is replica-only (a
primary package never sets it) and defaults off: ema emits
`MASTER_SSL_VERIFY_SERVER_CERT=0` unless it is set, because the primary's
certificate is the self-signed one MariaDB generates until a consumer
provisions a CA, and verifying a self-signed certificate fails the IO thread.
Only the server-identity check is toggled — the replication channel stays
TLS-encrypted either way.

A primary package opts into binary logging with `$db['binlog'] = true`: `ema
create` then writes `log_bin` + `binlog_format=ROW` + `expire_logs_days` to the
instance `my.cnf` — a replication source (or a point-in-time-recovery/CDC
source) with no post-build edit. Binlog retention is ema-owned and sized per
package via `$db['binlog_expire_days']` (default 7 days). Without `binlog` the
primary stays binlog-free, and a replica build needs the one-time hand edit
first (see `doc/system/replica-bootstrap.md`).

## Command reference

Shell and database lifecycle (each sandbox owns its instance under `var/sandbox/`):

| Command | Purpose |
|---|---|
| `ema sandbox srv/<name>-<GUID>` | Build a disposable per-instance sandbox for a database package (dev) |
| `ema sandbox pkg/<pkg>-<GUID>` | Build a disposable sandbox for a schema package (synthesized db) |
| `ema create srv/<name>-<GUID>` | Provision the per-database instance + create a prod database from a package (no users/grants) |
| `ema values <db>` | Print a prod database's instance connectivity section (recovery) |
| `ema mariadb <db> [args...]` | Open a MariaDB shell against a database's section (on the instance's host: its socket; off-host: TCP as `DBUSER`) |
| `ema start` / `ema stop` / `ema restart` | Start/stop/restart sandbox instance(s), addressed by `var/sandbox/<name>-<GUID>` path (sandbox instances only) |
| `ema status` | List sandbox instances and prod sections: up/down, endpoint, age, path |
| `ema gc [<path>]` | Remove stopped sandbox instance(s) under `var/sandbox/` (sandbox instances only) |

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
flags bundle (`-nq`). Flags are declared per verb, so `-n` is the no-shell
flag where a verb opens a shell (`ema sandbox`) and the `--dry-run` alias
where a verb provisions (`ema create`).

## Lifecycle & destruction safety

`ema sandbox` builds a disposable sandbox and then opens a MariaDB shell on
it; pass `-n/--no-shell` to print `EMA_TARGET=sandbox ema mariadb <db>`
instead.
Non-terminal stdin (pipes, CI) always prints the command, so scripts never
block. Raw SQL over a built sandbox uses stdin redirection —
`ema mariadb <db> < file.sql` — there is no dedicated `apply` verb.

Both creation verbs are **create-only**: the destructive bootstrap (create
instance + database) runs once, and re-running against an existing target
refuses — `ema gc var/sandbox/<name>-<guid>` first, then recreate.
`ema sandbox <target>` refuses when the sandbox instance already exists.
`ema create srv/<name>-<GUID>` is the prod counterpart (it never consults
`EMA_TARGET`): it refuses when the database already exists (checked via
`information_schema.SCHEMATA`), and provisions the database's own instance
(datadir/socket, auto-picked port, started under the host's `mariadb@<db>`
unit) before creating the database and applying its schema.
On success it prints the `[<dbname>]` section values to record in reuter.ini
(see `ema values <db>`). `ema create -n`/`--dry-run` prints the SQL that would
run (bootstrap + dependency graph in topological order) without provisioning
or running it. There is no upgrade/reapply surface by design: change a
database with imperative SQL or delete + recreate.

Destructive operations are deliberately narrow — ema destroys sandbox
instances and nothing else:

- `ema gc` removes **stopped** sandbox instances (`ema stop` first) under
  `var/sandbox/`; running instances are skipped. With a path
  (`ema gc var/sandbox/<name>-<guid>`) only that instance is considered, and a
  running one is refused.
- `ema start` / `stop` / `restart` manage sandbox instance servers only, and
  address one instance by its `var/sandbox/<name>-<guid>` path. Prod instances
  are provisioned by `ema create` and run under systemd (`mariadb@<db>`); there
  are no prod lifecycle verbs.
- Deleting a prod database is a deliberate SQL action — there is no `ema drop`.
  Run `DROP DATABASE` over the section's socket/TCP (`ema mariadb <db>` as root,
  or any client). ema never removes a prod instance (`$EMA_PROD_BASE/<db>/`, its
  `my.cnf`, its `mariadb@<db>` unit).

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
EMA_TARGET=sandbox ema mariadb test
SHOW TABLES;
```

`ema sandbox` is deliberately simpler than a consumer's: it needs no
`composer install`, no `.env` (it reads the per-instance
`var/sandbox/<name>-<guid>/reuter.ini` or `$REUTER_INI` / `etc/reuter.ini`,
never `.env`), no machines.ini, and no git-hooks. The reusable pieces are the
`ema` CLI (which initializes and runs each sandbox) and, optionally, the raw
data-dir init `init-cluster.sh`.

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

`ema sandbox` initializes each sandbox's own datadir inline, so the normal
path is just `ema sandbox <target>`; `init-cluster.sh` remains shipped as
the raw data-dir primitive behind that step. Everything else (`.env`
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
