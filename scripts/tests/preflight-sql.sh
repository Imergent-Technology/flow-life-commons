#!/usr/bin/env bash
# The pre-flight SQL a production operator pastes from the runbook is the file the MariaDB test ran. Run by `./flow check repo`.
#
# The statements live twice: in apps/platform/tests/Fixtures/preflight/v030-preflight.sql, which
# apps/platform/tests/Upgrade/PreflightQueriesTest.php executes verbatim against the WP3b historical fixture, and in the runbook, which is
# what an operator reads. The platform container mounts apps/platform only, so the test cannot see the runbook (the same reason
# public-surface.sh exists); this script is the only place that holds the two equal. It is deliberately about that one equality and nothing
# else: it does not parse or judge the SQL, which is the test's job.
set -Eeuo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd -P)"
SQL="$ROOT/apps/platform/tests/Fixtures/preflight/v030-preflight.sql"
DOC="$ROOT/docs/runbooks/g10-release-readiness.md"
OPEN='```sql preflight-v030'
WORK="$(mktemp -d)"
FAILURES=0
trap 'rm -rf "$WORK"' EXIT

pass() { printf '  ok    %s\n' "$1"; }
fail() {
    printf '  FAIL  %s\n' "$1" >&2
    FAILURES=$((FAILURES + 1))
}

# block FILE: print the lines of the one fenced block opened by "```sql preflight-v030", and fail unless there is exactly one, closed.
block() {
    awk -v open="$OPEN" '
        $0 == open { if (inside || seen) { exit 3 } inside = 1; seen = 1; next }
        inside && $0 == "```" { inside = 0; next }
        inside { print }
        END { if (inside || !seen) { exit 3 } }
    ' "$1"
}

[[ -f "$SQL" ]] || { fail "the tested SQL file is missing: $SQL"; exit 1; }
[[ -f "$DOC" ]] || { fail "the runbook is missing: $DOC"; exit 1; }

if block "$DOC" >"$WORK/runbook.sql"; then
    pass "the runbook holds exactly one closed preflight-v030 block"
else
    fail "the runbook must hold exactly one closed \`\`\`sql preflight-v030 block"
    exit 1
fi

if cmp -s "$WORK/runbook.sql" "$SQL"; then
    pass "the runbook's block is byte for byte the file the MariaDB test runs"
else
    fail "the runbook's block differs from $SQL; what an operator pastes is not what was tested"
    diff -u "$SQL" "$WORK/runbook.sql" | head -20 >&2 || true
fi

if grep -q $'\r' "$SQL"; then
    fail "the tested SQL file contains a carriage return"
else
    pass "the tested SQL file has plain line endings"
fi

# The comparison must be able to fail: a changed statement, an added one and a removed one are each seen.
mutant_differs() {
    local name="$1"
    shift
    "$@" <"$SQL" >"$WORK/mutant.sql"
    if cmp -s "$WORK/mutant.sql" "$WORK/runbook.sql"; then
        fail "self-check: the comparison would not notice: $name"
    else
        pass "self-check: the comparison notices: $name"
    fi
}
mutant_differs "a changed keyword" sed '0,/SELECT/s//SELEC/'
mutant_differs "an added statement" awk '{ print } END { print "SELECT 1;" }'
# shellcheck disable=SC2016 # sed's `$d` is "the last line", not a shell expansion
mutant_differs "a removed line" sed '$d'

if ((FAILURES > 0)); then
    printf '%d check(s) failed.\n' "$FAILURES" >&2
    exit 1
fi
printf 'The pre-flight SQL in the runbook is the tested file.\n'
