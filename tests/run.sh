#!/usr/bin/env bash
set -euo pipefail

# Run the ema test suite. Each test script is self-contained (builds a throwaway
# fixture under /tmp and cleans it up); this just runs them and reports.

TESTS_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

tests=(
    test_revision_scaffold.sh
    test_revision_apply.sh
)

failed=0
for t in "${tests[@]}"; do
    echo "--- $t ---"
    if ! bash "$TESTS_DIR/$t"; then
        echo "FAILED: $t" >&2
        failed=1
    fi
done

if [[ "$failed" -ne 0 ]]; then
    echo "One or more tests failed." >&2
    exit 1
fi

echo "All tests passed."
