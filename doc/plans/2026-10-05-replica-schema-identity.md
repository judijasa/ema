# Replica schema identity: instance vs database — Plan & Progress

Date: 2026-10-05
Repos: ema (this repo)

## Decision

A read replica is a **same-name copy**. Its datadir is the primary's backup, so
the schema it serves is the primary's schema under a plain name (`db`) distinct
from the suffixed instance names (`db0` primary, `db1` replica); the replica
never materializes a schema of its own. `type=replica` therefore stops
rewriting: the `replicate-rewrite-db` line goes away and the replica's database
is the primary's, asserted at build time.

The previous mechanism (`doc/plans/2026-09-11-read-replica-provisioning.md`)
rewrote the schema name in the replication stream but never created the target
schema, so the first replicated statement carrying a default database killed the
SQL thread (`Unknown database`) — a build that reports success and a replica that
stops replicating. Rewriting is also the wrong fix: it drags every in-schema
reference (views, triggers, routines, qualified SQL, grants) into the rename.

Dropping the rewrite requires separating two identities that today share one
string:

| identity | what it is | scope |
| --- | --- | --- |
| **instance** | a running mariadbd on one host: `mariadb@<name>`, `/etc/<name>/my.cnf`, `/var/lib/mariadb/<name>/`, its socket and port; addressed by the package name `srv/<name>-<GUID>` | one per host |
| **database** | the schema an app reads and writes: created by a `type=primary` package, carried to replicas by replication | the same name on every host that serves it |

A connection section describes an instance *and* the database it serves:
`SERVER`/`PORT`/`MYSQL_UNIX_PORT` are the instance's endpoint, `DBNAME` is the
schema (default: the section header). The key is not new — `ema sandbox` already
writes `DBNAME=` into its sections and `_load_section` already clears it; only
the prod path ignores it, and that asymmetry is the shape of the bug.

The section header stays the **instance**, not the database, because the private
connection file is a single file shipped verbatim to every host, and the primary's
and the replica's sections must coexist in it: the replica build reads the
primary's section for `MASTER_HOST`/`PORT`, while the replica host's app reads the
replica's section for its local endpoint. A database-keyed header would either
collide (the replica host's app would read the primary) or force per-host files.

Package definition (`srv/<name>-<GUID>/default.php`): the **package name is the
instance**, `dbname` is the database it serves. This re-keys the instance on the
package name — today `ema` keys it on `dbname` (read from `default.php`,
independent of the directory name), so the two are equal only because current
packages declare them equal. A `type=replica` package declares `replica_of` = the
primary's **instance** name and `dbname` = the primary's **database** name; the
build asserts the two agree.

What this buys: the replica's schema is the primary's by construction — no rename
or schema adoption, no trigger/view/routine replay, no second run of
service-account generation, every in-schema reference stays valid, one code path
for both package types, and an existing replica instance is repaired in place
instead of rebuilt.

Out of scope: cross-name replication (adopting the primary's schema under a
different name). It needs a drop-triggers → rename base tables → recreate
triggers → recreate views → drop-primary sequence, and MariaDB refuses
`RENAME TABLE` on a table that carries a trigger.

## Config model

```ini
[db0]                             ; the primary's instance
SERVER=<primary host>
PORT=<primary port>
MYSQL_UNIX_PORT=/var/lib/mariadb/db0/mysql.sock
DBNAME=db                         ; the schema this instance serves
<ACCOUNT>_PASSWORD=<secret>

[db1]                             ; the replica's instance (another host)
SERVER=<replica host>
PORT=<replica port>
MYSQL_UNIX_PORT=/var/lib/mariadb/db1/mysql.sock
DBNAME=db                         ; the schema this instance serves
<ACCOUNT>_PASSWORD=<secret>
```

`DBNAME` defaults to the section header when absent, so a section whose schema
is named after its instance may omit it; under this convention the schema is a
plain name distinct from every instance, so **both** sections declare
`DBNAME=db`. `ema values` emits `DBNAME=<dbname>` on both. The app's database
target keeps naming the instance it must use on that host — the schema follows
from the section, never from the target string.

Package definition (`srv/<name>-<GUID>/default.php`): the package name is the
instance, `dbname` is the database it serves. A `type=replica` package declares
`replica_of` = the primary's **instance** name and `dbname` = the primary's
**database** name; the build asserts the two agree.

## Mechanism & data ownership

| datum | owner |
| --- | --- |
| instance name | the package directory `srv/<name>-<GUID>`; reused verbatim by the host pin, the section header and the app's database target |
| database name | the package's `dbname` (a primary creates it; a replica asserts it) |
| endpoint | the private connection file's section |
| schema | the primary's datadir; a replica's is the restored copy of it |

## Changes

### ema (this repo)

- [x] `src/Config/DatabaseConfig.php` — document `dbname` as the database the
      instance serves and `replica_of` as the primary's instance name; update the
      `type=replica` assert message (today it reads "the primary database name")
      to match, keeping the cross-field assert logic.
- [x] `ema` — `_cmd_create_replica` (1237): drop the `replicate-rewrite-db` write
      (1322) and its dry-run line (1301).
- [x] `ema` — `_cmd_create_replica`: assert the replica's `dbname` equals the
      primary package's (`srv/<replica_of>-<GUID>`) before touching the datadir.
- [x] `ema` — `_cmd_create_replica` Gate 3 (1393): bounded wait for
      `Slave_IO_Running` **and** `Slave_SQL_Running`, then fail with
      `Last_IO_Error`/`Last_SQL_Errno`; the single immediate sample reports
      healthy builds as broken.
- [x] `ema` — `_prod_data`/`_prod_sock`/`_prod_pid`/`_prod_log`/`_prod_binlog`/
      `_prod_conf_file`/`_prod_unit` (790-797): key on the instance name (the
      package name), not `dbname`; only a replica distinguishes the two.
- [x] `ema` — `_run_root_sql` (630): default database = the loaded section's
      `DBNAME`, falling back to the header.
- [x] `ema` — `_prod_print_values` (1081): emit `DBNAME=<dbname>` in the section
      recorded from `ema values`.
- [x] `ema` — `_load_section` (210), `_write_sandbox_ini` (610) and the `create`
      help (1094): the header is the instance; `DBNAME` is the schema.
- [x] `doc/system/replica-bootstrap.md` — the schema arrives as the primary's
      schema, not by a rewrite; the primary's section is the primary's instance.
- [x] `README.md` — read-replica section (107-131) and the `create` help text:
      the same correction.
- [x] `doc/plans/2026-10-05-replica-schema-identity.md` — this tracker.

## Open items

- Repair the existing replica in place: drop the rewrite line from the replica
  instance's `my.cnf`, restart the unit, `START SLAVE`. The queued events' default
  database is the primary's, which exists in the restored datadir, so the SQL
  thread catches up. Destructive — explicit go-ahead first.
- The replica's `dbname` is **declared and asserted** rather than inherited from
  the primary package; revisit if the restatement is unwanted.
- Sandboxing a replica package: `ema sandbox` now refuses `type=replica` packages
  with a clear error — a replica has no schema of its own (its schema arrives from
  the primary's snapshot, and a sandbox has no primary to replicate from). The
  `DBNAME` emission itself was already correct (`_sandbox_srv` reads `.db.dbname`,
  never the instance name); the refusal supersedes the earlier "check" framing.
- The live replica predates the fix and its connection section predates `DBNAME`;
  the two must land together or the app keeps asking for a schema the instance
  does not serve.

## Notes

- Consumer-side changes — the connection layer reading `DBNAME`, the replica
  package/grants and connection-template updates, and the private connection
  file — are tracked in the consumers' own plan docs, not here.
