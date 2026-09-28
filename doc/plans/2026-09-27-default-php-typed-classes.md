# Typed PHP config classes — Plan & Progress

Date: 2026-09-27
Repos: ema (this repo).

## Decision

Keep `default.php`, but replace the untyped `$db`/`$dependencies` arrays with
instances of typed value classes shipped by ema (`Ema\Config\DatabaseConfig`,
`Ema\Config\PackageConfig`). Types, defaults and constraints are expressed as
constructor signatures + assertions in those classes. Validation is static
(PHPStan — already present in the consuming repos) plus runtime assertions.
`ema` still loads the file via `require` and re-emits it as JSON, exactly as it
does today; the only change is the config *shape* it receives.

Why this and not the alternatives:

- **JSON + CUE** — the strongest declarative model, but it adds a Go `cue`
  binary to every repo's dev/CI, a second language to author and maintain, and
  a cross-repo schema-import problem. None of that is necessary here: ema
  consumes the config through PHP one-liners, and consumers already run
  PHPStan.
- **Nix** — best config *language*, and what schematic uses, but Nix is
  schematic's *runtime*; ema's runtime is Bash+PHP, so Nix would be a foreign
  body and a heavy dependency for a thin config surface.
- **Plain JSON + JSON Schema** — dumb data, but JSON Schema has no defaults or
  cross-field constraints, so it doesn't fix the `//`-comment default problem.

Chosen mechanism: author a **typed PHP object** (not an array). Defaults live in
constructor parameter defaults; constraints live in constructor assertions;
wrong keys/types are caught by PHPStan before commit. Zero new runtime or CI
dependencies — PHPStan and Composer are already in place.

## Current state

- `src/` holds only `sort_schemas.php` — there is no `src/Config/` yet.
- `composer.json` declares `php >= 8.1` and `bin`, but no `autoload` mapping and
  no `require-dev`/PHPStan.
- `sort_schemas.php` `srv_definition()` / `extract_dependencies()` `require`
  `default.php` and read the `$db`/`$dependencies` variables it sets as side
  effects; `ema`'s `php -r` snippets do the same (e.g. `_build_schema_sql`,
  which reads `$db['dbname']`/`$db['charset']`/`$db['collation']` from
  `srv/<name>-<GUID>/default.php`).
- ema ships 3 `default.php` files in flat-array form:
  `srv/test-D01B9X95PUIWQ60Q/`, `pkg/demo-1F2E3D4C5B6A7980/`,
  `pkg/items-2A3B4C5D6E7F8091/`.
- Pre-commit has a single `flake` hook (`hooks/pre-commit-flake.sh`); no PHPStan.

## Config model

Two classes in ema, PHP ≥ 8.1 (`readonly` promoted properties):

```php
namespace Ema\Config;

final class DatabaseConfig implements \JsonSerializable {
    public function __construct(
        public readonly string $dbname,
        public readonly string $charset = 'utf8',
        public readonly string $collation = 'utf8_spanish_ci',
        public readonly bool   $binlog = false,
        public readonly ?int   $binlog_expire_days = null,
        public readonly bool   $replica_ssl_verify_server_cert = false,
        public readonly array  $dependencies = [],
    ) {
        if ($this->binlog_expire_days !== null && !$this->binlog) {
            throw new \InvalidArgumentException('binlog_expire_days requires binlog');
        }
    }
}

final class PackageConfig implements \JsonSerializable {
    public function __construct(
        public readonly array $dependencies = [],
        public readonly ?RolesConfig $roles = null,
    ) {}
}
```

The `roles` optional fields (`sources`, `accounts`, `allowlist`) — today only
`//`-documented — become a typed nested `RolesConfig`, ending the comment-strain.

**PHP version:** stay `>= 8.1` and use `readonly` *promoted properties*, not
`readonly` *classes* (which require PHP 8.2). `composer.json` declares `>= 8.1`;
the flake dev shells already pin PHP 8.4, so 8.2 buys nothing and would force a
breaking `require` bump.

## Loading the classes

`default.php` itself is a pure value file (`return new …Config(...)`, no
`require`s). Class loading is split by context:

| Context | Tool | Mechanism |
|---|---|---|
| Runtime (`ema`) | `php -r` + `sort_schemas.php` | direct `require` of `src/Config/*.php` from `_EMA_LIB`, *before* `require default.php` |
| Dev/CI | PHPStan (consumer) | Composer autoload — ema adds `"autoload": {"psr-4": {"Ema\\Config\\": "src/Config/"}}` |

`_EMA_LIB` already self-locates ema's `src/` whether run in-tree or via the
`vendor/bin/ema` symlink (the same mechanism that already loads
`sort_schemas.php`), so the runtime `require` needs no new path logic. The
anti-pattern — `default.php` self-requiring `vendor/autoload.php` or a relative
path into ema's `src/` — is rejected: it hardcodes vendor layout and breaks
under path/symlink installs.

## Drift guard

Structural drift (renamed/removed fields, wrong types) is caught by PHPStan at
pre-commit; cross-field constraints by constructor assertions at runtime. No
committed canonical artifact — the `json_encode | diff` artifact is deliberately
dropped. The *default-value* observation surface is instead
`ema create --dry-run`, which renders the exact SQL the defaults produce without
executing it — the same "show, don't apply" pattern the consumer toolchain
already uses for its dry-run flags.

## Pre-commit hooks

- **ema** — add a PHPStan local hook (its only current hook is `flake`) plus a
  `phpstan.neon` covering `src/` and its own `srv/`/`pkg/` `default.php` files.
- **consumers** — reuse their existing `phpstan` (scoped) and `phpstan-full`
  (pre-push) hooks; this only needs ema's `autoload` mapping to exist. No new
  tooling on the consumer side (tracked in each consumer's own plan).

```sh
# illustrative
vendor/bin/phpstan analyse srv pkg src --level=max --no-progress
```

## Changes

### ema (this repo)

- [x] `src/Config/DatabaseConfig.php`, `src/Config/PackageConfig.php` (+ `RolesConfig`) — add the typed value classes.
- [x] `composer.json` — add the `autoload` PSR-4 mapping (`"Ema\\Config\\": "src/Config/"`) so PHPStan resolves the classes in consumers.
- [x] `src/Config/*.php` — constructor defaults + `\InvalidArgumentException` constraints.
- [x] `ema` — change the `php -r` loader (and `src/sort_schemas.php` `srv_definition()`/`extract_dependencies()`) to require the Config classes from `_EMA_LIB` *before* `require default.php`, then `json_encode` the returned object (`JsonSerializable`/`toArray()`).
- [x] `ema` — preserve/extend `ema create --dry-run` as the defaults-materialization surface: add a `-n` short alias and ensure the loader change (object → `json_encode`) feeds the same render path.
- [x] `ema` — scaffolding (`ema create`, `ema sandbox`) emits the class-returning `default.php`.
- [x] `phpstan.neon` — add PHPStan dev dependency + config + local pre-commit hook.
- [x] `srv/test-D01B9X95PUIWQ60Q/`, `pkg/demo-1F2E3D4C5B6A7980/`, `pkg/items-2A3B4C5D6E7F8091/` — migrate `default.php` to class-returning form.
- [x] `README.md` — update config wording (config now returns a typed object).
- [x] `doc/system/replica-bootstrap.md` — the `$db[...]` key spellings become constructor named arguments (`binlog: true`, `type: 'replica'`, …).
- [x] `.gitignore` — ignore `vendor/` (the PHPStan dev dependency's install tree); `composer.lock` stays ignored so `^2.0` keeps picking up PHPStan patches.
- [x] `.gitattributes` — keep the dev-only tooling out of the Composer dist (`hooks/`, `phpstan.neon`, `.pre-commit-config.yaml`).

## Open items

- **Loader compatibility** — resolved: clean switch, no dual shape. A legacy
  array (or any other return value) fails with an explicit `must return an
  \Ema\Config\DatabaseConfig` / `PackageConfig` message, and a constructor
  assertion failing inside the file is reported on STDERR with its message —
  an uncaught fatal would otherwise be swallowed by the callers' command
  substitution and surface as a bare exit code.
- **Validation placement** — resolved: constructor assertions for cross-field
  constraints, PHPStan (`level: max`, `src/Config` + own `srv/`/`pkg/`) for
  structure and types. No separate `validate()` method.
- **`RolesConfig` shape** — resolved for now: nested class, with
  `sources`/`accounts`/`allowlist` typed loosely (array) until a consumer needs
  the element shapes.
