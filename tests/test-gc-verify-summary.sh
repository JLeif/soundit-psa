#!/usr/bin/env bash
# Control suite for assert_no_warnings() in scripts/gc-verify.sh (GitHub #2532/#2533).
#
# DISCIPLINE: this suite EXTRACTS AND EXECUTES the shipped function. It does not
# re-implement the parsing it tests — a suite that restates the logic agrees with
# itself by construction and measures nothing.
#
#   ./tests/test-gc-verify-summary.sh            run the cases
#   ./tests/test-gc-verify-summary.sh --selftest prove the harness can FAIL
#
# The --selftest mode mutates the extracted function (warnings are never counted)
# and asserts that the suite then reports failures. A green suite is only evidence
# if it is capable of going red.
set -uo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "$HERE/.." && pwd)"
GATE="$ROOT/scripts/gc-verify.sh"
CASES="$HERE/gc-verify-summary-cases.txt"

[ -r "$GATE" ]  || { echo "FATAL: cannot read $GATE"; exit 2; }
[ -r "$CASES" ] || { echo "FATAL: cannot read $CASES"; exit 2; }

WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

# Extract assert_no_warnings() verbatim: from its definition line to the closing
# brace at column 0. Fail loudly rather than silently testing nothing.
extract() {
    awk '/^assert_no_warnings\(\) \{/{f=1} f{print} f&&/^\}/{exit}' "$GATE" > "$WORK/fn.sh"
    if ! grep -q '^assert_no_warnings() {' "$WORK/fn.sh" || ! grep -q '^}' "$WORK/fn.sh"; then
        echo "FATAL: could not extract assert_no_warnings() from $GATE"; exit 2
    fi
    # Sanity: the body must be substantial. A one-line extract means the awk
    # range broke against a refactor and every case below would be vacuous.
    local n; n="$(wc -l < "$WORK/fn.sh")"
    [ "$n" -ge 20 ] || { echo "FATAL: extracted body is only $n lines; refusing to test a stub"; exit 2; }
}

MUTATE=0
[ "${1:-}" = "--selftest" ] && MUTATE=1

extract
if [ "$MUTATE" = 1 ]; then
    before="$(sha256sum "$WORK/fn.sh" | cut -d' ' -f1)"
    sed -i -E 's/warnings=\$\(\(warnings \+ count\)\)/warnings=0/' "$WORK/fn.sh"
    after="$(sha256sum "$WORK/fn.sh" | cut -d' ' -f1)"
    if [ "$before" = "$after" ]; then
        echo "FATAL: selftest mutation changed nothing — the target moved; refusing to claim a result"
        exit 2
    fi
    echo "selftest: mutated extracted function (warnings never counted)"
fi

# shellcheck disable=SC1090
source "$WORK/fn.sh"

pass=0; fail=0
while IFS='|' read -r expect summary; do
    case "$expect" in ''|'#'*) continue ;; esac
    printf '%s\n' "$summary" > "$WORK/log"
    out="$(assert_no_warnings "$WORK/log" 2>&1)"; rc=$?
    actual=PASS; [ "$rc" -ne 0 ] && actual=FAIL
    if [ "$actual" = "$expect" ]; then
        pass=$((pass+1))
        printf '  ok   %-4s %s\n' "$expect" "$summary"
    else
        fail=$((fail+1))
        printf '  FAIL want=%s got=%s  %s\n' "$expect" "$actual" "$summary"
        printf '       %s\n' "$(printf '%s' "$out" | head -2 | tr '\n' ' ')"
    fi
done < "$CASES"

echo
echo "cases: $pass passed, $fail failed"
if [ "$MUTATE" = 1 ]; then
    if [ "$fail" -gt 0 ]; then
        echo "SELFTEST OK: the mutated function is caught by $fail case(s) — this suite can fail."
        exit 0
    fi
    echo "SELFTEST FAILED: mutation survived every case. The suite proves nothing."
    exit 1
fi
[ "$fail" -eq 0 ] || exit 1
echo "gc-verify summary parsing: ALL CASES PASS"
