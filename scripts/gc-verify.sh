#!/usr/bin/env bash
#
# gc-verify.sh — SoundIT PSA pipeline quality gate.
#
# The single source of truth for "is this change shippable?". Run by the
# Gas City implementer formula's `check` step (so a bead cannot close and a
# PR cannot be opened unless it is green), by the CI workflow on every PR,
# and by humans on demand.
#
# Gates:
#   1. php artisan test          — the full PHPUnit suite must pass without warnings.
#      Enforced by TWO independent belts, because --fail-on-warning alone was
#      measured to be partial: it does not fail on the @-suppressed read at
#      vendor/vlucas/phpdotenv/src/Store/File/Reader.php:73 that Laravel's error
#      handler surfaces in the summary's `warnings` column. Belt one is
#      --fail-on-warning (PHPUnit's own exit policy); belt two parses the
#      summary line and requires warnings == 0. An unrecognised summary is a
#      FAIL, never a PASS — this gate fails closed on a format it cannot read.
#      Risky tests and deprecations (including PHPUnit metadata deprecations)
#      retain PHPUnit/config defaults; this gate does not newly fail on them.
#   2. pint --test (changed PHP) — code style, scoped to the PHP files this
#                                  branch changed vs main. The repo carries
#                                  pre-existing style debt, so we hold only
#                                  NEW/changed code to the standard, not the
#                                  whole tree.
#   3. real-data / secret guard  — fail if the diff reintroduces operator
#                                  emails, private keys, or known token shapes
#                                  (this is a public OSS repo).
#
# Environment: vendor/ must already be installed. A missing .env is PROVISIONED
# here the way CI does it (cp .env.example .env; php artisan key:generate)
# rather than tolerated, because a worktree without .env does not fail — it
# silently converts ~7100 real passes into `warnings` and still exits 0.
# Exits non-zero on the first failing gate.
set -euo pipefail

cd "$(git rev-parse --show-toplevel)"

# Resolve the base commit to diff against (prefer origin/main, then main).
BASE=""
for ref in origin/main main; do
    if git rev-parse --verify --quiet "$ref" >/dev/null 2>&1; then
        BASE="$(git merge-base HEAD "$ref" 2>/dev/null || true)"
        [ -n "$BASE" ] && break
    fi
done

echo "==> [0/3] environment (.env)"
if [ ! -f .env ]; then
    if [ ! -f .env.example ]; then
        echo "ERROR: no .env and no .env.example to provision one from." >&2
        echo "==> gc-verify: FAIL (no app environment)" >&2
        exit 1
    fi
    echo "    .env absent — provisioning from .env.example (as CI does)"
    if ! cp .env.example .env; then
        echo "==> gc-verify: FAIL (could not provision .env)" >&2
        exit 1
    fi
    # key:generate's exit status proves nothing: handle() returns void on every
    # path, so the command exits 0 even when it wrote no key. That happens when
    # APP_KEY is already set in the ambient environment (the replacement pattern
    # is then /^APP_KEY=<the ambient value>/m, which cannot match the empty
    # APP_KEY= line we just copied in), and when .env.example carries no APP_KEY=
    # line at all. So verify the file itself, and on failure remove the .env WE
    # created: leaving an unkeyed one behind would trip the refusal branch below
    # on this and every later run, bricking the gate for this worktree until
    # someone deleted the file by hand.
    php artisan key:generate || true
    if ! grep -qE '^APP_KEY=.+' .env; then
        rm -f .env
        echo "ERROR: provisioned .env from .env.example, but key:generate wrote no APP_KEY." >&2
        echo "       Usual causes: APP_KEY is already set in this shell/container" >&2
        echo "       environment (unset it and re-run), or .env.example carries no" >&2
        echo "       APP_KEY= line. The provisioned .env has been removed, so the" >&2
        echo "       worktree is as it was and a re-run can provision cleanly." >&2
        echo "==> gc-verify: FAIL (could not generate APP_KEY)" >&2
        exit 1
    fi
fi
# Never rewrite a .env this script did not create; an unkeyed one is a refusal.
if ! grep -qE '^APP_KEY=.+' .env; then
    echo "ERROR: .env exists but APP_KEY is empty; refusing to modify it." >&2
    echo "       Run: php artisan key:generate" >&2
    echo "==> gc-verify: FAIL (no APP_KEY)" >&2
    exit 1
fi
echo "    .env present with APP_KEY"

echo "==> [1/3] php artisan test --fail-on-warning"
if ! php artisan config:clear --ansi >/dev/null; then
    echo "==> gc-verify: FAIL (configuration clear)" >&2
    exit 1
fi

# Belt one: PHPUnit's own exit policy.
TEST_LOG="$(mktemp "${TMPDIR:-/tmp}/gc-verify-tests.XXXXXX")"
trap 'rm -f "$TEST_LOG"' EXIT
set +e
php artisan test --fail-on-warning 2>&1 | tee "$TEST_LOG"
TEST_STATUS="${PIPESTATUS[0]}"
set -e
if [ "$TEST_STATUS" -ne 0 ]; then
    echo "==> gc-verify: FAIL (PHPUnit failed or reported warnings)" >&2
    exit 1
fi

# Belt two: the summary line must exist, be a dialect we recognise, and report
# zero warnings. --fail-on-warning is known not to cover every warning class
# (see the header), so a green exit code is not accepted on its own.
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
        # Any token still here is either recognised below or fails closed below,
        # so counting them here is the "at least one count was read" proof.
        seen=$((seen + 1))
        if printf '%s' "$token" | grep -qE '^[0-9]+$'; then
            # Bare count: PHPUnit TextUI's leading test total ("Tests: 21, ...").
            IFS=','; continue
        elif printf '%s' "$token" | grep -qE '^[0-9]+[[:space:]]+[A-Za-z][A-Za-z[:space:]]*$'; then
            # Laravel dialect: "7286 warnings".
            count="$(printf '%s' "$token" | sed -E 's/^([0-9]+).*/\1/')"
            label="$(printf '%s' "$token" | sed -E 's/^[0-9]+[[:space:]]+//')"
        elif printf '%s' "$token" | grep -qiE '^(Duration|Time|Memory)[[:space:]]*:[[:space:]]*[0-9][0-9.:]*[[:space:]]*(s|ms|us|sec|secs|seconds|m|min|b|kb|mb|gb)?$'; then
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
if ! assert_no_warnings "$TEST_LOG"; then
    echo "==> gc-verify: FAIL (PHPUnit warnings, or an unreadable summary)" >&2
    exit 1
fi

echo "==> [2/3] pint --test (changed PHP files)"
changed_php() {
    { [ -n "$BASE" ] && git diff --name-only --diff-filter=ACMR "$BASE"...HEAD -- '*.php'
      git diff --name-only --diff-filter=ACMR -- '*.php'
      git diff --name-only --diff-filter=ACMR --cached -- '*.php'; } 2>/dev/null | sort -u
}
FILES=()
while IFS= read -r f; do [ -n "$f" ] && [ -f "$f" ] && FILES+=("$f"); done < <(changed_php)
if [ "${#FILES[@]}" -gt 0 ]; then
    printf '    %s\n' "${FILES[@]}"
    vendor/bin/pint --test "${FILES[@]}"
else
    echo "    (no changed PHP files — skipping)"
fi

echo "==> [3/3] real-data / secret guard"
GUARD_RE='@couttspnw\.com|-----BEGIN [A-Z ]*PRIVATE KEY-----|xox[baprs]-[0-9A-Za-z-]{8,}|AKIA[0-9A-Z]{16}'
DIFF="$( { [ -n "$BASE" ] && git diff -U0 "$BASE"...HEAD; git diff -U0; } 2>/dev/null || true )"
if printf '%s' "$DIFF" | grep -nEi "$GUARD_RE" >/dev/null 2>&1; then
    echo "ERROR: possible real-data/secret leak in diff:" >&2
    printf '%s' "$DIFF" | grep -nEi "$GUARD_RE" >&2
    exit 1
fi

echo "==> gc-verify: PASS"
