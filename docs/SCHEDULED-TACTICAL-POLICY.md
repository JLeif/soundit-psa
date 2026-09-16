# Scheduled Tactical policy — PR3

Specification #1724; boundary issue #1783; implementation #1889.
This candidate enrolls eight Tactical adapters alongside five mailbox adapters. The
scheduled feature remains default-off; implementation is not deployment or activation.
Immediate Tactical script and approved-patch behavior is unchanged.

## Exact type boundary

The following registry names are approved, but registry membership is not adapter availability:

| Action | Direct tool | Required argument boundary |
|---|---|---|
| tactical_stage_script | tactical_run_script | Script ID plus immutable executable revision, args and timeout; no silently changed interpreter, snippets or execution identity |
| tactical_stage_command | tactical_run_command | Exact command, closed shell enum, bounded timeout; no custom interpreter, environment or run-as-user override |
| tactical_stage_reboot | tactical_reboot_device | Exact device and existing hostname confirmation; no additional submode |
| tactical_stage_shutdown | tactical_shutdown_device | Same, including shutdown consequence |
| tactical_stage_recover_mesh | tactical_recover_mesh | Device recovery mode `mesh` only; not arbitrary recovery mode |
| tactical_stage_maintenance | tactical_set_maintenance | Exact boolean intent, never truthy coercion |
| tactical_stage_start_service | tactical_start_service | Exact service identity and start operation |
| tactical_stage_stop_service | tactical_stop_service | Exact service identity/stop and existing confirmations |
| tactical_stage_restart_service | tactical_restart_service | Exact service identity/restart and existing confirmations |
| tactical_stage_install_approved_patches | tactical_install_approved_patches | Exact reviewed patch set, not the set dynamically approved later |

The existing per-tool validators and confirmations remain required; this table does not
replace them or authorize more permissive limits. Unknown arguments and submodes are not
implicitly authorized by an approved action name. `tactical_stage_recover_mesh` is not
`mesh_stage_*`; those independent mail-security rule actions remain excluded. Tactical
admin/bulk and remote-control session actions remain excluded. The approved mailbox rows
are not an allowlist contradiction either. No prefix matching or fallback to immediate
approval is permissible for scheduling.

## Pinning is a producer contract, not a preflight hash

Source inspected: public `amidaware/tacticalrmm` revision
`1e786d37cae29120b64117659e61df39c1a8d142`:

- `api/tacticalrmm/agents/views.py::run_script` resolves a mutable Script PK from
  `request.data["script"]` and calls `agent.run_script(scriptpk=...)`.
- `api/tacticalrmm/agents/models.py::Agent.run_script` fetches that PK again,
  parses current script arguments/environment, uses current `script.code` and
  `script.shell`, and forces run-as-user if the current model requests it.
- PSA `TacticalClient::runScript` submits the PK; it does not submit an expected
  revision/content digest or immutable executable bytes. A GET/hash/POST sequence
  cannot prevent replacement after the GET. A fixture pretending it can is not proof.
- `api/tacticalrmm/winupdate/views.py::InstallWindowsUpdates.post` invokes
  `approve_updates()` and derives current approved GUIDs on the server. PSA
  `TacticalClient::installApprovedPatches` submits an empty body. Sending an
  invented patch-set field would not constrain this producer.

Both script and patch scheduling remain unavailable: registry entries are preserved,
`adapterAvailable` is false, and admission refuses before provider reads or authorization
creation with `unsupported_scheduling_type:tactical_stage_script` or
`unsupported_scheduling_type:tactical_stage_install_approved_patches`. An absent registry
name instead refuses with `scheduling_type_not_registered`. The cockpit scheduling
boundary exposes the same fixed reasons. Design-only follow-up #1911 owns the missing
conditional/immutable vendor primitives; this PR does not implement an alternative path. Do not silently replace
script execution with commands, create/mutate vendor scripts, weaken revision pinning,
or claim a preflight comparison closes the race. This assessment is of the cited public
producer, not certification of any live installation.

## Shared human-input fence (#1885)

`ScheduledCoordinator::intent` now requires both independently sealed top-level
`human_inputs` and `binding.human_inputs` to be arrays and canonically equal **before**
provider revalidation. The subsequent locked envelope equality remains in place.
Object-key ordering does not matter; field presence, scalar types and list order do.
Divergence blocks terminally without intent or vendor submission. Admission still seals
raw human inputs independently; this does not relabel #1780's capture work as missing.

`ScheduledCanonicalInputsTest` exercises actual mailbox admission, coordinator, adapter
and fake HTTP transport. Equal copies submit once; mismatched same-domain addresses,
dropped/extra/nested fields and malformed copies refuse. Controls prohibit live traffic.
The original deliberately lossy admission fixture remains to prove independent capture
and repeat-confirmation refusal, separately from the new fire-time equality check.

## Eight-adapter execution contract

`TacticalPlan` accepts only exact parameter sets. Commands use `cmd|powershell|shell`
and integer timeout 10–600; custom shells, environment and run-as overrides are absent.
Maintenance requires an actual boolean; recovery is mesh-only. Services bind exact SCM
names, not display aliases. Staging records MCP-token provenance and flags legacy argument
coercion as scheduling-ineligible rather than changing immediate behavior. Existing
hostname/service confirmations and cooldown checks run at admission and revalidation.

Fresh evidence binds PSA ticket/asset/client association, unique configured Tactical site
FK, agent ID, exact hostname, integration URL namespace and service identity. Offline or
unavailable reads may defer only before intent and within the window. Reconnect processing
cannot execute a Scheduled run. Scheduled tombstones cannot be revived by restaging.

The shared coordinator owns claim, intent, nonce, target fence, cancellation and recovery.
Only the intent winner enters the existing audited Tactical action bus. Scheduled transport
uses one request, no redirect/retry/fallback. Producer-specific exact receipts classify
reboot/shutdown/recovery/maintenance/service results; a raw command receipt is merely
`submitted` because it has no reliable exit-status contract, and it releases its
reservations because the send itself was observed. Bus refusals decided before execution
(`denied`/`rejected`/`blocked`) and any binding failure before the send settle `failed`,
which claims no execution, records the operator-visible reason `no_vendor_request`
rather than a vendor receipt, and releases the reservations too. That reason is supplied
by the Tactical dispatcher, which reaches `failed` only before the send; the shared
coordinator never infers it from the outcome, so an adapter whose `failed` is derived
from a vendor response body still records `vendor_receipt`. Unexpected bodies, transport
exceptions and post-intent crash recovery become `uncertain`, retain the target fence and
never automatically replay. No raw command result is included in the scheduled audit note.

Tests: `ScheduledTacticalTest` drives real admission, evidence, action bus and Guzzle client
with a hermetic handler; `ScheduledTacticalMariaDbTest` adds separate-process
send/send, send/cancel and process-death boundaries against isolated socket-only MariaDB.
The shared #1780/#1885 controls remain independent. Exact-tip warning-strict gates and CI,
then separately admitted held review, are required; tests alone grant no release authority.
Rollback before merge is branch discard/revert only; no production state has changed.
