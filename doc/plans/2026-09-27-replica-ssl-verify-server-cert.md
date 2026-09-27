# Replica SSL server-cert verification knob — Plan & Progress

Date: 2026-09-27
Repos: ema (this repo).

## Decision

ema's replica build emits a bare `CHANGE MASTER` (host/port/user/log-file/log-pos)
with no SSL options, so MariaDB 11.8 applies its implicit default
`MASTER_SSL_VERIFY_SERVER_CERT=Yes`. Against a primary whose server certificate
is the auto-generated self-signed one — the norm, since no consumer provisions
a CA today — the IO thread fails with `Last_IO_Errno=2026` ("SSL certificate is
self-signed") and `Slave_IO_Running=Connecting`, so gate 3 aborts the build.
The only recourse is a manual `CHANGE MASTER TO
MASTER_SSL_VERIFY_SERVER_CERT=0` on the half-built replica.

ema should stop inheriting MariaDB's default and emit
`MASTER_SSL_VERIFY_SERVER_CERT` explicitly, controlled by an opt-in package key
so each consumer picks the trust posture for its own replication transport. The
**mechanism is an opt-in package key** (decided): a replica package may declare
`$db['replica_ssl_verify_server_cert'] = true` to verify the primary's server
certificate; absent (or `false`) ema emits `MASTER_SSL_VERIFY_SERVER_CERT=0`.
The key is **replica-only** — a primary package never sets it, mirroring how
`$db['binlog']` is primary-only (a replica never sets that). Only the
verification flag is touched — `MASTER_SSL` is left as-is, so the channel stays
TLS-encrypted and only the server-identity check is toggled. A consumer that
later stands up a CA flips the key `true` (paired with a future `replica_ssl_ca`
path) with no ema change.

## Current state

- `src/sort_schemas.php` `srv_definition()` loads the package `default.php` and
  returns `$db` verbatim — keys are not filtered, so a new `$db` key flows
  through with no PHP change.
- `ema` `_cmd_create` reads the replica-relevant keys via `jq` (`.db.type`,
  `.db.replica_of`, `.db.binlog`, `.db.binlog_expire_days`) and routes
  `type=replica` to `_cmd_create_replica`.
- `_cmd_create_replica` writes the `CHANGE MASTER` in two places — the
  `--dry-run` echo and the on-disk `replica_sql` heredoc — neither carries any
  `MASTER_SSL_*` option today.
- Failure signature: `Last_IO_Errno=2026` + `Slave_IO_Running=Connecting` after
  `START SLAVE`, aborted by gate 3; the workaround is a manual
  `CHANGE MASTER TO MASTER_SSL_VERIFY_SERVER_CERT=0; START SLAVE;`.

## Changes

### ema (this repo)

- [x] `ema` — `_cmd_create` extract `.db.replica_ssl_verify_server_cert`
      (default off) into `db_replica_ssl_verify` (`1`/`0`) and pass it as a
      third arg to `_cmd_create_replica`; mark the extraction with a code
      comment opening `replica only:` (the mirror of the `binlog` comment's `a
      replica never sets it`).
- [x] `ema` — `_cmd_create_replica` accept the new arg (default `0`) and append
      `MASTER_SSL_VERIFY_SERVER_CERT=<0|1>` to the `CHANGE MASTER` in both the
      `--dry-run` echo and the `replica_sql` heredoc:

      CHANGE MASTER TO MASTER_HOST='…', MASTER_PORT=…, MASTER_USER='replication', MASTER_LOG_FILE='…', MASTER_LOG_POS=…, MASTER_SSL_VERIFY_SERVER_CERT=0;

- [x] `README.md` — document the `$db['replica_ssl_verify_server_cert']` opt-in
      key in the "Read replicas" section (default off; verification stays off
      until a consumer provisions a CA).

## Open items

- **Mechanism — decided: opt-in package key
  (`$db['replica_ssl_verify_server_cert'] = true`).** Default off because
  self-signed certs are the norm and no consumer CA exists; default-on would
  re-break every unconfigured replica. The flag is emitted unconditionally so
  the build never depends on MariaDB's implicit default.
- **Scope — decided: verification only.** `MASTER_SSL` (channel encryption) is
  left untouched; the key toggles `MASTER_SSL_VERIFY_SERVER_CERT` alone. A
  future "no TLS at all" posture is a separate key, not needed today.
- **CA/client-cert path — deferred.** A consumer adopting a real CA (server cert
  for the primary, client cert for the `replication` account) would add sibling
  keys — `replica_ssl_ca` (and possibly `replica_ssl_cert`/`replica_ssl_key`) —
  emitted into the same `CHANGE MASTER`. Deliberately out of this change.
- **Key shape — decided: flat** (`replica_ssl_verify_server_cert`), matching the
  existing `binlog`/`binlog_expire_days` flat keys rather than a nested
  `replica_ssl` map; sibling keys grow alongside it later (precedent:
  `doc/plans/2026-09-26-primary-binlog-enablement.md`).
- **GTID resume** remains a separate later change (see
  `doc/plans/2026-09-11-read-replica-provisioning.md`); this knob is orthogonal
  to coordinate style.
- Consumer-side policy — whether a consumer sets the key `true` (and later
  provisions a CA) — lives in that consumer's own plan; this plan covers the
  mechanism only.
