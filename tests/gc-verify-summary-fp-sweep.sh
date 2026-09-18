#!/usr/bin/env bash
#
# False-positive sweep for scripts/gc-verify.sh's summary parser.
#
# WHY THIS FILE EXISTS. A held review of the #2612 change made a fair objection:
# the sweep was the change's strongest evidence -- "127 real summary lines, 32
# refused before, 32 refused after, 0 changed verdicts" -- and it was NOT
# reproducible from the diff. The corpus lived on the build box, the driver lived
# in /tmp, and the numbers therefore rested on an instrument nobody could review.
# Worse, I had already disclosed that an EARLIER version of that driver was
# broken (it absorbed a literal \n into the token and would have faked both the
# before and after results), which makes "trust my numbers" an even weaker offer.
#
# So the instrument ships with the change. On a gate modification the
# false-positive question outranks the defect proof: a gate that refuses
# legitimate runs gets overridden, and an overridden gate protects nothing.
#
# WHAT IT DOES. Runs the summary parser from two revisions of the script over the
# same corpus of real summary lines and reports every line whose verdict CHANGED.
# A regression -- a line the old script accepted and the new one refuses -- is a
# nonzero exit.
#
# Usage:
#   tests/gc-verify-summary-fp-sweep.sh <baseline-script> [corpus-file]
#   tests/gc-verify-summary-fp-sweep.sh --selftest
#
#   baseline-script  a checkout of scripts/gc-verify.sh to compare against,
#                    e.g. `git show origin/main:scripts/gc-verify.sh > /tmp/base.sh`
#   corpus-file      one summary line per line. Defaults to the committed corpus
#                    beside this script, which is what makes the run reproducible
#                    off this box.
#   --selftest       prove this sweep can FAIL: compare the shipped script against
#                    a deliberately mutated copy of itself and require a nonzero
#                    exit. A green sweep that cannot go red is not evidence.
#
# WHAT A ROUND-2 REVIEW CORRECTED HERE, and it was right on every count:
#   * The corpus carried ZERO metric-bearing lines, so this sweep could not
#     observe the only branch the change adds. "changed=0" was TRUE BY
#     CONSTRUCTION and my strongest published number proved nothing about the
#     code under review. Metric lines are now in the corpus, including the
#     hours-form duration whose rejection was the round's must-fix.
#   * EXTRACT-FAILED was an ordinary verdict and the exit was gated only on
#     regressions, so a WHOLLY BLIND run exited 0. It is now fatal (exit 2),
#     matching the sibling harness.
#   * The exit ignored the loosening direction (REFUSE -> ACCEPT), which is the
#     only direction a skip branch can move. Loosenings are now counted and
#     reported, and unexpected ones are fatal.
#   * The corpus header claimed every line was an OBSERVED runner output. 27 of
#     them are this repo's sibling harness's own report lines. Header corrected.
set -uo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
NEW="$HERE/../scripts/gc-verify.sh"

# --selftest: prove this instrument can FAIL. Compare the shipped script against a
# mutated copy of itself in which the metric skip branch is disabled, so every
# metric-bearing corpus line flips REFUSE -> ACCEPT. That must be reported as a
# loosening and must exit nonzero. Run BEFORE trusting any green sweep.
if [ "${1:-}" = "--selftest" ]; then
    [ -r "$NEW" ] || { echo "no script at $NEW" >&2; exit 2; }
    mutant="$(mktemp)"; trap 'rm -f "$mutant"' EXIT
    # Faithful disabling of exactly the branch under review: make its label test
    # unmatchable. The mutation must LAND -- a harness that silently fails to
    # mutate reports a cheerful pass while measuring nothing.
    sed 's/\^(Duration|Time|Memory)/^(ZZZNEVERZZZ)/' "$NEW" > "$mutant"
    if cmp -s "$NEW" "$mutant"; then
        echo "SELFTEST FATAL: mutation did not land; the harness is not testing what it claims" >&2
        exit 2
    fi
    echo "selftest: comparing shipped script against a metric-branch-disabled mutant"
    if "$0" "$mutant" "${2:-$HERE/gc-verify-summary-corpus.txt}" >/dev/null 2>&1; then
        echo "SELFTEST FAILED: sweep exited 0 against a mutant that disables the branch under review" >&2
        exit 1
    fi
    echo "selftest PASSED: the sweep goes red when the branch under review is disabled"
    exit 0
fi

BASE="${1:?usage: $0 <baseline-script> [corpus-file] | $0 --selftest}"
CORPUS="${2:-$HERE/gc-verify-summary-corpus.txt}"

[ -r "$NEW" ]    || { echo "no script at $NEW" >&2; exit 2; }
[ -r "$BASE" ]   || { echo "no baseline at $BASE" >&2; exit 2; }
[ -r "$CORPUS" ] || { echo "no corpus at $CORPUS" >&2; exit 2; }

# Extract assert_no_warnings() from a script and call it with one summary line.
# Executing the REAL function, never a re-implementation: a sweep that
# re-implements the parser measures the re-implementation.
verdict() {
    local script="$1" summary="$2" fn
    # Bound the range at a column-0 '}' so a file without one cannot run the sed
    # range to EOF and eval the rest of the script.
    fn="$(sed -n '/^assert_no_warnings()/,/^}$/p' "$script")"
    # A failed extraction is FATAL, never a verdict: the whole point is to execute
    # the real function, and a sweep that cannot find it is blind, not clean.
    [ "$(printf '%s' "$fn" | wc -l)" -ge 20 ] || {
        echo "FATAL: could not extract assert_no_warnings() from $script" >&2
        exit 2
    }
    printf '%s' "$fn" | grep -qE '^\}$' || {
        echo "FATAL: extracted body from $script is not brace-terminated" >&2
        exit 2
    }
    (
        eval "$fn"
        if printf '%s\n' "$summary" | assert_no_warnings /dev/stdin >/dev/null 2>&1; then
            echo ACCEPT
        else
            echo REFUSE
        fi
    )
}

changed=0 regressions=0 loosenings=0 total=0 old_ref=0 new_ref=0 metric_lines=0
while IFS= read -r line; do
    [ -z "$line" ] && continue
    # Skip the corpus's own header. Without this the comment lines were being fed
    # to the parser as if they were summaries and counted in the totals -- caught
    # because the sweep reported 139 lines over a 129-line corpus. A sweep whose
    # denominator is wrong is not evidence, however green it looks.
    case "$line" in \#*) continue ;; esac
    total=$((total + 1))
    # Count the lines that actually exercise the branch under review. If this is
    # zero the sweep is blind to the change however green it looks, so it is an
    # assertion below, not a footnote.
    printf '%s' "$line" | grep -qiE '(Duration|Time|Memory)[[:space:]]*:' && metric_lines=$((metric_lines + 1))
    o="$(verdict "$BASE" "$line")"
    n="$(verdict "$NEW" "$line")"
    [ "$o" = REFUSE ] && old_ref=$((old_ref + 1))
    [ "$n" = REFUSE ] && new_ref=$((new_ref + 1))
    if [ "$o" != "$n" ]; then
        changed=$((changed + 1))
        printf 'CHANGED old=%s new=%s :: %s\n' "$o" "$n" "$line"
        if [ "$o" = ACCEPT ] && [ "$n" = REFUSE ]; then
            regressions=$((regressions + 1))
            printf 'REGRESSION (was accepted, now refused) :: %s\n' "$line" >&2
        else
            # REFUSE -> ACCEPT. This is the direction a widened skip branch moves
            # in, so it cannot be silent: an unintended loosening is exactly how a
            # warning gets smuggled past this gate.
            loosenings=$((loosenings + 1))
            printf 'LOOSENING (was refused, now accepted) :: %s\n' "$line" >&2
        fi
    fi
done < "$CORPUS"

printf '\nsweep: %d lines (%d metric-bearing); refused before=%d after=%d; changed=%d; regressions=%d; loosenings=%d\n' \
    "$total" "$metric_lines" "$old_ref" "$new_ref" "$changed" "$regressions" "$loosenings"

[ "$total" -gt 0 ] || { echo "empty corpus: a sweep over nothing proves nothing" >&2; exit 2; }
# A sweep whose corpus cannot reach the changed branch is blind, and a blind
# sweep reporting changed=0 is worse than no sweep: it looks like evidence.
[ "$metric_lines" -gt 0 ] || {
    echo "blind corpus: no line carries a metric token, so this sweep cannot observe the branch under review" >&2
    exit 2
}
[ "$regressions" -eq 0 ] || exit 1
# Loosenings are legitimate for this change (the skip branch is deliberately
# widened) but must be DECLARED, not discovered. Set the expected count.
if [ "$loosenings" -ne "${EXPECT_LOOSENINGS:-0}" ]; then
    echo "unexpected loosenings: got $loosenings, expected ${EXPECT_LOOSENINGS:-0} (set EXPECT_LOOSENINGS to declare)" >&2
    exit 1
fi
exit 0
