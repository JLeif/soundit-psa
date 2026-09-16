# Scheduled approvals (issue #1724)

## Activation runbook

**Readiness is not activation authorization.** The feature defaults off through
`SCHEDULED_APPROVALS_ENABLED=false`. Charlie alone authorizes activation in this
deployment; a reviewed change or dark deployment is not permission to flip it.
The existing technician kill switch is unchanged.

### What enabling exposes

In the cockpit, active Admin/Tech staff see **Schedule approval instead** on
awaiting proposals with recorded version-1 scheduling provenance and a registered
action. The form collects a future window, explicit time zone and human
confirmation; admission and fire-time checks still enforce identity, permissions,
lineage, clock health and the kill switch. Old proposals without lineage are not
grandfathered. Scheduling is not immediate approval or a generic delayed task.

Installed action types (only these thirteen can dispatch):

- Mailbox: `cipp_stage_set_mailbox_forwarding`,
  `cipp_stage_set_mailbox_out_of_office`, `cipp_stage_set_mailbox_delegate`,
  `cipp_stage_set_mailbox_gal_visibility`, `cipp_stage_convert_mailbox`.
- Tactical: `tactical_stage_command`, `tactical_stage_reboot`,
  `tactical_stage_shutdown`, `tactical_stage_recover_mesh`,
  `tactical_stage_maintenance`, `tactical_stage_start_service`,
  `tactical_stage_stop_service`, `tactical_stage_restart_service`.

`tactical_stage_script` and `tactical_stage_install_approved_patches` remain
registered but visibly refuse at admission with their named
`unsupported_scheduling_type:<type>` reason. Neither silently executes immediately.
They await an immutable/conditional vendor execution primitive (#1911); see
[SCHEDULED-TACTICAL-POLICY](SCHEDULED-TACTICAL-POLICY.md). Other registry entries
without adapters are not enabled by this flag. Immediate approval paths are unchanged.

The cockpit's **Scheduled approvals — latest 100** result table and the recorded
approver's **Cancel schedule** control for waiting/claimed rows remain available
when the flag is off; they are not new permissions granted by activation. Cancellation
can lose the intent race. Inspect the recorded result rather than assuming prevention.
Submitted means an observed send, not completed execution; uncertain means the effect
is unknown. Reconcile with the relevant vendor (CIPP or Tactical), never auto-retry.

### Before an authorized flip

1. Confirm the reviewed readiness release, migrations and retained evidence schema
   are present. Inspect existing waiting/claimed/intent rows and unresolved results;
   enabling also allows still-valid pending work to resume. The cockpit is bounded
   to the latest 100, not an exhaustive reconciliation inventory.
2. Verify production MariaDB UTC and system clock health using `ScheduledClock`:
   absolute skew at both read edges must be <=2 seconds, and the application runtime
   must be able to run `timedatectl show --property=NTPSynchronized --value` with a
   positive `yes` within its one-second timeout. SQLite is not clock certification.
3. Check the technician kill switch deliberately and verify existing integration
   availability, approver roles, staging lineage and explicit token grants. Do not
   change those settings or grants as an incidental part of this flag operation.
4. Verify the scheduler is running and `php artisan schedule:list` includes
   `technician:scheduled-sweep` every minute; verify the configured queue workers
   and their normal health checks too. The scheduled sweep itself runs as a console
   command, not a queued dispatch job. An entry in the list alone is not proof of
   execution: inspect recent scheduler completion/errors and private-note delivery.

Only after explicit activation approval, set `SCHEDULED_APPROVALS_ENABLED=true`
in the deployment environment and run `php artisan config:cache` using the normal
application deployment context. Verify `php artisan config:show scheduled_approvals`
reports `enabled true`. Refresh/restart long-lived application/queue/scheduler
processes through the normal operational procedure so they do not retain old config;
new web and console processes must agree. Merely editing `.env` does not refresh
cached config or a process that already loaded it. Verify cockpit availability and
sweep execution without staging an unapproved live vendor action.

### Disable and drain safely

Set `SCHEDULED_APPROVALS_ENABLED=false`, rebuild with `php artisan config:cache`,
refresh long-lived consumers as above, and verify effective `enabled false`.
This stops new admissions and pre-intent dispatch checks once those consumers see
it. It does **not** undo a persisted dispatch intent or an already sent operation:
in-flight work may finish, and a dead intent can become uncertain. Do not promise
that disabling or the kill switch retracts work past the intent boundary.

The automatic schedule is flag-gated, including recovery and note delivery. With
effective config verified off, run `php artisan technician:scheduled-sweep` manually
under the approved rollback procedure to recover/expire rows and drain private notes
without dispatching adapters. Each invocation is bounded to 100 recovery rows and
100 pending notes; inspect its `errors`/`notes` output and remaining outbox rows,
repeat as needed, and investigate orphan notes rather than calling zero deliveries
a clean drain. This command also applies the existing ciphertext-retention policy.
Cancel waiting/claimed approvals through the recorded approver's stop-only control
where appropriate; disabling does not itself cancel them, so account for them before
any re-enable. Keep intent/submitted/uncertain evidence for reconciliation, preserve
uncertain fences, and never revive a Scheduled proposal, drop populated tables or
replay an unknown effect. An inverse vendor operation needs separate approval.

Sources: `ScheduledMailboxController`, cockpit views, `ActionRegistry`,
`MailboxPlan`, `TacticalPlan`, `ScheduledAdmission`, `ScheduledCoordinator`,
`ScheduledClock`, `ScheduledSweep` and `routes/console.php` at this repository tip.

## Historical PR1 substrate notes

The following describes the original PR1 increment, **not current adapter/UI
availability**. The activation runbook above describes the shipped PR2/PR3 surface.

This increment is **not an executable scheduled-action feature**. It adds a dormant
server-side authorization ledger, not an adapter or a new bearer credential. There
is no schedule route/UI, production evidence provider, vendor client or action-bus
call. `config/scheduled_approvals.php` is false by default; the registry has exactly
30 future action contracts but `adapterAvailable()` always returns false. Even a
locally enabled flag cannot send anything. Immediate approvals and offline queue
queries remain unchanged. Scheduled runs cannot pass the existing immediate CAS.

## Boundaries

- `ApprovalWindow` rejects IANA gaps and folds, including non-hour transitions.
  Start is strictly future and at most seven days out; explicit end is after start
  and no more than 24 hours later. Saved UTC and offsets never move with rendering.
- `ApprovalEnvelope` encrypts the whole canonical binding and authenticates its
  digest with the application key. Full messages/addresses, not length/domain
  summaries, are bound. Key rotation/unreadable ciphertext refuses; it does not
  silently re-authorize. The envelope includes run/content revision, action/tool,
  exact client/ticket, approver, source token, provenance and window.
- `ScheduledAdmission` is an internal API requiring a read-only `ScheduledEvidence`
  implementation. **None is registered here.** Future adapters must implement
  exact action policy, full payload validators, tenant/object/mapping provenance
  and client access checks. A plain domain/UPN is not immutable identity evidence.
  Legacy staged rows lack instrumented provenance and refuse. The future staging
  integration must write real source token identity; no label-based grandfathering.
- Active Admin/Tech only; Billing/Contractor denied. Token must remain active and
  explicitly contain the staged capability. Broad/null and immediate-only grants
  are intentionally insufficient. Native-human provenance must identify an active
  authorized human. Future token client-scope extensions must be checked too.
- Admission locks the proposal, creates the sealed row, reserves run and target,
  changes its state to `scheduled`, and inserts the initial note in one transaction.
  Repeated identical admission returns the same row; changed confirmation refuses.
  DB unique reservations serialize all effects on a pinned tenant/object within a
  client, **conservatively including nonoverlapping windows** while a reservation
  is live. This stricter substrate restriction may be narrowed only with overlap
  controls; it never silently coalesces separate approvals.
- Claims are fenced by UUID and attempt count. Time is DB UTC on MariaDB. Clock
  health requires positive `timedatectl NTPSynchronized=yes`, bounded subprocess
  duration, and absolute DB/system skew **<=2 seconds at both read edges**. Missing
  tooling/unknown NTP/slow clock reads fail closed. This is not physical-time proof
  when both clocks are wrong. SQLite is not operational clock certification.
- Final intent checks exact bindings, provenance and permissions again, half-open
  window and nonce/state. In PR1 it terminates as `adapter_unavailable`. Future
  adapters must not bypass this fence or reuse generic immediate approval routes.
  No external network call may run inside the transaction.
- Pre-intent defer supports only offline/read-unavailable/cooldown/kill-switch/
  clock-unhealthy. Backoff is 1/2/4/8/15 minutes, respects a later cooldown, caps at
  100 claims and original expiry. Changed wait reasons produce one note. A crash
  before intent can be reclaimed only after invalidating the old nonce.
- A persisted intent is never automatically reissued. Abandoned intent becomes
  terminal `uncertain`, including death before an unobservable send. Submitted is
  not completed, but its send was observed, so only uncertain target fences remain
  for later read-only reconciliation; this PR deliberately has no fence-clear or
  reconcile endpoint. A failure proven to precede the send settles `failed`.
  Cancel/expiry cannot report prevention after intent. Terminal rows never become
  awaiting approval or legacy offline queue rows. Replacement requires a new
  proposal/fresh confirmation, not mutation or revival of the old row.
- Result state and outbox event commit together. Delivery inserts a private System
  note and acknowledges it under the outbox row lock in the same local transaction.
  No public reply/email API is called. Notes contain only fixed event/reason codes,
  run/approver identifiers and schedule bounds. Missing tickets retain orphan audit
  with `ticket_missing`. Submitted/uncertain notes require reconciliation explicitly.
- Ciphertext remains through terminal state plus 30 days, then is nulled, digest
  retained. Submitted is nonterminal pending reconciliation. No schema cascade
  deletes evidence. Migration rollback refuses when any authorization exists.

## Tests and limitations

`ScheduledApprovalPrimitivesTest` covers exact allowlist count/exclusions, no
adapters, canonical full-input sealing and strict window/DST validation.
`ScheduledApprovalTest` covers service admission/idempotence, clock/permissions,
lineage revocation, binding mutations, target conflict, fencing, cancel/expiry,
pre-intent retry and terminal uncertainty, note dedup and retention.

`ScheduledApprovalMariaDbTest` repeats the service controls on isolated MariaDB
10.11 and adds independent PHP-process admission/claim/cancel races, SIGKILL after
claim and synthetic intent, SIGKILL between note insert/ack, and fault triggers at
admission/settlement outbox boundaries. It requires an explicitly supplied
`SCHEDULED_TEST_SOCKET` ending in `/test.sock`, a sibling `db-launch.pid`, database
`scheduled_synthetic_test`, root with no password on that isolated socket and
`@@skip_networking=1`. It **destroys only that synthetic database**. Set
`SCHEDULED_TEST_EXTENSION_DIR` if the test PHP needs isolated mysqlnd/pdo_mysql
extensions. Without the socket the MariaDB class skips, not passes certification.
CI's SQLite run cannot establish concurrent locking/crash behavior.

Synthetic intent rows are deliberately seeded to test future dispatch-boundary
recovery. No actual provider request, provider acceptance, upstream idempotency or
adapter correctness is certified by PR1. Later PRs still owe all exact per-action
argument/mutation controls, live read-only evidence contracts, staging provenance
instrumentation, UI and integration/kill-switch gates. #1399 is Mesh-specific and
is not fixed or a gate for these scheduled-only notes.

## Rollback and release

Before merge, abandon/revert the unmerged branch. After an authorized deployment,
disable new admissions/dispatch; retain additive schema and immutable evidence.
Cancel waiting/claimed authorizations transactionally and drain private-note
outbox; leave intent/submitted/uncertain rows for controlled reconciliation. Do
not drop populated tables or reinterpret Scheduled as an old executable state.
An external operation is never undone by code rollback; inverse effects need a
separate approval. No production setting, grant, integration or adapter activation
is authorized by this PR. Full exact-tip gate, MariaDB controls, CI and held review
with independent adjudication remain release prerequisites.
