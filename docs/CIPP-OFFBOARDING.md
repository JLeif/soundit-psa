# CIPP offboarding admission (PR A)

This is a held-only, default-ungranted admission capability, not a completed offboarding workflow. Read-only reconciliation, progress and terminal staged-action detail are separate PR B work. Do not enable live use or hand off verification until both capabilities are released and the operator-owned prerequisites below are satisfied.

## Boundary

`cipp_offboard_user` requires an explicit grant and `staged: true`, explicit client/ticket/person, confirmed UPN, reason and selected actions. The staged alias uses the same path. Exactly eleven action names are supported; inspect `OffboardingPlan::ACTIONS` for the authoritative list. Unknown fields and destructive options are rejected even when false. No license removal, deletion, wipe, password reset, rerun, scheduling, notifications or vendor PsaTicketId is serialized. All licenses remain; shared mailbox conversion is not a license-compliance assertion.

An encrypted snapshot binds server-resolved tenant/target/successor, selected actions, reference and initiating token. The displayed preview and metadata must agree with it. Approval confirms revision, hash and every selected action. Active approver, explicit grant, integration/kill switch and current identities are checked again before admission.

## Durable contract

MariaDB transactions reserve a unique operation per staged run, permanent plan receipt and target-ID/UPN fences across tickets. The prepared -> send_intent compare-and-swap and audit commit before the single HTTP attempt. No redirect, HTTP retry, expired-lease resend, generic releaseClaim or recovery-safe reopening is permitted. A transport error or missing/malformed response retains intent and fences. HTTP 200 plus a scheduling receipt is queue acceptance only, never execution or verified selected effects.

Same-client spent conflicts name the prior operation, ticket, date and separate-new-card lifecycle path. Cross-client conflicts do not disclose those identifiers. No reset verb exists. Database rollback refuses to erase populated operation evidence; do not drop tables or clear reservations as operational recovery.

## Operator-owned pre-live prerequisites

These are blockers, not requests to change live credentials:

* The integration credential must successfully perform CIPP.Scheduler.Read. Visible and hidden Name+Type+tenant reads must both succeed; unknown or active tasks block admission.
* Installed Craft/CIPP runtime evidence must prove requested Sequential execution is actually honored. API source SHA alone is insufficient: older Craft may silently fall back to fan-out.
* A stable installation UUID setting `cipp_offboarding_installation_id` and bounded runtime evidence `cipp_offboarding_sequential_evidence` must be established through separately authorized administration. Evidence contains integration fingerprint, boolean sequential, version, checked_at and expires_at; lifetime is at most one day. No UI or automatic grant mechanism is added here.

The read/send gap cannot be eliminated across vendors: source identity or externally submitted work can change after validation. PSA single-attempt admission is not vendor exactly-once execution or transactional compensation.

## Source map

Pinned upstream CIPP-API tree: `c04bde0f4b53280c1ed21d838ba2c4bbcfc8a600`.

* `Invoke-ExecOffboardUser.ps1`: per-user scheduled jobs, optional DeploymentId, no duplicate-name switch; both immediate and scheduled branches explicitly use hidden=false. This adapter supports immediate-after-approval only.
* `Invoke-ListScheduledItems.ps1`: no ShowHidden selects visible only; ShowHidden=true selects hidden only. Both partitions are read to catch externally submitted jobs. The Id branch bypasses visibility filtering (future recovery). Task tenant aliases are resolved upstream.
* `Test-CIPPOffboardingRequest.ps1`, `Invoke-CIPPOffboardingJob.ps1`: request and effect keys; omitted options are not silently defaulted into the request.
* `Start-CIPPOrchestrator.ps1`: Sequential fallback depends on installed Craft arity.
* `Invoke-ListTenants.ps1`, `Get-Tenants.ps1`, `Invoke-ListUsers.ps1`: canonical customerId/default/initial domain aliases and tenant-scoped user identity. A fresh HTTP tenant listing still reads upstream tenant cache; it is not proof of a freshly queried directory domain inventory.

## Test evidence scope

Ordinary PHPUnit/CI uses synthetic sqlite and skips the dedicated MariaDB ledger suite. MariaDB tests require the explicitly named synthetic database, private socket, MariaDB version check and skip_networking; never load deployment credentials. Fresh child processes exercise crashes around prepare and intent, concurrent send admission, expired lease during hung send and retained audit/receipt failures. Remote persistence/queue boundaries are simulated callbacks, not real vendor calls.

Mutation controls must run at the final committed tip and record kill mechanism. Assertions inside the transport callback can be caught by the production ambiguity handler: observe there, assert outside. Full gc-verify and CI do not replace local MariaDB evidence. Exact-tip counts and receipts belong in the PR handback, not timeless documentation.
