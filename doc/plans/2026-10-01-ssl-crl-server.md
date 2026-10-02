# ssl-crl server-side revocation — Plan & Progress

Date: 2026-10-01
Repos: ema (this repo)

## Decision

ema gains a host-level `ssl-crl`, the revocation half of `ssl-ca`: the CRL the
instance's `[mysqld]` checks client certificates against. It is read once per
run from the same host config as `ssl-ca`, resolved by the same three-state
rule, and written beside `ssl-ca` into every instance's `[mysqld]`. `ssl-crl`
requires `ssl-ca` (a CRL with no CA is meaningless). The CRL file itself stays
consumer data — ema records the path only, and the CA workflow that generates
the CRL (a standard `openssl ca` operation) lives in the consumer's private
docs, not here.

Applying a new CRL is a restart event: MariaDB reads `ssl-crl` at startup only,
and `FLUSH SSL` does not reload it. ema does not automate that restart — the
operator regenerates the CRL, installs the file, and restarts the instance.

## Changes

### ema (this repo)

- [x] `ema` — `_EMA_SSL_CRL`: read `ssl-crl` from the host config
      (`_host_conf_get ssl-crl`), env override `EMA_SSL_CRL`, absolute-path
      shape check at read, and require `ssl-ca` (loud error when `ssl-crl` is
      set without `ssl-ca`).
- [x] `ema` — `_prod_write_conf`: consume-time existence check for `ssl-crl`;
      emit `ssl-crl = <path>` beside `ssl-ca`; comment updated.
- [x] `ema` — help text: document `EMA_SSL_CRL`.
- [x] `etc/ema.default.conf` — document `ssl-crl` (three-state rule, requires
      `ssl-ca`); commented `ssl-crl` example.
- [x] (doc) — `doc/system/host-ssl-ca.md` (revocation section), `README.md`
      (`ssl-ca` paragraph).

## Open items

- **CRL existence timing** — checked at `ema create`, like `ssl-ca`: a host may
  legitimately set `ssl-crl` before the CRL is generated.
- **Restart on apply** — ema records the path but never restarts the instance;
  applying a new CRL is an operator restart, out of scope here.
- **CRL renewal** — the CRL expires (typically 30 days) and is regenerated on
  the consumer's offline CA machine; an operator-scheduled task, not ema's.
