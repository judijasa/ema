# production database creation — Plan & Progress

Date: 2026-09-07

Repos: ema (this repo)

## Decision

ema gets a prod-only creation verb, `ema create srv/<name>-<GUID>`, the
production counterpart to `ema sandbox`. It reads the same
`srv/<name>-<GUID>/default.php` default definition and, over the resolved prod
`[<dbname>]` section, creates the database (name, charset/collation) and applies
the schema-package dependency graph in topological order. That is the whole
surface: `ema create` provisions **schema only** — it never creates users or
grants.

User and service-account provisioning (the `admin`/`reader`/`public`
dbuser-services, plus human "team member" accounts) is consumer policy and
lives in the consumer's own repos, run as a separate operation from database
creation. ema therefore drops its user/grants machinery entirely: the `users`
array and `servername` leave `default.php`, and the `upgrade.sql` bootstrap
shrinks to database DDL. There are no passwords and no `SERVERNAME` in ema.

There is no upgrade or reapply surface: `ema create` (prod) and `ema sandbox`
(dev) are both **create-only** and both refuse when the target already exists.
To change a database you either run imperative SQL
(`ema mariadb <db> < file.sql`) or delete it (`ema drop`) and recreate. This
makes sandboxes strictly disposable — `ema sandbox`'s non-destructive re-run
(the "reapply loop" from
`doc/plans/2026-09-03-sandbox-schema-application.md`) is dropped in favor of a
warning that says "delete first". Declarative diff/upgrade assistance
(schematic-style) is a remote-future aspiration and explicitly out of scope.

The destructive/non-destructive separation is preserved but sharpened: the only
destructive SQL `ema create` emits is the database bootstrap, and only after
confirming the database is absent, so its `DROP … IF EXISTS` statements are
no-ops. Prod drops remain `ema drop --force` (the existing safety ladder).
One-way reuse from `doc/plans/2026-08-26-schematic-cli-adoption.md` is
unchanged — ema adopts the *shape* (create-or-refuse, prompt/env fallback) in
bash/PHP, never schematic code.

## Command surface

| command | behavior |
|---|---|
| `ema create srv/<name>-<GUID>` | prod-only: create db + apply deps (topo order); refuse if the db exists; `--dry-run` prints the SQL instead of running |
| `ema sandbox srv/<name>-<GUID>` (existing instance) | now refuses ("already built — `ema drop` first") instead of re-applying |
| `ema drop <db>` / `ema drop <db> --force` | unchanged: the delete-then-recreate path |

Users/grants are not part of `ema create` (or `ema sandbox`): the consumer
provisions dbuser-services and team-member accounts as a separate operation in
its own repo.

## Changes

### ema (this repo)

- [x] `ema` — add `ema create srv/<name>-<GUID>` (prod-only: create db + apply
      dep graph; refuse if db exists; `--dry-run` prints SQL).
- [x] `ema` — gate `create` on `EMA_TARGET=prod` (refuse under sandbox, hint at
      `ema sandbox`).
- [x] `ema` — add a prod "database exists" check via `information_schema.SCHEMATA`
      and refuse when present.
- [x] `ema` — `_sandbox_build`: re-run against an existing instance refuses
      (drop the non-destructive reapply branch).
- [x] `ema` — `_cmd_database`, `_build_srv_bootstrap_sql`, and
      `_build_synth_bootstrap_sql`: drop the user/grants and password/servername
      emit; the bootstrap becomes database DDL only.
- [x] `ema` — `srv/<name>-<GUID>/default.php`: drop `users` and `servername`;
      keep `dbname`/`charset`/`collation` + `$dependencies`.
- [x] `ema` — `_write_sandbox_ini`: drop `ADMIN_PASSWORD`/`READER_PASSWORD`.
- [x] `etc/reuter.ini.template` — drop `ADMIN_PASSWORD`/`READER_PASSWORD`
      (endpoint keys only: `SERVER`/`PORT`/`DBMS`/`MYSQL_UNIX_PORT`).
- [x] `README.md` — document `ema create`, the create-only/refuse-on-existing
      model, and that users/grants are consumer policy (separate operation).
- [x] tests (new) — prod create → re-run refuses → drop → recreate; `--dry-run`;
      sandbox re-run refuses; bootstrap contains no user/grants SQL.

## Open items

- **Upgrade/diff engine (deferred):** no declarative diff now; schema changes
  use imperative SQL or delete+recreate. schematic-style diff assistance is a
  remote-future plan, not this one, and not a hidden mode of `ema create`.
- **Service-user provisioning (consumer-side):** creating/granting the
  `admin`/`reader`/`public` dbuser-services — and the passwordless/TLS posture
  (`admin`/`reader` require SSL, `public` does not) — is consumer policy, run
  as a separate operation alongside the existing "team member" account flow in
  the consumer's own repo. Tracked in the consumer's plan, not here.
- **Failed-create residue:** a bootstrap that fails midway (db created, deps
  partial) leaves a db that the next `ema create` refuses; cleanup is
  `ema drop --force`. No `--recreate` flag by design.
