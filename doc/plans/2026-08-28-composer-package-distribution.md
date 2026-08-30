# Composer package distribution — Plan & Progress

Date: 2026-08-28

Repos: ema (this repo)

## Decision

ema's `pkg/` schema packages become consumable as Composer dependencies. ema
ships as a **plain Composer package** — `"type": "library"`, `"bin": ["ema"]` —
that distributes `pkg/` (SQL + `default.php` manifests) and
`src/sort_schemas.php`. No composer-plugin: ema needs no install-time side
effect; resolution is a read-time lookup. This refines the plain-package
approach already sketched in `HANDOFF.md` (same mechanism, two changes: a
multi-provider locator and transitive arrival).

The resolver gains a **multi-provider locator**: a schema package is found in
the consumer's local `pkg/` first, then in any installed Composer package that
ships a `pkg/` subdirectory (discovered via `vendor/composer/installed.php`).
GUIDs (`<name>-<GUID>`) keep cross-root matches unambiguous. This serves two
goals at once: (1) a consumer can depend on schema packages shipped by *any*
installed provider — not just ema's — and (2) ema can arrive **transitively**:
an intermediate package may `require` ema so the end consumer never declares
it.

`ema schema` keeps writing to the consumer's local `pkg/` only; provider roots
are read-only to the resolver.

Out of scope: install-time materialization / composer-plugin, per-schema
versioning, and the lock-sync of the dual nix+Composer distribution (a
consumer-side concern, tracked in the consumers' own plan docs).

## Mechanism

### Distribution (ema)

```json
{
  "name": "judijasa/ema",
  "type": "library",
  "license": "MIT",
  "require": { "php": ">=8.1" },
  "bin": ["ema"]
}
```

Artifacts are SQL + a PHP manifest include, not classes: no autoload section
needed, just ship `pkg/`, `src/sort_schemas.php`, the `ema` bin, `LICENSE`,
`README.md`. Published via a git remote; the consumer declares a VCS
repository + `require` (direct or transitive). Private auth is Composer's job.

### Consumption (consumer, generic)

- `composer require judijasa/ema` → installs at `vendor/judijasa/ema/`, with
  `pkg/` at `vendor/judijasa/ema/pkg/` and `ema` linked at `vendor/bin/ema`.
- An intermediate package may `require` ema instead, so it arrives in the end
  consumer transitively; the consumer only needs a `repositories` VCS entry
  for ema (Composer reads `repositories` from the root package only).

### Resolver locator

```php
function package_roots() {
    $roots = array();
    if (is_dir('pkg'))                                  $roots[] = realpath('pkg');
    if (is_dir(dirname(__DIR__) . '/pkg'))              $roots[] = realpath(dirname(__DIR__) . '/pkg');
    if (is_file('vendor/composer/installed.php')) {
        $data = require 'vendor/composer/installed.php';
        foreach ($data['versions'] ?? array() as $v) {
            $p = $v['install_path'] ?? null;
            if ($p && is_dir($p . '/pkg'))              $roots[] = realpath($p . '/pkg');
        }
    }
    return array_values(array_unique($roots));
}

function find_package($schema) {
    foreach (package_roots() as $root) {
        $dir = $root . '/' . $schema;
        if (is_dir($dir)) return $dir;
    }
    return null;
}
```

| search root | source | access |
|---|---|---|
| `pkg/<schema>` | consumer's local repo | read + write (`ema schema`) |
| `<install-path>/pkg/<schema>` (each provider) | `vendor/composer/installed.php` | read-only |
| ema's own `pkg/<schema>` | `dirname(__DIR__) . '/pkg'` | read-only (standalone-dev fallback) |

`extract_dependencies()` `require`s `find_package($schema)/default.php`;
`build_dependency_graph()` pushes a dependency only when `find_package($dep)`
is non-null. The CLI resolves the `vendor/bin/ema` symlink (`readlink -f` on
`$BASH_SOURCE[0]`) so `_EMA_LIB` and the provider root self-locate, and uses
`find_package()` for the root-pkg check and the `upgrade.sql` concat in
`_cmd_init_tables`, plus `inspect`/`deps`.

## Changes

### ema (this repo)

- [ ] `composer.json` (new) — `judijasa/ema`, `"type": "library"`, `"bin": ["ema"]`,
      `require php >=8.1`; ship `pkg/` + `src/`.
- [ ] `.gitattributes` (new) — `export-ignore` dev-only paths (`doc/`,
      `bin/dev/`, `etc/`, `srv/`, `Makefile`, `flake.nix`, `flake.lock`,
      `.gitignore`, `var/`) so the Composer dist carries only `ema`, `src/`,
      `pkg/`, `composer.json`, `README.md`, `LICENSE`.
- [ ] `src/sort_schemas.php` — add `package_roots()` + `find_package()`; replace
      `require_once 'pkg/'…` in `extract_dependencies()` and the
      `file_exists('pkg/'…)` check in `build_dependency_graph()`.
- [ ] `ema` — resolve the bin symlink for `_EMA_LIB`/provider-root
      self-location; thread `find_package()` through `_cmd_init_tables`
      (root-pkg check + `upgrade.sql` concat), `inspect`, `deps`.
- [ ] `ema` — keep `ema schema` writing to local `pkg/` only; `_assert_repo_root`
      unchanged.
- [ ] tests (new) — fixture consumer layout (own `pkg/` + an installed
      provider's `pkg/`); assert cross-root dependency resolution and
      topological order (local root depending on a provider package).
- [ ] `README.md` — document Composer consumption (`require` + `repositories`
      VCS entry, direct or transitive) in generic "consumer" phrasing; document
      the multi-provider `pkg/` lookup.

## Open items

- **Dual-distribution drift:** ema is fetched two ways — its nix flake (CLI +
  `src/`) and Composer (`pkg/` + `src/`). A consumer pulling both can end up
  with mismatched revisions. A consumer's own `flake.lock` records the
  transitive nix pin, so equality is assertable locally; the sync gate is a
  consumer-side concern, tracked in the consumers' own plan docs, not here.
- **`jq`/`php` on PATH:** the nix package wraps `jq` onto PATH for the `ema`
  CLI (`_cmd_init_tables` parses schema JSON with `jq`); a Composer-installed
  `vendor/bin/ema` relies on the consumer's environment providing `jq` + `php`.
  Confirm/document the consumer contract.
- Plugin escalation only if ema later needs to materialize schemas outside
  `vendor/` or emit an install-time registry — not now.
- Consumer-side wiring (who `require`s ema, the `repositories` entries, and
  reading a framework package's `pkg/`) is tracked in the consumers' own plan
  docs, not here.
