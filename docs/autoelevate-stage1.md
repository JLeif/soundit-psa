# AutoElevate stage 1

Settings → Integrations → RMM & Monitoring provides an admin-only Bearer API key
form and a short collapsible setup guide. The key is stored through encrypted
Settings and read only through `AutoElevateConfig`; it is never rendered back.
Blank or the masked placeholder preserves it. Replacing it clears the previous
connection result. Invalid submissions are not flashed into the session. The
4096-character bound is a local encrypted TEXT-storage bound, not a vendor limit.

The separate admin-only, CSRF-protected POST Test connection action is throttled
to six attempts per minute. It makes one read-only request, without retries or
redirects, with a 3-second connect timeout and 10-second overall timeout:

- Fixed origin `https://partner-api.autoelevate.com`
- `GET /api/v1/companies?take=1`
- `Authorization: Bearer <stored key>`
- `X-Acknowledgment: i-understand-this-is-beta-and-may-change`
- `Accept: application/json`

Only the HTTP status is classified. No response body, headers, company data or
exception text is parsed, persisted, logged or shown. HTTP 200 becomes `ok`;
400, 401, 403, 406 and 429 retain those fixed status labels; all other statuses
(including redirects) become `error`. Invalid/missing local configuration becomes
`configuration`; transport failures become `transport`. Only that allowlisted
outcome and the attempt timestamp are persisted. Success checks companyView only,
not the remaining permissions. A 403 can indicate missing companyView, MSP scope
or tenant access; it is not inferred from vendor prose.

Vendor contract: [Partner API OpenAPI](https://partner-api-docs.autoelevate.com/openapi.json),
AutoElevate Partner API (Beta) 1.0.0, verified September 15, 2026. Setup uses a
Service user with AE-BEARER and read-only permissions. AE-HMAC-SHA256 is not
supported by this stage. No polling, webhooks, grants, approve/deny operations or
other elevation writes are implemented.

Development and CI use `Http::fake` with `preventStrayRequests` only. No live
vendor/key/portal action is part of verification. A live check is an operator
release action, not a test prerequisite.

Focused tests: `php artisan test --filter AutoElevate --fail-on-warning`.
Full release gate: unmodified `bash scripts/gc-verify.sh` plus exact-tip CI and held
review. Removing/unmerging the feature before deployment has no live effect;
there are no migrations, scheduled jobs or backfills in this stage.
