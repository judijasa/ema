# ema.conf default + validated override — Plan & Progress

Date: 2026-10-01
Repos: ema (this repo)

## Decision

Replace the "copy `etc/ema.conf.template` into a git-ignored `etc/ema.conf`"
step with a committed **default** file ema reads as a fallback, plus an optional
git-ignored **override**. This keeps the default as data (discoverable, not
buried in the CLI) while removing a manual step from quick setup.

ema reads `etc/ema.default.conf` (committed, tracked) then `etc/ema.conf`
(optional, consumer-owned override) — both repo-root `etc/`, `[default]`
section, `ssl-ca` key, same shape as today. The value is resolved by key state:

- **absent** (not in either file) → default from `ema.default.conf`;
- **empty** (`ssl-ca =`) → meaningful "no CA": plain TCP, explicitly clearing a
  defaulted CA;
- **present non-empty** → validated as an absolute path (loud error if not);
  file existence is checked at `ema create` (when the value is written into the
  instance `my.cnf`), not at read time — the operator installs the CA out of
  band.

`EMA_SSL_CA` still overrides everything (tests, one-off runs). `etc/reuter.ini`
is unchanged (per-database endpoints, no default).

## Changes

### ema (this repo)

- [x] `ema` script — `_host_conf_get`: read `etc/ema.default.conf` then
      `etc/ema.conf` (both via `parse_ini_file`, merged with
      `array_replace_recursive`); distinguish absent / empty / non-empty for
      `ssl-ca`; error loudly on a present non-empty value that is not an
      absolute path.
- [x] `ema` script — `_prod_write_conf` / `ema create`: consume-time check —
      when `ssl-ca` is set but the file does not exist, fail loudly (shape is
      checked at read, existence at consume).
- [x] `etc/ema.conf.template` → `etc/ema.default.conf` — committed default,
      `[default]` with `ssl-ca` commented out (generic "no CA"), documenting the
      three-state rule; the `.template` suffix stays reserved for copy-me shapes.
- [x] `.gitignore` — keep ignoring `/etc/ema.conf` (the override); the tracked
      `ema.default.conf` is unaffected.
- [x] (doc) — `doc/system/host-ssl-ca.md` + `README.md` document the
      default/override/validation model.

## Open items

- **CA existence timing** — checked at `ema create`, not at read: a host may
  legitimately have `ssl-ca` written before the CA is installed. Revisit if a
  read-time existence check is later wanted.
- **Mutual TLS** — client-side server verification stays deferred (see
  `doc/plans/2026-09-29-host-ssl-ca-config.md`).
- **`reuter.ini` default** — deliberately out of scope (per-database endpoints
  have no sensible default).
