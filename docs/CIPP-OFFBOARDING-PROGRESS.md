# Offboarding reconciliation and scoped receipts (PR B, #1530)

The feature remains held-only and default-ungranted. This change grants nothing and never
invokes a vendor write during recovery. Admission, task persistence, reported job completion
and independently verified selected effects are different facts. **All effects remain
unverified**: this adapter does not implement the future per-effect verifier or claim that
revocation invalidated every existing token. Chet verification waits for PR B on main.

## Surface and authorization

`get_staged_action_status` retains its pending-list behavior. Supplying a positive integer
`run_id` selects local offboarding detail (including terminal run receipts), requires explicit
client scope, and excludes listing filters. Scope is supplied by the authenticated executor,
never adopted from a raw detail argument. Wrong-client and missing IDs have the same refusal.
Detail performs no network work and returns no vendor tenant, user, task/deployment ID, raw
message, payload, or preview. Messages are represented by digests; action/status summaries
are bounded by the selected manifest. A stale observation never becomes failure or success.

An operator with shell authority can invoke one bounded read:

```
php artisan cipp:reconcile-offboarding <local-run-id> <local-client-id> <staff-observer-id>
```

This is not exposed as a token-granted send tool or installed scheduler. Staff authority is
checked before reads and again before persistence: active administrator, or active technician
assigned to the run's ticket, whose client must match the run. An administrator has fleet
staff authority but still must select the exact run/client binding. The CLI staff id is an
audit/ACL identity selected by a trusted shell operator, not a remote authentication scheme.
It can run while admission is paused; a changed integration identity refuses vendor reads.
Original sealed tenant routing is retained on client remap. CIPP's current allowed-tenant
filter remains an additional gate. No all-tenant fallback, no credential or grant mutation.

Approval uses this same explicit ticket/client staff check and rejects the ticket's recorded
human creator as approver. The wizard is proposed only by an authenticated MCP bearer token
and approved by an active human User; their identifiers are different principal namespaces,
not comparable integer IDs. Tokens currently have no human-owner field, so this is not proof
that the human controlling the token differs from the approver. Stronger person-level
separation requires a separately designed token-owner binding; no such identity is invented.

## Evidence and source pin

Contract pinned to CIPP-API `c04bde0f4b53280c1ed21d838ba2c4bbcfc8a600`:

- `Invoke-ExecOffboardUser.ps1`: options retain all body keys except user, tenantFilter and
  Scheduled, including reference. Parameters contain Username, APIName, options,
  RunScheduled and optional DeploymentId. Notification fields are absent in our submission.
- `Invoke-ListScheduledItems.ps1`: Name/Type lookup reads two exclusive visibility partitions
  (default visible, then ShowHidden=true); either read failing is unavailable, never empty.
  Stored Id lookup bypasses the hidden/name/type filters, but not tenant/access filtering.
  Hence local binding checks remain mandatory even for Id lookup. Tenant is an object with
  value and type. Recurrence is normalized to Once.
- `Get-CIPPAsyncDeployment.ps1`: progress is an array of Name, Source, Status, TaskId,
  TenantFilter, Steps and Logs; **no returned DeploymentId field**. Correlation uses the
  exact server-bound GET argument plus returned task/tenant/target, not a guessed envelope.
- `Invoke-CIPPOffboardingJob.ps1` TaskOrder: eleven retained action/title mappings in
  `OffboardingProgress::TITLES`, in vendor order. Notifications/duplicates/missing steps
  are contract drift, not silently omitted work. `Set-CIPPAsyncDeploymentStep.ps1` accepts
  only pending/running/succeeded/failed. Empty progress before the worker initializes its
  TaskId/manifest is unknown. `Push-CIPPOffboardingComplete.ps1` can set scheduler Completed
  despite step failures; no selected effect is independently verified by this report.

Exactly one own-reference task must match tenant, target, command, options, recurrence and
stored identity. Multiple/malformed/conflicting tasks remain uncertain. Response DeploymentId
alone is not admission. Missing stored deployment leaves task evidence but cannot manufacture
progress. Own-reference lookup never adopts a foreign-reference row based on name alone.

Encrypted append-only observations preserve every local read result, with observer, start
and commit time. Operation-row locking serializes observation commits; the observed prior-id
must still match. Concurrent readers without an ordering proof set a sticky conflict instead
of overwriting silently. Terminal-step regressions, changed terminal messages, scheduler vs
progress contradictions and task/deployment conflicts remain uncertain. No vendor revision
is available, so this is deliberately conservative; it is not a global ordering guarantee. An
unavailable read is absence of evidence, not contradiction: it is retained as its own
observation but is never scored as a regression, so a transient vendor outage cannot latch the
sticky conflict. It is equally never used as the regression baseline: the next read is scored
against the last observation that carried evidence, so a terminal-step or terminal-state
regression arriving after an outage is still flagged instead of being cleared by the
intervening placeholder. A normal queued/running advance into reported-but-incomplete steps ranks with
terminal-row evidence and is likewise not a regression; only a reported terminal state moving
backwards is. Unavailability/regression does not delete older observations. Audit and observation commit
atomically. Neither admission/send intent/generation nor run state/fences/spent plans change.

## Blockers, limits and rollback

Both Charlie-owned pre-live dependencies remain **blockers, not requests**:
1. CIPP.Scheduler.Read on the PSA credential.
2. Read-only installed Craft/CIPP compatibility evidence proving Sequential is honoured.

A successful report is not a full offboarding policy, licensing attestation, immutable-ID
conditional execution, vendor exactly-once guarantee or cancellation. No retries, reruns,
resets, scheduling, OOO, notifications or destructive options are added. No automatic poller
is installed; authorized operators own refresh and investigation. A run can stay Executing
while its separate receipt reports terminal scheduler evidence: Done would overstate effects.

Fence description corrected for #1553: stable **unkeyed SHA-256**, not HMAC. No key rotation,
hash rewrite or change to permanent reservation identity; candidate matching remains possible
for someone with database access. #1529 compares the original explicit client argument before
restoring it after generic argument stripping; #1542 adds the contextual approval checks.

Rollback before merge: leave branch unmerged. After authorized release: reviewed code disable
or revert through the normal gate, retaining operation, fence, spent-plan and observation
tables. Migration down refuses populated observation tables. Never erase evidence as rollback.

## Test boundary

Pure/parser and HTTP-fake tests are synthetic only. SQLite CI does not certify locking or
crash safety. `OffboardingReconcilerMariaDbTest` explicitly requires an isolated socket-only
MariaDB 10.11.x and dedicated `cipp_progress_synthetic_test`; admission controls use a separate
`cipp_admission_synthetic_test`. Fresh child processes inject SIGKILL during read, after
observation insert, after audit insert, and after commit/before status. Restarts never send.
Two process readers are barrier-controlled, preserving both observations and conflict.
Test fixtures never invoke real vendor APIs. Exact-tip counts/mutation mechanisms are in the
PR and handback, not implied by the existence of these tests.
