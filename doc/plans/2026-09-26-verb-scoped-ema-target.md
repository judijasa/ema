# Verb-scoped EMA_TARGET resolution — Plan & Progress

Date: 2026-09-26
Repos: ema (this repo).

## Decision

`EMA_TARGET` is a binary sandbox/prod **mode** flag, not a connection-file
selector, and it is consulted only by verbs that address a database **by name**.
A dbname is ambiguous by construction — the same name exists on both sides,
which is exactly what lets the consumers' app layer point at a sandbox without
changing dbname or user — so name-addressed verbs must resolve through the flag.
Everything else resolves its own target:

- **one-sided verbs** never read it: `ema sandbox` (build) loads its instance's
  ini, `create` (including the replica path) / `values` always operate on prod,
  and `start`/`stop`/`restart`/`gc` operate on `var/sandbox/` by construction.
- **path-addressed verbs** do not need it either: `var/sandbox/<key>` is the
  sandbox namespace and `$EMA_PROD_BASE/<db>` (default `/var/lib/mariadb/<db>`)
  the prod one, so the side falls out of the path. `ema status` is the discovery
  surface that hands out those paths.
- **name-addressed verbs** keep it, and `mariadb` is the only one left: `status`
  now enumerates both sides, and `drop` is retired.

The end state is a single flag-driven verb, `mariadb`, whose dbname form must
keep working for parity with the consumers' app layer.

The whole set lands in **one change set**: the sections under `## Changes` are
parts of it, not separate commits (the sandbox/prod decoupling and the
`EMA_TARGET` default flip share one interleaved diff in `ema`).

The predecessor change (unset `EMA_TARGET` defaulting to prod, with its own
safety trade-off) is `doc/plans/2026-09-25-ema-target-prod-default.md`. This doc
supersedes the `gate create on EMA_TARGET=prod` item in
`doc/plans/2026-09-07-production-database-creation.md`.

## Changes

### One-sided verbs resolve their own connection file

- [x] `ema` — split resolution: `_prod_reuter_file()` (the prod file) and
      `_load_section <file> <dbname>` (parse/eval one section); `_reuter_file()`
      + `_load_target()` stay the flag-driven pair for the name-addressed verbs.
- [x] `ema` — `_sandbox_build`: load `_sandbox_ini "$key"` directly.
- [x] `ema` — `_maybe_open_shell`: open the client in-process over the loaded
      args (root socket) and print the flag-complete hint.
- [x] `ema` — `_cmd_create` / `_cmd_values`: drop the `EMA_TARGET` refusal;
      `_cmd_create_replica`: read the primary's section from the prod file.
- [x] `ema` — `_cmd_start` / `_cmd_stop` / `_cmd_restart` / `_cmd_gc`: drop the
      target guards (`_assert_sandbox_lifecycle` deleted); help says "Sandbox
      instances only."
- [x] `README.md` — scope the flag paragraph to the name-addressed verbs; drop
      the `EMA_TARGET=prod` mentions from the `create` row and the create-only
      paragraph.
- [x] `bin/dev/shell-enter.sh` — document which verb the export is still for.

### `status` reports both sides

- [x] `ema` — `_cmd_status`: drop the `_is_sandbox` branch and print two
      labelled tables (sandbox instances, prod sections).
- [x] `ema` — `_status_prod`: take the file from `_prod_reuter_file` instead of
      the flag-driven `_reuter_file`, and degrade a missing prod file to a note
      rather than `exit 1` (it would otherwise kill the combined output).
- [x] `ema` — print the instance path as the trailing column (`var/sandbox/<key>`;
      for prod `$EMA_PROD_BASE/<db>` when it exists on this host, `-` otherwise)
      so it is copy-pasteable into the lifecycle verbs.
- [x] `ema` — `_help_status`: document both tables, drop the mode split.
- [x] `README.md` — `status` row and machine-mode paragraph.

### Lifecycle verbs address instances by path

- [x] `ema` — `_resolve_instance_path()`: resolve a ref that ends in
      `var/sandbox/<key>` (relative or absolute) to its instance key, and refuse
      bare names and `srv/<key>` with a hint to copy the path from `ema status`.
      `_resolve_instance()` keeps its name-addressed role for `mariadb`.
- [x] `ema` — `_cmd_gc`: accept one `<path>` (that instance only) while no
      argument keeps the sweep; refuse a running instance by path; fix the stale
      `(sandbox mode only)` in `_help_gc`.
- [x] `ema` — `_cmd_start` / `_cmd_stop` / `_cmd_restart`: resolve the address
      before iterating, so a refused one exits non-zero (`mapfile ... < <(...)`
      swallowed the failure and exited 0).
- [x] `ema` — `_help_start` / `_help_stop` / `_help_restart` / `_help_gc` usage
      lines become `[<path>]`.
- [x] `README.md` — lifecycle examples use paths.

### `ema drop` retires

- [x] `ema` — delete `_cmd_drop` / `_help_drop`, the dispatch entry and the usage
      line, and reword the comments and error messages that pointed at it
      (`create`, `sandbox` build).
- [x] `ema` — `_load_target` / `_reuter_file` have one consumer left (`mariadb`);
      collapse the resolver comments.
- [x] `README.md` — drop the `drop` row; state that prod database deletion is a
      deliberate `DROP DATABASE` over `ema mariadb`.

## Open items

- **Prod instance deletion is undecided.** With `drop` gone nothing removes a
  prod instance (`$EMA_PROD_BASE/<db>/`, its conf and its `mariadb@<db>` unit) —
  only the database inside it, and then only by hand. Options: leave it manual,
  or let `gc` take a prod path (stop the unit + drop the database + remove the
  instance dir).
- **`mariadb` stays name-addressed** (`ema mariadb <db>` plus the flag) for
  parity with the consumers' app layer; a `var/sandbox/<key>` ref could be
  accepted as an explicit escape hatch, but the dbname form must keep working.
- **Dropping the dev shell's export:** still needs the name-addressed verbs to
  resolve by instance existence (`var/sandbox/<key>` present → sandbox) — the
  model `doc/plans/2026-08-22-reuter-redesign.md` rejected for prod safety. Not
  now; the export also serves the consumers' app layer.
- **`_target()` error arm:** it `exit 1`s inside a `$( )` subshell, so a bogus
  `EMA_TARGET` prints the error and the caller continues (pre-existing; the same
  shape was fixed in the lifecycle verbs, where it made a refusal exit 0).
- **Empty sandbox set:** `ema start`/`stop` with no instances stays silent, in
  every mode — unchanged by this work.
