#!/bin/bash
#
# PSA macOS Disk Capacity Check (psa-0pb9m)
#
# A Tactical RMM SCRIPT check that genuinely runs on macOS and reports real
# state — shipped because macOS/Linux Tactical agents support script checks
# only, and the fleet's previous single Mac check failed on 100% of runs
# (wrong-platform script), leaving Macs visible-but-unverified in RMM.
#
# WHAT GATES THE EXIT CODE — disk capacity, nothing else. The check FAILS
# (exit 1) only on objective, actionable disk problems: the data volume
# at/above 90% used, or under 10 GB free (mirrors the PSA's EndpointInsight
# LOW_DISK thresholds). Uptime, load, SIP, FileVault, and macOS version are
# REPORT-ONLY facts for the technician — they never affect the verdict, and
# the verdict copy says "disk capacity", not "healthy", so a pass is never
# read as a whole-device all-clear (a check that failed on org-policy
# variance, e.g. FileVault intentionally off, would recreate the
# always-failing noise this bead removes).
#
# Design rules:
#   - Refuses to run anywhere but Darwin (exit 1, explicit message): if a
#     wrong-platform assignment ever happens anyway, it must FAIL LOUDLY,
#     never print a passing verdict on the wrong OS.
#   - Fixed system PATH — an unattended root-context check must not trust an
#     inherited PATH for df/awk/sysctl/csrutil/fdesetup/sw_vers.
#   - darwin-native tools only — all present on every supported macOS; no
#     Homebrew, no network calls, no files written.
#   - macOS ships bash 3.2: no arrays under `set -u`, no bash-4 features.
#   - No TCC-protected reads (no tmutil, no ~/Library scans): the check must
#     never hang on a permission prompt or fail for lack of Full Disk Access.
#   - Report-only probes that cannot be read emit "<fact>: unavailable"
#     rather than silently vanishing — absent facts must be visibly absent.
#   - Fast (<5s) and quiet: one "fact: value" line per signal, one verdict line.
#
# Exit codes: 0 = disk capacity within thresholds, 1 = problem found (or not
# macOS). Tactical treats 0 as passing.

PATH=/usr/bin:/bin:/usr/sbin:/sbin
export PATH

# ── Platform self-check: fail loudly anywhere but Darwin ─────────────────────
UNAME_S=$(uname -s 2>/dev/null)
if [ "$UNAME_S" != "Darwin" ]; then
    echo "NOT-DARWIN: this check only runs on macOS (uname reports: ${UNAME_S:-unknown}) — a wrong-platform check assignment, not a device problem."
    exit 1
fi

PROBLEMS=""

add_problem() {
    if [ -n "$PROBLEMS" ]; then
        PROBLEMS="${PROBLEMS}; $1"
    else
        PROBLEMS="$1"
    fi
}

# ── Disk: the APFS data volume is what actually fills up ────────────────────
DATA_VOLUME="/System/Volumes/Data"
[ -d "$DATA_VOLUME" ] || DATA_VOLUME="/"

DF_LINE=$(df -Pk "$DATA_VOLUME" 2>/dev/null | awk 'NR==2 {print $2, $4, $5}')
if [ -n "$DF_LINE" ]; then
    TOTAL_KB=$(echo "$DF_LINE" | awk '{print $1}')
    FREE_KB=$(echo "$DF_LINE" | awk '{print $2}')
    USED_PCT=$(echo "$DF_LINE" | awk '{gsub("%", "", $3); print $3}')
    FREE_GB=$((FREE_KB / 1024 / 1024))
    TOTAL_GB=$((TOTAL_KB / 1024 / 1024))
    echo "disk: ${USED_PCT}% used, ${FREE_GB} GB free of ${TOTAL_GB} GB (${DATA_VOLUME})"

    if [ "$USED_PCT" -ge 90 ]; then
        add_problem "data volume ${USED_PCT}% used (>=90%)"
    fi
    if [ "$FREE_GB" -lt 10 ]; then
        add_problem "only ${FREE_GB} GB free (<10 GB)"
    fi
else
    echo "disk: unavailable"
    add_problem "could not read disk usage for ${DATA_VOLUME}"
fi

# ── Report-only signals (never affect the verdict) ───────────────────────────
BOOT_S=$(sysctl -n kern.boottime 2>/dev/null | awk -F'sec = |,' '{print $2}')
if [ -n "$BOOT_S" ]; then
    NOW_S=$(date +%s)
    UP_DAYS=$(((NOW_S - BOOT_S) / 86400))
    echo "uptime_days: ${UP_DAYS} (report-only)"
else
    echo "uptime_days: unavailable (report-only)"
fi

CORES=$(sysctl -n hw.ncpu 2>/dev/null || echo 0)
LOAD1=$(sysctl -n vm.loadavg 2>/dev/null | awk '{print $2}')
if [ -n "$LOAD1" ]; then
    echo "load_1m: ${LOAD1} (cores: ${CORES}) (report-only)"
else
    echo "load_1m: unavailable (report-only)"
fi

# csrutil/fdesetup: report-only — org policy varies; never a failure condition.
SIP=$(csrutil status 2>/dev/null | awk -F': ' '{print $2}' | awk '{print $1}' | tr -d '.')
if [ -n "$SIP" ]; then
    echo "sip: ${SIP} (report-only)"
else
    echo "sip: unavailable (report-only)"
fi

FILEVAULT=$(fdesetup status 2>/dev/null | head -1)
if [ -n "$FILEVAULT" ]; then
    echo "filevault: ${FILEVAULT} (report-only)"
else
    echo "filevault: unavailable (report-only)"
fi

OS_VERSION=$(sw_vers -productVersion 2>/dev/null)
if [ -n "$OS_VERSION" ]; then
    echo "macos_version: ${OS_VERSION} (report-only)"
else
    echo "macos_version: unavailable (report-only)"
fi

# ── Verdict: disk capacity only — never a whole-device health claim ──────────
if [ -n "$PROBLEMS" ]; then
    echo "FAIL: disk capacity - ${PROBLEMS}"
    exit 1
fi

echo "PASS: disk capacity within thresholds (other facts above are report-only)"
exit 0
