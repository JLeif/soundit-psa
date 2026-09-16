# Control D fixtures and boundaries

B3 `read-only-rejection.json` copies the sanitized HTTP403 envelope observed at the
September 16 13:47 Pacific pilot and ruled at 16:17: integer code40301 and vendor
read-only-token message. Only code/status are consumed; the message is never persisted.
The shape is not proof of any pre-write key capability. B3 organization fixtures reuse
B2's producer OpenAPI fixtures; case-only/duplicate rows are deliberate negative controls.
`stage-contender.php` is a test-only independent-process contender using an isolated
SQLite snapshot and an empty Guzzle MockHandler: no live transport or production DB.


Provenance: producer contract measured September 16, 2026; development rulings on card
6a9b3613b036fdbdc152e6ea at 05:05 and 06:03 Pacific. No live write was used to make these fixtures.

- Vendor dashboard `https://controld.com/_next/static/chunks/6023-86729853984166b4.js`, module
  24702: POST `/provision` returns `body.provision`; GET returns `body.provisions`;
  PUT `/provision/{PK}/invalidate`, DELETE `/provision/{PK}`; status -1/0/1.
- Dashboard `2399-b7405144dc88f719.js`, module 11150: create key list `eg`, numeric PIN input
  (unary +), analytics 0/1/2, `standard`/`intercept-dns`, iOS excluded from provisioning.
- Dashboard `pages/_app-fe22923e4dabb076.js`: getProfiles consumes `body.profiles`;
  getOrganization consumes `body.organization` including `PK` and `stats_endpoint`.
  Module 54993 transforms the OS icon map into choices; the recorded read-only
  `/devices/types` probe establishes `body.types.os.icons` keys.
- `provision.json` copies the field names/types of the recorded root/sub-org GET rows,
  substituting synthetic values. The no-expiry value 0, integer counters/status, 32-character
  code and object profile/ctrld are from that observation, not inferred from this implementation.
  The chosen fixture is active/unexpired; recorded upstream examples also included expired codes.
- PIN and prefix variants in tests are synthetic contract fixtures, NOT captured live writes.
  The explicit numeric PIN ruling permits integer wire values and requires matching integer
  read-back; requested PIN missing is refusal. No leading-zero/string-accepting vendor shape
  is asserted. Optional PIN defaults off. Omitted prefix accepts absent or empty-string only;
  any other undocumented representation refuses success.

A exposes no route, setting, command, persistence or Tactical action. Its caller supplies final
validated wire values. Future onboarding B owns settings/default selection, overflow-safe asset
count + headroom and expiry arithmetic, idempotency, storage and approval. A independently checks
wire types/ranges, enablement/configuration, device inventory, scoped profile membership and (when
analytics is requested) scoped organization region before POST. No inferred parent-org fallback.
An uncertain write/read-back failure must be reconciled, never blindly retried or auto-deleted.
Delete returns only vendor acknowledgment, not verified absence. First live pilot remains separately
gated; fixtures and a successful review do not authorize it.
