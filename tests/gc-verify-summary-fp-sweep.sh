#!/usr/bin/env bash
#
# False-positive sweep for the PHPUnit summary parser in
# scripts/lib/gc-verify-summary.sh.
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
# WHAT IT DOES. Sources the parser from two revisions of the library over the
# same corpus of real summary lines and reports every line whose verdict CHANGED.
# A regression -- a line the old library accepted and the new one refuses -- is a
# nonzero exit.
#
# Usage:
#   tests/gc-verify-summary-fp-sweep.sh <baseline-library> [corpus-file]
#   tests/gc-verify-summary-fp-sweep.sh --selftest
#
#   baseline-library  a checkout of scripts/lib/gc-verify-summary.sh to compare
#                     against, e.g.
#                       git show origin/main:scripts/lib/gc-verify-summary.sh > /tmp/base.sh
#   corpus-file       one summary line per line. Defaults to the committed corpus
#                     beside this script, which is what makes the run reproducible
#                     off this box.
#   --selftest        prove this sweep can FAIL: compare the shipped library
#                     against a deliberately mutated copy of itself and require a
#                     nonzero exit. A green sweep that cannot go red is not
#                     evidence.
#
# THE BASELINE IS SOURCED, SO IT MUST BE ONE YOU WOULD RUN. Both arguments are
# shell files this sweep loads into a subshell in order to call the real parser;
# that is the point of the instrument (a sweep that re-implements the parser
# measures the re-implementation). Point it at a checkout of this repo's library,
# not at an untrusted file. This is the same trust requirement the extraction-era
# version carried and could not enforce either -- the difference is that it no
# longer pretends a lexical guard bounds it.
#
# WHAT ROUNDS 2-5 CORRECTED HERE, and each correction was right:
#   * The corpus carried ZERO metric-bearing lines, so this sweep could not
#     observe the only branch the change adds. "changed=0" was TRUE BY
#     CONSTRUCTION. Metric lines are now in the corpus, including the hours-form
#     duration whose rejection was that round's must-fix.
#   * A WHOLLY BLIND run (parser not found) exited 0 as a clean sweep. It is now
#     fatal, exit 2.
#   * The exit ignored the loosening direction (REFUSE -> ACCEPT), which is the
#     only direction a skip branch can move. Loosenings are now counted, reported
#     and must be declared.
#   * The fatal guards lived inside verdict(), which is only ever called as
#     `o="$(verdict ...)"`, so `exit 2` killed the SUBSHELL and the loop ran on
#     with EMPTY verdicts that compared equal. Guards now run in this script's own
#     shell, and the loop refuses a verdict that is neither ACCEPT nor REFUSE.
#   * A --selftest arm asserted `rc -eq 2`, but 2 is also this script's code for a
#     missing input, so the arm passed without reaching the guard it was testing.
#     Arms now pin the REASON and run a positive control first.
#
# WHAT THE LIBRARY LIFT CHANGED, and why the guard family below it is GONE
# (Jeeves's ruling on Trello card 6aadcd16, 2026-09-18; GitHub #2650/#2655/#2665).
# Every round above was a lexical guard on an extraction that should never have
# existed. This sweep used to recover the parser with
# `sed -n '/^assert_no_warnings()/,/^}$/p'` and `eval` the recovered text. That
# range is bounded by where it ENDS, not by what it CONTAINS: an indented or
# absent closing brace let it swallow the following statements, and `eval` ran
# them. Three guards -- a column-0 interior test, a >=20-line floor, an awk
# brace-depth count -- each failed in the case they were added for, because
# counting braces lexically is not parsing shell. `bash -n` does not close it
# either: measured against the exact escape, it PASSES, because a clean overrun is
# valid script by construction.
#
# The parser now lives in a file that defines a function and executes nothing, so
# this sweep SOURCES it. extract_fn(), fn_body_overruns() and require_extractable()
# are deleted along with the eval -- there is no text to mis-extract, nothing to
# validate, and no smuggling route to guard. What remains is a check that the
# sourced file actually defined the function, because a silently blind sweep is
# still the worst outcome available here.
set -uo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
NEW="$HERE/../scripts/lib/gc-verify-summary.sh"

# --selftest: prove this instrument can FAIL. Compare the shipped library against a
# mutated copy of itself in which the metric skip branch is disabled, so every
# metric-bearing corpus line flips REFUSE -> ACCEPT. That must be reported as a
# loosening and must exit nonzero. Run BEFORE trusting any green sweep.
if [ "${1:-}" = "--selftest" ]; then
    [ -r "$NEW" ] || { echo "no library at $NEW" >&2; exit 2; }
    mutant="$(mktemp)"; blind="$(mktemp)"; trap 'rm -f "$mutant" "$blind"' EXIT
    # Faithful disabling of exactly the branch under review: make its label test
    # unmatchable. The mutation must LAND -- a harness that silently fails to
    # mutate reports a cheerful pass while measuring nothing.
    sed 's/\^(Duration|Time|Memory)/^(ZZZNEVERZZZ)/' "$NEW" > "$mutant"
    if cmp -s "$NEW" "$mutant"; then
        echo "SELFTEST FATAL: mutation did not land; the harness is not testing what it claims" >&2
        exit 2
    fi
    echo "selftest: comparing shipped library against a metric-branch-disabled mutant"
    if "$0" "$mutant" "${2:-$HERE/gc-verify-summary-corpus.txt}" >/dev/null 2>&1; then
        echo "SELFTEST FAILED: sweep exited 0 against a mutant that disables the branch under review" >&2
        exit 1
    fi
    echo "selftest: the sweep goes red when the branch under review is disabled"

    # ARM 2. A library that does not define the parser must be FATAL, not a clean
    # sweep over empty verdicts. This is the blind-run guard, and it is the one
    # piece of the old extraction-era guard family that survives the lift --
    # restated against what can still go wrong. Sourcing cannot mis-extract, but
    # it CAN load a file that defines nothing (a renamed function, a truncated
    # checkout, a path pointing at the wrong file), and a sweep that measures
    # nothing while printing changed=0 is worse than no sweep.
    #
    # ROUND 4's LESSON IS KEPT: the arm pins the REASON, not just the exit code,
    # because 2 is also this script's code for a missing baseline or corpus. A
    # positive control proves the inputs were readable first, so a later exit 2
    # can only be this guard.
    sed 's/^assert_no_warnings()/assert_no_warnings_renamed()/' "$NEW" > "$blind"
    if cmp -s "$NEW" "$blind"; then
        echo "SELFTEST FATAL: blind mutation did not land; the harness is not testing what it claims" >&2
        exit 2
    fi
    selftest_corpus="${2:-$HERE/gc-verify-summary-corpus.txt}"
    # Positive control: the corpus and baseline must be usable, so that a later
    # exit 2 can only be the definition guard and never a missing input.
    if ! "$0" "$NEW" "$selftest_corpus" >/dev/null 2>&1; then
        echo "SELFTEST FATAL: the unmutated library does not sweep cleanly over $selftest_corpus;" >&2
        echo "  arm 2 cannot distinguish a load failure from a broken input." >&2
        exit 2
    fi
    blind_err="$(mktemp)"; trap 'rm -f "$mutant" "$blind" "$blind_err"' EXIT
    "$0" "$blind" "$selftest_corpus" >/dev/null 2>"$blind_err"
    rc=$?
    if [ "$rc" -ne 2 ]; then
        echo "SELFTEST FAILED: sweep exited $rc (want 2) against a library that does not define the parser" >&2
        exit 1
    fi
    # The code alone is not the assertion: require the definition diagnostic.
    if ! grep -q 'did not define assert_no_warnings' "$blind_err"; then
        echo "SELFTEST FAILED: sweep exited 2 but not for the definition guard; stderr was:" >&2
        sed 's/^/    /' "$blind_err" >&2
        exit 1
    fi
    echo "selftest: fatal, and for the stated reason, when the library does not define the parser"

    # ARM 3. THE DEFECT THIS LIFT EXISTS TO REMOVE, pinned as a REGRESSION TEST.
    #
    # The old sweep recovered the parser by sed range and eval'd the text. A
    # library whose function closes on an INDENTED brace, with statements after
    # it, was swallowed whole and EXECUTED -- see this file's header. The fixtures
    # below are the exact two shapes that defeated the column-0 guard (A smuggles
    # at column 0 after an indented brace; B indents the smuggled line too, so a
    # column-0 test sees nothing).
    #
    # Sourcing a file RUNS ITS TOP LEVEL, and this arm does not pretend
    # otherwise: a statement written at top level in a baseline you pass WILL
    # execute, exactly as it would if you ran the file. That is the sweep's
    # stated contract, not a hole in it.
    #
    # THERE IS NO EXECUTION MARKER IN THIS ARM, and that is deliberate: no marker
    # placement could discriminate, so any marker assertion here would be a
    # control incapable of firing. Sourcing runs the WHOLE top level, while the
    # extraction era eval'd only a sed range cut out of the same file -- so
    # sourcing executes a SUPERSET of whatever the old eval could run. A marker at
    # top level therefore fires under both and would make a correct sweep look
    # red; a marker inside the trailing function body fires under neither, because
    # a range ending at that function's column-0 brace hands eval a complete
    # DEFINITION and nothing ever calls it. Round 1 asserted on a marker no
    # fixture planted; asserting on one planted inside a function body would have
    # been unfireable for a new reason rather than a repair.
    #
    # The difference that IS fireable is the VERDICT, and that is what this arm
    # asserts: the extraction-era guards REFUSED this shape outright, so a
    # regression to them turns the rc=0 assertion below red.
    #
    # What is therefore pinned here is the DIFFERENCE: a well-formed library whose
    # brace is indented is now used CORRECTLY (the function is defined and swept,
    # no marker, no diagnostic) where the old code refused it as an "overrun". The
    # indented-brace shape was never actually dangerous -- bash closes a function
    # at an indented `}` exactly as at a column-0 one -- and refusing it was
    # GitHub #2655's false-FAIL class, which this lift deletes.
    indented="$(mktemp)"; ind_err="$(mktemp)"
    trap 'rm -f "$mutant" "$blind" "$blind_err" "$indented" "$ind_err"' EXIT
    {
        # A faithful copy of the real library whose closing brace is INDENTED and
        # which defines a second function afterwards: valid shell, valid parser,
        # refused outright by every extraction-era guard. The trailing definition
        # is what made the shape refusable -- a range bounded by where it ends ran
        # past the indented brace and swallowed it -- so it stays, empty of
        # anything that pretends to be an execution probe.
        sed '$ s/^}$/  }/' "$NEW"
        echo 'other_function() {'
        echo '    :'
        echo '}'
    } > "$indented"
    # EVERY property of the fixture must be proven to have landed, or the
    # assertion below measures nothing. Round 1 asserted on a marker no fixture
    # ever planted, so that branch could never fire -- the review caught it and it
    # was a fair catch. The repair is to assert only what a regression can
    # actually turn red.
    if cmp -s "$NEW" "$indented"; then
        echo "SELFTEST FATAL: indented-brace fixture did not land; arm 3 measures nothing" >&2
        exit 2
    fi
    if ! grep -q "^  }$" "$indented"; then
        echo "SELFTEST FATAL: the fixture's closing brace is not indented; arm 3 is not testing #2655" >&2
        exit 2
    fi
    if ! grep -q '^other_function() {$' "$indented"; then
        echo "SELFTEST FATAL: the fixture carries no definition after the indented brace;" >&2
        echo "  arm 3 is not testing the shape the extraction-era guards refused" >&2
        exit 2
    fi
    if ! bash -n "$indented" 2>/dev/null; then
        echo "SELFTEST FATAL: the indented-brace fixture is not valid shell; it is unfaithful" >&2
        exit 2
    fi
    "$0" "$indented" "$selftest_corpus" >/dev/null 2>"$ind_err"
    rc=$?
    if [ "$rc" -ne 0 ]; then
        echo "SELFTEST FAILED: a valid library with an indented closing brace was refused (rc=$rc)." >&2
        echo "  That is the #2655 false-FAIL class this lift removes. stderr was:" >&2
        sed 's/^/    /' "$ind_err" >&2
        exit 1
    fi
    echo "selftest: a valid library whose closing brace is indented now sweeps cleanly (#2655 false-FAIL class removed)"

    echo "selftest PASSED: red when the branch under review is disabled, fatal (and for the stated reason) when the library does not define the parser, and no longer refusing a legitimate indented-brace definition. The baseline is SOURCED by design and must be a file you would run."
    exit 0
fi

BASE="${1:?usage: $0 <baseline-library> [corpus-file] | $0 --selftest}"
CORPUS="${2:-$HERE/gc-verify-summary-corpus.txt}"

[ -r "$NEW" ]    || { echo "no library at $NEW" >&2; exit 2; }
[ -r "$BASE" ]   || { echo "no baseline at $BASE" >&2; exit 2; }
[ -r "$CORPUS" ] || { echo "no corpus at $CORPUS" >&2; exit 2; }

# A library that loads but defines nothing is BLIND, never a verdict: the whole
# point is to execute the real function, and a sweep that cannot find it measures
# nothing.
#
# THE PLACEMENT IS LOAD-BEARING, and that lesson is inherited unchanged from the
# extraction era: this check runs in THIS script's shell, so `exit 2` is this
# script's exit. The guards it replaces once sat inside a function called as
# `$( )`, where `exit 2` ended only the command-substitution subshell and the loop
# carried on with empty verdicts that compared equal. Never place an `exit` that
# must stop this sweep inside a function run in `$( )`.
require_defines_parser() {
    local lib="$1"
    # A TYPO-CATCHER, AND ONLY THAT. The old usage took a checkout of
    # scripts/gc-verify.sh, and that argument is what everyone's shell history
    # still holds. Sourcing the GATE executes it -- .env provisioning and ~7800
    # PHPUnit tests -- so the predictable mistake is expensive and silent rather
    # than wrong-looking.
    #
    # ROUND 1 GOT THIS WRONG AND THE REVIEW CAUGHT IT. The check tested the
    # BASENAME against "gc-verify.sh", but the mistake it names is a REDIRECT:
    # `git show origin/main:scripts/gc-verify.sh > /tmp/base.sh`. The basename is
    # then base.sh, so the guard did not fire on the exact command in its own
    # documentation -- measured, not theorised. A typo-catcher that misses the
    # documented typo is worse than none, because it implies a coverage it has
    # not got. It now recognises the gate by a line the GATE ITSELF prints, which
    # survives being redirected to any filename.
    #
    # STILL NOT A BOUNDARY, and the wording stays deliberate. Any other file is
    # sourced as given, because sourcing IS how this sweep runs the real parser
    # and the baseline must be a file you would run. Three rounds of lexical
    # guards on the old extraction each claimed a bound they did not have; this
    # one claims only to recognise one known file by one known string, and a
    # renamed or edited gate defeats it trivially. That is acceptable for a typo,
    # and would not be for a boundary -- which is why it is not called one.
    if [ "$(basename "$lib")" = "gc-verify.sh" ] || grep -q '^echo "==> \[1/3\] php artisan test' "$lib"; then
        echo "FATAL: $lib looks like the GATE, not the parser library." >&2
        echo "  Sourcing it would RUN the gate. The parser lives in" >&2
        echo "  scripts/lib/gc-verify-summary.sh; pass a checkout of that instead, e.g." >&2
        echo "    git show HEAD:scripts/lib/gc-verify-summary.sh > /tmp/base.sh" >&2
        echo "  (the library exists only at refs where this change has landed; before" >&2
        echo "  that, the comparable baseline is the inline parser in the gate at that" >&2
        echo "  ref, which is why this sweep's own gate run cuts it by line range.)" >&2
        exit 2
    fi
    # Load in a SUBSHELL for the check so this script's own shell keeps whatever
    # parser it has: the two libraries under comparison define the same name, and
    # loading either one here would decide the sweep's answer for both.
    if ! ( . "$lib" >/dev/null 2>&1; declare -F assert_no_warnings >/dev/null ); then
        echo "FATAL: $lib did not define assert_no_warnings (or failed to load); refusing a blind sweep" >&2
        exit 2
    fi
}

require_defines_parser "$BASE"
require_defines_parser "$NEW"

# Call one library's parser with one summary line. Executing the REAL function,
# never a re-implementation: a sweep that re-implements the parser measures the
# re-implementation. The subshell is what lets the two libraries -- which define
# the SAME function name -- be compared in one run without either overwriting the
# other, and it is why nothing here needs to escape it.
verdict() {
    local lib="$1" summary="$2"
    (
        . "$lib"
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
# Loosenings are legitimate for a change that deliberately widens the skip branch,
# but must be DECLARED, not discovered. Set the expected count.
if [ "$loosenings" -ne "${EXPECT_LOOSENINGS:-0}" ]; then
    echo "unexpected loosenings: got $loosenings, expected ${EXPECT_LOOSENINGS:-0} (set EXPECT_LOOSENINGS to declare)" >&2
    exit 1
fi
exit 0
