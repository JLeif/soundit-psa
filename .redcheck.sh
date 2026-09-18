#!/usr/bin/env bash
# Red-check harness: apply one BEHAVIOURAL mutation, run the suite, record the
# failure count, restore from the COMMITTED tip (never from an unverified tree).
#
# Each mutation must change behaviour while leaving signatures intact. A mutant
# that only changes a declaration would prove the suite asserts declarations.
set -u
cd "$(dirname "$0")"

TIP=$(git rev-parse HEAD)
echo "tip: $TIP"
git diff --quiet || { echo "ABORT: tree dirty, commit first"; exit 1; }

run() {
  local name="$1" file="$2" from="$3" to="$4"
  # Prove the mutation landed exactly once, or the run is meaningless.
  # Counted on the WHOLE FILE as a substring (grep -c counts lines, which is
  # wrong for a multi-line anchor and silently skipped four mutants).
  if ! python3 - "$file" "$from" "$to" <<'PY'
import sys
p,f,t=sys.argv[1],sys.argv[2],sys.argv[3]
s=open(p).read()
n=s.count(f)
if n!=1:
    print(f'anchor appears {n} times'); sys.exit(1)
open(p,'w').write(s.replace(f,t))
PY
  then
    echo "SKIP $name: bad anchor in $file"
    return
  fi
  local out
  out=$(timeout 900 ./vendor/bin/phpunit --filter HdbReportClientTest 2>&1 | tail -3 | tr '\n' ' ')
  echo "MUTANT $name => $out"
  git checkout "$TIP" -- "$file"
  git diff --quiet -- "$file" || { echo "ABORT: restore failed for $file"; exit 1; }
}

C=app/Services/Hdb/HdbReportClient.php
R=app/Services/Hdb/HdbReportRedaction.php
V=app/Services/Hdb/HdbReportResult.php

# M1 — the upload gate deleted entirely (import an unfinished press).
run M1-upload-gate-removed "$C" \
  'if (! is_bool($complete)) {' \
  'if (false) {'

# M2 — the natural permissive coalesce on the upload gate.
run M2-upload-gate-coalesce "$C" \
  '$complete = $ticket[' "\$complete = (bool) (\$ticket['uploadComplete'] ?? true); \$ignored = \$ticket["

# M3 — THE dangerous redaction default: absent flag means "not redacted".
run M3-redaction-permissive-default "$R" \
  '$diagnostic = $ticket[' \
  'return new self((bool) ($ticket['"'"'redactDiagnostic'"'"'] ?? false), (bool) ($ticket['"'"'redactScreenshots'"'"'] ?? false)); $diagnostic = $ticket['

# M4 — screenshot fetched regardless of the flag, nulled afterwards.
run M4-screenshot-fetched-then-discarded "$C" \
  '$screenshot = $redaction->allowsScreenshots() ? $this->screenshot($press) : null;' \
  '$screenshot = $this->screenshot($press); $screenshot = $redaction->allowsScreenshots() ? $screenshot : null;'

# M5 — the CIPP failure: a missing section coalesces to empty, import proceeds.
run M5-missing-section-becomes-empty "$C" \
  'if (! array_key_exists($section, $report)) {' \
  'if (false && ! array_key_exists($section, $report)) {'

# M6 — a degraded read reported as importable.
run M6-degraded-is-importable "$V" \
  'return $this->status === HdbReportStatus::Fetched;' \
  'return in_array($this->status, [HdbReportStatus::Fetched, HdbReportStatus::Degraded], true);'

# M7 — the gate DEFERRED: the session is established and the fetch attempted
# first, and only then is the refusal returned. The status is identical; what
# changes is that a refused press now costs round trips, which is the existence
# oracle the zero-request assertion exists to stop.
run M7-gate-deferred-after-network "$C" \
  '        if ($authorization->refused()) {
            return HdbReportResult::refused($authorization->refusal ?? HdbReportFetchRefusal::NoKeyedNote);
        }

        // The authorizer'"'"'s normalised id' \
  '        if ($authorization->refused()) {
            $this->session ??= $this->auth->authenticate();
            $this->get('"'"'00000000-0000-4000-8000-000000000000'"'"', self::FILE_TICKET);

            return HdbReportResult::refused($authorization->refusal ?? HdbReportFetchRefusal::NoKeyedNote);
        }

        // The authorizer'"'"'s normalised id'

# M7b — the gate itself removed: every press authorizes.
run M7b-gate-removed "$C" \
  'if ($authorization->refused()) {
            return HdbReportResult::refused($authorization->refusal ?? HdbReportFetchRefusal::NoKeyedNote);
        }

        // The authorizer'"'"'s normalised id' \
  'if (false) {
            return HdbReportResult::refused($authorization->refusal ?? HdbReportFetchRefusal::NoKeyedNote);
        }
        $authorization = $authorization->allowed ? $authorization : \App\Services\Hdb\HdbReportFetchAuthorization::allow(strtolower(trim((string) $pressId)), 1, (int) $ticketId, 1);

        // The authorizer'"'"'s normalised id'

# M8 — a malformed body becomes a clean empty payload.
run M8-malformed-becomes-empty "$C" \
  '        if (! is_array($decoded) || $decoded === [] || array_is_list($decoded)) {
            return null;
        }' \
  '        if (! is_array($decoded) || array_is_list($decoded)) {
            return [];
        }'

# M9 — the memo consulted BEFORE the gate (cross-client cache hit).
run M9-memo-before-gate "$C" \
  '$authorization = $this->authorizer->authorize($ticketId, $pressId);' \
  'foreach ($this->fetched as $k => $v) { if (strtolower(trim((string) $pressId)) === $k) { return $v; } } $authorization = $this->authorizer->authorize($ticketId, $pressId);'

# M10 — the getFile whitelist removed.
run M10-whitelist-removed "$C" \
  'if (! in_array($file, self::FETCHABLE_FILES, true)) {' \
  'if (false) {'

# M11 — the transport catch removed (a vendor outage becomes an exception).
run M11-transport-catch-removed "$C" \
  '} catch (\Throwable) {' \
  '} catch (\LogicException) {'

# M12 — every outcome memoised, so a failing press is pinned for the life of
# the process and no retry can ever succeed.
run M12-memoise-every-outcome "$C" \
  '        return $this->fetchAuthorized($press);' \
  '        return $this->fetched[$press] = $this->fetchAuthorized($press);'

echo "restored tip: $(git rev-parse HEAD); dirty: $(git status --porcelain | wc -l)"
