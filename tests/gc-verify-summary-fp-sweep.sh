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
#
# WHAT A ROUND-3 REVIEW CORRECTED, and the correction was to the round-2 fix
# immediately above. The fatal guards were real but they lived inside verdict(),
# which is only ever called as `o="$(verdict ...)"`. `exit 2` in a command
# substitution kills the SUBSHELL, not this script; with `set -uo pipefail` and
# no `-e`, and with the assignment's status never inspected, the loop ran on with
# EMPTY verdicts, every line compared equal, and a wholly blind run still printed
# changed=0 and exited 0. The documented contract was false as implemented -- the
# same shape of defect this whole round is about: an instrument that could not
# fail. The extraction and its guards now run in this script's own shell, and the
# loop refuses a verdict that is neither ACCEPT nor REFUSE.
#
# WHAT A ROUND-4 REVIEW CORRECTED, and it was again the fix above. Round 3 added
# a --selftest arm asserting the blind run exits 2 -- but 2 is ALSO this script's
# code for a missing baseline or corpus, so the arm passed without ever reaching
# the extraction guard: `--selftest /nonexistent-corpus` printed "selftest
# PASSED" and exited 0. Three review seats found it independently. The arm now
# pins the REASON (the extraction diagnostic on stderr) and runs a positive
# control proving the inputs were readable first. Asserting an exit CODE that
# several distinct failures share is not an assertion about which one happened.
#
# WHAT A ROUND-5 REVIEW CORRECTED, and it was again a guard that could not fail in
# the case it was added for. The overrun guard tested only for a COLUMN-0 statement
# inside the extracted range, but bash closes a function on an INDENTED `}` exactly
# as it does on a column-0 one, and every line after it -- also indented -- is then
# a top-level command that `eval` RUNS. One leading space (or a tab) defeated the
# guard while this file's own comments asserted the hole was shut, and arm 3
# certified only the column-0 variant the guard happened to catch. The guard is now
# brace-DEPTH based -- the depth opened by the definition line must not return to
# zero before the range's last line, whatever the indentation -- and arm 3 pins the
# indented variant beside the column-0 one, by marker file rather than exit code.
set -uo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
NEW="$HERE/../scripts/gc-verify.sh"

# --selftest: prove this instrument can FAIL. Compare the shipped script against a
# mutated copy of itself in which the metric skip branch is disabled, so every
# metric-bearing corpus line flips REFUSE -> ACCEPT. That must be reported as a
# loosening and must exit nonzero. Run BEFORE trusting any green sweep.
if [ "${1:-}" = "--selftest" ]; then
    [ -r "$NEW" ] || { echo "no script at $NEW" >&2; exit 2; }
    mutant="$(mktemp)"; blind="$(mktemp)"; trap 'rm -f "$mutant" "$blind"' EXIT
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
    echo "selftest: the sweep goes red when the branch under review is disabled"

    # ARM 2. A script whose parser cannot be extracted must be FATAL, not a clean
    # sweep over empty verdicts.
    #
    # ROUND 4 CORRECTED THIS ARM. It previously asserted only `rc -eq 2`, but 2 is
    # ALSO this script's exit code for a missing baseline and a missing corpus.
    # Measured: `--selftest /nonexistent-corpus` printed "selftest PASSED ... fatal
    # when the parser cannot be extracted" and exited 0, having never reached the
    # extraction guard at all. An arm that passes for the wrong reason certifies
    # nothing -- the very defect class this file exists to refuse, one layer up.
    # So the arm now pins the REASON as well as the code: the run must fail with
    # the extraction diagnostic, and a positive control proves the corpus that
    # produced it was readable.
    sed 's/^assert_no_warnings()/assert_no_warnings_renamed()/' "$NEW" > "$blind"
    if cmp -s "$NEW" "$blind"; then
        echo "SELFTEST FATAL: blind mutation did not land; the harness is not testing what it claims" >&2
        exit 2
    fi
    selftest_corpus="${2:-$HERE/gc-verify-summary-corpus.txt}"
    # Positive control: the corpus and baseline must be usable, so that a later
    # exit 2 can only be the extraction guard and never a missing input.
    if ! "$0" "$NEW" "$selftest_corpus" >/dev/null 2>&1; then
        echo "SELFTEST FATAL: the unmutated script does not sweep cleanly over $selftest_corpus;" >&2
        echo "  arm 2 cannot distinguish an extraction failure from a broken input." >&2
        exit 2
    fi
    blind_err="$(mktemp)"; trap 'rm -f "$mutant" "$blind" "$blind_err"' EXIT
    "$0" "$blind" "$selftest_corpus" >/dev/null 2>"$blind_err"
    rc=$?
    if [ "$rc" -ne 2 ]; then
        echo "SELFTEST FAILED: sweep exited $rc (want 2) against a script it cannot extract the parser from" >&2
        exit 1
    fi
    # The code alone is not the assertion: require the extraction diagnostic.
    if ! grep -q 'could not extract assert_no_warnings()' "$blind_err"; then
        echo "SELFTEST FAILED: sweep exited 2 but not for the extraction guard; stderr was:" >&2
        sed 's/^/    /' "$blind_err" >&2
        exit 1
    fi
    echo "selftest: fatal, and for the stated reason, when the parser cannot be extracted"

    # ARM 3. The extractor must refuse a body whose own closing brace is indented,
    # WITHOUT executing the text it swallowed. Arms 1 and 2 both prove the sweep
    # can go red; neither proves it declines to EXECUTE. That distinction is the
    # whole finding: the old guards passed on this input while `eval` ran the
    # smuggled statements.
    #
    # The control is the marker file. Asserting only the exit code would repeat
    # this round's other mistake -- the pre-fix sweep also exited nonzero here,
    # but for an unrelated reason, AFTER running the planted command.
    #
    # TWO FIXTURES, because the first version of this arm pinned only the variant
    # the first version of the guard happened to catch. Fixture A smuggles at
    # column 0. Fixture B indents EVERY line after the indented closing brace, so a
    # column-0 test sees nothing while bash still ends the function at that brace --
    # B is the one that was still EXECUTED under the column-0-only guard, and it is
    # the case the header's non-execution claim rests on.
    overrun_a="$(mktemp)"; over_err_a="$(mktemp)"; marker_a="$(mktemp -u)"
    overrun_b="$(mktemp)"; over_err_b="$(mktemp)"; marker_b="$(mktemp -u)"
    trap 'rm -f "$mutant" "$blind" "$blind_err" "$overrun_a" "$over_err_a" "$marker_a" "$overrun_b" "$over_err_b" "$marker_b"' EXIT
    {
        echo 'assert_no_warnings() {'
        echo '    local f="$1"'
        for _pad in $(seq 1 25); do echo "    : pad_$_pad"; done
        echo '    return 0'
        echo '  }'   # INDENTED brace: the sed range cannot stop here
        echo "touch '$marker_a'"
        echo 'other_function() {'
        echo '    :'
        echo '}'
    } > "$overrun_a"
    {
        echo 'assert_no_warnings() {'
        echo '    local f="$1"'
        for _pad in $(seq 1 25); do echo "    : pad_$_pad"; done
        echo '    return 0'
        echo '  }'   # INDENTED brace: bash ends the function HERE
        echo "    touch '$marker_b'"   # INDENTED too: invisible to a column-0 test
        echo '    other_function() {'
        echo '    :'
        echo '}'
    } > "$overrun_b"
    # Called from this script's own shell, never inside `$( )`, so these exits are
    # this script's exits -- the round-3 lesson, kept.
    assert_refuses_overrun() {
        local fixture="$1" errfile="$2" mark="$3" label="$4" rc
        "$0" "$fixture" "$selftest_corpus" >/dev/null 2>"$errfile"
        rc=$?
        if [ -e "$mark" ]; then
            echo "SELFTEST FAILED: the extractor EXECUTED script text beyond the function body ($label)" >&2
            echo "  (planted marker $mark was created). This is the eval-smuggling defect." >&2
            exit 1
        fi
        if [ "$rc" -ne 2 ] || ! grep -q 'ran past assert_no_warnings' "$errfile"; then
            echo "SELFTEST FAILED: overrunning body ($label) exited $rc (want 2) without the overrun diagnostic; stderr was:" >&2
            sed 's/^/    /' "$errfile" >&2
            exit 1
        fi
    }
    assert_refuses_overrun "$overrun_a" "$over_err_a" "$marker_a" "column-0 smuggling"
    assert_refuses_overrun "$overrun_b" "$over_err_b" "$marker_b" "indented smuggling"
    echo "selftest: refuses an overrunning body, column-0 AND indented, WITHOUT executing what it swallowed"

    echo "selftest PASSED: red when the branch under review is disabled, fatal (and for the stated reason) when the parser cannot be extracted, and non-executing when the extraction range overruns"
    exit 0
fi

BASE="${1:?usage: $0 <baseline-script> [corpus-file] | $0 --selftest}"
CORPUS="${2:-$HERE/gc-verify-summary-corpus.txt}"

[ -r "$NEW" ]    || { echo "no script at $NEW" >&2; exit 2; }
[ -r "$BASE" ]   || { echo "no baseline at $BASE" >&2; exit 2; }
[ -r "$CORPUS" ] || { echo "no corpus at $CORPUS" >&2; exit 2; }

# Extract assert_no_warnings() from a script. The range is bounded at a column-0
# '}' so a file without one cannot run the sed range to EOF and eval the rest of
# the script.
#
# ROUND 5 CLOSED THE HOLE THIS BOUND LEFT OPEN. Bounding the range at the FIRST
# column-0 '}' bounds where the range ENDS; it says nothing about what the range
# CONTAINS. If the function's own closing brace is indented (or absent), the range
# runs on to the NEXT column-0 '}' -- swallowing every statement in between, which
# `eval` then executes. Both prior guards passed while this happened, by
# construction: an overrun body is LONGER (so the >=20 line floor is satisfied)
# and it ends at a column-0 '}' (so the brace-termination assertion is satisfied).
# The guards could not fail in the case they were added for.
#
# MEASURED, not reasoned: a fixture whose own brace is indented caused this sweep
# to execute a planted `touch` and print arbitrary text to stderr, while exiting
# for an unrelated reason. See test_extractor_refuses_an_overrunning_body.
#
# INDENTATION IS NOT THE INVARIANT, and a round-5 review was right that an earlier
# version of this comment claimed it was. Bash ends a function at an INDENTED `}`
# exactly as it does at a column-0 one, so a crafted body that indents its own
# closing brace AND everything it wants executed satisfied every guard here while
# `eval` ran it. "No column-0 interior line" is a property of the two files checked,
# not a property that excludes execution.
#
# The invariant that actually holds is BRACE DEPTH: the depth opened by the
# definition line must not return to zero before the LAST line of the extracted
# range. Depth reaching zero earlier means the function closed there and everything
# after it is smuggled text, at column 0 or indented or tabbed. The column-0 test is
# kept beside it as a cheaper second witness of the same escape. Braces inside
# strings and comments are counted too, which can only make this REFUSE a body it
# might have accepted -- the safe direction for a guard whose alternative is `eval`.
# Verified against both revisions of the real function under review: depth first
# returns to zero on the final line, and 0 column-0 interior lines in each.
extract_fn() {
    sed -n '/^assert_no_warnings()/,/^}$/p' "$1"
}

# Return 0 if the extracted range covers anything other than this one function
# body, i.e. the sed range ran past assert_no_warnings()'s own closing brace.
# Refuses when the brace depth closes before the range's last line (an indented or
# tabbed `}` closes it just as well as a column-0 one), when it never closes, and
# -- as a second witness of the same escape -- when an interior line starts at
# column 0.
fn_body_overruns() {
    printf '%s\n' "$1" | awk '
        { line[NR] = $0
          t = $0; opens  = gsub(/\{/, "", t)
          t = $0; closes = gsub(/\}/, "", t)
          d += opens - closes
          depth[NR] = d }
        END {
            if (NR < 2) exit 0                                  # nothing to bound
            if (depth[NR] != 0) exit 0                          # never closed
            for (i = 1; i < NR; i++) if (depth[i] <= 0) exit 0   # closed early
            for (i = 2; i < NR; i++) if (line[i] !~ /^[ \t]/ && line[i] != "") exit 0
            exit 1
        }'
}

# A failed extraction is FATAL, never a verdict: the whole point is to execute the
# real function, and a sweep that cannot find it is blind, not clean.
#
# THE PLACEMENT IS THE FIX. These guards previously sat inside verdict(), which is
# only ever called as `o="$(verdict ...)"` -- so `exit 2` ended the
# command-substitution subshell and nothing else, and the loop carried on with
# empty verdicts that compared equal. A blind run therefore reported changed=0 and
# exited 0, which is precisely what the guards were added to prevent. Called from
# the script's own shell, as below, the exit is what the header claims it is.
# Never place an `exit` that must stop this sweep inside a function run in `$( )`.
require_extractable() {
    local script="$1" fn
    fn="$(extract_fn "$script")"
    [ "$(printf '%s' "$fn" | wc -l)" -ge 20 ] || {
        echo "FATAL: could not extract assert_no_warnings() from $script" >&2
        exit 2
    }
    printf '%s' "$fn" | grep -qE '^\}$' || {
        echo "FATAL: extracted body from $script is not brace-terminated" >&2
        exit 2
    }
    # The range ended at a column-0 '}' -- but is that THIS function's brace? If the
    # body's brace depth closes before the range's last line, or an interior line
    # starts at column 0, the range overran and `eval` would execute script text
    # that is not the parser. Refuse rather than execute it.
    fn_body_overruns "$fn" && {
        echo "FATAL: extracted body from $script does not close on its own last line, so the" >&2
        echo "  range ran past assert_no_warnings()'s own closing brace; refusing to eval it" >&2
        exit 2
    }
}

require_extractable "$BASE"
require_extractable "$NEW"
BASE_FN="$(extract_fn "$BASE")"
NEW_FN="$(extract_fn "$NEW")"

# Call one already-validated parser body with one summary line. Executing the REAL
# function, never a re-implementation: a sweep that re-implements the parser
# measures the re-implementation. This function has no failure of its own left to
# report, which is why nothing here needs to escape the subshell.
verdict() {
    local fn="$1" summary="$2"
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
    o="$(verdict "$BASE_FN" "$line")"
    n="$(verdict "$NEW_FN" "$line")"
    # Belt and braces on the failure mode this sweep actually had: anything that
    # is not ACCEPT or REFUSE means the verdict subshell died, and two EMPTY
    # verdicts compare EQUAL -- indistinguishable from "nothing changed". Silence
    # does not get counted as agreement. The loop reads from a redirect, not a
    # pipe, so this exit really is this script's exit.
    case "$o:$n" in
        ACCEPT:ACCEPT|ACCEPT:REFUSE|REFUSE:ACCEPT|REFUSE:REFUSE) ;;
        *)
            echo "FATAL: unreadable verdict (old='$o' new='$n') on line: $line" >&2
            exit 2 ;;
    esac
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
