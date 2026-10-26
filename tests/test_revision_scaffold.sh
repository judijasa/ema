#!/usr/bin/env bash
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
EMA="$REPO_ROOT/ema"

fail() { echo "FAIL: $1" >&2; exit 1; }

FIXTURE="$(mktemp -d /tmp/ema_test_scaffold_XXXXXX)"
trap 'rm -rf "$FIXTURE"' EXIT

mkdir -p "$FIXTURE/pkg/items-2A3B4C5D6E7F8091" "$FIXTURE/srv/test-D01B9X95PUIWQ60Q"
cat > "$FIXTURE/pkg/items-2A3B4C5D6E7F8091/default.php" <<'PHP'
<?php
return new \Ema\Config\PackageConfig();
PHP
touch "$FIXTURE/pkg/items-2A3B4C5D6E7F8091/upgrade.sql"
cat > "$FIXTURE/srv/test-D01B9X95PUIWQ60Q/default.php" <<'PHP'
<?php
return new \Ema\Config\DatabaseConfig(
    dbname: 'test',
    dependencies: ['items-2A3B4C5D6E7F8091'],
);
PHP
touch "$FIXTURE/srv/test-D01B9X95PUIWQ60Q/upgrade.sql"

cd "$FIXTURE"

# --- first revision -----------------------------------------------------------
"$EMA" revision pkg/items-2A3B4C5D6E7F8091 >/dev/null

rev1="$(find rev -mindepth 1 -maxdepth 1 -type d -printf '%f\n' | head -1)"
[[ -n "$rev1" ]] || fail "no revision dir created"
[[ "$rev1" =~ ^[0-9]{4}-[0-9]{2}-[0-9]{2}-items-R0[A-Z0-9]{14}$ ]] || fail "unexpected rev dir name: '$rev1'"

# Generated default.php is a bare RevisionConfig with single-backslash namespace.
diff -u - "$FIXTURE/rev/$rev1/default.php" <<'EOF' || fail "rev1 default.php content wrong"
<?php
return new \Ema\Config\RevisionConfig();
EOF
php -l "$FIXTURE/rev/$rev1/default.php" >/dev/null || fail "rev1 default.php is not valid PHP"

# Package references only the new head.
grep -Fq "dependencies: ['$rev1']" pkg/items-2A3B4C5D6E7F8091/default.php \
    || fail "package does not reference head '$rev1'"

# --- second revision (head replacement + chaining) ---------------------------
"$EMA" revision pkg/items-2A3B4C5D6E7F8091 >/dev/null

rev2="$(find rev -mindepth 1 -maxdepth 1 -type d -printf '%f\n' | grep -vx "$rev1" | head -1)"
[[ -n "$rev2" && "$rev2" != "$rev1" ]] || fail "second revision did not create a new dir"

# Package keeps only the newest head: new head present, old head removed.
grep -Fq "dependencies: ['$rev2']" pkg/items-2A3B4C5D6E7F8091/default.php \
    || fail "package does not reference new head '$rev2'"
grep -Fq "'$rev1'" pkg/items-2A3B4C5D6E7F8091/default.php \
    && fail "package still references old head '$rev1'"

# rev2 chains to rev1 (immediately-prior revision dependency).
grep -Fq "dependencies: ['$rev1']" "rev/$rev2/default.php" \
    || fail "rev2 does not chain to rev1"

# --- topo order (dependencies, not date) -------------------------------------
order="$(php -r "
require '$REPO_ROOT/src/sort_schemas.php';
echo implode(PHP_EOL, build_revision_graph_roots(['items-2A3B4C5D6E7F8091'])->topological_sort());
")"
[[ "$order" == "$rev1"$'\n'"$rev2" ]] || fail "unexpected topo order: '$order'"

# Revision refs must never surface in the schema topo sort.
schema_order="$(php -r "
require '$REPO_ROOT/src/sort_schemas.php';
echo implode(PHP_EOL, build_dependency_graph('items-2A3B4C5D6E7F8091')->topological_sort());
")"
[[ "$schema_order" == "items-2A3B4C5D6E7F8091" ]] || fail "schema topo leaked revision refs: '$schema_order'"

echo "PASS: revision scaffold + wiring + ordering"
