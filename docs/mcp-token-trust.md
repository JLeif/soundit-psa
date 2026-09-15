# Staff MCP token trust controls

Staff tokens are not bound to a client. Their grants restrict tools and execution modes, not a tenant list. Tool-specific ownership checks, required identifiers and confirmation still apply.

## Retired explicit-client setting

`require_explicit_client_scope` is retired. Its database column and historical migration remain for non-destructive upgrade/rollback, but runtime authentication, settings and UI do not read or write it. Existing true/false values have identical behavior. New rows use the unchanged database default; it is not an active security default.

Compatibility:
- Settings requests containing the retired key ignore it, return the ordinary success response, and leave stored legacy values untouched. It is not mass assignable.
- PHP `McpConfig::rotateStaffToken(..., requireExplicitClientScope: ...)` and the `McpStaffToken` constructor retain that argument in its original position as an ignored compatibility parameter. Named and positional callers keep working. The DTO no longer exposes a property of that name; consumers must not inspect it as an authorization signal.
- `add_ticket_note` still requires a valid client ID because its executor needs client context. `propose_close` and `send_reply` derive context from the ticket when the client argument is absent. For all three, a supplied malformed client ID is refused and a valid supplied ID must match the ticket. Held actions stay held. Other tools retain their existing identifier/ownership rules.

This supersedes historical design notes describing the flag as active; it does not implement per-client token binding.

## Attribution, grants and lifecycle

The Trust & scope list links to Tools for grants and modes and to the existing lifecycle controls. Only active tokens authenticate; draft, paused and revoked credentials cannot call tools. Staged-only grants do not authorize immediate execution. An immediate grant is not a bypass of confirmation or tool-specific restrictions.

`ai_actor` selects strict configured-AI-user attribution for `add_ticket_note`, `wiki_add_fact`, `wiki_create_page` and `wiki_update_page`. With it off, those calls use the service-account resolver (including its first-user fallback). The assistant note executor still marks notes AI-authored in either case. This switch does not govern replies or every action and does not grant any tool.

Tests pair rendered copy with actual requests, legacy-value matrices, the exact attribution cohort, inactive credential rejection and staged downgrade. No production setting/grant changes are required to retire the flag.
