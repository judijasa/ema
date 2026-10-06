# Read-replica bootstrap (manual)

How a read-only replica is created from a writable primary with ema. The
replica serves reads so a consumer's read path (e.g. a public website) never
touches the writable primary.

Naming convention: the database (schema) has a plain name (`db`); the `0`/`1`
suffixes mark the **instances** that serve it — `db0` the writable primary and
`db1` the read-only replica, both serving the one `db` schema. This is a
consumer convention, not something ema enforces — ema only reads the two
package keys below (`dbname` and `replica_of`).

## Quick setup

```bash
# primary: declare binlog: true in its srv/<name>-<GUID>/default.php
# primary: the passwordless 'replication'@'<replica-ip>' account (REPLICATION SLAVE)
mariadb-backup --backup --target-dir=/srv/backup/<primary> --user=root --socket=<primary-socket>
# primary: record its [<primary>] section in the consumer's reuter.ini (TCP SERVER/PORT)

# replica host
ema create srv/db1-<GUID> --from-snapshot /srv/backup/<primary>
```

## The replica package

A replica is an `srv/<name>-<GUID>` package whose `default.php` returns:

```php
return new \Ema\Config\DatabaseConfig(
    dbname: 'db',             // the schema both instances serve (the primary's)
    type: 'replica',
    replica_of: 'db0',        // the primary's instance name
    charset: 'utf8',
    collation: 'utf8_spanish_ci',
);
```

The package name (`db1`, from `srv/db1-<GUID>`) is the replica's **instance**;
`dbname` is the schema it serves — the shared `db`, not a schema of its own.
The primary package (`srv/db0-<GUID>`) declares the same `dbname: 'db'`; `ema
create` asserts the two agree and serves the primary's schema under its own
name, with no rename or rewrite.

The definition declares no `dependencies` and there is no `upgrade.sql`: the
replica's schema arrives from the primary via replication, never from a schema
builder. `ema create` skips schema apply for a `type=replica` package.

## What ema does (and requires)

`ema create srv/db1-<GUID> --from-snapshot <path>`:

1. asserts the replica's `dbname` equals the primary package's `dbname` (a
   replica serves the primary's schema under the schema's own name), and refuses otherwise;
2. refuses if `--from-snapshot <path>` is absent or not a mariadb-backup backup;
3. reads the snapshot's binlog file/position (the replication coordinate);
4. provisions the replica instance, writing `read_only=1` and a distinct
   `server_id` (no `replicate-rewrite-db` — the replica serves the primary's
   schema under the schema's own name, unchanged);
5. restores the snapshot into the replica datadir (no `mariadb-install-db` —
   the snapshot carries the system tables);
6. `CHANGE MASTER` + `START SLAVE` against the primary over the low-priv
   `replication` account, resuming from the snapshot's coordinate;
7. verifies `Slave_IO_Running` and `Slave_SQL_Running` are both `Yes`, and
   aborts loudly with `Last_IO_Error`/`Last_SQL_Errno` otherwise.

ema runs **local-only** on the replica host and reaches the primary only over
the `replication` account — never as root. The primary's `[<primary>]` section
in the consumer's reuter.ini supplies `MASTER_HOST`/`MASTER_PORT` (the header
is the primary's instance; `DBNAME` names the schema it serves).

The build fails loudly on: a `dbname` that does not match the primary package's,
a missing snapshot, a snapshot with no binlog coordinate (the primary is not
replication-ready, or the snapshot is not a mariadb-backup backup), and
replication threads that never reach `Yes` (missing/mis-pinned `replication`
account, unreachable primary, or a wrong-source snapshot).

## Manual bootstrap (before `ema create`)

ema ships no helper for these steps, which run by hand **before** `ema
create`. They happen against the primary, except that the binlog enablement in
step 1 is a package opt-in (`binlog: true`) — only a primary built
without the key needs the hand edit.

The placeholders resolve to values the primary's own instance already knows —
recover them on the primary host rather than guessing:

- **`<primary>`** is the primary's **instance** name: the package name in
  `srv/<name>-<GUID>/`, and the `[<primary>]` section header in the consumer's
  reuter.ini. The schema it serves (`dbname`) is a plain name distinct from the
  instance — recover it from the package's `default.php` (`dbname`) or from
  `ema values <primary>` (which prints `DBNAME`).
- **`<primary-socket>`** is the instance's root socket
  `$EMA_PROD_BASE/<primary>/mysql.sock` (default
  `/var/lib/mariadb/<primary>/mysql.sock`). Get it with `ema values <primary>`
  (prints the `[<primary>]` section including `MYSQL_UNIX_PORT`), or read the
  `socket =` line in the instance's `/etc/<primary>/my.cnf`.
- **`<EMA_PROD_BASE>`** (in step 1's `log_bin` path) is the instance base dir,
  `/var/lib/mariadb` unless overridden with `EMA_PROD_BASE` — the same base
  that holds the socket above.
- **`/srv/backup/<primary>`** is a scratch directory you choose on each host;
  any path works — `<primary>` is just the name, so `/srv/backup/db0` for a
  primary named `db0`.

1. **The primary must have binary logging enabled.** A primary package opts
   into it with `binlog: true`, so `ema create srv/db0-<GUID>` writes
   `log_bin` + `binlog_format=ROW` to the instance `my.cnf` and the primary is
   replication-ready with no extra step. A primary built *without* the key
   still needs the one-time hand edit: add
   `log_bin = <EMA_PROD_BASE>/<primary>/binlog` and `binlog_format = ROW` to
   its `my.cnf` and restart the daemon. Without `log_bin` the snapshot has no
   replication coordinate and the replica build refuses to restore it. (GTID
   is preferred and a future refinement; today ema resumes from the binlog
   file/position.)

2. **Create the `replication` transport account** on the primary, passwordless
   and host-pinned to the replica host:

   ```sql
   CREATE USER 'replication'@'<replica-ip>';
   GRANT REPLICATION SLAVE ON *.* TO 'replication'@'<replica-ip>';
   ```

   This account is the replica's only credential to the primary. It is
   deliberately not part of any service-account reconcile — the reconcile must
   never see or touch it.

3. **Take a consistent snapshot** of the primary and ship it to the replica
   host:

   ```bash
   mariadb-backup --backup --target-dir=/srv/backup/<primary> \
     --user=root --socket=<primary-socket>
   ```

   Optionally prepare it once on the replica host (ema also prepares an
   unprepared backup before restoring):

   ```bash
   mariadb-backup --prepare --target-dir=/srv/backup/<primary>
   ```

4. **Record the primary's `[<primary>]` section** in the consumer's
   reuter.ini (with a TCP `SERVER`/`PORT`) — the replica build reads it for
   `MASTER_HOST`/`MASTER_PORT`.

Then build the replica:

```bash
ema create srv/db1-<GUID> --from-snapshot /srv/backup/<primary>
```

## Open items

- **Coordinate style:** binlog file/position is the concrete coordinate today;
  GTID resume (`MASTER_USE_GTID=slave_pos` + `SET GLOBAL gtid_slave_pos`) is
  preferred and tracked for a later change.
- **Snapshot tool:** `mariadb-backup` is the supported snapshot; a
  `mysqldump --master-data` SQL dump is not yet accepted by `--from-snapshot`.
