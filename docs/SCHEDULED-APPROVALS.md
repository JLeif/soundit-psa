# Scheduled approval substrate (PR1, issue #1724)

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
  not completed. Uncertain/submitted target fences remain for later read-only
  reconciliation; this PR deliberately has no fence-clear/reconcile endpoint.
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
