# Shared scanner URL rule (#3089)

The linear candidate/segment implementation supersedes the unsafe PCRE lookahead
at 85ea02b9. That predecessor could fail open on long slash paths; see the measured
before/after section below. Engine errors now fail closed in both APIs.

The extension-aware N8 design supersedes the whole-run-mix experiment at df2bc197: that version
still redacted SHA-pinned GitHub links. It also misnamed the mix-only miss:
**discord-lower**, not slack-lower. The experiment JSON was correct; the prose
was wrong. This version measures the mix within slash-separated segments.

The distinctive-run rule is shared by `WikiRedactor::scan()` and `redact()`.
No channel exemptions or URL stripping are introduced. `+` keeps its old rule;
a slash-only candidate also needs uppercase, lowercase and a digit **inside
one slash-free segment of at least 8 characters**. Separate path segments
cannot pool categories. The final segment before a short file extension
(`\.[A-Za-z][A-Za-z0-9]{0,5}\b`) cannot supply the mix; earlier segments still can.
A sentence period alone is not an extension. Contextual alternatives in the same rule detect Slack
webhooks on any `hooks.slack.com/` path (including `/triggers/`), Teams Office incoming webhook, Discord webhook URLs and
signed-query values without requiring case mix. Padding, PEM, JWT, keyword and
connection rules are unchanged. OperatorNotifier's emergency path is unchanged;
#3088 and #3090 are not included.

## Reproduce

`php scripts/measure-scanner.php` emits exact denominators and **every newly
admitted sample ID**, including naive-drop comparison lists.
It executes the current shared policy through BOTH real APIs, and historical
PCRE rules for comparisons. A position/shape assertion refuses policy
reordering. `tests/Fixtures/ScannerCoverage.php` is synthetic, not client traffic.
Seed: `scanner-3089-v1`. Sample i is base64 of the first 30 bytes of
SHA256(`scanner-3089-v1:` followed by decimal i), i=0..99999. This creates
100,000 forty-character unpadded base64 samples; 25,274 contain slash but no
plus or padding. These are AWS-secret-like shapes, **not live AWS keys**.

| Corpus | N | Baseline | Naive drop | Whole-run mix | Extension-N8 chosen |
|---|---:|---:|---:|---:|---:|
| Unpadded base64 detected | 100000 | 37612 | 20887 | 37593 | 37567 |
| Synthetic webhook/signed URLs detected | 16 | 9 | 0 | 8 | 16 |
| Ordinary URLs falsely detected | 13 | 10 | 0 | 5 | 0 |

Naive removes slash as a distinctive trigger, retaining it in the alphabet.
Newly admitted random samples vs baseline remeasured after the linear rewrite:
naive **16725**, whole-run mix **19**, chosen **45**.
Historical threshold experiments (not re-executed by this script) at 85ea02b9:
segment8 **45**, segment10 **85**, segment12 **182**, segment16 **1116**;
without extension exclusion, thresholds 8/10/12 left the numbered code path falsely detected.
The chosen extension-aware N8 also clears all thirteen, at only 45 incremental
misses on the standard corpus. Thus it meets the owner's <=150/100000 standard
corpus threshold; the N16 fallback is not selected. The PHP numbers differ from
an approximate segment simulation because the existing word-boundary and
24-preceding-character trigger constraints are retained.

The chosen incremental cost is **45/100000**, or **45/37612** baseline
 detections. All samples suffixed with a sentence period also newly admit 45;
all samples suffixed with `.json` newly admit **152/100000**. The extension-case
figure is separate from the owner's <=150 standard-corpus condition, not rounded
away. Removing the extension check and always excluding the final segment also
newly admits 152 on the standard corpus; seed sample 152 kills that mutant.
The exact chosen IDs are emitted by the script. The SHA256 of their ascending
comma-joined decimal IDs, without a final newline, is
`88d35b49ab2536476f6b711a4e84387d90fc1945ffdb3c3d39f62dd9c0d6b73c`;
the unit test pins count, digest and detection total.

Baseline already misses **62388/100000**; chosen misses **62433/100000**.
The old trigger requires 24 preceding characters before the distinctive
character, so a slash/plus only early in a run can escape; entirely alphanumeric
runs are deliberately outside the rule. This is not generic secret detection.

The URL corpus has mixed, lowercase/hex and uppercase/hex variants each for
Slack, Teams (group@tenant path), Discord, SAS sig and X-Amz-Signature. Baseline
misses six query signatures and the added lowercase triggers fixture; chosen detects all 16 and newly admits none.
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
  eight-character threshold and extension condition are heuristics, not proof of a secret or universal URL
  clearance. It is selected against the measured ordinary-path corpus.
- Slash-only secrets without a sufficiently long mixed segment can escape;
  short or fragmented secrets can incur more misses than a forty-character corpus.
  A secret whose only qualifying segment immediately precedes `.json` can escape;
  the 152/100000 experiment measures that incremental cost for this corpus.
- Known webhook hosts are not exhaustive. Lowercase secret URLs on other hosts
  (e.g. other integration/catch-hook providers) can newly escape systematically;
  the 45 random-sample count does NOT measure that separate population.
- Scheme-less webhook pastes, Teams workflow endpoints, encoded wrappers and
  HTML-entity query separators are not claimed covered. Bare identifiers and
  base32 remain gaps. Some of these gaps predate this change.
- Contextual signature names can flag non-secret values; this deliberately errs
  toward withholding. Webhook-tail matching can consume adjacent punctuation.
- Forty-character random samples cannot establish large-input safety. The separate
  long-input controls below cover the demonstrated regression, not every possible input.
- These measurements describe the supplied synthetic corpus, not production
  prevalence or empirical credential distributions.

## Long input and engine errors (r3 correction)

The earlier assertion that worst-case behavior was unchanged was **false**.
At 85ea02b9, the nested per-position lookahead rescanned slash runs. Remeasured
on the same PHP runtime with `a/` repeated to the stated prefix length, a space,
and `Ab3` repeated ten times plus `+Cd4`:

| Prefix characters | Before scan / redact seconds | After scan / redact seconds |
|---|---|---|
| 5000 | 0.110 / 0.103 | 0.00039 / 0.00019 |
| 20000 | 1.614 / 1.631 | 0.00087 / 0.00070 |
| 60000 | fail-open / empty string | 0.00255 / 0.00208 |

Before, the 60k input returned no violations and a zero-byte redaction. The
failing pattern hits PCRE's JIT stack limit; later successful patterns overwrite
`preg_last_error()`, so checking only the final error would conceal the failure.
After, both APIs detect/remove the token with no engine errors. Unit controls
cover 60k with and without whitespace and both plus/slash tokens, with a generous
one-second bound per scan+redact pair.

A possessive maximal candidate regex visits each run once. The PHP check uses
bounded suffix inspection and a fixed number of linear byte/segment passes;
it does not restart a nested lookahead at each slash. Shared contextual patterns
still run before candidate rewriting. This is not a general regex complexity
claim for every pre-existing pattern.

Every scan pattern, including injection and marker patterns, treats engine
failure as a **credential violation**. Every redaction pattern failure withholds
the **whole text as `[REDACTED:credential]`**, explicitly, never an empty or
partially processed string. The candidate callback has the same policy.
Separate-process namespace fault injection exercises all 17 scan and 9 redact
pattern sites, asserts the fault actually fired and verifies the healthy control.
Mutants removing each of the four engine-error guard sites must fail assertions.

## Independent consumer controls

`ScannerUrlPolicyTest` executes both APIs, C1/ordinary preservation, URL secret
shapes, legacy positives, mix locality and exact random-corpus parity/counts.
`WikiTicketContextTest` checks actual pre-AI assembled context retains the
knowledgebase URL and removes discord-lower. `MineTicketKnowledgeTest` checks
actual storage retains the SHA-pinned GitHub reference and drops discord-lower,
with run status and quarantine count. Both consumer tests independently fail
when contextual alternatives are removed; the storage test also fails against
the superseded whole-run rule. No consumer policy or channel routing is changed.
