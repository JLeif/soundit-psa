# Decisions and retained development obligations

These are retained rulings, not new authorization for any action. Forge maintains the record; Jeeves adjudicates development changes; Charlie owns product/design and production decisions.

<a id="pricing"></a>
## 2026-07-13 — Pricing

Source: `863bbcd60fc7721a1891cf3ac22b753672e22e39:CLAUDE.md`, Contracts & Billing architecture (owner ruling dated in the original). Full wording retained:

- **Graduated vs volume tiers — two rate cards, one seam, line overrides SKU default** — The billing engine carries two *different, both legitimate* tier pricing models with deceptively similar shapes (each is an ascending `up_to` + `unit_price` list with an unbounded final entry). **Volume** (per-SKU `backup_storage_tiers`, backup-storage lines only): picks the one tier covering the measured quantity and bills the *whole* quantity at that single rate. **Graduated** (per-profile-line `pricing_tiers`, any quantity type): splits the quantity into bands and bills *each band* at its own rate. Same numbers, different money — 300 GB over 1.00/0.80/0.60 is **$240 volume** but **$260 graduated**. They are deliberately kept distinct: same shape with opposite semantics is exactly how you mis-bill a client, so do not "unify" them without also unifying the semantics.
  - **The SKU's pricing method is a DEFAULT, not a constraint.** A line that carries its own graduated bands overrides the SKU's volume rate card — allowed at every door (profile line create/edit, bulk `set_quantity_type`, and the SKU tiers form gaining a card under an already-graduated line), never refused, no confirm dialog. `BillingService::priceLineSegments()` — the single seam where a (line, quantity) becomes money — encodes **graduated (line) > volume (SKU) > flat**: a line-level setting beats one inherited from the product, the same precedence as `unit_cost_override ?? sku->unit_cost`. (Owner ruling 2026-07-13: SoundPSA targets small/single-member MSPs where the SKU-creator is usually the invoice-creator and per-client pricing varies; a mandatory SKU method is friction without benefit.)
  - **The override is never silent.** Real money must not hang on a code-precedence rule the operator never sees, so which card applies is stated everywhere the billing decision is made or reviewed: an inline note beside the graduated toggle in the profile line editors (driven by `data-has-volume-tiers` on the SKU options), a per-line rate-card badge + explicit override notice on the profile show page, a note on the SKU tiers editor naming the profile lines that override its card, the applied card in invoice preview (`quantity_source`), and an info log at generation. `App\Support\PricingModelOverride` is the single predicate all of them consult — an override needs all three of: graduated bands on the line, a volume rate card on its SKU, **and** `quantity_type = PerBackupStorageGb` (the only type that reads the card; a graduated per-user line on the same SKU overrides nothing, and no surface may claim it does).
  - **Audit record.** Where a *non-flat* rate card priced a line, the invoice line's `quantity_source` names it — `[graduated: N bands]` or `[volume tier rate $X/GB]` — so the record can never claim a rate that was not applied. It is appended to the quantity description, or stands alone when there is none: a **Fixed** line records no quantity source (the operator typed the number; nothing to audit about *how much*), but Fixed + graduated still records `[graduated: N bands]`. Only a plain **flat Fixed** line has a null `quantity_source`.

<a id="authority"></a>
## 2026-09-13 — Freeze, release and production authority

Source: card `6aa664b8`, reaffirmed by card `6i4cN8BC` (Charlie: “Lift it. Yes, you should make these calls in the future.”). Jeeves is Charlie's standing development delegate and makes PSA freeze/release calls, including ordering reviewed, green, adjudicated changes to land through the normal gate. A specific PR hold remains until its owner lifts it. A watchdog alarm, stall gauge or drained queue is not release authority.

Production settings, grants, credentials, live-route activation, schema-destructive operations, client-data migrations and irreversible production actions require Charlie's explicit go for the specific action. Development approval is not production authorization. Escalate ambiguous product/scope judgments, security beyond a mechanical fix, schema/data-loss risks, money, customer impact and irreversibility to Jeeves; they cannot spend Charlie's production authority.

<a id="release-conduct"></a>
### 2026-09-25 — Merging is not releasing (card `PNxPmLMO`)

A seat that merges to `main` owes the release in the same sitting. It must either deploy and verify at the consumer boundary, or state on the owning card that the merge is deliberately unshipped and name who ships it. Ending silently between those two is the one forbidden outcome.

Observed 2026-09-23 on card `57SuhqPY`: a seat merged, ran `psa-deploy-gate.sh`, saw it PASS, and stopped. For about fifteen minutes the ledger, CI and the deploy gate all read green while production ran the old code. **A GATE RECEIPT IS AN AUTHORIZATION, NOT AN ACT.** It was caught by a later session re-deriving at source, not by any alarm.

A deliberate no-deploy is legitimate and is recorded, not merely intended: the sha goes in the no-ship acknowledgement list with a reason, so correct inaction is distinguishable from neglect. Detection is a drift gauge comparing `origin/main` against the deployed HEAD; it reports what it measures (two shas, a clock, whether a deploy gate has authorized the tip) and does not assert why, because a gauge may fire correctly and still accuse the wrong cause.

<a id="review"></a>
## Review contract retained on 2026-09-15

Source: gate SPEC (2026-07-28; review-brief amendment 2026-09-12), recorded by parent card `CKFuKp9m`, leg-1 ruling `6aa9df4732eaa04b2e8a65bd`. This repository copy retains the obligations for contributors; it does not modify the harness or rubric.

FIND → ADJUDICATE → REWORK → VERIFY is structural, not an approve/reject loop. FIND uses independent diff/context/contract slices (not persona selection or reviewers calling reviewers), plus a deterministic seat for syntax, changed-module smoke, invariants and secrets. Report every finding with evidence and a concrete failure scenario, not a verdict. Each timed-out model seat gets one replacement, then forfeits; quorum is any two of three model seats plus the deterministic seat.

The arbiter classifies existing findings, not new ones, with three samples and per-finding majority. Classes are must-fix, ticket, discard, with duplicates identified. Two of three win; without a majority choose the most severe (must-fix > ticket > discard). A security finding majority-discarded is still filed as a ticket flagged for Charlie, never silently discarded.

**RUBRIC:** must-fix means incorrect results on valid input; crash/hang on admitted input; introduced security vulnerability (injection, authorization bypass, secret exposure, unsafe deserialization, externally triggerable resource exhaustion); data loss/corruption; or broken documented API contract/invariant relied on by callers. Ticket means real non-blocking degradation outside the evident contract, maintainability/naming/structure, missing tests/docs, hardening beyond the contract, performance without a demonstrated pathological case, or valid findings outside the rework delta. Discard means factually wrong, non-behavioral style preference, speculation without a concrete scenario, or duplicate (mark duplicate_of). Standards prose does not itself amend those classes.

The author fixes exactly must-fix findings; tickets are filed rather than fixed in that cycle. No change against standing must-fix findings goes to terminal verdict. VERIFY reviews only the rework delta: confirm each fix with evidence; new findings on the delta are fully valid and adjudicated by the same majority, outside it advisory. No new must-fix permits a merge recommendation; otherwise repeat rework/verify up to three cycles total. Terminal vocabulary: merge, merge-with-final-edit, reject, escalate. Overridden objections are filed as tickets. A recommendation is not release authority.

<a id="held"></a>
## Held review and landing

Source: development protocol dated 2026-07-29, parent `CKFuKp9m` §4, retained by leg-1 ruling `6aa9df4732eaa04b2e8a65bd`.

`hold: true` requests human-grade review. Verify branch state in git, not a payload; assess every finding on its merits, not panel authority. Run guards and the full suite on the landing tree, red-check new guards against unfixed code, and require CI green at the exact SHA. Every added commit, even test-only, goes back through review before merge. Compare PR head with the ledger's `completed_payload->>'sha'`, and merge that recorded SHA, not an assumed branch head. After release authorization, merge `--no-ff` with the verdict in the message, deploy through the normal gate, then verify production HEAD and `/` → 302 → `/login` → 200. Report on the owning card. None of this authorizes a deployment on its own.

<a id="ledger"></a>
## Held-verdict ledger

Source: card `Y9XACeDi` (2026-09-15), parent `CKFuKp9m` §4. The holder records DECISION/PIN and the residual MANIFEST before filer/landing consumption; narration is not a ledger act. Use the installed ledger client and binding contract, not hand-invented markers. Missing holder-appended DECISION/PIN refuses filing. Preserve distinct UNPINNED and UNMANIFESTED dispositions; neither is acceptance. Ledger binding does not waive unresolved findings or grant release authority.

<a id="development"></a>
## Issue-first and vendor isolation

Sources: cards `6aa86bf7` (2026-09-14) and `6aa9bf6f` (2026-09-15). Open the issue before implementation, use an isolated owned branch, and scope a change to its brief. Tests use `Http::fake` plus `Http::preventStrayRequests`; dev/tests/CI must not make live vendor calls. Vendor credentials are read only through their Config path. Present one exact-tip held review, not competing launches. Jeeves owns release; Charlie owns the live click. A source fixture does not grant permission to call a vendor.

<a id="evidence"></a>
## Mutation and red controls

Sources: card `6aa9bf6f` handback 2026-09-15 and card `6aa69606`. Handback identifies each meaningful mutant, its intended behavioral defect and the assertion that killed it. Run new guards against unfixed code; a missing symbol/import or setup failure is not a behavioral red. Include a positive control reaching the behavior on both sides, restore the original source, and rerun guards and the full suite green. Prove the loader binds to the tree tested; a linked dependency tree can silently test a sibling. State exact SHA, commands, results, denominator, failed/skipped checks and limitations. Documentation controls may mutate documentation, not runtime behavior; do not claim those controls validate application logic.

<a id="brief"></a>
## Driver brief admission

Source: SPEC amendment 2026-09-12 and card `6aa39605`. Keep the descriptor in `domain`, terminate its sentence, add a blank line and `Review brief:`, and preserve the full brief. `notes` is rebase-only (`rebase.notes`); top-level review notes are dropped. The installed `preflight.cjs` must run before any separately permitted launch: exit 2 (dropped notes) or 3 (unverified) refuses clearance; exit 0 is narrow static clearance, not launch/release authority. More than 2,000 characters is advisory, not a model limit. Do not truncate or rewrite historical payloads.

<a id="push"></a>
## Durable work before review

Source: development ruling 2026-07-31, parent `CKFuKp9m` §4. Work carrying a real fix must be committed and pushed to origin as soon as it exists, before review and before ending the session. Verify the remote ref and clean local state; resolve failed pushes rather than leaving work only in a worktree. Push carries a branch ref, not merge, deployment or release authorization. File remaining work, run gates, update status and hand back context; do not erase another author's residue while cleaning up.

<a id="hygiene"></a>
## Public-source hygiene

Source: parent `CKFuKp9m` §4 and corrected leg-1 ruling 2026-09-15 on `6aa9df4732eaa04b2e8a65bd`. The public tracker credential files under Charlie's own account (`Wldc4rd`): each filing is a technical claim in that name. No client material, secrets, internal hosts, IPs or absolute infrastructure paths in public issues, PRs or source citations. Cite commits, tests, PR/issue numbers, card IDs or repository documents. Issue bodies are create-only in this workflow: correct with a comment; PR titles/bodies are editable. A green secret regex is not proof of privacy. Newly discovered unfixed vulnerabilities require private escalation, not public disclosure.

Source: card `zLXEBPec`, Charlie's 2026-09-22 ruling (“integration mechanics are ours to document”), recorded by Jeeves the same day.

Vendor integration mechanics (endpoints, parameter/query shapes, redirect chains, auth flow, product names) may be documented in public source, tests, issues and PRs; credentials, client-derived identifiers and vendor security weaknesses may not (G-9; private escalation).

This retires Jeeves's 2026-09-04 bar on putting integration detail in the public tracker, so code and issues follow one rule again. The existing prohibition on client material (including real press IDs from real client tickets), secrets, internal hosts, IPs and infrastructure paths remains binding; vendor security weaknesses stay under private escalation.

<a id="modes"></a>
## Fail-closed MCP modes

Source: card `6aa986d5`, retained 2026-09-15. Unknown is refusal, never a pass. A bare grant runs immediate; held-only verbs never offer immediate execution. Do not infer mode authority from a permissive fallback or missing grant data. Preserve the distinction between a capability's catalog presence, caller grant and live availability.

<a id="runtime-mechanism-strings"></a>
## 2026-09-23 — Runtime mechanism strings

Source: adopted development ruling on runtime mechanism strings, coordination card `Ujy42wr5`, retained by the sweep in issue #3265 and PR #3266. The adoption record and its round-two clarification are held in private coordination; this public record carries the rule without private thread content.

An operator-facing runtime string that asserts why something happened, how a function behaves, or what is true of data is output and must be true on every emitting path. Pure event labels name an event without asserting a mechanism and are outside this rule. Work one site at a time: first delete an explanatory clause when structured keys already carry the fact or it is false or unverifiable on any emitting path; otherwise assert the claim on every emitting arm, killing a swap/merge-arms mutant or a predicate-weakening mutant for a single arm. Logging controls cover every exposed level and generic log calls, not just the expected record's level. Prose with an executable citation is the last resort. Verify truth by isolated execution before pinning text; do not bulk-assert strings or cite suite totals as truth evidence. Cite methods, not unstable line numbers. This governs new mechanism strings too; comments and names keep their separate delete-or-rename remedy. [STANDARDS G-14](../STANDARDS.md#later-development-obligations) indexes this adopted rule.

<a id="reporting"></a>
## Measured reporting

Source: measured-reporting protocol (2026-08-03), retained by parent `CKFuKp9m` §4 and leg-1 ruling. Distinguish measured, inferred, attempted, skipped, unknown and failed. Anchor citations to an immutable commit and measurement timestamp (or symbol/step when no SHA exists); enumerate the complete set and name the denominator. Every assertion of absence needs proof the instrument ran and a positive control. Unknown or unable-to-assess is refusal, never pass. Read what the instrument knows and defaults to, verify the load/consumer boundary, and report environment limits as findings. Correct a cited artifact by addendum with a visible pointer, not silent rewriting of its cited identity.

