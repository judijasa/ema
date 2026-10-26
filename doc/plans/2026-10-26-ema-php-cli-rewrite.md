# ema PHP CLI rewrite (`bin/ema.php` + `src/Cli`) — Plan & Progress

Date: 2026-10-26
Repos: ema (this repo).

## Goal

Replace the Bash dispatch/runtime of the `ema` CLI with a typed PHP ≥ 8.1
implementation (`bin/ema.php` → `Ema\Cli\*`), while keeping every verb's
behavior, help text, exit codes, and error wording a faithful mirror of the
Bash functions they replace. The Bash `ema` remains the source of truth until
each phase is validated; a phase is "done" when the PHP command is wired into
`bin/ema.php`, passes `phpstan` at `level: max`, and its error/help paths match
the Bash counterpart.

The runtime stays Bash+PHP (no Nix/Python engine) — consistent with
`2026-08-26-schematic-cli-adoption.md` and
`2026-09-03-sandbox-schema-application.md`. `src/sort_schemas.php` is reused
in-process (no `jq`, no subprocess `php -r`); the Bash helpers that shell out to
`php -r` become direct calls to the in-process equivalents.

## Phase map

| Phase | Scope | Status |
|---|---|---|
| 0 | Foundation: entry point, dispatch, flag parser, help, error type, subprocess runner, GUID/repo helpers | ✅ done |
| 1 | Graph + scaffold verbs: `schema`, `database`, `inspect`, `deps`, `revision` | ✅ done |
| 2 | Sandbox + client lifecycle: `sandbox`, `start`, `stop`, `restart`, `gc`, `status`, `mdb` | ✅ done |
| 3 | Prod provisioning + revision apply: `create`, `values`, `upgrade` | ✅ done |

## Done — Phases 0–3

### Phase 0 — foundation

- `src/Cli/Error.php` — exception caught once at the `Application` boundary
  (Bash `echo >&2; exit 1`).
- `src/Cli/Command.php` (`interface`), `src/Cli/AbstractCommand.php` (shared
  `run()` + `--help` preamble), `src/Cli/Args.php` (replaces `_parse_flags`),
  `src/Cli/Help.php` (`show_help`/`show_version`), `src/Cli/Application.php`
  (replaces the `case "$1"` dispatch).
- `src/Cli/Support/Guid.php`, `src/Cli/Support/Repo.php` (repo-root assertion,
  `instanceName`, `prefixExists`), `src/Cli/Support/Process.php` (argv arrays,
  never a shell string).
- `bin/ema.php` — self-locating entry point (EMA_LIB env or `__FILE__`).
- `src/sort_schemas.stub` — PHPStan stub for the procedural
  `src/sort_schemas.php` API.

### Phase 1 — graph + scaffold verbs

- `src/Cli/Command/Schema.php` (`ema schema <name>`), `Database.php`
  (`ema database <name>`), `Inspect.php` (`ema inspect <root-pkg>`),
  `Deps.php` (`ema deps <root-pkg>`), `Revision.php`
  (`ema revision pkg/<name>-<GUID>`, including the `_revision_wire` rewire).

### Phase 2 — sandbox + client lifecycle

- Support: `src/Cli/Support/SandboxServer.php` (paths, start/stop, build
  driver, shell/remove prompt), `Connection.php` (`_load_section`,
  `_mariadb_args`, `_run_root_sql`/`_run_root_eval`, off-host connect),
  `Sql.php` (`_build_schema_sql`/`_build_srv_bootstrap_sql`/
  `_build_synth_bootstrap_sql`), `Target.php` (`EMA_TARGET`), `Prompt.php`,
  and the read-only half of `ProdServer.php`.
- Commands: `Sandbox.php`, `Start.php`, `Stop.php`, `Restart.php`, `Gc.php`,
  `Status.php`, `Mariadb.php`.

### Phase 3 — prod provisioning + revision apply

- `src/Cli/Support/ProdServer.php` — added the provisioning half (path/unit
  helpers, `freePort`/`portFree`/`serverAddr`/`confPort`/`replicaServerId`,
  `writeConf`, `unitFile`/`requireUnit`/`waitListening`/`instanceEnsure`/
  `printValues`, host-config `ssl-ca`/`ssl-crl`, and `srvDbnameByName`).
- `src/Cli/Support/Sql.php` — added `applyRevisions()` (idempotent
  `ema_revision` tracking, skip-recorded, pending `upgrade.sql`, insert row).
- `src/Cli/Support/Connection.php` — added `forUnixSocket()` socket factory.
- Commands: `Create.php` (primary + replica branches), `Values.php`,
  `Upgrade.php`.

`bin/ema.php` registers all fifteen verbs; `Help::main()` lists the full
surface.

## Phase 3 — prod provisioning + revision apply (completed spec)

Three prod-only verbs plus the provisioning half of `ProdServer` and the
revision-apply path. Prod verbs never consult `EMA_TARGET` (they are inherently
prod) and never ask `sudo` interactively — the Bash helpers fall back to
`sudo` internally, mirroring `Connection::runRootSql`.

### Command surface (Bash → PHP)

| Verb | Bash source | PHP target |
|---|---|---|
| `create srv/<name>-<GUID>` | `_cmd_create()` (`ema:1257`) | `src/Cli/Command/Create.php` |
| `create … --from-snapshot <path>` (replica) | `_cmd_create_replica()` (`ema:1373`) | `Create.php` (replica branch) |
| `values <instance>` | `_cmd_values()` (`ema:1592`) | `src/Cli/Command/Values.php` |
| `upgrade srv/<name>-<GUID>` | `_cmd_upgrade()` (`ema:2379`) + `_apply_revisions()` (`ema:2331`) | `src/Cli/Command/Upgrade.php` + `Sql::applyRevisions()` |

### `ProdServer` provisioning methods (added)

Path/unit helpers (mirror `ema:913–922`):

- `data()`, `sock()`, `pid()`, `log()`, `binlog()`, `confBase()`, `confFile()`,
  `user()`, `bind()`.

Port / identity / conf (mirror `ema:991–1101`):

- `freePort()` (`_get_free_port`), `portFree()` (`_port_free`),
  `serverAddr()` (`_prod_server_addr`), `confPort()` (`_prod_conf_port`),
  `replicaServerId()` (`_replica_server_id`), `writeConf()`
  (`_prod_write_conf` — incl. the `ssl-ca`/`ssl-crl` lines and the opt-in
  `log_bin`/`binlog_format`/`expire_logs_days` block).

Unit / lifecycle (mirror `ema:1104–1205`):

- `unitFile()`, `requireUnit()` (`_prod_require_unit`), `waitListening()`
  (`_prod_wait_listening`), `instanceEnsure()` (`_prod_instance_ensure`),
  `printValues()` (`_prod_print_values`).

Host config (mirror `ema:925–984`, `_host_conf_get` + `_EMA_SSL_CA`/
`_EMA_SSL_CRL`):

- Read `etc/ema.default.conf` then `etc/ema.conf` (flat, top-level
  `ssl-ca`/`ssl-crl` keys), overridable by `EMA_SSL_CA`/`EMA_SSL_CRL`. Shape
  check at read (must be an absolute path); existence check at `writeConf`.
  `ssl-crl` requires `ssl-ca`.

Name→schema resolution (mirror `ema:676` `_srv_dbname_by_name`):

- `srvDbnameByName()` — a bare instance name resolves to its package's
  `dbname` (or falls back to the name). Needed by `values`.

### Command details

- **`Create` (primary):** `srv_definition()` (already in `sort_schemas.php`),
  `Repo::instanceName()`, read `dbname`/`type`/`binlog`/`binlog_expire_days`/
  `replica_of`/`replica_ssl_verify_server_cert` from `$defs['db']`. Build the
  bootstrap + schema SQL up front (`Sql::buildSrvBootstrapSql`,
  `Sql::buildSchemaSql`) so `--dry-run` prints without provisioning.
  `ProdServer::instanceEnsure()` then `Connection::runRootSql` (create-only:
  refuse when `databaseExists`).
- **`Create` (replica):** the `_cmd_create_replica` path — declaration gate
  (replica `dbname` == primary package's `dbname`), `--from-snapshot`
  validation (`mariadb_backup_info`/`xtrabackup_info`), read the primary's
  `[<replica_of>]` section for `MASTER_HOST`/`MASTER_PORT`,
  `mariadb-backup --prepare`/`--copy-back`, `writeConf` with
  `read_only`/`server_id`, `CHANGE MASTER … START SLAVE`, and the
  `SHOW SLAVE STATUS` gate (wait for `Slave_IO_Running`/`Slave_SQL_Running`
  to settle past `Connecting`).
- **`Values`:** `ProdServer::confFile` + `confPort` + `srvDbnameByName` +
  `printValues` (error when no conf file, or no port).
- **`Upgrade`:** discover revisions reachable from the srv deps
  (`build_revision_graph_roots` already in `sort_schemas.php`), topo-sort,
  then `Sql::applyRevisions($dbname, $revisions)` — create `ema_revision`
  idempotently, skip recorded GUIDs, run each pending `upgrade.sql`, insert a
  tracking row. Prod instance + database must already exist (never provisions).

### New/changed files

- [x] `src/Cli/Command/Create.php` — `ema create` (primary + replica branches).
- [x] `src/Cli/Command/Values.php` — `ema values`.
- [x] `src/Cli/Command/Upgrade.php` — `ema upgrade`.
- [x] `src/Cli/Support/ProdServer.php` — add the provisioning methods above.
- [x] `src/Cli/Support/Sql.php` — add `applyRevisions()` (mirror
      `_apply_revisions`).
- [x] `src/Cli/Support/Connection.php` — added `forUnixSocket()` socket
      factory (covers the prod socket path via `ProdServer::sock()`).
- [x] `bin/ema.php` — require + register `Create`/`Values`/`Upgrade`.
- [x] `src/sort_schemas.stub` — no new procedural helper; reused
      `build_revision_graph_roots` (already stubbed).
- [x] `Help.php` — the three verbs are listed; wording verified.

### Tests

- [x] `tests/test_php_cli_phase3.sh` — consolidated coverage:
      `ema create --dry-run` (primary + replica) prints the bootstrap/schema
      SQL and provisions nothing (no `sudo`, no systemd); `ema values` reads a
      fixture conf file (temp `EMA_PROD_CONF_DIR`) and prints the section shape;
      `ema upgrade` discovery (no-revisions + no-instance error), and
      `Sql::applyRevisions` driven against a sandbox socket: ordering,
      tracking, apply-once idempotency.
- [x] Keep the existing `tests/run.sh` green (Bash regression).

## Open items / deferred

- **Replica create is not exercised in CI** — it needs `sudo` + systemd +
  `mariadb-backup` and a real primary; it is validated by review/`phpstan`
  only for now. The primary `create` and `values` paths get dry-run/fixture
  coverage above.
- **GTID resume** for replicas is a future refinement (file/pos only today) —
  already tracked in `2026-09-11-read-replica-provisioning.md`.
- **Cut-over:** after Phase 3, decide whether `bin/ema.php` becomes the
  shipped `vendor/bin/ema` shim (composer `bin` currently points at the Bash
  `ema`). Not part of Phase 3.
