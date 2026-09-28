#!/usr/bin/env sh
set -euo pipefail

# Only act when a PHP source file (or the PHPStan config/composer manifest)
# is staged.
git diff --cached --name-only | grep -qE '(\.php$|^phpstan\.neon$|^composer\.json$)' || exit 0

if [ ! -x vendor/bin/phpstan ]; then
    cat >&2 <<'EOF'
phpstan not found (vendor/bin/phpstan) — run 'composer install' first.
EOF
    exit 1
fi

vendor/bin/phpstan analyse --no-progress
