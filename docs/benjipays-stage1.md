# BenjiPays read-only integration (stage 1)

The client reads `GET /v2/gateways` and `GET /v2/invoices/{accountingInvoiceId}`
from the fixed HTTPS merchant origin. It uses the existing encrypted
`BenjiPaysConfig` accessor. It does not create payments, links or transactions,
request accounting expansion, enable automation, or wire the portal/MCP.

Settings → Integrations offers administrators a CSRF-protected POST **Test
connection** action. It reads gateways once (no pagination/retry), persists
`benjipays_last_verified_at` and `benjipays_last_verification_outcome`
(`ok`, `401`, `403`, `error`), and displays the last *attempt*. Success means
only that gateway discovery succeeded with the key used for that attempt;
it does not establish invoice permission or promise current credential validity.
The action is limited to six attempts per minute by the existing web throttle.

401 can mean credentials **or disabled organization API access**. 403 can mean
scope, live owner mapping, or trial/billing eligibility. Other failures can
include absent accounting integration (400), missing invoice (404), rate limits
(429), server failures or transport problems. Vendor error strings are not
trusted: only HTTP status is retained; body/header text and transport exception
chains are discarded. Request/correlation UUIDs are generated locally. The client
has a three-second connection and ten-second total timeout, no retries, and no
redirects. Stored control characters are refused before transport.

`BenjiPaysInvoiceBalance::read(Invoice)` returns an `InvoiceBalance` projection:
nullable integer `balanceCents`, bounded `status`, nullable ISO-shaped `currency`,
and a safe `reason` on unavailable/invalid data. Missing IDs cause no call.
Unknown/missing/null/invalid balances never become zero. JSON numeric values
are converted through their decimal representation, with at most two fractional
digits and twelve whole digits; excess precision/unsupported magnitude is
refused rather than silently rounded. The DTO is not a payment authorization.

## Public schema sources

Independently read for this implementation:

- https://developer.benjipays.com/reference/get_v2-gateways.md
- https://developer.benjipays.com/reference/get_v2-invoices-invoiceid.md
- https://developer.benjipays.com/docs/authentication.md

Gateway discovery returns `{data: [...]}`. The invoice envelope is
`{data: InvoiceSummary}`; `balance` is a nullable JSON number and `status` is
`open`, `paid`, or `overdue`. Test fixtures follow the public summary schema and
contain no live account data. Optional `accounting` is not requested.

## Verification and boundaries

The settings/client tests use `Http::fake` with `preventStrayRequests`. PHPUnit's
forced HTTP(S) deny proxy remains intact. The connection tests exercise the real
web middleware stack, overriding only Laravel's unit-test CSRF bypass so missing
and incorrect tokens really produce 419. No live vendor call belongs in local
development or CI. Production connection verification is a separate explicit
operator action after authorized deployment; fake tests do not establish it.
