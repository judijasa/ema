# read-only replica provisioning — Plan & Progress

Date: 2026-09-11

Repos: ema (this repo)

## Decision

ema gains a read-only **replica** database package type. A package's
`default.php` may declare `$db['type']='replica'` with
`$db['replica_of']=<primary>`; `ema create srv/<name>-<GUID>` then takes a
dedicated local-only build path that restores the primary's shipped snapshot
and attaches the replica over a low-privilege `replication` account — no
schema apply, because the replica's schema arrives via replication. This is
the production counterpart to the schema-apply path a `type=primary` (or
default) package takes.

The build is **local-only**: it runs on the replica host and never reaches the
primary as root. Its only primary interaction is reading the primary's
`[<primary>]` section for the replication endpoint; the replica daemon then
authenticates over the `replication` account (created beforehand by the manual
bootstrap, which ema documents but does not perform). Because the replica's
provisioning preconditions are external, the build **fails loudly** on three
gates rather than attempting a partial attach.

The manual bootstrap (binlog enablement, `replication` account creation, and a
consistent `mariabackup` snapshot) is documented in
`doc/system/replica-bootstrap.md`; there is no automated helper — the operator
runs every step by hand before `ema create`.

## Replica mechanism

`ema create srv/<name>-<GUID> --from-snapshot <path>`:

1. **Gate 1 (snapshot absent)** — `--from-snapshot <path>` is a required,
   explicit input; missing or not-a-directory aborts before provisioning
   anything.
2. **Gate 2 (snapshot not replication-ready)** — the snapshot must be a
   `mariabackup` backup recording a non-empty binlog file/position (the
   primary has `log_bin`). A missing info file or an empty coordinate aborts
   at restore. MariaDB records no `server_uuid`, so a wrong-source snapshot is
   not detectable here; it fails at gate 3.
3. Provision the instance exactly like a primary — datadir, socket, systemd
   unit, my.cnf — but write `read_only=1`,
   `replicate-rewrite-db = <primary>-><replica>`, and a distinct `server_id`
   derived from the replica name. Restore the snapshot into the empty datadir
   (no `mariadb-install-db`: the snapshot carries the system tables).
4. `CHANGE MASTER` + `START SLAVE` over the low-priv `replication` account,
   resuming from the snapshot's file/position.
5. **Gate 3 (attach failed)** — verify `Slave_IO_Running = Yes`; otherwise
   abort. This catches a missing/mis-pinned `replication` account, an
   unreachable primary, or a wrong-source snapshot.

`ema create` and `ema values` keep emitting the usual `[<dbname>]` reuter.ini
section on success; a replica package with `dbname => 'db1'` therefore emits
`[db1]`.

## Changes

### ema (this repo)

- [x] `ema` — `_cmd_create` detects `type=replica` (`$db['type']='replica'` +
      `$db['replica_of']=<primary>`) and routes to `_cmd_create_replica`.
- [x] `ema` — `_cmd_create_replica`: local-only replica build — require
      `--from-snapshot <path>`, provision with `read_only=1` +
      `replicate-rewrite-db=<primary>-><replica>` + distinct `server_id`,
      restore the snapshot (no schema apply), `CHANGE MASTER` + `START SLAVE`.
- [x] `ema` — three loud-failure gates: snapshot absent, snapshot with no
      binlog coordinate, and `Slave_IO_Running != Yes` after `START SLAVE`.
- [x] `ema` — `_prod_write_conf` gains an `<extra>` append arg;
      `_replica_server_id` derives a stable, distinct `server_id`.
- [x] `ema` — `create`/`values` emit `[<dbname>]` unchanged (replica emits
      `[db1]` via its `dbname`).
- [x] `ema` — `create --help` documents `--from-snapshot` and the replica
      model.
- [x] `doc/system/replica-bootstrap.md` (new) — manual bootstrap procedure.
- [x] `README.md` — replica package type + `--from-snapshot` pointer.
- [x] tests (new) — gate 1 (missing/non-directory snapshot), gate 2 (missing
      info file / empty binlog coordinate), and the `--dry-run` replica build.

## Open items

- **Coordinate style:** binlog file/position is the concrete coordinate today;
  GTID resume (`MASTER_USE_GTID=slave_pos` + `SET GLOBAL gtid_slave_pos`) is
  preferred and tracked for a later change. With GTID, a foreign snapshot's
  server_id/domain also fails `START SLAVE` explicitly.
- **Snapshot tool:** `mariabackup` is the supported snapshot; a
  `mysqldump --master-data` SQL dump is not yet accepted by `--from-snapshot`.
- **Wrong-source detection at restore:** MariaDB records no `server_uuid` in a
  snapshot, so a wrong-source snapshot is caught at gate 3 (attach) rather than
  at restore; GTID resume would strengthen this to a restore-time check.
