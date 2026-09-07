# sandbox schema application & EMA_TARGET — Plan & Progress

Date: 2026-09-03

Repos: ema (this repo)

## Decision

ema adopts schematic's object-centric schema application: one command,
`ema sandbox`, whose single argument is a path to a database package
(`srv/<name>-<GUID>`) or a schema package (`pkg/<pkg>-<GUID>`). The database
definition — dbname, charset/collation, users, grants, and schema-package
dependencies — moves into `srv/<name>-<GUID>/default.php` as the **default**
definition: generated with default values by `ema database`, and overridable
by the connection file (endpoint + secrets). It is not a single source of
truth — dev and prod read the same default, with the connection file supplying
the machine-local overrides (notably prod secrets, which never live in
`default.php`). Today that file is dead weight (an empty `$dependencies` that
`ema init db` never reads). This removes the dev/prod divergence that made
section generation hard to reason about (dev generates the section before
filling it, prod fills a deploy-written section).

Each sandbox owns its MariaDB instance (own datadir/socket/port under `var/`).
This is not cosmetic: MariaDB users/roles are instance-level, so per-database
bootstrap SQL (`DROP/CREATE USER … @'{{servername}}'`) is only safe when the
instance contains exactly one database. Per-instance sandboxes make that
"one db per instance" rule structural instead of conventional, keep the
database's prod name (no `-sandbox` suffix on the database itself), and
isolate tests from pre-existing instance state — a shared instance can mask
topological-sort or missing-package failures because the objects already
exist there.

The destructive/non-destructive separation is preserved. The destructive
bootstrap (create instance + database + users + grants) runs only on first
build. Re-running `ema sandbox <target>` against an existing instance
reapplies the dependency graph's `upgrade.sql` in topological order without
dropping — the reapply loop `ema init tables` served today.

`EMA_MODE` is renamed `EMA_TARGET`, a **binary flag** telling ema whether the
connection is a sandbox database or a prod database (set vs unset, or two
values). It is not a connection-file selector — endpoint/file resolution is a
separate env var. The flag gates sandbox-vs-prod behavior (root-guard,
destructive-safety ladder). This preserves the pipeline-testing property:
connection configs that hardcode a dbname keep working because the sandbox
exposes the same name locally while `EMA_TARGET` marks the connection as
sandbox.

The declarative diff engine and
Nix/Python implementation remain out of scope (unchanged from
`doc/plans/2026-08-26-schematic-cli-adoption.md`). The definition stays
`default.php`: ema's runtime logic remains bash + PHP, with Nix kept to the
toolchain/distribution layer (`flake.nix`). A Nix-evaluated `default.nix` was
considered and rejected — it would put Nix in ema's core resolution path and
require Nix on every Composer consumer, for a payoff (cross-engine definition
sharing) that is prospective and metadata-only.

## Command surface

| command | behavior |
|---|---|
| `ema sandbox srv/<name>-<GUID>` | build instance + bootstrap + apply deps (topo order) + open shell; `-n` prints instead |
| `ema sandbox pkg/<pkg>-<GUID>` | synthesize a default database (zero deps, no bootstrap SQL) and apply the pkg graph |
| `ema sandbox <target>` (existing instance) | non-destructive reapply: skip bootstrap, re-run the dependency graph |
| `ema mariadb <db> < file.sql` | raw SQL via stdin redirection (already works; the client has no tty guard) |

`srv/<name>-<GUID>/default.php` becomes the **default** definition
(overridable by the connection file); its sibling `upgrade.sql` stays the
bootstrap, but `{{dbname}}`/`{{admin_password}}`/`{{servername}}` placeholders
are replaced by `default.php` default values (unless overridden) plus
instance-generated secrets, so both dev and prod go through one emit path.

## Changes

### ema (this repo)

- [x] `srv/<name>-<GUID>/default.php` — become the default definition (dbname,
      charset/collation, users, grants, schema deps), overridable by the
      connection file; generated with defaults by `ema database <name>`.
- [x] `ema` — add `ema sandbox srv/<name>-<GUID>` (build instance + bootstrap
      + apply deps + open shell; `-n/--no-shell` prints instead).
- [x] `ema` — add `ema sandbox pkg/<pkg>-<GUID>` (synthesized default database:
      zero deps, no bootstrap SQL, apply the pkg graph).
- [x] `ema` — non-destructive reapply: a second `ema sandbox <target>` skips
      bootstrap and reapplies the dependency graph; never drops.
- [x] `ema` — rename `EMA_MODE` → `EMA_TARGET` (binary sandbox/prod flag;
      connection-file resolution stays a separate env var); update resolution
      and the root-guard accordingly.
- [x] `ema` — refactor `_cmd_init_tables` (topo-sort → concat → FK guards →
      root/sudo runner) into the shared streaming primitive used by `sandbox`
      build and reapply.
- [x] `ema` — per-instance sandbox lifecycle: datadir/socket/port under
      `var/sandbox/<name>-<guid>/`; thread into `start`/`stop`/`status`/`gc`/
      `drop`.
- [x] `ema` — exit prompt `remove sandbox database? (Y/n)` default Yes;
      answering No persists the instance (the deletion unit is the instance,
      not a database inside a shared server).
- [x] `src/sort_schemas.php` — resolve `srv/<name>-<GUID>/default.php`
      dependencies (multi-provider `find_package()` lookup unchanged).
- [x] `ema` — do not add an `apply` verb; document stdin `<` redirection
      (`ema mariadb <db> < file.sql`) as the raw-SQL path.
- [x] tests (new) — sandbox build + reapply e2e; assert the second run issues
      no DROP; `EMA_TARGET` resolution; per-instance isolation (no
      instance-level user collisions).
- [x] `README.md` — document `sandbox`, `EMA_TARGET`, and the
      `srv/<name>-<GUID>/default.php` definition model.
- [x] `bin/dev/shell-enter.sh` — stop auto-starting the dev server on entry
      (drop the legacy `var/mariadb` resume/start block); bind an EXIT trap
      that runs `ema stop` so running sandbox instances stop when the shell
      exits.
- [x] `bin/dev/init-cluster.sh` — drop the `mysqld` start (data-dir init only);
      `make dev-init` no longer starts a daemon.
- [x] `Makefile` — drop the `var/mariadb` single-cluster init; `make dev-init`
      now only creates `var/log` (instances self-initialize via `ema sandbox`).
- [x] `README.md` — reflect the daemon lifecycle (start on `ema sandbox`, stop
      on shell exit) and `init-cluster.sh` as data-dir-init-only.

## Open items

- **Reapply surface naming:** whether "re-run `ema sandbox`" is the right
  non-destructive surface, or a distinct durable verb (schematic's `upgrade`)
  is needed once prod sections enter the picture. The prod durable path
  (deploy-written connection file + `srv` default definition) is not yet
  settled.
- **Target/config resolution details:** `EMA_TARGET` is a binary sandbox/prod
  flag, but its exact spelling (set/unset vs two values) and the name/value
  set of the separate connection-file selector env (which today the old
  `EMA_MODE=dev` did double duty for: `dev` → `var/reuter.local.ini`, else
  `$REUTER_INI`/`etc/reuter.ini`) are still open.
- **Sandbox instance registry:** file provenance (`var/reuter.local.ini` =
  sandbox) no longer implies one-instance-per-file once sandboxes are
  per-instance; a `meta.json`-style marker per instance may be needed for safe
  `status`/`gc` (open item carried from
  `doc/plans/2026-08-26-schematic-cli-adoption.md`).
- **Port/socket allocation:** the shared dev server used one fixed socket;
  per-instance sandboxes need per-instance socket/port and a cleanup policy
  (`gc`) for stale ones.
- **Consumer-side migration:** consumers still own whether their srv SQL is
  one-db-per-instance; this plan makes the guarantee structural in ema but
  tracks consumer changes in the consumers' own plan docs, not here.
- **Prod DCL escalation:** if a consumer ever needs to emit/run grants over a
  prod section as root, add a root/sudo stdin runner reusing the
  `_apply_schema` shape — deferred until a concrete need; not a new verb.
- **Shell-exit stop scope:** the `shell-enter.sh` EXIT trap stops *all*
  running sandbox instances, not just those started by the current session.
  Two concurrent `nix develop` shells in one repo would tear each other's
  instances down; scoping the stop to per-session instances is possible if
  that becomes a problem.
