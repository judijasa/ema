#!/usr/bin/env bash
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
EMA="$REPO_ROOT/ema"

fail() { echo "FAIL: $1" >&2; exit 1; }

FIXTURE="$(mktemp -d /tmp/ema_test_apply_XXXXXX)"
key=""

# Source ema as a library (skip dispatch) so we can drive _apply_revisions and
# _run_root_eval against the sandbox connection directly.
EMA_SOURCE_ONLY=1 source "$EMA"

cleanup() {
    if [[ -n "$key" ]] && command -v _sandbox_stop >/dev/null 2>&1; then
        (cd "$FIXTURE" && _sandbox_stop "$key" "yes") >/dev/null 2>&1 || true
    fi
    rm -rf "$FIXTURE"
}
trap cleanup EXIT

# --- fixture ------------------------------------------------------------------
mkdir -p "$FIXTURE/pkg/items-2A3B4C5D6E7F8091" "$FIXTURE/srv/test-D01B9X95PUIWQ60Q"
cat > "$FIXTURE/pkg/items-2A3B4C5D6E7F8091/default.php" <<'PHP'
<?php
return new \Ema\Config\PackageConfig();
PHP
cat > "$FIXTURE/pkg/items-2A3B4C5D6E7F8091/upgrade.sql" <<'SQL'
CREATE TABLE IF NOT EXISTS items_table (id INT);
SQL
cat > "$FIXTURE/srv/test-D01B9X95PUIWQ60Q/default.php" <<'PHP'
<?php
return new \Ema\Config\DatabaseConfig(
    dbname: 'test',
    dependencies: ['items-2A3B4C5D6E7F8091'],
);
PHP
# The srv bootstrap creates the database the schema packages apply into; the
# sandbox build fills {{dbname}} from default.php before running it.
cat > "$FIXTURE/srv/test-D01B9X95PUIWQ60Q/upgrade.sql" <<'SQL'
CREATE DATABASE {{dbname}};
SQL

cd "$FIXTURE"

# --- scaffold two chained revisions (rev2 depends on rev1's table) ------------
"$EMA" revision pkg/items-2A3B4C5D6E7F8091 >/dev/null
rev1="$(find rev -mindepth 1 -maxdepth 1 -type d -printf '%f\n' | head -1)"
cat > "rev/$rev1/upgrade.sql" <<'SQL'
CREATE TABLE IF NOT EXISTS rev_check (n INT);
SQL

"$EMA" revision pkg/items-2A3B4C5D6E7F8091 >/dev/null
rev2="$(find rev -mindepth 1 -maxdepth 1 -type d -printf '%f\n' | grep -vx "$rev1" | head -1)"
cat > "rev/$rev2/upgrade.sql" <<'SQL'
INSERT INTO rev_check (n) VALUES (2);
SQL

# --- build the sandbox (schema-only; revisions must NOT be applied) ----------
"$EMA" sandbox srv/test-D01B9X95PUIWQ60Q -n -q >/dev/null
key="$(find var/sandbox -mindepth 1 -maxdepth 1 -type d -printf '%f\n' | head -1)"
[[ -n "$key" ]] || fail "no sandbox instance built"

_load_section "$(_sandbox_ini "$key")" "$(_instance_name "$key")"
_mariadb_args
dbname="test"

# Schema applied, revisions skipped.
[[ "$(_run_root_eval "$dbname" "SHOW TABLES LIKE 'items_table';")" == "items_table" ]] \
    || fail "sandbox build did not apply the schema"
[[ -z "$(_run_root_eval "$dbname" "SHOW TABLES LIKE 'ema_revision';")" ]] \
    || fail "sandbox build created ema_revision"
[[ -z "$(_run_root_eval "$dbname" "SHOW TABLES LIKE 'rev_check';")" ]] \
    || fail "sandbox build applied revision SQL"

# --- discover + apply pending revisions --------------------------------------
defs="$(_srv_definition "test-D01B9X95PUIWQ60Q")"
deps_json="$(echo "$defs" | jq -c '.dependencies')"
mapfile -t revisions < <(_revisions_for "$deps_json")
[[ ${#revisions[@]} -eq 2 ]] || fail "expected 2 revisions, got ${#revisions[@]}"
[[ "${revisions[0]}" == "$rev1" && "${revisions[1]}" == "$rev2" ]] \
    || fail "unexpected revision order: ${revisions[*]}"

apply_out="$(_apply_revisions "$dbname" "${revisions[@]}")"
echo "$apply_out" | grep -Fq "Applied $rev1" || fail "rev1 not applied (got: '$apply_out')"
echo "$apply_out" | grep -Fq "Applied $rev2" || fail "rev2 not applied (got: '$apply_out')"

# Tracking rows written; order guaranteed by rev2 succeeding against rev1's table.
[[ "$(_run_root_eval "$dbname" "SELECT COUNT(*) FROM ema_revision;")" == "2" ]] \
    || fail "ema_revision row count != 2"
[[ "$(_run_root_eval "$dbname" "SELECT n FROM rev_check;")" == "2" ]] \
    || fail "rev_check value wrong (rev2 ran out of order or not at all)"

# --- apply-once idempotency ---------------------------------------------------
apply2="$(_apply_revisions "$dbname" "${revisions[@]}")"
[[ -z "$apply2" ]] || fail "second apply was not a no-op: '$apply2'"
[[ "$(_run_root_eval "$dbname" "SELECT COUNT(*) FROM ema_revision;")" == "2" ]] \
    || fail "ema_revision row count changed on re-run"

echo "PASS: revision apply (schema-skip, ordering, tracking, idempotency)"
