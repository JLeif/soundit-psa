#!/usr/bin/env bash
#
# Tests for scripts/mockshot: argument parsing and playwright-core resolution.
# These never launch Chromium (CI has none), so every case fails before render.
# The live render is a manual receipt; see design/mockups/README.md.
#
# Run from anywhere:
#   scripts/tests/mockshot.test.sh
#
# Exits non-zero if any assertion fails.
set -u

HERE="$(cd "$(dirname "$0")" && pwd)"
MOCKSHOT="$HERE/../mockshot"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

PASS=0
FAIL=0

printf '<!doctype html><title>t</title><p>t</p>\n' >"$TMP/page.html"
mkdir -p "$TMP/empty"

# No playwright-core anywhere for the parse cases: an explicit path that does
# not exist means a case that wrongly passes parsing exits 2, not 1, and never
# reaches a browser even on a machine that has one.
NOPW="$TMP/empty/no-playwright-core"

# run <name> <expected_exit> <stderr-substring|-> [args...]
run() {
    local name="$1" expected="$2" needle="$3"
    shift 3
    local rc=0
    MOCKSHOT_PLAYWRIGHT_CORE="$NOPW" "$MOCKSHOT" "$@" >"$TMP/$name.out" 2>"$TMP/$name.err" || rc=$?
    if [ "$rc" -ne "$expected" ]; then
        FAIL=$((FAIL + 1))
        echo "FAIL [$name] exit: expected $expected, got $rc"
        sed 's/^/    err: /' "$TMP/$name.err"
        return
    fi
    if [ "$needle" != "-" ] && ! grep -qF -- "$needle" "$TMP/$name.err"; then
        FAIL=$((FAIL + 1))
        echo "FAIL [$name] stderr lacks: $needle"
        sed 's/^/    err: /' "$TMP/$name.err"
        return
    fi
    PASS=$((PASS + 1))
}

P="$TMP/page.html"

# --- usage errors: exit 1, before any playwright lookup -------------------------
run no-args          1 "missing <file.html>"
run unknown-device   1 "unknown --device 'watch'"        "$P" --device watch
run unknown-device-eq 1 "unknown --device 'Desktop'"     "$P" --device=Desktop
run bad-size-word    1 "bad --size 'big'"                "$P" --size big
run bad-size-sep     1 "bad --size '1440*900'"           "$P" --size '1440*900'
run bad-size-neg     1 "bad --size '-1x900'"             "$P" --size=-1x900
run bad-size-zero    1 "bad --size '0x900'"              "$P" --size 0x900
run bad-size-huge    1 "bad --size '20000x900'"          "$P" --size 20000x900
run bad-size-partial 1 "bad --size '1440x'"              "$P" --size 1440x
run size-no-value    1 "--size needs a value"            "$P" --size
run device-no-value  1 "--device needs a value"          "$P" --device --full
run device-and-size  1 "mutually exclusive"              "$P" --device phone --size 100x100
run unknown-option   1 "unknown option --fullpage"       "$P" --fullpage
run too-many-args    1 "too many arguments"              "$P" "$TMP/a.png" "$TMP/b.png"
run missing-input    1 "no such file"                    "$TMP/nope.html"
run dir-input        1 "not a file"                      "$TMP/empty"
run out-equals-in    1 "would overwrite the input"       "$P" "$P"

# --- playwright-core resolution: exit 2 with the install hint -------------------
# Each valid invocation gets past parsing and stops at the lookup, which proves
# the parse cases above were refused by the parser rather than the lookup.
run pw-missing-default 2 "MOCKSHOT_PLAYWRIGHT_CORE=$NOPW could not be loaded" "$P"
run pw-missing-hint    2 "npm install -g playwright-core"                     "$P"
run pw-missing-phone   2 "could not be loaded"                                "$P" --device phone
run pw-missing-tablet  2 "could not be loaded"                                "$P" "$TMP/o.png" --device=tablet --full
run pw-missing-size    2 "could not be loaded"                                "$P" --size 1280x800

# Unset variable, empty NODE_PATH and no npm on PATH: the search fallback must
# also exit 2 and name what it searched.
rc=0
env -u MOCKSHOT_PLAYWRIGHT_CORE NODE_PATH="$TMP/empty" PATH="$(dirname "$(command -v node)"):/usr/bin:/bin" \
    HOME="$TMP" npm_config_prefix="$TMP/empty" \
    "$MOCKSHOT" "$P" >"$TMP/search.out" 2>"$TMP/search.err" || rc=$?
if [ "$rc" -eq 2 ] && grep -qF "playwright-core not found" "$TMP/search.err" \
    && grep -qF "$TMP/empty" "$TMP/search.err"; then
    PASS=$((PASS + 1))
else
    FAIL=$((FAIL + 1))
    echo "FAIL [pw-search-fallback] expected exit 2 naming the searched NODE_PATH, got $rc"
    sed 's/^/    err: /' "$TMP/search.err"
fi

# No PNG may be written by any refused run.
if ls "$TMP"/*.png >/dev/null 2>&1 || [ -e "${P%.html}.png" ]; then
    FAIL=$((FAIL + 1))
    echo "FAIL [no-output] a refused run wrote a PNG"
else
    PASS=$((PASS + 1))
fi

echo "mockshot tests: $PASS passed, $FAIL failed"
[ "$FAIL" -eq 0 ]
