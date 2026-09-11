# Read-replica bootstrap (manual)

How a read-only replica is created from a writable primary with ema. The
replica serves reads so a consumer's read path (e.g. a public website) never
touches the writable primary.

Naming convention: the primary is `0`-suffixed and its replica `1`-suffixed
(e.g. `db0` / `db1`). This is a consumer convention, not something ema
enforces — ema only reads the two package keys below.

## The replica package

A replica is an `srv/<name>-<GUID>` package whose `default.php` carries:

```php
$db = array(
    'dbname'      => 'db1',          // the replica's own database name
    'type'        => 'replica',
    'replica_of'  => 'db0',          // the primary's database name
    'charset'     => 'utf8',
    'collation'   => 'utf8_spanish_ci',
);
```

There is no `$dependencies` and no `upgrade.sql`: the replica's schema arrives
from the primary via replication, never from a schema builder. `ema create`
skips schema apply for a `type=replica` package.

## What ema does (and requires)

`ema create srv/db1-<GUID> --from-snapshot <path>`:

1. refuses if `--from-snapshot <path>` is absent or not a mariabackup backup;
2. reads the snapshot's binlog file/position (the replication coordinate);
3. provisions the replica instance, writing `read_only=1`,
   `replicate-rewrite-db = db0->db1` (so the primary's `db0` schema is
   presented as `db1` on the replica), and a distinct `server_id`;
4. restores the snapshot into the replica datadir (no `mariadb-install-db` —
   the snapshot carries the system tables);
5. `CHANGE MASTER` + `START SLAVE` against the primary over the low-priv
   `replication` account, resuming from the snapshot's coordinate;
6. verifies `Slave_IO_Running = Yes` and aborts loudly otherwise.

ema runs **local-only** on the replica host and reaches the primary only over
the `replication` account — never as root. The primary's `[<primary>]` section
in the consumer's reuter.ini supplies `MASTER_HOST`/`MASTER_PORT`.

The build fails loudly on three gates: snapshot missing, snapshot with no
binlog coordinate (the primary is not replication-ready, or the snapshot is
not a mariabackup backup), and `Slave_IO_Running != Yes` (missing/mis-pinned
`replication` account, unreachable primary, or a wrong-source snapshot).

## Manual bootstrap (before `ema create`)

There is no automated helper; every step below is run by hand, **before**
`ema create`. All of it happens against the primary.

1. **Enable binlog on the primary.** The primary instance must write a binary
   log (`log_bin`); otherwise its snapshot has no replication coordinate and
   the replica build refuses to restore it. (GTID is preferred and a
   future refinement; today ema resumes from the binlog file/position.)

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
   mariabackup --backup --target-dir=/srv/backup/<primary> \
     --user=root --socket=<primary-socket>
   ```

   Optionally prepare it once on the replica host (ema also prepares an
   unprepared backup before restoring):

   ```bash
   mariabackup --prepare --target-dir=/srv/backup/<primary>
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
- **Snapshot tool:** `mariabackup` is the supported snapshot; a
  `mysqldump --master-data` SQL dump is not yet accepted by `--from-snapshot`.
