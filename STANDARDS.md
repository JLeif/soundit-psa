# Project standards — sourced operative index

Membership: 58 carried ledger entries + 14 later obligations; IDs are stable, not a rule count. Full bundled wording remains binding in the linked same-tip sources; this index is not a replacement. Forge maintains; Jeeves adjudicates; Charlie owns product/design and production authority. No prompt wiring or rubric change is made here. For any Blade diff read [DESIGN §§5–6](DESIGN.md#5-components) and [Do/Don't](DESIGN.md#6-dos-and-donts).

## Architecture and integrations

- C-5, K-6: Satisfy, never defeat, the local + `_dev` demo guard; [CONTRIBUTING / Demo data](CONTRIBUTING.md#demo-data).
- C-6: Ship replaceable navy/gold, Montserrat/Inter branding, logo, favicon and app name; [ARCHITECTURE / Branding](docs/ARCHITECTURE.md#brand).
- C-8: Use the current Bootstrap/CDN no-build-step architecture, allowing a justified future build step; [ARCHITECTURE / Core](docs/ARCHITECTURE.md#core).
- C-11: Keep staff Entra/web and Person/portal auth, sessions and reset brokers separate; [ARCHITECTURE / Core](docs/ARCHITECTURE.md#core).
- C-14: Store UTC, convert display via `toAppTz()` and local due inputs on save; [ARCHITECTURE / Core](docs/ARCHITECTURE.md#core).
- C-19: Resolve quantity from contract pivots when assigned, else client-wide; [ARCHITECTURE / Billing](docs/ARCHITECTURE.md#billing).
- C-20: Compute overage as `max(0, ceil((usage - base × included_per_base_unit) / overage_divisor))`; [ARCHITECTURE / Billing](docs/ARCHITECTURE.md#billing).
- C-21: Apply volume storage rates to the whole binary-rounded GB quantity, retaining fallback and audit semantics; [ARCHITECTURE / Billing](docs/ARCHITECTURE.md#billing).
- C-22: Expand graduated bands into exact per-band invoice lines with distinct sort order, costs and prepaid allocation; [ARCHITECTURE / Billing](docs/ARCHITECTURE.md#billing).
- C-23: Keep graduated/volume semantics distinct, line > SKU > flat, override visible and audited; [DECISIONS / Pricing](docs/DECISIONS.md#pricing).
- C-25: Resolve profitability cost from line override → SKU cost → zero; [ARCHITECTURE / Billing](docs/ARCHITECTURE.md#billing).
- C-26: Deposit prepay only on Paid with source, idempotency, contract and dollar-prepay guards; [ARCHITECTURE / Billing](docs/ARCHITECTURE.md#billing).
- C-29: Aggregate reseller license quantity across active child clients, not contract pivots; [ARCHITECTURE / Billing](docs/ARCHITECTURE.md#billing).
- C-31: Suppress empty/zero-quantity invoices by nullable override, not zero-price coverage, and advance the schedule; [ARCHITECTURE / Billing](docs/ARCHITECTURE.md#billing).
- C-32: Store then dispatch verified QBO webhooks; retain status/line correspondence and no partial-payment sync; [ARCHITECTURE / Billing](docs/ARCHITECTURE.md#billing).
- C-35, C-36, C-37, C-38, C-39, C-40, C-41, C-42, C-43, C-44, C-45: Preserve each vendor's Config/client/sync/mapping/schedule shape; Stripe Prices immutable, Zorus filtered client-side, Comet `isEnabled()=isConfigured()` exception to OFF=OFF; full per-vendor obligations [ARCHITECTURE / Integrations](docs/ARCHITECTURE.md#integrations).
- C-46: Dispatch only names published once from the delivered schema, return availability errors, retain separate surface guards; [ARCHITECTURE / AI](docs/ARCHITECTURE.md#ai).
- C-47: OFF=OFF binds publication at one availability choke point; 8 obligations, full text [ARCHITECTURE / OFF](docs/ARCHITECTURE.md#off).
- C-48: Retain transcription pipeline, stereo channel mapping, fallback, locking and triggers; [ARCHITECTURE / Transcription](docs/ARCHITECTURE.md#transcription).
- C-49: Discover caller capability availability from the actual live assembly and auto-classify requests by remedy; [ARCHITECTURE / Discovery](docs/ARCHITECTURE.md#discovery).
- C-50: Keep triage client-locked with staged execution, review-only recommendations and safe degradation; 10 core obligations plus supporting detail, full text [ARCHITECTURE / Triage](docs/ARCHITECTURE.md#triage).
- C-51: Never overwrite human taxonomy ownership; lock and stamp ownership in the same UPDATE, audit all changes; [ARCHITECTURE / Taxonomy](docs/ARCHITECTURE.md#taxonomy).
- C-52: Keep portal auth/scope, note/invoice visibility, replies, notifications and client labels distinct; full portal contract [ARCHITECTURE / Portal](docs/ARCHITECTURE.md#portal).
- C-53: Resolve portal MCP identity from authenticated header → Person, never tool input; [ARCHITECTURE / Portal MCP](docs/ARCHITECTURE.md#portal-mcp).
- C-54: Keep portal chatbot read-only and client-locked; never reuse staff `AssistantToolExecutor`; [ARCHITECTURE / Portal chat](docs/ARCHITECTURE.md#portal-chat).
- C-55: Respect Graph auth, response, renewal, paging and mailbox boundaries; 6 obligations, full text [ARCHITECTURE / Graph](docs/ARCHITECTURE.md#graph).
- C-56: Read vendor producers, fixture from real payloads, make degraded reads SCREAM; 3 obligations with subguards, full text [ARCHITECTURE / Vendor shapes](docs/ARCHITECTURE.md#vendor).
- C-58: Update installation documentation for every install/config/dependency/integration/schedule change; [ARCHITECTURE / Install](docs/ARCHITECTURE.md#install).

## Product and review

- D-1: Use the design token palette, type, spacing, radii and component vocabulary; [DESIGN frontmatter](DESIGN.md).
- D-11: Keep content flat at rest/hover; shadows only for overlays, no lift/bloom/accent-border swap; [DESIGN / Elevation](DESIGN.md#4-elevation).
- D-14: Use field borders, navy focus glow and checked controls; [DESIGN / Inputs](DESIGN.md#inputs--fields).
- D-15: Pair status color with text or icon, never color alone; [DESIGN / Badges](DESIGN.md#badges-status-and-priority).
- P-5: Surface adjacent-domain context where the generalist makes the decision; [PRODUCT / Principles](PRODUCT.md#design-principles).
- R-4: Apply security rubric and escalate majority-discarded security findings rather than dropping them; [DECISIONS / Review](docs/DECISIONS.md#review).
- R-14: Escalate schema/data-loss risk, security ambiguity, money, customers and irreversibility; [DECISIONS / Authority](docs/DECISIONS.md#authority).

## Contributor and agent workflow

- K-1: Meet the actual PHP 8.3 floor, not the stale Composer constraint; [CONTRIBUTING / Setup](CONTRIBUTING.md#getting-it-running).
- K-2: Install every required PHP extension, including XML/curl and the chosen DB driver; [CONTRIBUTING / Setup](CONTRIBUTING.md#getting-it-running).
- K-3: Serve local development through artisan, not the routerless built-in server; [CONTRIBUTING / Setup](CONTRIBUTING.md#getting-it-running).
- K-4: Use INSTALL for deployment and CONTRIBUTING for contributor setup; [CONTRIBUTING / Setup](CONTRIBUTING.md#getting-it-running).
- K-5: Restrict dev login to local and set production environment before deploying; [CONTRIBUTING / Login](CONTRIBUTING.md#getting-logged-in).
- K-7: Run the same full test/style/secret gate as CI; [CONTRIBUTING / Gate](CONTRIBUTING.md#the-gate).
- K-8: Fetch main before trusting diff-scoped checks; [CONTRIBUTING / Gate](CONTRIBUTING.md#the-gate).
- K-10: Branch from main, keep one change, explain why and give behavioral test evidence; [CONTRIBUTING / PRs](CONTRIBUTING.md#pull-requests).
- K-11: Assess machine reviews on merits and argue with incorrect findings; [CONTRIBUTING / PRs](CONTRIBUTING.md#pull-requests).
- K-12: Keep real data/secrets out yourself; a narrow regex cannot prove hygiene; [CONTRIBUTING / Send-backs](CONTRIBUTING.md#things-that-will-be-sent-back).
- A-1: Use non-interactive shell flags without erasing another author's work; [AGENTS / Shell](AGENTS.md#non-interactive-shell-commands).
- A-4, G-8: Commit/push real work promptly before review and session end; branch publication merges nothing; [DECISIONS / Push](docs/DECISIONS.md#push).

## Later development obligations

- G-14: Operator-facing runtime strings (including log context, exception messages and operator output) that assert a mechanism — why something happened, how a function behaves, or what is or is not true of data — must be true on every emitting path. Pure event labels are outside this rule. Work one site at a time: (1) delete the explanatory clause when structured keys already carry the fact; (2) otherwise assert the claim on every emitting arm, with a swap/merge-arms mutant that the controls kill; (3) use prose with an executable citation only as a last resort. Verify truth by isolated execution before pinning text; never bulk-assert strings or cite suite totals as truth evidence. Cite methods rather than unstable line numbers. Review new mechanism strings against this rule; comments and names retain the separate delete-or-rename remedy.

- G-1: Follow independent FIND/ADJUDICATE/REWORK/VERIFY, rubric, 3-sample majority and bounded cycles; [DECISIONS / Review](docs/DECISIONS.md#review).
- G-2: Hold for human-grade exact-SHA review, guards/full suite/CI, re-review additions, authorized gated landing and production verification; [DECISIONS / Held](docs/DECISIONS.md#held).
- G-3: Bind held decisions and residuals through holder DECISION/PIN/MANIFEST; [DECISIONS / Ledger](docs/DECISIONS.md#ledger).
- G-4: Fail the gate on PHPUnit warnings; [CONTRIBUTING / Gate](CONTRIBUTING.md#the-gate), commit `695ea813`.
- G-5: Work issue-first; fake HTTP/prevent strays, no live vendor calls, Config-only credentials, one exact-tip held review; [DECISIONS / Development](docs/DECISIONS.md#development).
- G-6: Hand back assertion-killed mutants, behavioral red/positive controls, restored green source and load-path proof; [DECISIONS / Evidence](docs/DECISIONS.md#evidence).
- G-7: Preserve review brief in domain, keep notes rebase-only and require preflight without treating it as admission; [DECISIONS / Brief](docs/DECISIONS.md#brief).
- G-9: Publish no client material, secrets, internal hosts/IPs or infrastructure paths; filings carry the owner's name; [DECISIONS / Hygiene](docs/DECISIONS.md#hygiene).
- G-10: Refuse unknown; bare grants run immediate, held-only verbs never do; [DECISIONS / Modes](docs/DECISIONS.md#modes).
- G-11: Leave freeze/release to Jeeves and specific production/settings/grants/credentials/live actions to Charlie; [DECISIONS / Authority](docs/DECISIONS.md#authority).
- G-12: Separate measured from inferred, cite SHA/time, enumerate denominators and control absence claims; [DECISIONS / Reporting](docs/DECISIONS.md#reporting).
- G-13: Vendor integration mechanics (endpoints, parameter/query shapes, redirect chains, auth flow, product names) may be documented in public source, tests, issues and PRs; credentials, client-derived identifiers and vendor security weaknesses may not (G-9; private escalation); [DECISIONS / Hygiene](docs/DECISIONS.md#hygiene).
