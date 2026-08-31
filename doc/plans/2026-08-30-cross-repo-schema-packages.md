# Cross-repo schema packages — Plan & Progress

Date: 2026-08-30

Repos: ema (this repo)

## Decision

ema's `pkg/` schema packages serve as **dependencies of schema packages
shipped by other repos**. ema is the terminal object of the schema-dependency
category: every cross-repo dependency edge points *into* ema (another repo's
packages depend on ema's packages), and ema itself **never** depends on
another repo's `pkg/`. Its `composer.json` therefore carries no
`require`/`require-dev`/`repositories` entries for sibling repos — it only
ships `pkg/`.

Consumers declare `judijasa/ema` directly and reference ema's packages by
`<name>-<GUID>`. The multi-provider resolver already shipped in
`src/sort_schemas.php` locates a package across, in order, the consumer's
local `pkg/`, ema's own `pkg/`, and every installed Composer package that
ships a `pkg/` subdirectory (via `vendor/composer/installed.php`). Each repo
exposes its `pkg/` through its own `composer.json` dist; GUIDs keep
cross-repo matches unambiguous.

The one-way direction is structural, not asserted: ema requires nothing
external, so a consumer can depend on ema without ema depending back.

Out of scope: per-schema versioning, install-time materialization (no
composer-plugin), and consumer-side wiring (tracked in the consumers' own
plan docs).

## Current state (already landed)

- ema ships as a plain Composer package (`judijasa/ema`, `"type": "library"`,
  `"bin": ["ema", "bin/dev/init-cluster.sh"]`), committed at `8ce8a16` and
  pinned by consumers at that ref. `require` is `php >=8.1` only — no
  sibling-repo dependency.
- `src/sort_schemas.php` — `package_roots()` + `find_package()` multi-provider
  locator; `extract_dependencies()` and `build_dependency_graph()` resolve
  through it.
- `ema` CLI — `_find_package()` threads the locator through
  `_cmd_init_tables`, `inspect`, `deps`; resolves the `vendor/bin/ema`
  symlink so `_EMA_LIB` self-locates whether run in-tree or installed.
- `.gitattributes` — `export-ignore` dev-only paths; the Composer dist ships
  `ema`, `src/`, `pkg/`, `bin/dev/init-cluster.sh`, `composer.json`,
  `README.md`, `LICENSE`.
- `README.md` — documents the Composer reuse contract and the multi-provider
  lookup in generic "consumer" phrasing.
- The nix flake is dev-shell-only (no `packages.default`): ema code now ships
  via Composer alone, so the former nix/Composer dual-distribution drift is
  resolved.
- Consumers already `require` ema directly via VCS `repositories` entries;
  their `vendor/` trees already contain ema's `pkg/`.

## Mechanism

| search root | source | access |
|---|---|---|
| `pkg/<schema>` | consumer's local repo | read + write (`ema schema`) |
| ema's own `pkg/<schema>` | `dirname(__DIR__) . '/pkg'` | read-only (standalone-dev fallback) |
| `<install-path>/pkg/<schema>` (each provider) | `vendor/composer/installed.php` | read-only |

The resolver is already generic — it finds a package in *any* installed
provider's `pkg/`, so the cross-repo directions work without hardcoded repo
names and without ema depending on any specific provider. No further ema code
change is required; the remaining ema-side work is documentation only.

## Changes

### ema (this repo)

- [x] `composer.json` — `judijasa/ema`, `"type": "library"`, `"bin"` ships
      `ema` + `bin/dev/init-cluster.sh`; `require` is `php >=8.1` only (no
      sibling-repo `require`/`require-dev`/`repositories`).
- [x] `src/sort_schemas.php` — `package_roots()` / `find_package()` locator;
      `extract_dependencies()` + `build_dependency_graph()` resolve through it.
- [x] `ema` — `_find_package()` + thread through `_cmd_init_tables`, `inspect`,
      `deps`; symlink self-location for `_EMA_LIB`.
- [x] `.gitattributes` — `export-ignore` dev-only paths.
- [x] `README.md` — Composer reuse contract + multi-provider lookup.
- [x] `README.md` — document the one-way direction: ema is the terminal
      provider (never depends on another repo's `pkg/`); consumers `require`
      it directly and reference its packages by GUID.

## Open items

- **`demo-*` export-ignore:** a sibling repo that `export-ignore`s its
  `pkg/demo-*` packages will not have those present in its Composer dist, so a
  dependency on a demo package resolves from a dev checkout but not from
  `vendor/`. Intentional (demo packages are not for prod).
- **`jq` on PATH:** `ema init tables` still invokes `jq`; the nix dev shell
  provides it, but a Composer-installed `vendor/bin/ema` relies on the
  consumer's PATH. Confirm/document the consumer contract (the earlier nix
  `makeWrapper` bundling was dropped with `packages.default`).
- **No hardcoded repo names in the resolver** — by design; the locator stays
  generic (`installed.php` scan). ema has no knowledge of, or dependency on,
  any specific sibling repo.

## Notes

- Consumer-side wiring (which repos `require` ema, their `repositories`
  entries, and reading an installed provider's `pkg/`) lives in the consumers'
  own plan docs, not here.
