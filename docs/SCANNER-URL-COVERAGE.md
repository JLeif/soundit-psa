# Shared scanner URL rule (#3089)

The distinctive-run rule is shared by `WikiRedactor::scan()` and `redact()`.
No channel exemptions or URL stripping are introduced. `+` keeps its old rule;
a slash-only candidate also needs uppercase, lowercase and a digit **inside
that run**. Contextual alternatives in the same rule detect Slack incoming
webhook, Teams Office incoming webhook, Discord webhook URLs and signed-query
values without requiring case mix. Padding, PEM, JWT, keyword and connection
rules are unchanged. OperatorNotifier's emergency path is unchanged; #3088 and
#3090 are not included.

## Reproduce

`php scripts/measure-scanner.php` emits exact denominators and newly admitted
sample IDs (including the full naive-drop list). It executes PHP PCRE and the
current shared policy, changing only the distinctive rule for the comparisons.
`tests/Fixtures/ScannerCoverage.php` is synthetic, not captured client traffic.
Seed: `scanner-3089-v1`. Sample i is base64 of the first 30 bytes of
SHA256(`scanner-3089-v1:` followed by decimal i), for i=0..99999. This creates
100,000 forty-character unpadded base64 samples; 25,274 contain slash but no
plus or padding. These are AWS-secret-like shapes, **not live AWS keys**.

| Corpus | N | Baseline detected | Naive slash-drop | Mix only | Chosen |
|---|---:|---:|---:|---:|---:|
| Unpadded base64 | 100000 | 37612 | 20887 | 37593 | 37593 |
| Synthetic webhook/signed URLs | 15 | 9 | 0 | 8 | 15 |
| Ordinary URLs | 8 | 5 | 0 | 0 | 0 |

Naive means removing slash as a **distinctive trigger**, retaining it in the
run alphabet. Against baseline, naive newly admits 16,725 random samples;
chosen newly admits **19/100000** (19/37612 baseline detections). Their IDs:
1413, 10453, 17176, 19899, 22118, 29051, 38721, 38931, 39303, 43865, 45460,
45878, 63673, 64374, 66616, 68602, 68865, 74334, 79215.

The baseline already misses **62,388/100000** random samples; chosen misses
**62,407/100000**. Most importantly, the old trigger requires 24 preceding
characters before the distinctive character, so a slash/plus only early in a
run escapes; an entirely alphanumeric run is deliberately outside this rule.
The fix does not claim to solve generic secret detection. A small *incremental*
miss count is not a high overall detection rate.

The URL corpus has three case variants (mixed, lowercase/hex, uppercase/hex)
each for Slack, Teams, Discord, SAS sig and presigned X-Amz-Signature. Baseline
misses the six query signatures; chosen detects all 15 and newly admits none.
Mix-only misses the lowercase Slack variant: adjacent structural path text can
supply upper/lower/digits for other webhooks, but that is no reliable token
property. Contextual alternatives are therefore required. Hosts in the fixtures
are public vendor endpoints or reserved example domains; nothing is requested.

The eight ordinary rows cover #3089 (with a reserved PSA hostname), the inbound
knowledgebase tail, and a GitHub blob link. Five false positives become clean.
C1 hex, SHA, UUID and serial tests remain clean. PEM/padding/JWT/keyword/connection
controls run without a keyword accidentally masking the feature under test.

## Limits and tradeoffs for adjudication

- Mixed-case numbered ordinary paths can still match. This is not a universal
  URL classifier; it fixes the measured ordinary URL class without exempting URLs.
- Slash-only secrets lacking a required character category can now escape. The
  exact 19 newly admitted samples pin that tradeoff, not a zero-miss guarantee.
- Known webhook shapes are not an exhaustive vendor catalog. New webhook hosts,
  Teams workflow endpoints, encodings and alternative signature parameter names
  are not claimed covered. Bare identifiers and base32 remain accepted gaps.
- The contextual signature rule can flag non-secret `sig`/`signature` query
  values. It intentionally does not assume hex is safe in a signed URL.
- Random shapes and constructed URLs measure the supplied corpus, not production
  prevalence or empirical credential distributions.

## Consumer controls

`ScannerUrlPolicyTest` executes scan/redact, ordinary URL and C1 preservation,
all 15 URL secret shapes, legacy positives, local-vs-surrounding character mix,
and exact random-corpus parity/counts. `WikiTicketContextTest` independently
checks the pre-AI assembled context retains the knowledgebase URL and removes
a webhook token. `MineTicketKnowledgeTest` independently checks mining's actual
storage boundary: the GitHub reference is stored and the credential candidate
is dropped, with the run and quarantine counter asserted.
