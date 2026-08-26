# adopt schematic CLI & lifecycle patterns — Plan & Progress

Date: 2026-08-26

Repos: ema (this repo), schematic (../schematic)

## Decision

ema (MariaDB) and schematic (PostgreSQL) are the same tool in spirit — a
"package manager for a database" — and their command surfaces overlap. This
plan compares the two for similar commands and options, and adopts
schematic's approach where it is cheap and clearly better, while leaving
ema's own model where the two differ for a reason.

Two schematic strengths are worth adopting outright: (1) its **CLI
ergonomics** — one command module per command with self-documenting
`--help`, a uniform flag/option parser with env-var fallback, and a
`-n/--no-*` "print the command instead" convention — and (2) its
**destructive-operation safety ladder** (`gc`/`gc-basedir` + `prompt_yesno` +
a temp/non-temp marker). ema today has no way to drop or stop a database at
all, and no confirmation primitive.

Two schematic strengths are deliberately **not** adopted: its declarative
diff engine (`upgrade` builds a temp reference database and diffs; destructive
changes require an explicit `revision`) and its Nix/Python implementation.
Those are schematic's core and a much larger commitment than ema's
migration-file model warrants. Adopting them would be a rewrite, not a
pattern transplant.

**Scope constraint:** ema stays a bash + PHP tool, exactly as it is today —
the `ema` bash script plus `src/sort_schemas.php` and the
`pkg/*/default.php` migration/package model. No Python enters ema at any
layer (CLI, config resolution, lifecycle, or tests); every adoption in this
plan is a *pattern* re-implemented in bash/PHP, never schematic code. Python
is named in this document only to describe schematic for comparison.
Postgres-only replication (`sync`/`oplog`) is out of scope by construction.

Reuse direction is one-way: schematic is a sibling analog, not a consumer, so
ema adopts *patterns* (never code) and never references schematic at runtime.

## Command & option comparison

| Concern | ema | schematic | Adopt? |
|---|---|---|---|
| Dispatch | single `ema` bash script, `case` | `scm_cli.py` imports `commands/<name>.py` per command | in spirit |
| Help | one top-level `show_help` | per-command docstring = `--help` | yes |
| Arg parsing | manual, only `-h`/`-v` | `parse_args` flags/options/bundled `-abc`, env fallback | yes |
| Target config | `EMA_MODE` → `reuter.local.ini`/`reuter.ini`, `[<dbname>]` section | `$SCM_PG/<name>-<guid>/meta.json` (+ `postmaster.pid`) | partial |
| Create/upgrade db | `init db` (apply `srv/<name>.sql`), `init tables` (topo-sort `upgrade.sql`) | `upgrade` (declarative build + diff), `database` (create `srv/*.nix`) | no (diff) / partial (creator) |
| Create packages | `schema <name>` → `pkg/<name>-<GUID>/{default.php,upgrade.sql}` | `schema`/`namespace`/`extension`/… → `default.nix` | partial |
| Shell | `mariadb <db>` (`exec` client) | `psql <basedir>` + auto-open / `-n` | partial |
| Lifecycle | none | `status`/`start`/`stop`/`restart`/`reload` | yes |
| Destroy | none | `gc`, `gc-basedir --force --stop`, `sandbox --kill`, `prompt_yesno` | yes |

### CLI structure & option parsing — pros/cons

- ema (bash, single file): **pros** — one auditable file, no deps beyond
  bash/php/jq, trivial nix wrap, fast. **cons** — manual arg handling, no
  per-command help, subcommands via nested `case`, `eval`-of-INI string
  splicing is fragile, hard to unit-test, grows monolithic.
- schematic (Python, module-per-command + shared `utils/cli.py`): **pros** —
  self-documenting, uniform parsing (flags/options/bundled shorts/env
  fallback), trivially extensible, testable. **cons** — heavier runtime, more
  indirection, `importlib` dispatch is less greppable.

**Recommendation:** keep the bash + PHP structure (no Python); port only the
*shape* — a small `_parse_flags` / `_get_flag` / `_get_option` helper with
`EMA_*` fallback, and one `--help` heredoc per command. This is the single
highest-value adoption.

### Config/target resolution — pros/cons

- ema (`EMA_MODE` + section-as-dbname): **pros** — tiny surface, mirrors the
  consumer `.env`/`reuter.ini` model, mode doubles as root-guard/write-gate.
  **cons** — no runtime metadata, so nothing records "is this db a disposable
  sandbox / is it running / on what port" — exactly the data schematic's safe
  `gc` relies on.
- schematic (`meta.json` + `postmaster.pid`): **pros** — self-describing
  databases enable safe `gc`/`status`/`psql`. **cons** — more moving parts,
  metadata can drift.

**Recommendation:** don't add a `meta.json` yet — ema already has a clean
temp/non-temp signal: the *file* the section resolved from
(`var/reuter.local.ini` = sandbox, prod `reuter.ini` = real). Derive
pid/socket from the known `var/mariadb/*` paths. Add `meta.json` only if
per-database granularity is ever needed (open item).

### Create/upgrade — pros/cons

- ema (migration files): **pros** — explicit, auditable, `srv/*.sql` doubles
  as the prod bootstrap. **cons** — no diff, idempotence is the author's job
  (`DROP … IF EXISTS`), destructive changes are raw SQL with no guard.
- schematic (declarative + diff): **pros** — no hand-written migrations, never
  drops columns/tables automatically, feature-complete diff. **cons** — large
  (Nix + Python + derivative build).

**Recommendation:** do **not** adopt the diff engine. Adopt only its *safety
UX*: destructive operations must be explicit and confirmed, never inferred.

## The destruction safety ladder (canonical adoption)

schematic separates "delete a disposable sandbox" from "delete a real
database" and guards both:

- `scm gc` — deletes only **stopped** sandbox (temp) databases.
- `scm gc-basedir <path>` — a non-temp database **refuses** without `--force`
  ("not a temp database … use `--force` to insist").
- even with `--force`, a non-temp db calls `prompt_yesno('remove database …?')`
  with default **No**.
- a **running** server refuses without `--stop`; `--stop` shuts it down
  (`shutdown_mode=immediate`) before `rmtree`.
- `scm sandbox --kill` destroys immediately after building (test-only);
  `scm sandbox` interactively prompts (default **Yes**) to remove the sandbox.

`prompt_yesno(prompt, default)` is the whole primitive (a `y/N`/`Y/n` line).

Mapping to ema — "prod" vs "dev" already exists as the section's *file*:

```
ema drop <db>              # sandbox section (var/reuter.local.ini):
                           #   prompt "remove sandbox database …? (Y/n)" -> drop
ema drop <db>              # prod section (reuter.ini):
                           #   refuse: "not a sandbox database … use --force"
ema drop <db> --force      # prod: prompt "remove database …? (y/N)" -> drop
ema gc                     # drop stopped sandbox dirs under var/mariadb
ema status                 # list sections/sandboxes: up/down + port + age
```

`ema drop` = `DROP DATABASE` over the resolved section (as root over the
socket in dev, root/unix_socket over `MYSQL_UNIX_PORT` in prod per the
issue-9 model); `ema stop` = kill the `var/mariadb/mysql.pid` server. The
istemp signal is the *file provenance* (local vs prod), so no new metadata is
required. Unlike schematic's `gc-basedir --stop`, `ema drop` needs no
`--stop`: `DROP DATABASE` runs against the live server (no directory removal
underneath a running process), so the flag is skipped.

## Easy-to-adapt features still missing in ema

| schematic feature | ema gap | cost to adapt |
|---|---|---|
| `prompt_yesno` (y/N, default) | no confirmation primitive | trivial |
| `drop`/`gc-basedir --force --stop` (+ prod confirm) | no drop at all | small |
| `status` (up/down + port + age) | nothing | small |
| `start`/`stop`/`restart`/`reload` | only the dev shell trap | small |
| flag/option parser + env fallback (`-f/-q/-v/-n`) | `-h`/`-v` only | medium |
| per-command `--help` | one top-level help | medium |
| `-n/--no-psql` + "print the command" | `mariadb` always `exec`s | trivial |
| `database` creator (`srv/*.sql` stub) | write `srv/*.sql` by hand | small |
| `inspect`/`dependencies-list` | `sort_schemas.php` has no CLI wrapper | small |

## Changes

### ema (this repo)

- [x] `ema` — add a `prompt_yesno` helper (`y/N` with default) for destructive ops.
- [x] `ema` — add `ema drop <db>` (`DROP DATABASE` over the resolved section)
      with the temp/non-temp + `--force` ladder; prod sections require
      `--force` and confirm (default No), sandbox drops confirm default Yes.
      No `--stop`: `DROP DATABASE` runs against the live server, so the flag
      schematic needed for `rmtree`-style deletion does not apply.
- [x] `ema` — add `ema gc` (drop stopped sandbox databases under `var/mariadb`).
- [x] `ema` — add `ema status` (list sections/sandboxes: up/down + port + age).
- [x] `ema` — add `ema start`/`ema stop`/`ema restart` wrapping `mariadb-admin`
      + `var/mariadb/mysql.pid`/`mysql.sock`.
- [x] `ema` — add a `_parse_flags`/`_get_flag`/`_get_option` helper with `EMA_*`
      env fallback (`-f/--force`, `-q/--quiet`, `-v/--verbose`, `-n/--no-*`).
- [x] `ema` — split `show_help` into per-command `--help` (heredoc per command).
- [x] `ema` — add `-n/--no-shell` to `init db`/`init tables` and print the
      `ema mariadb <db>` command instead of auto-opening.
- [x] `ema` — add `ema database <name>` to generate an `srv/<name>.sql` stub
      (placeholder keys) + a `[<dbname>]` section, mirroring schematic's `database`.
- [x] `ema` — add `ema inspect`/`ema deps <root-pkg>` as a CLI wrapper over
      `src/sort_schemas.php`.
- [x] `README.md` — document the new commands and the destruction safety model.

## Open items

- Per-database `istemp` marker vs file provenance: rely on local-vs-prod file
  provenance now; add a `meta.json`-style marker only if a single prod file
  ever needs to mix disposable and real databases.
- Prod `drop` runs as root over `MYSQL_UNIX_PORT` (issue-9 model) — confirm it
  reuses the `_apply_schema`-style root/sudo fallback, not the `init` root-guard.
  **Resolved:** `_cmd_drop` first tries `"$DBMS" -u root` and falls back to
  `sudo env PATH="$PATH" MYSQL_UNIX_PORT=... "$DBMS" -u root` — the same shape
  as `_apply_schema`; it never uses the `init` root-guard (which only refuses
  EUID 0 in dev mode for the init path).
- Explicitly not adopted (do not revisit unless scope changes): Python/Nix
  rewrite, the declarative diff engine, `revision` objects, replication
  (`sync`/`oplog`).

## Validation (2026-08-26)

Real-MariaDB e2e (nix dev shell, mariadb 11.8.8) — full lifecycle
`init-cluster → status → init db → init tables → drop → stop → gc → start →
init db` passed end-to-end. Key confirmation: after `ema gc` removes
`var/mariadb/data/<name>` with the server stopped, `ema start` comes up
cleanly and `ema init db` recreates the database (MariaDB discovers schemas
from the filesystem — no data-dictionary residue from the rmtree deletion).

One real bug surfaced by the e2e and fixed: `_dev_start_server` passed
relative `--datadir/--pid-file/--socket` to `mysqld`, which resolves them
against its own basedir (the nix store prefix) rather than the CWD, so
`ema start` after `ema stop`/`gc` aborted. The paths are now absolutized
against `$PWD` (repo root, already asserted), matching
`bin/dev/init-cluster.sh`. Mock testing could not catch this: the mock
`mysqld` ignores paths, so only the real run exposed it.
