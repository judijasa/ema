#!/usr/bin/env bash
set -euo pipefail

# Phase 3 CLI coverage for the PHP rewrite: `create --dry-run` (primary +
# replica), `values` (conf fixture), and `upgrade` discovery + revision apply
# (Sql::applyRevisions driven against a sandbox socket). No root/systemd is
# needed: the dry-run, values, and discovery paths never provision; the apply
# path uses the sandbox (like the existing revision-apply test).

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
EMA_PHP="$REPO_ROOT/bin/ema.php"

fail() { echo "FAIL: $1" >&2; exit 1; }

FIXTURE="$(mktemp -d /tmp/ema_test_phase3_XXXXXX)"
sandbox_key="test-D01B9X95PUIWQ60Q"

cleanup() {
    if [[ -d "$FIXTURE/var/sandbox/$sandbox_key" ]]; then
        (cd "$FIXTURE" && php "$EMA_PHP" stop "var/sandbox/$sandbox_key" >/dev/null 2>&1) || true
    fi
    rm -rf "$FIXTURE"
}
trap cleanup EXIT

cd "$FIXTURE"

# --- fixtures -----------------------------------------------------------------
mkdir -p pkg/demo-1F2E3D4C5B6A7980 srv/test-D01B9X95PUIWQ60Q
cat > pkg/demo-1F2E3D4C5B6A7980/default.php <<'PHP'
<?php
return new \Ema\Config\PackageConfig();
PHP
cat > pkg/demo-1F2E3D4C5B6A7980/upgrade.sql <<'SQL'
CREATE TABLE IF NOT EXISTS items_table (id INT);
SQL
cat > srv/test-D01B9X95PUIWQ60Q/default.php <<'PHP'
<?php
return new \Ema\Config\DatabaseConfig(
    dbname: 'test',
    dependencies: ['demo-1F2E3D4C5B6A7980'],
);
PHP
cat > srv/test-D01B9X95PUIWQ60Q/upgrade.sql <<'SQL'
CREATE DATABASE {{dbname}};
SQL

# --- 1. create --dry-run (primary) -------------------------------------------
out="$(php "$EMA_PHP" create srv/test-D01B9X95PUIWQ60Q --dry-run)"
echo "$out" | grep -Fq 'CREATE DATABASE test' || fail "create --dry-run missing bootstrap SQL"
echo "$out" | grep -Fq 'CREATE TABLE IF NOT EXISTS items_table' || fail "create --dry-run missing schema SQL"
echo "$out" | grep -Fq 'SET FOREIGN_KEY_CHECKS=0' || fail "create --dry-run missing FK guard"
[[ ! -d "$FIXTURE/var/sandbox" ]] || fail "create --dry-run had side effects"

# --- 2. create --dry-run (replica) -------------------------------------------
mkdir -p srv/replica-B0ABCDEF12345678 snap etc
cat > srv/replica-B0ABCDEF12345678/default.php <<'PHP'
<?php
return new \Ema\Config\DatabaseConfig(
    dbname: 'test',
    type: 'replica',
    replica_of: 'test',
    replica_ssl_verify_server_cert: true,
);
PHP
cat > snap/mariadb_backup_info <<'SQL'
binlog filename 'mariadb-bin.000001'
binlog position '1234'
SQL
cat > etc/reuter.ini <<'INI'
[test]
SERVER=localhost
PORT=3306
DBNAME=test
INI

replica_out="$(php "$EMA_PHP" create srv/replica-B0ABCDEF12345678 --from-snapshot snap --dry-run)"
echo "$replica_out" | grep -Fq 'Replica build (dry-run):' || fail "replica dry-run missing header"
echo "$replica_out" | grep -Fq "MASTER_HOST='localhost', MASTER_PORT=3306" || fail "replica dry-run missing MASTER endpoint"
echo "$replica_out" | grep -Fq "MASTER_LOG_FILE='mariadb-bin.000001', MASTER_LOG_POS=1234" || fail "replica dry-run missing resume coord"
echo "$replica_out" | grep -Fq 'MASTER_SSL_VERIFY_SERVER_CERT=1' || fail "replica dry-run missing ssl verify posture"

# --- 3. values ----------------------------------------------------------------
confdir="$FIXTURE/conf"
mkdir -p "$confdir/test"
cat > "$confdir/test/my.cnf" <<'CNF'
[mysqld]
port = 3307
CNF
values_out="$(EMA_PROD_CONF_DIR="$confdir" EMA_PROD_BASE="$FIXTURE/base" EMA_SERVER=localhost php "$EMA_PHP" values test)"
echo "$values_out" | grep -Fq '[test]' || fail "values missing section header"
echo "$values_out" | grep -Fq 'SERVER=localhost' || fail "values missing SERVER"
echo "$values_out" | grep -Fq 'PORT=3307' || fail "values missing PORT"
echo "$values_out" | grep -Fq 'DBNAME=test' || fail "values missing DBNAME"

# --- 4. upgrade discovery -----------------------------------------------------
upgrade_none="$(php "$EMA_PHP" upgrade srv/test-D01B9X95PUIWQ60Q)"
echo "$upgrade_none" | grep -Fq 'No pending revisions.' || fail "upgrade no-revisions message wrong"

# Scaffold one revision so the graph has a pending revision (but no prod
# instance yet -> upgrade must refuse without provisioning).
php "$EMA_PHP" revision pkg/demo-1F2E3D4C5B6A7980 >/dev/null
rev_dir="$(find rev -mindepth 1 -maxdepth 1 -type d -printf '%f\n' | head -1)"
[[ -n "$rev_dir" ]] || fail "revision scaffold produced no dir"

upgrade_err="$(php "$EMA_PHP" upgrade srv/test-D01B9X95PUIWQ60Q 2>&1 || true)"
echo "$upgrade_err" | grep -Fq "no prod instance for 'test'" || fail "upgrade missing no-instance error"

# --- 5. upgrade apply (Sql::applyRevisions against a sandbox) ----------------
cat > "rev/$rev_dir/upgrade.sql" <<'SQL'
CREATE TABLE IF NOT EXISTS rev_check (n INT);
INSERT INTO rev_check (n) VALUES (1);
SQL

# The sandbox build is schema-only (revisions skipped).
php "$EMA_PHP" sandbox srv/test-D01B9X95PUIWQ60Q -n -q >/dev/null
[[ -d "var/sandbox/$sandbox_key" ]] || fail "sandbox build did not create the instance"

apply_out="$(
    EMA_LIB="$REPO_ROOT/src" php <<'PHP'
<?php
$lib = getenv('EMA_LIB');
require "$lib/sort_schemas.php";
require "$lib/Cli/Error.php";
require "$lib/Cli/Support/Process.php";
require "$lib/Cli/Support/Connection.php";
require "$lib/Cli/Support/Sql.php";

function check($cond, $msg) {
    if (!$cond) { fwrite(STDERR, "FAIL: $msg\n"); exit(1); }
}

$sock = 'var/sandbox/test-D01B9X95PUIWQ60Q/mysql.sock';
$conn = \Ema\Cli\Support\Connection::forUnixSocket($sock);
$conn->mariadbArgs();

check($conn->databaseExists('test'), "database 'test' missing after sandbox build");

$revisions = \build_revision_graph_roots(['demo-1F2E3D4C5B6A7980'])->topological_sort();
check(count($revisions) === 1, "expected 1 revision, got " . count($revisions));

\Ema\Cli\Support\Sql::applyRevisions($conn, 'test', $revisions, false);

check(trim($conn->runRootEval('test', 'SELECT COUNT(*) FROM ema_revision;')) === '1', "ema_revision row count != 1");
check(trim($conn->runRootEval('test', 'SELECT n FROM rev_check;')) === '1', "rev_check value wrong");

// apply-once idempotency: re-running is a silent no-op.
\Ema\Cli\Support\Sql::applyRevisions($conn, 'test', $revisions, false);
check(trim($conn->runRootEval('test', 'SELECT COUNT(*) FROM ema_revision;')) === '1', "ema_revision row count changed on re-run");
PHP
)"

echo "$apply_out" | grep -Fq "Applied $rev_dir" || fail "applyRevisions printed no 'Applied' line (got: '$apply_out')"

echo "PASS: phase3 CLI (create dry-run, values, upgrade discovery + apply)"
