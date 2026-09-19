#!/usr/bin/env bash
# Control suite for the WIRING between scripts/gc-verify.sh and its summary
# parser library, scripts/lib/gc-verify-summary.sh (Trello 6aadcd16, #2650).
#
#   ./tests/test-gc-verify-lib-wiring.sh            run the controls
#   ./tests/test-gc-verify-lib-wiring.sh --selftest prove this suite can FAIL
#
# WHAT THIS EXISTS TO STOP. Moving the parser out of the gate created a new way
# for belt two to not run at all: the library goes missing, or loads without
# defining the function, and the gate carries on having proved nothing about
# warnings. That is the same harm as the 7286-warning floor -- a PASS that
# measured nothing -- arriving by a new route, so the route gets its own controls
# rather than an assurance in a comment.
#
# HOW IT REACHES THE GUARD WITHOUT RUNNING THE SUITE. Stages 0 and 1 of the gate
# provision .env and run ~7800 PHPUnit tests before belt two is reached; a control
# that waited for that would be run rarely and therefore not be a control. So each
# case builds a DIAGNOSTIC COPY of scripts/gc-verify.sh with the work before the
# library source replaced by a stub, and then asserts -- byte for byte -- that the
# block under test in the copy is IDENTICAL to the block in the shipped file. If
# the shipped guard ever changes, the extraction that feeds these controls stops
# matching and the suite fails rather than quietly certifying a copy of something
# else. Nothing here is eval'd: the copy is a file, run as a file.
set -uo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "$HERE/.." && pwd)"
GATE="$ROOT/scripts/gc-verify.sh"
LIB="$ROOT/scripts/lib/gc-verify-summary.sh"

[ -r "$GATE" ] || { echo "FATAL: cannot read $GATE"; exit 2; }
[ -r "$LIB" ]  || { echo "FATAL: cannot read $LIB"; exit 2; }

WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

MUTATE=0
[ "${1:-}" = "--selftest" ] && MUTATE=1

pass=0; fail=0
ok()   { pass=$((pass+1)); printf '  ok   %s\n' "$1"; }
bad()  { fail=$((fail+1)); printf '  FAIL %s\n' "$1"; [ -n "${2:-}" ] && printf '       %s\n' "$2"; }

# Build a runnable gate whose stages 0 and 1 are stubbed, keeping the library
# wiring verbatim. $1 is the destination; $2 is the repo root the copy should use.
#
# The guard block is located by its first and last lines, and the result is then
# compared against the same block cut from the shipped file. Locating by content
# and VERIFYING the copy is what keeps this from being one more range extraction
# trusted on faith.
build_stub_gate() {
    local dest="$1" root="$2" first last
    first="$(grep -n '^GC_VERIFY_LIB=' "$GATE" | head -1 | cut -d: -f1)"
    last="$(grep -n '^if ! assert_no_warnings ' "$GATE" | head -1 | cut -d: -f1)"
    if [ -z "$first" ] || [ -z "$last" ] || [ "$last" -le "$first" ]; then
        echo "FATAL: cannot locate the library-wiring block in $GATE (first='$first' last='$last')"
        exit 2
    fi
    {
        echo '#!/usr/bin/env bash'
        echo 'set -euo pipefail'
        # The real script computes this before its cd; the stub must too.
        echo 'GC_VERIFY_SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"'
        echo "cd '$root'"
        echo 'TEST_LOG="$(mktemp)"; printf "OK (3 tests, 9 assertions)\n" > "$TEST_LOG"'
        sed -n "${first},$((last - 1))p" "$GATE"
        echo 'if ! assert_no_warnings "$TEST_LOG"; then'
        echo '    echo "==> gc-verify: FAIL (PHPUnit warnings, or an unreadable summary)" >&2'
        echo '    exit 1'
        echo 'fi'
        echo 'echo "==> gc-verify: PASS (stub)"'
    } > "$dest"
    chmod +x "$dest"
    # PROVE the copy carries the shipped block, not an approximation of it.
    # The block is located in the COPY the same way it was located in the
    # original -- by its own first and last lines -- rather than by counting the
    # prologue. An earlier version did the arithmetic and was off by one, which is
    # the whole argument against line-range reasoning that put this branch here.
    local dfirst dlast
    dfirst="$(grep -n '^GC_VERIFY_LIB=' "$dest" | head -1 | cut -d: -f1)"
    dlast="$(grep -n '^if ! assert_no_warnings ' "$dest" | head -1 | cut -d: -f1)"
    if [ -z "$dfirst" ] || [ -z "$dlast" ] || [ "$dlast" -le "$dfirst" ]; then
        echo "FATAL: cannot locate the wiring block in the stub copy"
        exit 2
    fi
    if ! diff -q <(sed -n "${first},$((last - 1))p" "$GATE") \
                 <(sed -n "${dfirst},$((dlast - 1))p" "$dest") >/dev/null; then
        echo "FATAL: the stub gate's wiring block is not byte-identical to $GATE's"
        exit 2
    fi
}

# A repo-shaped sandbox: scripts/ and scripts/lib/ under a fresh root, so a case
# can delete or corrupt the library without touching the checkout.
new_sandbox() {
    local box="$1"
    mkdir -p "$box/scripts/lib"
    cp "$LIB" "$box/scripts/lib/gc-verify-summary.sh"
    build_stub_gate "$box/scripts/gc-verify.sh" "$box"
    if [ "$MUTATE" = 1 ]; then
        # SELFTEST MUTATION: delete both refusals, keeping the source itself. A
        # gate that sources whatever is there and carries on regardless is exactly
        # the silent-skip this suite exists to refuse, so every negative case below
        # must now fail. `|| true` on the source is what makes a missing library
        # survivable, which is the defect being simulated.
        local before after
        before="$(sha256sum "$box/scripts/gc-verify.sh" | cut -d' ' -f1)"
        sed -i -e 's|^if \[ ! -r "$GC_VERIFY_LIB" \]; then|if false; then|' \
               -e 's|^if ! declare -F assert_no_warnings >/dev/null; then|if false; then|' \
               -e 's|^\. "$GC_VERIFY_LIB"$|. "$GC_VERIFY_LIB" 2>/dev/null \|\| true|' \
               "$box/scripts/gc-verify.sh"
        after="$(sha256sum "$box/scripts/gc-verify.sh" | cut -d' ' -f1)"
        # THE MUTATION MUST LAND. A selftest whose mutation silently fails to
        # apply measures the unmutated file and then reports whatever that file
        # does -- here it would have said "this suite proves nothing" about a
        # suite that was never tested. Caught exactly that way on first run.
        if [ "$before" = "$after" ]; then
            echo "SELFTEST FATAL: mutation did not land in $box/scripts/gc-verify.sh;"
            echo "  the guards' text moved and this harness is not testing what it claims."
            exit 2
        fi
        # And it must still be a runnable script.
        if ! bash -n "$box/scripts/gc-verify.sh" 2>/dev/null; then
            echo "SELFTEST FATAL: mutated gate is not valid shell; the mutation is unfaithful."
            exit 2
        fi
    fi
}

# --- Case 1: a healthy wiring PASSES. -------------------------------------
# The positive control. Without it, a suite whose cases all expect failure would
# pass against a gate that refuses unconditionally.
box1="$WORK/box1"; new_sandbox "$box1"
out1="$("$box1/scripts/gc-verify.sh" 2>&1)"; rc1=$?
if [ "$rc1" -eq 0 ] && printf '%s' "$out1" | grep -q 'warnings=0'; then
    ok "healthy library: gate reaches belt two and the real parser runs"
else
    bad "healthy library should pass belt two (rc=$rc1)" "$(printf '%s' "$out1" | tail -2 | tr '\n' ' ')"
fi

# --- Case 2: library MISSING must FAIL, not skip belt two. ----------------
box2="$WORK/box2"; new_sandbox "$box2"
rm -f "$box2/scripts/lib/gc-verify-summary.sh"
out2="$("$box2/scripts/gc-verify.sh" 2>&1)"; rc2=$?
if [ "$rc2" -ne 0 ] && printf '%s' "$out2" | grep -q 'summary parser library missing'; then
    ok "missing library: FAILs, and for the stated reason"
else
    bad "missing library must fail with the named reason (rc=$rc2)" "$(printf '%s' "$out2" | tail -2 | tr '\n' ' ')"
fi

# --- Case 3: library present but DEFINES NOTHING must FAIL. ---------------
# The nastier shape: readable, sources cleanly, and leaves belt two with no
# parser. A `-r` test alone cannot see this, which is why there are two guards.
box3="$WORK/box3"; new_sandbox "$box3"
printf '# a library that defines nothing\n:\n' > "$box3/scripts/lib/gc-verify-summary.sh"
out3="$("$box3/scripts/gc-verify.sh" 2>&1)"; rc3=$?
if [ "$rc3" -ne 0 ] && printf '%s' "$out3" | grep -q 'did not define assert_no_warnings'; then
    ok "library defines nothing: FAILs, and for the stated reason"
else
    bad "a library defining no parser must fail with the named reason (rc=$rc3)" "$(printf '%s' "$out3" | tail -2 | tr '\n' ' ')"
fi

# --- Case 4: the library path does not depend on the caller's cwd. --------
# `dirname "$BASH_SOURCE"` is relative to the CALLER, and the gate cds to the repo
# root early, so a relative invocation from a subdirectory once resolved the
# library against the wrong place. Run the SAME healthy sandbox from elsewhere via
# a relative path and require the identical result.
box4="$WORK/box4"; new_sandbox "$box4"
mkdir -p "$box4/sub"
out4="$(cd "$box4/sub" && bash ../scripts/gc-verify.sh 2>&1)"; rc4=$?
if [ "$rc4" -eq 0 ] && printf '%s' "$out4" | grep -q 'warnings=0'; then
    ok "relative invocation from a subdirectory resolves the library"
else
    bad "cwd must not change which library is loaded (rc=$rc4)" "$(printf '%s' "$out4" | tail -2 | tr '\n' ' ')"
fi

# --- Case 5: the shipped library is side-effect free. ---------------------
# Sourcing it must define the parser and do nothing else. If it ever grows a
# top-level command, the gate would run that command mid-run and both harnesses
# would run it too.
# A FRESH bash process, not a subshell of this one: a subshell inherits every
# function this suite has defined, so `declare -F` there lists ok(), bad(),
# new_sandbox() and friends and the control fails against a perfectly clean
# library. That is what it did when first written -- the control was wrong, not
# the library -- and an enumerate-everything assertion has to run somewhere that
# has nothing else in it.
se_out="$(env -i bash --noprofile --norc -c '. "$1" 2>&1' _ "$LIB")"
se_fns="$(env -i bash --noprofile --norc -c '. "$1" >/dev/null 2>&1; declare -F | awk "{print \$3}" | sort | tr "\n" " "' _ "$LIB")"
if [ -z "$se_out" ] && [ "$se_fns" = "assert_no_warnings " ]; then
    ok "sourcing the library is silent and defines exactly assert_no_warnings"
else
    bad "library must be side-effect free (output='$se_out' functions='$se_fns')"
fi

echo
echo "wiring controls: $pass passed, $fail failed"
if [ "$MUTATE" = 1 ]; then
    if [ "$fail" -gt 0 ]; then
        echo "SELFTEST OK: removing the gate's two refusals is caught by $fail control(s) — this suite can fail."
        exit 0
    fi
    echo "SELFTEST FAILED: the gate skipped belt two silently and every control still passed. This suite proves nothing."
    exit 1
fi
[ "$fail" -eq 0 ] || exit 1
echo "gc-verify library wiring: ALL CONTROLS PASS"
