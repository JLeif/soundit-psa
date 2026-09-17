# BenjiPays applied payment links (stage 2) — #2065

Stage 2 of the BenjiPays integration (stage 1: #1476). Builds on the #1173
partial-balance work.

The client-portal **Pay Online** button can open a BenjiPays *applied*
(invoice-tied) payment link instead of the Stripe hosted invoice page, so a
partially paid QuickBooks invoice is presented with its remaining balance by the
vendor's own pay page rather than the full Stripe total.

Scope, boundaries, tests and the vendor sources are recorded on the issue. This
file is filled in with the shipped behaviour as the stage lands.
