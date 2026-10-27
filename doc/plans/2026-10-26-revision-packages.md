# Revision packages (`rev/`) — Plan & Progress

Date: 2026-10-26
Repos: ema (this repo).

## Decision

ema gains a third package kind — **revision** — alongside `pkg/` (schema) and
`srv/` (database). A revision is an **imperative, one-shot** migration that
mutates an *already-materialized* database: `rev/<date>-<name>-R0<GUID>/`
holding a `default.php` (returning a typed `RevisionConfig`) and an
`upgrade.sql` of migration statements (`ALTER TABLE …`, `UPDATE …`). Each
revision is applied exactly once per database and recorded in a tracking table,
so a re-run skips it.

Why: `pkg/upgrade.sql` is **idempotent target-state** (`CREATE OR REPLACE
TABLE …`), and `ema create` rebuilds from scratch (`DROP DATABASE …; CREATE
DATABASE …`). Neither can migrate a live database without dropping data.
Revisions are the data-preserving path: they run once, in place, against an
existing database — mirroring schematic's `rev/` (which exists because
schematic's declarative diff cannot express imperative steps).

Scope boundary: ema's runtime stays Bash+PHP — no Nix/Python diff engine
(consistent with `2026-08-26-schematic-cli-adoption.md` and
`2026-09-03-sandbox-schema-application.md`). A revision is an ordered, tracked
SQL runner, not a migration DSL.

Key decisions:

- **One revision per package, named after that package** — the specific
  `pkg/<name>-S0<GUID>` it migrates (e.g. `empleo`), *not* the database's
  main/grouping package (e.g. `simo`).
- **Register by wiring into the package.** The migrated package's `default.php`
  `dependencies` holds the revision **head** — the newest revision of that
  package. There is no separate migration list; the dependency graph is the
  registry.
- **Order by `dependencies`, never by the date in the title.** A revision's own
  `dependencies` chain the prior revision it must run after (full
  `<name>-R0<GUID>` ref). The `<date>` in the folder name is a human label only
  and is never parsed for ordering.
- **GUID prefix `R0`**, a fresh GUID (not the migrated package's `S0` GUID),
  reusing the existing `_gen_guid` type-prefixed convention (16 chars total).
- **`rev/` is local-only**, like `srv/` (consumer-owned data), not
  multi-provider like `pkg/`. Revisions are not shipped through Composer.

## Two apply modes (the core split)

- **Fresh database** (`ema create …`): run **schemas only**. Their idempotent
  `CREATE OR REPLACE` already materializes the final structure, so revisions are
  pointless there (and would fail on tables that do not exist yet).
- **Existing database** (`ema upgrade …`): run **pending revisions only**, in
  dependency order. Schemas are *not* re-run — re-running them would
  `CREATE OR REPLACE` and drop live data.

There is no "run both for the same object." Because ema has no diff engine,
"new vs altered" is not auto-detected: on an existing database, even a
brand-new table is expressed as a revision (`CREATE TABLE … IF NOT EXISTS`).

## Ordering & chaining (important)

Revisions are ordered by **`dependencies`, not by the date in the title**.

- Each revision's `default.php` `dependencies` lists the **immediately prior**
  revision of the same package (its full `<name>-R0<GUID>` dir name), forming a
  linear chain.
- The migrated package's `default.php` `dependencies` lists **only the head**
  (newest) revision; older revision refs are removed. Reachability is
  transitive — `package → head → older → …` — so the whole chain is discovered
  and ordered correctly.
- `ema revision` auto-chains a new revision to the current head, and **replaces**
  the package's old revision ref with the new head (it does not append).
- The `<date>` in the folder name is a **label only**: two revisions of the same
  package created on the same day, or a back-dated fix, must not change
  execution order.

Concrete (`empleo`, two revisions):

```
rev/2026-10-26-empleo-R0AAA/default.php   dependencies: []              # rev1
rev/2026-11-01-empleo-R0BBB/default.php   dependencies: ['…-R0AAA']     # rev2 → rev1

pkg/empleo-…/default.php                  dependencies: ['…-R0BBB']     # head only
```

Order: rev1 → rev2 (transitive via the head).

## Command surface

| command | behavior |
|---|---|
| `ema revision pkg/<name>-S0<GUID>` | validate the package exists, scaffold `rev/<date>-<name>-R0<GUID>/` with `default.php` + `upgrade.sql` (the first revision copies the schema's `upgrade.sql` verbatim; later ones start empty), chain it to the current head, and replace the package's prior revision ref with the new head in `default.php` |
| `ema create …` (fresh) | schemas only; revisions are not applied |
| `ema upgrade …` (existing) | pending revisions only, in topo order, each once (tracked) |
| `ema mariadb <db> < file.sql` | unchanged — raw SQL remains the ad-hoc path |

`<date>` = today (ISO). `<name>` = the package's name (ref stripped of its
`-S0<GUID>`). The `R0<GUID>` is a **fresh** guid, not the package's `S0<GUID>`.

## Config model

New typed class, PHP ≥ 8.1 (`readonly` promoted properties), sibling to
`PackageConfig` / `DatabaseConfig`:

```php
namespace Ema\Config;

final class RevisionConfig implements \JsonSerializable
{
    /**
     * @param list<string> $dependencies The immediately prior revision this one
     *        must run after, referenced by its full <name>-R0<GUID> dir name.
     */
    public function __construct(
        public readonly array $dependencies = [],
    ) {}

    public function toArray(): array
    {
        return ['dependencies' => $this->dependencies];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
```

There is no `schema` field: the revision's target package is the one whose
`dependencies` contains its (head) ref, and the revision's own name already
carries the package name. `ema revision pkg/empleo-…` scaffolds:

```php
<?php
// first revision of empleo
return new \Ema\Config\RevisionConfig();

// second revision of empleo (auto-chained to the first)
return new \Ema\Config\RevisionConfig(
    dependencies: ['2026-10-26-empleo-R0…'],
);
```

## Tracking

An `ema_revision` table, created idempotently in the target database before
applying revisions (`ema_` is a reserved prefix — no consumer-table collision):

```sql
CREATE TABLE IF NOT EXISTS ema_revision (
    guid       VARCHAR(16)  NOT NULL,
    name       VARCHAR(255) NOT NULL,
    applied_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (guid)
);
```

`dependencies` is deliberately not duplicated here — it lives in the revision's
own `default.php`. The table records *which* revisions ran, keyed by `guid`.

Apply flow (`ema upgrade`): discover the revisions reachable from the target
`srv/` graph (revision refs in package `dependencies`), topo-sort them over
`RevisionConfig::$dependencies`, then for each revision whose `guid` is absent
from `ema_revision`, run its `upgrade.sql` and insert a tracking row. Recorded
GUIDs are skipped → each revision runs exactly once.

Atomicity contract: MariaDB DDL auto-commits, so a multi-statement
`upgrade.sql` is not atomic. Revisions must write **idempotent SQL** (rely on
`IF [NOT] EXISTS`); the tracking row is inserted only on full success — a
mid-way failure leaves the revision unrecorded and re-runnable.

## Changes

### ema (this repo)

- [ ] `src/Config/RevisionConfig.php` — add the typed class (`dependencies` only).
- [ ] `src/sort_schemas.php` — `require` `RevisionConfig.php` before loading `rev/*/default.php` (same pattern as the other Config classes).
- [ ] `src/sort_schemas.php` — add `find_revision_package()` (local `rev/` only, mirroring `find_srv_package()`), a revision loader, and topo sort over `RevisionConfig::$dependencies` (chained revision refs).
- [ ] `ema` — add `R0` to the `_gen_guid` type prefixes; add `ema revision <pkg>` (validate via `find_package`, scaffold `rev/<date>-<name>-R0<GUID>/`, chain to the current head, and replace the package's prior revision ref with the new head).
- [ ] `ema` — add `ema upgrade` (existing DB): create `ema_revision`, discover reachable revisions, topo-sort, apply pending ones, insert tracking rows. `ema create` stays schema-only.
- [ ] `ema` — do not add a diff/upgrade engine; Nix/Python stay out of the runtime.
- [ ] tests (new) — `ema revision` scaffold + wiring (package keeps only the head); apply-once idempotency (second `ema upgrade` runs no revision SQL); dependency order (later revision runs after the one it lists); tracking row written per applied revision; `ema create` does not apply revisions.
- [ ] `README.md` — document `rev/`, `ema revision`, `ema upgrade`, and the tracking table.

## Open items

- **Self-revision**: a revision that must alter `ema_revision` itself has a
  self-reference problem (schematic's `add_meta_revision = false`); defer until
  needed. ema sidesteps the common case by creating `ema_revision` idempotently
  in the apply path (no implied dependency on a meta-revision schema).
- **Revision → schema ordering**: since revisions only run in existing-DB mode
  (schemas skipped), revision `dependencies` reference prior *revisions*, not
  schemas. Cross-kind ordering is not needed now.
