# Primary binary-log enablement — Plan & Progress

Date: 2026-09-26
Repos: ema (this repo).

## Decision

ema's primary build must produce a replication-ready instance. A `type=replica`
package's `replica_of` primary has to have binary logging (`log_bin`) enabled,
or its snapshot carries no binlog coordinate and the replica build refuses it
(gate 2). Today the primary build writes none of that — `_prod_write_conf`
emits only datadir/socket/pid-file/log-error/user/port/bind-address — so every
primary is born without `log_bin` and the operator must hand-edit the instance
`my.cnf` and restart the daemon before a replica can be bootstrapped
(`doc/system/replica-bootstrap.md` step 1).

ema should enable binary logging on primary instances at build time, so a
primary that will source a replica is ready with no manual step. The
**mechanism is an opt-in package key** (decided): a primary package declares
`$db['binlog'] = true` and `_prod_write_conf` then writes the binlog block —
`log_bin` + `binlog_format=ROW` — for that primary only. A primary without the
key is born binlog-free (no disk cost where replication is unused), and a
replica never sets the key — it is `read_only` and is never itself a source
(chained replication is out of scope).

## Current state

- `_prod_write_conf` is the single author of the per-instance `my.cnf`. It
  already takes an `<extra>` append arg (used by the replica build to add
  `read_only`/`replicate-rewrite-db`/`server_id`), so a primary-side binlog
  block has an obvious injection point.
- The primary leaves `server_id` at the server default (1); replicas get a
  distinct `server_id` via `_replica_server_id`. Enabling binlog therefore
  needs an explicit `log_bin` (and, for correctness under
  `replicate-rewrite-db`, `binlog_format=ROW`) but no new `server_id` on the
  primary.
- Scope is the **build path**: a first `ema create` initialises and starts the
  instance with the conf already written, so binlog lands with no restart.
  Retrofitting an already-created primary is out of scope (`ema create` is
  create-only); such an instance still follows the manual step.

## Changes

### ema (this repo)

- [x] `ema` — `_prod_write_conf` writes `log_bin` + `binlog_format=ROW` only
      when the package opts in, gated by a new `binlog` arg (default off; the
      replica build passes `no`).
- [x] `ema` — implement the opt-in package key (`$db['binlog'] = true`),
      threaded `_cmd_create` → `_prod_instance_ensure` → `_prod_write_conf`.
- [x] `doc/system/replica-bootstrap.md` — shrink the manual "enable binlog"
      step to the opt-in key, keeping the hand-run fallback for primaries built
      without it.
- [x] `ema` — own binlog expiry: `_prod_write_conf` emits `expire_logs_days`
      (per-package `$db['binlog_expire_days']`, default 7) with the binlog
      block.
- [x] `README.md` — document the `$db['binlog']` opt-in key and ema-owned
      `$db['binlog_expire_days']` retention.

## Open items

- **Mechanism — decided: opt-in package key (`$db['binlog'] = true`).**
  Explicit, keeps binlog off where replication is unused, and lets the consumer
  declare the key ahead of the first replica. Default-on was rejected for its
  unconditional disk cost, and inference from `replica_of` is impossible
  because primary and replica packages build independently.
- **Binlog path — decided:** `log_bin` writes to `$EMA_PROD_BASE/<db>/binlog`
  (a sibling of `data/`, covered by the existing per-instance `chown`).
- **Expiry — decided: ema-owned, per-package.** `expire_logs_days` is emitted
  with the binlog block, sized by `$db['binlog_expire_days']` (default 7 days)
  so an opted-in primary self-clears old binlogs instead of growing unbounded
  (MariaDB's `expire_logs_days=0` default keeps them forever). `max_binlog_size`
  stays unset — file-size rotation, not age-based expiry.
- **`binlog_format` — decided: `ROW`** (safe under `replicate-rewrite-db`).
  Adopting GTID in place of file/position resume is a separate, later change
  tracked in `doc/plans/2026-09-11-read-replica-provisioning.md`.
- **Retrofit path:** no mechanism today to add `log_bin` to an existing primary
  without a manual edit plus restart; out of scope unless a later change adds a
  lifecycle verb for it.
