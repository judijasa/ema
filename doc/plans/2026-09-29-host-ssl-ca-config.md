# Host-level ssl-ca config — Plan & Progress

Date: 2026-09-29
Repos: ema (this repo)

## Decision

Give the server half of `REQUIRE X509` a home: a consumer-owned **host-level**
config file that carries the server's `ssl-ca` path, which ema emits into each
instance's `my.cnf`. Without `ssl-ca`, `REQUIRE X509` authenticates nobody — the
server has no CA to verify client certs against.

The value is host-level (one CA shared by every database on the host), so it
does not belong in the per-database `DatabaseConfig` or the per-database
`reuter.ini` sections. ema reads a consumer-owned INI file (repo-root
`etc/ema.conf` — the sibling of `etc/reuter.ini`, not the system `/etc` —
`[default]` section, `ssl-ca` key); when set, it emits `ssl-ca = <path>` into
the instance's `[mysqld]` block via the existing `_prod_write_conf` append.
Unset/absent → plain TCP, unchanged.

Out of scope: the CA cert file itself (installed out-of-band by the operator;
ema never copies certificate bytes), the client cert material, and the `require`
declaration (all consumer data). Client-side server verification and replication
client-cert keys are deferred.

## Changes

### ema (this repo)

- [x] `ema` script — read the consumer-owned host config `etc/ema.conf`
      (`[default]` `ssl-ca`) once per run; carry the value into the conf writer.
      `EMA_SSL_CA` overrides the file (tests, one-off runs); the file is the
      repo-root `etc/`, resolved against the repo root like `etc/reuter.ini` —
      the system `/etc` is never consulted.
- [x] `_prod_write_conf()` — when `ssl-ca` is set, append `ssl-ca = <path>` to
      the `[mysqld]` block of `<db>/my.cnf` (host-level, every instance);
      unset/absent → no `ssl-ca` line.
- [x] `etc/ema.conf.template` + `.gitignore` — ship the shape with no value (the
      path is consumer-chosen) and ignore the real repo-root `/etc/ema.conf`,
      mirroring `etc/reuter.ini.template` / `/etc/reuter.ini`.
- [x] (doc) — `doc/system/host-ssl-ca.md` records the `etc/ema.conf` shape
      (`[default] ssl-ca = <path>`) as the consumer-owned file ema reads,
      stating the repo-root-`etc/` vs. system-`/etc` distinction; referenced
      from `README.md`.

## Open items

- **Client-side server verification** — out for v1 (client presents cert+key;
  server verifies). Full mutual TLS (CA-signed server cert + client `ssl-ca`) is
  a recorded follow-up.
- **Replication client-cert keys** (`MASTER_SSL_CERT`/`MASTER_SSL_KEY`) — only
  if the `replication` account is later certed; still deferred (see
  `doc/plans/2026-09-27-replica-ssl-verify-server-cert.md`).
- **`require_secure_transport`** — stays deferred.
- **Revocation** — no CRL is consumed, so a leaked machine cert cannot be
  revoked.
