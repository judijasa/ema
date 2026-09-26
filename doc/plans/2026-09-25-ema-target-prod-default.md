# Align ema's EMA_TARGET default to prod — Plan & Progress

Date: 2026-09-25
Repos: ema (this repo).

## Decision

ema's `_target()` currently defaults an unset/empty `EMA_TARGET` to `sandbox`
(safe-by-default: a bare `ema` run never touches prod). Consumers' app layer
treats unset/empty as `prod`. To keep `EMA_TARGET` one consistent switch across
the CLI and the app layer, ema flips its unset default to `prod` too:
`sandbox` becomes explicit (`EMA_TARGET=sandbox`), and unset/empty means `prod`.

Safety trade-off (accepted 2026-09-25): a bare `ema` command with no `.env` now
targets prod, so prod-only commands (`create`, `values`) no longer self-refuse
on the default (they stopped consulting the flag altogether in
`doc/plans/2026-09-26-verb-scoped-ema-target.md`). In practice both dev and prod
machines always declare `EMA_TARGET` (dev → the dev shell's export, prod → the
deployed `.env`), so the flip only bites where nothing declares a mode — and prod
resolution still requires `REUTER_INI`/`etc/reuter.ini`, so a prod command
cannot act without a resolvable prod connection file.

## Changes

### ema (this repo)

- [x] `ema` — `_target()`: reorder the `case` so `sandbox` → `sandbox` and
      `""|prod` → `prod` (drop `""` from the sandbox arm); the unknown-value
      error arm is unchanged.
- [x] `README.md` — "Connectivity and machine mode": change "unset or
      `sandbox` (default)" to "`sandbox`"; add "unset or `prod` (default)" to
      the prod bullet; update the `EMA_TARGET` flag line and the "Prod machines
      run `EMA_TARGET=prod` by default" sentence.

## Open items

- Schema-only contract and sandbox lifecycle are unchanged; the per-instance
  `var/sandbox/<name>-<guid>/reuter.ini` layout stays as-is.
