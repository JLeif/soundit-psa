# Designs as code: mockups

A UI change is designed as a small static HTML page, rendered to PNG, and reviewed as a
picture. The HTML is the source and the PNG is what a human looks at. Both are committed.

## Layout

```
design/mockups/
  _tokens.css              DESIGN.md front-matter tokens as CSS custom properties
  <area>/<screen>.html     one mockup per screen or component state
  <area>/<screen>.png      its render, committed next to it
```

`<area>` is a product area in kebab-case (`tickets`, `billing`, `assets`, ...). `<screen>` names the
screen or the state shown (`ticket-detail`, `invoice-list-empty`). `example/ticket-card.html` is a
worked example.

## Rules

1. **Use the tokens.** Each mockup links `../_tokens.css` and takes colours, type, radii and
   spacing from the `--psa-<group>-<name>` properties, e.g. `var(--psa-colors-navy)`,
   `var(--psa-typography-title-font-size)`, `var(--psa-rounded-md)`, `var(--psa-spacing-md)`.
   Do not hard-code a hex value that a token already names. Follow DESIGN.md §§5–6 (components,
   do's and don'ts): the gold-signal rule, a flat plane and status shown as colour plus text.
2. **Self-contained: no CDNs and no external requests.** No Bootstrap, font, icon or script CDN,
   and no remote images. Put the CSS inline in the page; anything else goes beside it as a
   relative file. `scripts/mockshot` aborts every non-`file:` request and prints the blocked URLs
   as a warning, so a render with a CDN reference does not look like the real thing.
3. **Fake data only** (STANDARDS.md G-13 and repo hygiene). No real client, contact, hostname,
   IP, domain or ticket text. Use obviously synthetic values: `Example Dental Co.`,
   `alex@example.test`, `PRINTER-EXAMPLE-01`.
4. **Fonts.** Neither the tokens nor the mockups load web fonts. The family stacks from
   DESIGN.md are used as written (`Montserrat, sans-serif`, `Inter, -apple-system, ...`), so on a
   machine without Montserrat or Inter installed the PNG shows the fallback sans-serif. The
   layout, colours and weights still show correctly. Read the PNG for structure and colour, not
   for exact glyph shapes.
5. **Commit the PNG next to the HTML** and re-render it whenever the HTML changes. Prefer the
   default desktop viewport. Use `--full` only when the page is short, to keep PNGs small.

## Build PRs

A PR that builds or changes UI cites the mockup path it implements (for example
`design/mockups/tickets/ticket-detail.html`) and attaches a screenshot of the built page, taken at
the same device size, for side-by-side comparison with the committed mockup PNG. Differences from
the mockup are called out in the PR description, not left for the reviewer to find.

## Rendering: `scripts/mockshot`

```
scripts/mockshot <file.html> [out.png] [--device phone|tablet|desktop | --size WxH] [--full]
```

| device            | viewport  | scale |
|-------------------|-----------|-------|
| `desktop` (default) | 1440x900  | 2x    |
| `tablet`          | 820x1180  | 2x    |
| `phone`           | 390x844   | 2x    |

`--size WxH` sets a custom viewport (also 2x). `--full` captures the whole scrollable page.
`out.png` defaults to the input path with `.png` in place of `.html`.

Exit codes: `0` written; `1` usage error; `2` playwright-core not found; `3` render failed
(including a browser that will not start); `127` node not installed.

### Requirements

The repo has no `package.json`, and mockshot adds no npm dependency. It needs Node 18+ and an
existing `playwright-core` with its matching Chromium headless shell. It resolves
`playwright-core` from, in order:

1. `MOCKSHOT_PLAYWRIGHT_CORE`, the path to a `playwright-core` package directory;
2. `require.resolve('playwright-core')` over `NODE_PATH` and the global npm root (`npm root -g`).

If neither finds it, mockshot exits 2 and prints how to install it:

```
npm install -g playwright-core
npx playwright-core install chromium-headless-shell
```

The installed Chromium revision must be the one that `playwright-core` version expects. mockshot
never downloads a browser. If Chromium starts but is missing shared libraries (for example
`libatk-1.0.so.0`), install the distribution's Playwright/Chromium dependencies or point
`LD_LIBRARY_PATH` at a directory that has them. The error appears as exit 3 with the loader
message.

Example:

```
MOCKSHOT_PLAYWRIGHT_CORE=/path/to/node_modules/playwright-core \
  scripts/mockshot design/mockups/example/ticket-card.html
```

### Tests

`scripts/tests/mockshot.test.sh` covers argument parsing and the missing-playwright-core path
without launching a browser. It runs in the PHPUnit suite through
`tests/Unit/MockshotScriptTest.php`. CI has no Chromium, so a live render is a manual check.
`tests/Unit/DesignMockupTokensTest.php` fails if `_tokens.css` drifts from the DESIGN.md
front-matter.
