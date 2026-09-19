#!/usr/bin/env bash
#
# scripts/lib/gc-verify-summary.sh — the PHPUnit summary parser, as a library.
#
# ONE DEFINITION, TWO CONSUMERS. `scripts/gc-verify.sh` sources this file and
# calls assert_no_warnings() as belt two of gate 1;
# `tests/gc-verify-summary-fp-sweep.sh` and `tests/test-gc-verify-summary.sh`
# source it to exercise the REAL parser. Nobody reconstructs the function from
# script text any more.
#
# WHY THIS FILE EXISTS (Jeeves's ruling on Trello card 6aadcd16, 2026-09-18
# 17:0x PT; GitHub #2650/#2655/#2665). Both harnesses used to recover the parser
# by regex -- `sed -n '/^assert_no_warnings()/,/^}$/p'` -- and `eval` the text
# they recovered. That range is bounded by where it ENDS, not by what it
# CONTAINS: a closing brace that is indented, or absent, lets the range swallow
# the statements that follow and `eval` then runs them. Three successive lexical
# guards (a column-0 interior test, a >=20 line floor, an awk brace-depth count)
# each failed in the case they were added for, because counting braces lexically
# is not parsing shell. `bash -n` does not close it either -- it was measured
# against the exact escape and PASSES, since a clean overrun is valid script by
# construction.
#
# Sourcing removes the class rather than guarding it: there is no extraction to
# mis-aim, no text to validate, and no eval. The file is SOURCEABLE BY
# CONSTRUCTION -- it defines a function and executes nothing at top level, which
# is the property `scripts/gc-verify.sh` does not have (it runs its three gate
# stages when read, so sourcing IT would run the whole gate).
#
# CONTRACT, relied on by all three consumers:
#   * Sourcing this file has NO side effects beyond defining assert_no_warnings.
#     It sets no variable, runs no command, and changes no shell option. A
#     control in tests/test-gc-verify-summary.sh pins that.
#   * assert_no_warnings <logfile> returns 0 when the log's PHPUnit summary is a
#     dialect this gate understands AND reports zero warnings; 1 otherwise,
#     with the reason on stderr. It reads the file only; it never exits.
#   * It uses `local` throughout and restores IFS on every return path, so a
#     caller's shell state survives the call.
#
# Everything below the header is the parser as it stood in scripts/gc-verify.sh
# at f4fa7452, moved verbatim. Its own comments carry the measured history of
# GitHub #2532 (paren unwrapping), #2612 (non-count metrics) and the allow-list
# discipline; they are the reason the gate fails closed on a format it cannot
# read, and they move with the code.
assert_no_warnings() {
    local log="$1" plain summary warnings=""
    # Strip ANSI colour before matching; take the LAST summary line.
    plain="$(sed -e 's/\x1b\[[0-9;]*[a-zA-Z]//g' "$log")"
    summary="$(printf '%s\n' "$plain" \
        | grep -E '^[[:space:]]*Tests:[[:space:]]' | tail -n 1)"
    if [ -z "$summary" ]; then
        # A fully clean PHPUnit TextUI run prints "OK (n tests, m assertions)"
        # INSTEAD of a `Tests:` counts line (SummaryPrinter returns early), so
        # that line is itself proof of zero warnings. The "OK, but ..." variants
        # do print a `Tests:` line and are handled by the dialects below.
        if printf '%s\n' "$plain" \
            | grep -qE '^[[:space:]]*OK \([0-9]+ tests?, [0-9]+ assertions?\)[[:space:]]*$'; then
            echo "    summary parsed: warnings=0 (clean TextUI run)"
            return 0
        fi
        echo "ERROR: no PHPUnit summary line found; cannot prove warnings == 0." >&2
        return 1
    fi
    # Every count token on the line must be one this gate UNDERSTANDS. An
    # allow-list, not a catch-all: if a runner renames or adds a token (say
    # `7286 warned` or `Warnings(!): 7286`), the gate does not get to assume it
    # meant zero warnings. It says so and FAILs. That is the whole point of the
    # exercise — a parser that shrugs at what it cannot read is how a 7286-
    # warning floor passed as PASS in the first place.
    local body token label count
    # Drop the `Tests:` label, then UNWRAP any parenthetical rather than deleting
    # it. Deleting it was GitHub #2532: `Tests: 467 passed (7286 warnings, 48511
    # assertions)` had its warnings count removed before the allow-list below
    # ever saw it, and the gate then "proved" warnings == 0 from the absence it
    # had just manufactured. The counts inside the parens are counts like any
    # other, so they go through the same allow-list: a warnings count fails the
    # gate wherever it appears, and a token this gate cannot read fails closed
    # wherever it appears. Parens become commas so the existing IFS split sees
    # each token. Unwrapping never empties `body` though: `()` collapses to
    # `,,`, not to nothing, so the `-z "$body"` check below can no longer be
    # what catches a line carrying no readable counts. Two explicit guards take
    # that weight instead — the parens must balance, so a clipped `Tests: 467
    # passed (` is refused as the unreadable line it is, and the loop must end
    # having recognised at least one count. Without them a truncated log would
    # be "proved" to have zero warnings from an absence: #2532 by another route.
    local seen=0 opens closes
    opens="$(printf '%s' "$summary" | tr -cd '(' | wc -c | tr -d '[:space:]')"
    closes="$(printf '%s' "$summary" | tr -cd ')' | wc -c | tr -d '[:space:]')"
    if [ "$opens" != "$closes" ]; then
        echo "ERROR: unbalanced parentheses in PHPUnit summary; failing closed." >&2
        echo "       summary: $summary" >&2
        return 1
    fi
    body="$(printf '%s' "$summary" | sed -E 's/^[[:space:]]*Tests:[[:space:]]*//; s/[()]/,/g; s/[.[:space:]]*$//')"
    if [ -z "$body" ]; then
        echo "ERROR: PHPUnit summary line carries no counts; failing closed." >&2
        echo "       summary: $summary" >&2
        return 1
    fi
    warnings=0
    local old_ifs="$IFS"
    IFS=','
    for token in $body; do
        IFS="$old_ifs"
        token="$(printf '%s' "$token" | sed -E 's/^[[:space:]]+//; s/[[:space:]]+$//')"
        [ -z "$token" ] && { IFS=','; continue; }
        # Any token still here is recognised below, fails closed below, or is a
        # NON-COUNT METRIC -- and that third outcome gives this increment back
        # (see the metric branch's `seen=$((seen - 1))`), because a measurement
        # is not evidence that a count was read. For every other token, counting
        # here is the "at least one count was read" proof.
        seen=$((seen + 1))
        if printf '%s' "$token" | grep -qE '^[0-9]+$'; then
            # Bare count: PHPUnit TextUI's leading test total ("Tests: 21, ...").
            IFS=','; continue
        elif printf '%s' "$token" | grep -qE '^[0-9]+[[:space:]]+[A-Za-z][A-Za-z[:space:]]*$'; then
            # Laravel dialect: "7286 warnings".
            count="$(printf '%s' "$token" | sed -E 's/^([0-9]+).*/\1/')"
            label="$(printf '%s' "$token" | sed -E 's/^[0-9]+[[:space:]]+//')"
        elif printf '%s' "$token" | grep -qiE '^(Duration|Time|Memory)[[:space:]]*:[[:space:]]*([0-9]+|[0-9]+\.[0-9]+|[0-9]+:[0-9]{2}(:[0-9]{2})?(\.[0-9]+)?)[[:space:]]*(s|ms|us|sec|secs|seconds|m|min|byte|bytes|b|kb|mb|gb)?$'; then
            # NON-COUNT METRIC (GitHub #2612), deliberately its own branch.
            #
            # ORDER IS LOAD-BEARING: this must be tested BEFORE the generic
            # `Label: count` branch below. `Duration: 1` (an integer-valued
            # metric) also matches that generic pattern, so if the generic
            # branch ran first it would claim the token and then die on the
            # label allow-list -- which is exactly how this defect presented in
            # two different forms, and why fixing only the decimal shape would
            # have left the integer shape still failing.
            #
            # Pest prints `Duration: 0.66s` and PHPUnit TextUI prints `Time: 0.66`
            # / `Memory: 24.00 MB`. These are MEASUREMENTS, not counts of test
            # outcomes, so they carry no warning information and there is nothing
            # to add to `warnings`. They were previously refused: with a decimal
            # value the token matched no pattern at all ("unrecognised token");
            # with an integer value it parsed as the `Label: count` dialect and
            # then died on the label allow-list ("unknown count 'duration'"). Both
            # refusals were correct-by-design fail-closed behaviour on a token the
            # gate could not read, and the gate only ever saw them when the metric
            # shared the `Tests:` line -- on its own line it is never parsed.
            #
            # This branch is kept SEPARATE from the known-count allow-list below
            # so that "a measurement I skip" and "a count I understand and do not
            # fail on" remain distinguishable in the code. The value pattern is
            # anchored and deliberately narrow: only these three labels, only a
            # numeric value, and only a unit drawn from a CLOSED list. Anything
            # else -- including a renamed or decorated warning token -- still
            # falls through to the fail-closed `else` and FAILS, which is what
            # #2532 exists to protect.
            #
            # THE UNIT LIST IS CLOSED FOR A MEASURED REASON. An earlier draft of
            # this branch ended `[[:space:]]*[A-Za-z]*$` so that `0.66s` and
            # `24.00 MB` would match. That trailing wildcard also matched the
            # WORD `warnings`, so `Duration: 5 warnings` was accepted and the gate
            # printed `warnings=0` over a line that reported five. Caught by an
            # adversarial case before this shipped, not by the happy path: a
            # permissive tail on a SKIP branch is a warning-smuggling route.
            #
            # `byte`/`bytes` are in the list because PHPUnit's OWN formatter
            # emits them: php-timer's ResourceUsageFormatter::bytesToString()
            # only knows GB/MB/KB and falls through to `N byte(s)` for a peak
            # under 1024. Omitting them made this fix incomplete on its own
            # premise -- verified against the installed vendor source, not
            # assumed. The VALUE is also enumerated rather than loose: the
            # earlier `[0-9][0-9.:]*` accepted `1...`, `1.` and `1:2:3:4:5`,
            # which is the same permissiveness this comment warns about, one
            # field to the left. An instrument that refuses what it cannot read
            # must not quietly accept a measurement it cannot parse either.
            #
            # The clock form's hours field is OPTIONAL because the SIBLING file
            # of that same package emits one: Duration::asString() prepends
            # `HH:` whenever hours > 0, so a run at or over an hour prints
            # `Time: 01:02:03.456`. Enumerating only M:SS(.fff) refused that
            # legitimate zero-warning line as an unrecognised token -- the exact
            # false-positive class this branch exists to close, found by review
            # because the byte/bytes check stopped at ResourceUsageFormatter and
            # did not read asString() beside it. Three fields is the ceiling:
            # php-timer emits no fourth, and `1:2:3:4:5` stays refused.
            seen=$((seen - 1))   # a metric is not the "at least one count was read" proof
            IFS=','; continue
        elif printf '%s' "$token" | grep -qE '^[A-Za-z][A-Za-z[:space:]]*:[[:space:]]*[0-9]+$'; then
            # PHPUnit TextUI dialect: "Warnings: 1".
            label="$(printf '%s' "$token" | sed -E 's/:.*$//')"
            count="$(printf '%s' "$token" | sed -E 's/^.*:[[:space:]]*//')"
        else
            echo "ERROR: unrecognised token in PHPUnit summary; failing closed." >&2
            echo "       token:   $token" >&2
            echo "       summary: $summary" >&2
            IFS="$old_ifs"
            return 1
        fi
        # Normalise: lowercase, collapse spaces, drop a leading "phpunit ".
        label="$(printf '%s' "$label" | tr '[:upper:]' '[:lower:]' \
            | sed -E 's/[[:space:]]+/ /g; s/^phpunit //; s/^[[:space:]]+//; s/[[:space:]]+$//')"
        case "$label" in
            warning|warnings)
                warnings=$((warnings + count)) ;;
            assertion|assertions|test|tests|passed|failed|failure|failures|error|errors|skipped|incomplete|risky|deprecation|deprecations|deprecated|notice|notices|todo|todos|pending)
                : ;;  # known, and deliberately not failed on here
            *)
                echo "ERROR: unknown count '$label' in PHPUnit summary; failing closed." >&2
                echo "       summary: $summary" >&2
                IFS="$old_ifs"
                return 1 ;;
        esac
        IFS=','
    done
    IFS="$old_ifs"
    if [ "$seen" -eq 0 ]; then
        echo "ERROR: PHPUnit summary line carries no readable counts; failing closed." >&2
        echo "       summary: $summary" >&2
        return 1
    fi
    if [ "$warnings" -ne 0 ]; then
        echo "ERROR: PHPUnit reported $warnings warning(s); gate 1 requires zero." >&2
        echo "       summary: $summary" >&2
        return 1
    fi
    echo "    summary parsed: warnings=0"
    return 0
}
