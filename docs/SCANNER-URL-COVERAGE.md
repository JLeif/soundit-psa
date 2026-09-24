# Shared scanner URL rule (#3089)

Revision 2 supersedes the whole-run-mix experiment at df2bc197: that version
still redacted SHA-pinned GitHub links. It also misnamed the mix-only miss:
**discord-lower**, not slack-lower. The experiment JSON was correct; the prose
was wrong. This version measures the mix within slash-separated segments.

The distinctive-run rule is shared by `WikiRedactor::scan()` and `redact()`.
No channel exemptions or URL stripping are introduced. `+` keeps its old rule;
a slash-only candidate also needs uppercase, lowercase and a digit **inside
one slash-free segment of at least 16 characters**. Separate path segments
cannot pool categories. Contextual alternatives in the same rule detect Slack
incoming webhook, Teams Office incoming webhook, Discord webhook URLs and
signed-query values without requiring case mix. Padding, PEM, JWT, keyword and
connection rules are unchanged. OperatorNotifier's emergency path is unchanged;
#3088 and #3090 are not included.

## Reproduce

`php scripts/measure-scanner.php` emits exact denominators and **every newly
admitted sample ID**, including naive-drop and threshold-sensitivity lists.
It executes PHP PCRE and the current shared policy, changing only the
distinctive rule for comparisons. A position/shape assertion refuses policy
reordering. `tests/Fixtures/ScannerCoverage.php` is synthetic, not client traffic.
Seed: `scanner-3089-v1`. Sample i is base64 of the first 30 bytes of
SHA256(`scanner-3089-v1:` followed by decimal i), i=0..99999. This creates
100,000 forty-character unpadded base64 samples; 25,274 contain slash but no
plus or padding. These are AWS-secret-like shapes, **not live AWS keys**.

| Corpus | N | Baseline | Naive drop | Whole-run mix | Segment16 chosen |
|---|---:|---:|---:|---:|---:|
| Unpadded base64 detected | 100000 | 37612 | 20887 | 37593 | 36496 |
| Synthetic webhook/signed URLs detected | 15 | 9 | 0 | 8 | 15 |
| Ordinary URLs falsely detected | 13 | 10 | 0 | 5 | 0 |

Naive removes slash as a distinctive trigger, retaining it in the alphabet.
Newly admitted random samples vs baseline: naive **16725**, whole-run mix **19**,
segment8 **45**, segment10 **85**, segment12 **182**, segment16 **1116**.
Thresholds 8/10/12 each leave one ordinary fixture falsely detected (the bare
WikiRedactor2.php path); 16 clears all thirteen. The PHP numbers differ from
an approximate segment simulation because the existing word-boundary and
24-preceding-character trigger constraints are retained.

The chosen incremental cost is **1116/100000**, or **1116/37612** baseline
 detections. This is larger than the first experiment and needs its own owner
adjudication; the earlier 19-sample acceptance is not acceptance of 1116.
The exact chosen IDs are emitted by the script. The SHA256 of their ascending
comma-joined decimal IDs, without a final newline, is
`b05e503ac677347094dada61b657f1cd0c161455c50dbf80866ed3ba35e15343`;
the unit test pins the count, digest and detection total.

Baseline already misses **62388/100000**; chosen misses **63504/100000**.
The old trigger requires 24 preceding characters before the distinctive
character, so a slash/plus only early in a run can escape; entirely alphanumeric
runs are deliberately outside the rule. This is not generic secret detection.

The URL corpus has mixed, lowercase/hex and uppercase/hex variants each for
Slack, Teams (group@tenant path), Discord, SAS sig and X-Amz-Signature. Baseline
misses six query signatures; chosen detects all 15 and newly admits none.
Whole-run mix misses discord-lower. A webhook token need not contain all three
character categories; contextual alternatives protect these named shapes.
Fixtures are public vendor endpoint shapes or reserved example domains; no
requests are made, and the constructed tokens cannot authenticate.

The thirteen ordinary rows include #3089 (reserved PSA hostname), inbound
knowledgebase, GitHub blob/main, SHA-pinned blob, deep code permalink,
mixed-case PowerShell issue, a bare numbered code path and Dell service-tag URL.
C1 hex/SHA/UUID/serial and legacy PEM/padding/JWT/keyword/connection controls
remain explicit. Legacy positives run without accidental keyword masking.

## Limits and tradeoffs

- Ordinary paths with a long mixed-case numbered segment can still match. The
  16-character threshold is a heuristic, not proof of a secret or universal URL
  clearance. It is selected against the measured ordinary-path corpus.
- Slash-only secrets without a sufficiently long mixed segment can escape;
  short or fragmented secrets incur more misses than a forty-character corpus.
- Known webhook hosts are not exhaustive. Lowercase secret URLs on other hosts
  (e.g. other integration/catch-hook providers) can newly escape systematically;
  the 1116 random-sample count does NOT measure that separate population.
- Scheme-less webhook pastes, Teams workflow endpoints, encoded wrappers and
  HTML-entity query separators are not claimed covered. Bare identifiers and
  base32 remain gaps. Some of these gaps predate this change.
- Contextual signature names can flag non-secret values; this deliberately errs
  toward withholding. Webhook-tail matching can consume adjacent punctuation.
- Regex engine error handling and worst-case long input behavior are not changed.
  Forty-character random samples cannot establish large-input safety.
- These measurements describe the supplied synthetic corpus, not production
  prevalence or empirical credential distributions.

## Independent consumer controls

`ScannerUrlPolicyTest` executes both APIs, C1/ordinary preservation, URL secret
shapes, legacy positives, mix locality and exact random-corpus parity/counts.
`WikiTicketContextTest` checks actual pre-AI assembled context retains the
knowledgebase URL and removes discord-lower. `MineTicketKnowledgeTest` checks
actual storage retains the SHA-pinned GitHub reference and drops discord-lower,
with run status and quarantine count. Both consumer tests independently fail
when contextual alternatives are removed; the storage test also fails against
the superseded whole-run rule. No consumer policy or channel routing is changed.
