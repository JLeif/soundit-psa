#!/usr/bin/env bash
# Control suite for assert_no_warnings() in scripts/lib/gc-verify-summary.sh
# (GitHub #2532/#2533/#2650).
#
# DISCIPLINE: this suite SOURCES AND EXECUTES the shipped function. It does not
# re-implement the parsing it tests — a suite that restates the logic agrees with
# itself by construction and measures nothing.
#
# IT USED TO EXTRACT THE FUNCTION BY awk RANGE, which is the defect class Jeeves
# ruled out on Trello card 6aadcd16 (2026-09-18): a range bounded by where it ends
# says nothing about what it contains, so an indented or missing closing brace let
# the sibling harness swallow and `eval` the statements that followed. The parser
# now lives in a sourceable library that defines a function and executes nothing,
# so both harnesses load it. The extraction, its >=20-line floor and its
# brace-termination assertion are gone; what replaces them is a check that the
# file actually defined the function, because a suite that silently tests nothing
# is the outcome all of those guards existed to prevent.
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
LIB="$ROOT/scripts/lib/gc-verify-summary.sh"
CASES="$HERE/gc-verify-summary-cases.txt"

[ -r "$LIB" ]   || { echo "FATAL: cannot read $LIB"; exit 2; }
[ -r "$CASES" ] || { echo "FATAL: cannot read $CASES"; exit 2; }

WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

# Take the library as-is. Copying rather than sourcing the original in place is
# what lets --selftest mutate it without touching the repo file.
cp "$LIB" "$WORK/fn.sh"

# THE LIBRARY MUST BE SIDE-EFFECT FREE, and this is the control that pins the
# contract its header states. Both consumers source it; if it ever grows a
# top-level command, sourcing it inside the gate would run that command mid-gate
# and sourcing it here would run it mid-suite. Measured, not assumed: load it in a
# subshell with `set -x`-free tracing off and assert it produced NO output and
# left the shell able to see exactly the one new function.
side_effect_out="$( . "$LIB" 2>&1 )"
if [ -n "$side_effect_out" ]; then
    echo "FATAL: sourcing $LIB produced output, so it is not side-effect free:"
    printf '%s\n' "$side_effect_out" | sed 's/^/    /'
    exit 2
fi
if ! ( . "$LIB" >/dev/null 2>&1; declare -F assert_no_warnings >/dev/null ); then
    echo "FATAL: $LIB did not define assert_no_warnings; refusing to test nothing"; exit 2
fi

MUTATE=0
[ "${1:-}" = "--selftest" ] && MUTATE=1

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
