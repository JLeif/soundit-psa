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
#
#   baseline-script  a checkout of scripts/gc-verify.sh to compare against,
#                    e.g. `git show origin/main:scripts/gc-verify.sh > /tmp/base.sh`
#   corpus-file      one summary line per line. Defaults to the committed corpus
#                    beside this script, which is what makes the run reproducible
#                    off this box.
set -uo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
NEW="$HERE/../scripts/gc-verify.sh"
BASE="${1:?usage: $0 <baseline-script> [corpus-file]}"
CORPUS="${2:-$HERE/gc-verify-summary-corpus.txt}"

[ -r "$NEW" ]    || { echo "no script at $NEW" >&2; exit 2; }
[ -r "$BASE" ]   || { echo "no baseline at $BASE" >&2; exit 2; }
[ -r "$CORPUS" ] || { echo "no corpus at $CORPUS" >&2; exit 2; }

# Extract assert_no_warnings() from a script and call it with one summary line.
# Executing the REAL function, never a re-implementation: a sweep that
# re-implements the parser measures the re-implementation.
verdict() {
    local script="$1" summary="$2" fn
    fn="$(sed -n '/^assert_no_warnings()/,/^}/p' "$script")"
    [ "$(printf '%s' "$fn" | wc -l)" -ge 20 ] || { echo "EXTRACT-FAILED"; return; }
    (
        eval "$fn"
        if printf '%s\n' "$summary" | assert_no_warnings /dev/stdin >/dev/null 2>&1; then
            echo ACCEPT
        else
            echo REFUSE
        fi
    )
}

changed=0 regressions=0 total=0 old_ref=0 new_ref=0
while IFS= read -r line; do
    [ -z "$line" ] && continue
    # Skip the corpus's own header. Without this the comment lines were being fed
    # to the parser as if they were summaries and counted in the totals -- caught
    # because the sweep reported 139 lines over a 129-line corpus. A sweep whose
    # denominator is wrong is not evidence, however green it looks.
    case "$line" in \#*) continue ;; esac
    total=$((total + 1))
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
        fi
    fi
done < "$CORPUS"

printf '\nsweep: %d lines; refused before=%d after=%d; changed=%d; regressions=%d\n' \
    "$total" "$old_ref" "$new_ref" "$changed" "$regressions"

[ "$total" -gt 0 ] || { echo "empty corpus: a sweep over nothing proves nothing" >&2; exit 2; }
[ "$regressions" -eq 0 ] || exit 1
exit 0
