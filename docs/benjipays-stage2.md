# BenjiPays applied payment links (stage 2) — #2065

Stage 2 of the BenjiPays integration (stage 1: #1476, `docs/benjipays-stage1.md`).
Builds on the #1173 partial-balance work.

The client-portal **Pay Online** button can open a BenjiPays *applied*
(invoice-tied) payment link instead of the Stripe hosted invoice page, so a
partially paid QuickBooks invoice is presented with its remaining balance by
the vendor's own pay page rather than the full Stripe total.

## Client

`BenjiPaysClient::createAppliedPaymentLink(string $accountingInvoiceId)` posts
`/v2/payment-links/applied/{invoiceId}` (the QuickBooks invoice id, one
rawurlencoded path segment, same id guard as the invoice read). The body is
always `{"allowSavedPaymentMethods": false}` — the vendor's own security note
says `true` may expose the invoice customer's stored cards to whoever holds the
link. A fresh alphanumeric `Idempotency-Key` is sent per mint (the vendor
answers a same-key/different-body replay with 409). Transport hardening is
stage 1's: status-only errors, no vendor strings or headers retained, 3 s
connect / 10 s total timeout, no retries, no redirects.

The 200 body is a **bare** `{url, expiresAt}` object (vendor OpenAPI
`CreateAppliedPaymentLinkResponse`, `additionalProperties: false`), not the
`{data: …}` envelope the reads unwrap. `AppliedPaymentLink::fromResponse()`
accepts only an https URL on `benjipays.com` or a subdomain and a strict
ISO-8601 expiry; anything else is `invalid_response`. Minting takes no payment.

## Setting

`benjipays_pay_online` (`'1'`/`'0'`, default off). `BenjiPaysConfig::payOnlineEnabled()`
is true only when the setting is on **and** a key is stored, so clearing the
key returns the portal to Stripe. The switch lives on the Integrations
BenjiPays card, is offered to administrators only when a key is stored, and
posts to an admin-only route. Turning it on in production is an operator
decision; it ships off.

## Portal

`Invoice::paysOnlineViaBenjiPays()` = toggle on ∧ `qbo_invoice_id` present ∧
status client-payable. The three Pay Online buttons (`portal/dashboard`,
`portal/invoices/index`, `portal/invoices/show`) and the `qbo-balance-note`
partial read that one predicate. On the BenjiPays path the button is a CSRF
POST to `portal.invoices.pay-online`; otherwise the Stripe markup is unchanged.

`PortalInvoiceController::payOnline()` 404s unless the invoice belongs to the
signed-in portal user's client and is in a portal-visible status, re-checks
the predicate (a stale form after the toggle was turned off falls back to
Stripe), mints or reuses the cached link and 302s to it. Any
`BenjiPaysException` falls back to `stripe_invoice_url` when present, else back
to the invoice with a generic flash; no vendor string reaches the client.

`BenjiPaysPayOnline::linkFor()` caches the minted link per invoice
(`benjipays:applied-link:{id}:{sha1(qbo id)}`) for `expiresAt − 60 s`, capped
at one hour; a link already within the margin is not cached. Two clicks are
one mint.

The balance note drops the Stripe "will charge the full amount" sentence on
the BenjiPays path (the vendor page is priced at the balance) and keeps the
balance and its as-of date.

## Staff preview

Administrators see **Preview BenjiPays link** on the invoice page when a key
is stored and the invoice has a QuickBooks id and a client-payable status. It
is a CSRF POST behind `admin` + `throttle:6,1`, mints (or reuses) the link and
renders the URL and expiry for the admin to open. It is independent of the
portal toggle so the amount can be checked before the switch is flipped.

## Verification and boundaries

All tests use `Http::fake` + `preventStrayRequests` with a synthetic key; the
PHPUnit deny proxy is intact. No live BenjiPays call belongs in development or
CI. Production use of the preview and the toggle are separate operator actions
after an authorized deployment.
