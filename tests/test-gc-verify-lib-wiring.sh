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
# Case names that went red, so --selftest can require the RIGHT cases to fail
# rather than merely counting. Round 1 asserted `fail > 0`, which is satisfied by
# any one control -- so cases 1, 4 and 5 were never proven capable of going red
# at all. The review called that out and it is the same shape as a selftest that
# passes on a shared exit code.
FAILED_CASES=""
ok()   { pass=$((pass+1)); printf '  ok   %s\n' "$1"; }
bad()  { fail=$((fail+1)); printf '  FAIL %s\n' "$1"; [ -n "${2:-}" ] && printf '       %s\n' "$2"; }
# Every case calls ok()/bad() through this, so the name is recorded either way.
case_result() { # $1 name, $2 0=pass/1=fail, $3 pass-msg, $4 fail-msg, $5 detail
    if [ "$2" -eq 0 ]; then ok "$3"; else FAILED_CASES="$FAILED_CASES $1"; bad "$4" "${5:-}"; fi
}

# Build a runnable gate whose stages 0 and 1 are stubbed, keeping the library
# wiring verbatim. $1 is the destination; $2 is the repo root the copy should use.
#
# The guard block is located by its first and last lines, and the result is then
# compared against the same block cut from the shipped file. Locating by content
# and VERIFYING the copy is what keeps this from being one more range extraction
# trusted on faith.
build_stub_gate() {
    # THE BLOCK'S END MOVED and the locator had to move with it. The wiring now
    # sits BEFORE stage 1, so `if ! assert_no_warnings` -- which used to follow it
    # immediately -- is now on the far side of the whole ~7800-test PHPUnit stage,
    # and a range ending there would drag `php artisan test` into every stub.
    # The block therefore ends at the LAST line of the second guard, found by the
    # first `^fi$` at or after the final `declare -F` line. Both ends are located
    # by content in the shipped file and RE-LOCATED the same way in the copy.
    # `last` is assigned only inside the `if` below, and the refusal that follows
    # dereferences it unconditionally under `set -u`; initialise it so a drifted
    # gate gets the named FATAL rather than `last: unbound variable`.
    local dest="$1" root="$2" first last="" dclare
    first="$(grep -n '^GC_VERIFY_LIB=' "$GATE" | head -1 | cut -d: -f1)"
    dclare="$(grep -n '^if ! declare -F assert_no_warnings ' "$GATE" | tail -1 | cut -d: -f1)"
    if [ -n "$dclare" ]; then
        last="$(awk -v s="$dclare" 'NR>s && /^fi$/{print NR+1; exit}' "$GATE")"
    fi
    if [ -z "$first" ] || [ -z "$last" ] || [ "$last" -le "$first" ]; then
        echo "FATAL: cannot locate the library-wiring block in $GATE (first='$first' last='$last')"
        exit 2
    fi
    # The range must not have swallowed a gate STAGE. If it has, the stub would
    # run php artisan test and every case would measure the wrong thing.
    if sed -n "${first},$((last - 1))p" "$GATE" | grep -qE '^php artisan test|^echo "==> \[1/3\]'; then
        echo "FATAL: the located wiring block contains a gate stage; the range is wrong"
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
    # Same conditional assignment, same reason: see `last` above.
    local dfirst dlast=""
    dfirst="$(grep -n '^GC_VERIFY_LIB=' "$dest" | head -1 | cut -d: -f1)"
    local ddeclare
    ddeclare="$(grep -n '^if ! declare -F assert_no_warnings ' "$dest" | tail -1 | cut -d: -f1)"
    if [ -n "$ddeclare" ]; then
        dlast="$(awk -v s="$ddeclare" 'NR>s && /^fi$/{print NR+1; exit}' "$dest")"
    fi
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
        sed -i -e 's|^if \[ ! -f "$GC_VERIFY_LIB" \] \|\| \[ ! -r "$GC_VERIFY_LIB" \]; then|if false; then|' \
               -e 's|^if \[ "$( unset -f assert_no_warnings .*!= LOADED \]; then|if false; then|' \
               -e 's|^if ! declare -F assert_no_warnings >/dev/null; then|if false; then|' \
               -e 's|^unset -f assert_no_warnings 2>/dev/null \|\| true$|:|' \
               -e 's|^\. "$GC_VERIFY_LIB"$|. "$GC_VERIFY_LIB" 2>/dev/null \|\| true|' \
               "$box/scripts/gc-verify.sh"
        # ALL FIVE must land, not "at least one". Round 1 compared one sha256
        # across the whole file, which an OR across independent expressions
        # satisfies partially -- the review called that out. Each expression is
        # therefore asserted to have changed the text it targets.
        for pat in 'if \[ ! -f "\$GC_VERIFY_LIB" \]' 'unset -f assert_no_warnings 2>/dev/null; \. "\$GC_VERIFY_LIB"' 'if ! declare -F assert_no_warnings' '^unset -f assert_no_warnings 2>/dev/null \|\| true$' '^\. "\$GC_VERIFY_LIB"$'; do
            if grep -qE "$pat" "$box/scripts/gc-verify.sh"; then
                echo "SELFTEST FATAL: mutation did not remove /$pat/ from $box/scripts/gc-verify.sh;"
                echo "  the guard's text moved and this harness is not testing what it claims."
                exit 2
            fi
        done
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
r=1; { [ "$rc1" -eq 0 ] && printf '%s' "$out1" | grep -q 'warnings=0'; } && r=0
case_result healthy "$r" \
    "healthy library: gate reaches belt two and the real parser runs" \
    "healthy library should pass belt two (rc=$rc1)" "$(printf '%s' "$out1" | tail -2 | tr '\n' ' ')"

# --- Case 2: library MISSING must FAIL, not skip belt two. ----------------
box2="$WORK/box2"; new_sandbox "$box2"
rm -f "$box2/scripts/lib/gc-verify-summary.sh"
out2="$("$box2/scripts/gc-verify.sh" 2>&1)"; rc2=$?
r=1; { [ "$rc2" -ne 0 ] && printf '%s' "$out2" | grep -q 'summary parser library missing'; } && r=0
case_result missing "$r" \
    "missing library: FAILs, and for the stated reason" \
    "missing library must fail with the named reason (rc=$rc2)" "$(printf '%s' "$out2" | tail -2 | tr '\n' ' ')"

# --- Case 3: library present but DEFINES NOTHING must FAIL. ---------------
# The nastier shape: readable, sources cleanly, and leaves belt two with no
# parser. A `-r` test alone cannot see this, which is why there are two guards.
box3="$WORK/box3"; new_sandbox "$box3"
printf '# a library that defines nothing\n:\n' > "$box3/scripts/lib/gc-verify-summary.sh"
out3="$("$box3/scripts/gc-verify.sh" 2>&1)"; rc3=$?
r=1; { [ "$rc3" -ne 0 ] && printf '%s' "$out3" | grep -qE 'did not define assert_no_warnings|did not yield assert_no_warnings'; } && r=0
case_result defines-nothing "$r" \
    "library defines nothing: FAILs, and for the stated reason" \
    "a library defining no parser must fail with the named reason (rc=$rc3)" "$(printf '%s' "$out3" | tail -2 | tr '\n' ' ')"

# --- Case 4: the library path does not depend on the caller's cwd. --------
# `dirname "$BASH_SOURCE"` is relative to the CALLER, and the gate cds to the repo
# root early, so a relative invocation from a subdirectory once resolved the
# library against the wrong place. Run the SAME healthy sandbox from elsewhere via
# a relative path and require the identical result.
box4="$WORK/box4"; new_sandbox "$box4"
mkdir -p "$box4/sub"
out4="$(cd "$box4/sub" && bash ../scripts/gc-verify.sh 2>&1)"; rc4=$?
r=1; { [ "$rc4" -eq 0 ] && printf '%s' "$out4" | grep -q 'warnings=0'; } && r=0
case_result cwd "$r" \
    "relative invocation from a subdirectory resolves the library" \
    "cwd must not change which library is loaded (rc=$rc4)" "$(printf '%s' "$out4" | tail -2 | tr '\n' ' ')"

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
# THE CONTRACT HAS THREE CLAUSES and round 1 pinned only one of them ("silent,
# one function"). The review was right: "sets no variable" and "changes no shell
# option" were stated in the library header and measured by nothing. Both are now
# compared against a control process that sources NOTHING, so the comparison
# isolates what sourcing added rather than enumerating bash's own baseline.
# THE BASELINE IS "SOURCED AN EMPTY FILE", NOT "SOURCED NOTHING". Measured: the
# act of sourcing ANY file materialises PIPESTATUS in a fresh shell, so a
# baseline that sources nothing blames the library for bash's own bookkeeping.
# My first version of this control did exactly that and reported a clean library
# dirty -- the same mistake, in the same control, as the r1 `declare -F`
# subshell: the control was wrong, not the library.
se_vars_before="$(env -i bash --noprofile --norc -c 'e="$(mktemp)"; . "$e" >/dev/null 2>&1; rm -f "$e"; compgen -v | sort | tr "\n" " "')"
se_vars_after="$(env -i bash --noprofile --norc -c '. "$1" >/dev/null 2>&1; compgen -v | sort | tr "\n" " "' _ "$LIB")"
se_opts_before="$(env -i bash --noprofile --norc -c 'set -o | sort; shopt | sort')"
se_opts_after="$(env -i bash --noprofile --norc -c '. "$1" >/dev/null 2>&1; set -o | sort; shopt | sort' _ "$LIB")"
# `_` is bash's last-argument variable and `e` is the baseline's own temp-file
# name; both move for reasons unrelated to the library and are excluded by name.
se_newvars="$(comm -13 <(printf '%s' "$se_vars_before" | tr ' ' '\n' | grep -v '^$' | sort) \
                        <(printf '%s' "$se_vars_after"  | tr ' ' '\n' | grep -v '^$' | sort) | grep -vxE '_|e' | tr '\n' ' ')"
r=1
if [ -z "$se_out" ] && [ "$se_fns" = "assert_no_warnings " ] &&
   [ -z "$se_newvars" ] && [ "$se_opts_before" = "$se_opts_after" ]; then r=0; fi
case_result side-effects "$r" \
    "sourcing the library is silent, defines exactly assert_no_warnings, sets no variable and changes no shell option" \
    "library must be side-effect free (output='$se_out' functions='$se_fns' newvars='$se_newvars' opts-changed=$([ "$se_opts_before" = "$se_opts_after" ] && echo no || echo yes))"

# --- Case 6: a library that EXITS at top level must FAIL, not end the gate. -
# The worst shape found in review r1: a top-level `exit 0` in the library ended
# the gate AT RC 0, with belt two, pint and the secret guard never run. A green
# gate that checked nothing -- the silent-skip class, arriving through the new
# source path. The probe closes it because `$( )` is a subshell: the exit ends
# the probe, the sentinel is never printed, and the gate refuses.
box6="$WORK/box6"; new_sandbox "$box6"
{ cat "$LIB"; echo 'exit 0'; } > "$box6/scripts/lib/gc-verify-summary.sh"
out6="$("$box6/scripts/gc-verify.sh" 2>&1)"; rc6=$?
r=1; { [ "$rc6" -ne 0 ] && printf '%s' "$out6" | grep -q 'summary parser library did not load'; } && r=0
case_result top-level-exit "$r" \
    "library with a top-level exit: FAILs instead of ending the gate green" \
    "a library that exits must not terminate the gate successfully (rc=$rc6)" "$(printf '%s' "$out6" | tail -2 | tr '\n' ' ')"

# --- Case 7: a syntactically BROKEN library must FAIL by name. -------------
# It always failed closed, but with a raw bash `syntax error` and no named
# verdict, so the operator got no statement of what the gate concluded.
box7="$WORK/box7"; new_sandbox "$box7"
printf 'assert_no_warnings() {\n    echo unterminated\n' > "$box7/scripts/lib/gc-verify-summary.sh"
out7="$("$box7/scripts/gc-verify.sh" 2>&1)"; rc7=$?
# ASSERT THE LIBRARY-SPECIFIC REASON, not merely "something failed". A broken
# library makes the gate fail either way -- bash cannot call a function that was
# never defined -- so a case satisfied by any FAIL passes with the guards removed
# and is therefore evidence for nothing. The selftest below caught exactly that
# and refused this case until it was tightened. What the guard actually buys is
# the DIAGNOSIS, so the diagnosis is what gets asserted.
r=1; { [ "$rc7" -ne 0 ] && printf '%s' "$out7" | grep -q 'summary parser library did not load'; } && r=0
case_result broken-library "$r" \
    "syntactically broken library: FAILs with the library's own named verdict, not a bare bash error" \
    "a broken library must produce the library-specific named FAIL (rc=$rc7)" "$(printf '%s' "$out7" | tail -2 | tr '\n' ' ')"

# --- Case 8: an INHERITED exported parser must not satisfy the guard. ------
# `declare -F` alone was satisfied by a function exported from the caller's
# environment, so an impostor could stand in while the library on disk was empty.
# Measured in review r1 against an empty library and `export -f`.
box8="$WORK/box8"; new_sandbox "$box8"
printf '# defines nothing\n:\n' > "$box8/scripts/lib/gc-verify-summary.sh"
out8="$(assert_no_warnings() { echo IMPOSTOR; }; export -f assert_no_warnings; "$box8/scripts/gc-verify.sh" 2>&1)"; rc8=$?
r=1; { [ "$rc8" -ne 0 ] && printf '%s' "$out8" | grep -q 'summary parser library did not load'; } && r=0
case_result inherited-impostor "$r" \
    "an inherited exported parser does not satisfy the guard" \
    "an empty library must FAIL even when a parser is exported into the environment (rc=$rc8)" "$(printf '%s' "$out8" | tail -2 | tr '\n' ' ')"

# --- Case 9: a DIRECTORY at the library path must FAIL by name. ------------
# `[ -r ]` is true for a directory, so round 1 reached the `.` and aborted with
# bash's "is a directory" and no verdict.
box9="$WORK/box9"; new_sandbox "$box9"
rm -f "$box9/scripts/lib/gc-verify-summary.sh"; mkdir -p "$box9/scripts/lib/gc-verify-summary.sh"
out9="$("$box9/scripts/gc-verify.sh" 2>&1)"; rc9=$?
# Same discipline as case 7: assert the library-specific reason. `[ -r ]` is true
# for a directory, so round 1 reached the `.` and died with bash's own "is a
# directory"; the -f test is what turns that into a verdict.
r=1; { [ "$rc9" -ne 0 ] && printf '%s' "$out9" | grep -q 'summary parser library missing'; } && r=0
case_result directory-at-path "$r" \
    "a directory at the library path: FAILs with the library's own named verdict" \
    "a directory at the library path must produce the library-specific named FAIL (rc=$rc9)" "$(printf '%s' "$out9" | tail -2 | tr '\n' ' ')"

echo
echo "wiring controls: $pass passed, $fail failed"
if [ "$MUTATE" = 1 ]; then
    # EVERY NEGATIVE CASE MUST GO RED, BY NAME. `fail > 0` was satisfied by any
    # single control, so cases 1, 4 and 5 were never proven capable of failing --
    # the same defect as a selftest that passes on a shared exit code. With the
    # refusals removed, every case that depends on them must be named below.
    want="missing defines-nothing top-level-exit broken-library inherited-impostor directory-at-path"
    missing_red=""
    for c in $want; do
        case " $FAILED_CASES " in *" $c "*) ;; *) missing_red="$missing_red $c" ;; esac
    done
    if [ -z "$missing_red" ]; then
        echo "SELFTEST OK: with the gate's refusals removed, every negative control went red:$FAILED_CASES"
        exit 0
    fi
    echo "SELFTEST FAILED: these controls did NOT go red when the gate's refusals were removed:$missing_red"
    echo "  They cannot be evidence for a guard they do not detect the absence of."
    exit 1
fi
[ "$fail" -eq 0 ] || exit 1
echo "gc-verify library wiring: ALL CONTROLS PASS"
