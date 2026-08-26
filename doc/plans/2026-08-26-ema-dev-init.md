# ema dev-init + standalone template — Plan & Progress

Date: 2026-08-26

## Decision

ema was the only repo in the chain without `make dev-init` (consumers
both have one) and its README lacked the standalone-template philosophy. This plan adds both, and deduplicates the MariaDB dev-init
machinery along the way:

1. A `make dev-init` for ema — deliberately simpler than the consumers': ema
   is the leaf tool, so no composer, no `.env` config, no
   machines.ini, no git-hooks.
2. A "Standalone template usage" README section (dual-role philosophy).
3. `bin/dev/init-cluster.sh` is **owned by ema and shipped in its
   `packages.default`**, so consumers call it from PATH instead of keeping a duplicate copy.

Direction of reuse: **consumers → ema**. Because consumers depend on ema
(not the reverse), ema cannot reuse their dev-init scripts — that would be
circular. Consumers keep their policy scripts (`init-local-env.sh`,
`shell-enter.sh`, `init-git-hooks.sh`); only the duplicated `init-cluster.sh`
moves. The ema package was already on consumers' PATH, so the dedup is purely
additive.

Deployment is **out of scope** — this plan is dev-init only. No composer
package for ema (it is not a PHP library; it is a bash+php CLI shipped as a
nix package).

## Mechanism & data ownership

The shareable dev-init code is the **MariaDB-specific** part; the rest is
consumer policy and stays put — the same reuse pattern as the `ema` binary
itself:

| Script | Purpose | Owner after refactor |
|---|---|---|
| `init-cluster.sh` | `mariadb-install-db` + start `mysqld` | **ema** |
| `init-local-env.sh` | write `.env` (REPO_PATH/REPO_LOG/MYSQL_*/REUTER_INI/EMA_MODE/DBUSER) | consumer |
| `shell-enter.sh` | source `.env` + resume mysqld daemon | consumer |
| `init-git-hooks.sh` | pre-commit install + pre-push wrap | consumer |

## Standalone template philosophy

ema is dual-role: a **tool consumed** by other projects (consumers pin
`ema.packages.${system}.default`, which also provides `init-cluster.sh`),
and a **standalone, forkable template** that behaves like its own consumer:
`make dev-init` + `ema init db <name>` (+ `ema init tables <root-pkg> <db>`)
must work from inside this repo, with only the data being this repo's own
(`pkg/`, `srv/`, `var/reuter.local.ini`, `etc/reuter.ini`).

The one structural difference vs an external consumer: ema does not consume
itself through its own flake — its Makefile and shell invoke the local copies
(the in-tree `ema` script, `bin/dev/init-cluster.sh`, `src/`), and those same
files are the artifacts the nix package installs. **ema owns the mechanism
(the CLI and the MariaDB dev-init); the consumer (or this repo, standalone)
owns the data and policy.**

## Changes

### ema (this repo)

- [x] `Makefile` (new): `dev-init` target — assert nix, create dirs, init the
      cluster; owns its paths (`REPO_PATH`/`REPO_VAR`, `var/mariadb/...`);
      calls the in-tree `bin/dev/init-cluster.sh` (standalone does not
      self-consume the nix package).
- [x] `bin/dev/init-cluster.sh` (new): `mariadb-install-db` + start `mysqld`,
      parameterized (data-dir/pid/socket); shipped via
      `cp bin/dev/init-cluster.sh $out/bin/init-cluster.sh` in the flake
      `installPhase`.
- [x] `flake.nix` devShell: minimal shellHook (exports `MYSQL_*` + `EMA_MODE`,
      starts mysqld when the data dir exists); no `.env`/phprun, so no
      shell-enter.sh split.
- [x] demo consumer data: `pkg/demo-1F2E3D4C5B6A7980` +
      `pkg/items-2A3B4C5D6E7F8091` + `srv/test.sql`, so the standalone flow
      runs end-to-end (mirrors the consumers' Quick test).
- [x] README "Standalone template usage" (+ "Standalone dev init"): dual-role
      philosophy and the reuse contract (binary + `init-cluster.sh`).

Consumer-side adoption (dropping their duplicated `init-cluster.sh` in
favor of the one shipped in `packages.default`) is tracked in the consumers'
own plans — out of scope here.

## `make dev-init` design

```make
SHELL := $(shell which bash 2>/dev/null)

REPO_PATH = $(CURDIR)
REPO_VAR = $(REPO_PATH)/var
REPO_LOG = $(REPO_VAR)/log
MYSQL_BASE_DIR = $(REPO_VAR)/mariadb
MYSQL_DATA_DIR = $(MYSQL_BASE_DIR)/data
MYSQL_UNIX_PORT = $(MYSQL_BASE_DIR)/mysql.sock
MYSQL_PID_FILE = $(MYSQL_BASE_DIR)/mysql.pid

.PHONY: help dev-init _dev-assert-nix _dev-init _dev-create-dirs _dev-init-cluster

help:
	@echo "Available initialization targets:"
	@echo "  dev-init   - Run ONCE after cloning locally to build the dev sandbox"

dev-init: _dev-assert-nix _dev-init

_dev-assert-nix:
	@if [ -z "$$IN_NIX_SHELL" ]; then \
		echo "ERROR: This target must be run inside 'nix develop'"; \
		exit 1; \
	fi

_dev-init: _dev-create-dirs _dev-init-cluster
	@echo "Developer environment successfully initialized."

_dev-create-dirs:
	@echo "Creating local logging and storage directories..."
	mkdir -p $(REPO_LOG) $(MYSQL_DATA_DIR)

_dev-init-cluster:
	@bin/dev/init-cluster.sh "$(MYSQL_DATA_DIR)" "$(MYSQL_PID_FILE)" "$(MYSQL_UNIX_PORT)"
```

Contrast with consumer dev-inits, ema drops: `_dev-init-git-hooks` (no
hooks), `_dev-init-composer` (no composer.json), `_dev-update-hosts`
(consumer-specific), `_dev-init-local-env` (no `.env`). Only the cluster init
survives — and that cluster init is the single piece consumers reuse.

## Open items

- Full end-to-end exercise of the standalone flow on a fresh clone
  (`nix develop` → `make dev-init` → `ema init db test` →
  `ema init tables <root-pkg> test`) — each piece is in place and committed;
  a single recorded run is still pending.
- Git-hooks/pre-commit: **no** for now (ema has no `hooks/`, no
  `.pre-commit-config.yaml`); add later if a linter is adopted.
- Split the shellHook into a sourced `bin/dev/shell-enter.sh` for parity:
  **no** — ema has no `.env`, so the split would add a file with no runtime
  benefit.
- Move the "resume mysqld daemon" logic from consumers' `shell-enter.sh`
  into ema as a shared helper: **no for now** (minimal diff); revisit only if
  a third consumer needs it.
