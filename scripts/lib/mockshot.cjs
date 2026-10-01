//
// mockshot — render a static HTML mockup to PNG with headless Chromium.
// Invoked through the scripts/mockshot bash wrapper.
//
//   scripts/mockshot <file.html> [out.png] [--device phone|tablet|desktop | --size WxH] [--full]
//
// Devices (all at deviceScaleFactor 2):
//   desktop 1440x900 (default) · tablet 820x1180 · phone 390x844
// --size WxH   custom viewport (also 2x); mutually exclusive with --device
// --full       capture the full scrollable page instead of the viewport
// out.png      defaults to the input path with its .html extension replaced by .png
//
// The PSA repo has no package.json and this script adds no npm dependency.
// playwright-core is resolved, in order, from:
//   1. $MOCKSHOT_PLAYWRIGHT_CORE — a path to a playwright-core package directory
//   2. require.resolve('playwright-core') over $NODE_PATH and the global npm root
// Its matching Chromium (headless shell) must already be installed; mockshot
// never downloads a browser.
//
// The page is loaded from a file:// URL and every non-file request is aborted,
// so a CDN reference cannot load. Blocked URLs are listed on stderr.
//
// Exit codes: 0 ok · 1 usage error · 2 playwright-core not found · 3 render failed
'use strict';

const fs = require('fs');
const path = require('path');
const { pathToFileURL } = require('url');
const { execFileSync } = require('child_process');

const DEVICES = {
    desktop: { width: 1440, height: 900 },
    tablet: { width: 820, height: 1180 },
    phone: { width: 390, height: 844 },
};
const SCALE = 2;
const MAX_DIM = 10000;

const USAGE = 'usage: mockshot <file.html> [out.png] [--device phone|tablet|desktop | --size WxH] [--full]';

function usageError(msg) {
    process.stderr.write(`mockshot: ${msg}\n${USAGE}\n`);
    process.exit(1);
}

function parseArgs(argv) {
    const opts = { positional: [], device: null, size: null, full: false };
    for (let i = 0; i < argv.length; i++) {
        const a = argv[i];
        if (a === '-h' || a === '--help') {
            process.stdout.write(USAGE + '\n');
            process.exit(0);
        } else if (a === '--full') {
            opts.full = true;
        } else if (a === '--device' || a === '--size') {
            const v = argv[i + 1];
            if (v === undefined || v.startsWith('--')) usageError(`${a} needs a value`);
            i++;
            if (a === '--device') opts.device = v;
            else opts.size = v;
        } else if (a.startsWith('--device=')) {
            opts.device = a.slice('--device='.length);
        } else if (a.startsWith('--size=')) {
            opts.size = a.slice('--size='.length);
        } else if (a.startsWith('-')) {
            usageError(`unknown option ${a}`);
        } else {
            opts.positional.push(a);
        }
    }
    if (opts.positional.length < 1) usageError('missing <file.html>');
    if (opts.positional.length > 2) usageError(`too many arguments: ${opts.positional.slice(2).join(' ')}`);
    if (opts.device !== null && opts.size !== null) usageError('--device and --size are mutually exclusive');

    let viewport = DEVICES.desktop;
    if (opts.device !== null) {
        if (!Object.prototype.hasOwnProperty.call(DEVICES, opts.device)) {
            usageError(`unknown --device '${opts.device}' (expected phone, tablet or desktop)`);
        }
        viewport = DEVICES[opts.device];
    } else if (opts.size !== null) {
        const m = /^([0-9]+)x([0-9]+)$/.exec(opts.size);
        if (!m) usageError(`bad --size '${opts.size}' (expected WxH, e.g. 1280x800)`);
        const width = Number(m[1]);
        const height = Number(m[2]);
        if (width < 1 || height < 1 || width > MAX_DIM || height > MAX_DIM) {
            usageError(`bad --size '${opts.size}' (each dimension must be 1..${MAX_DIM})`);
        }
        viewport = { width, height };
    }

    const input = path.resolve(opts.positional[0]);
    let output = opts.positional[1];
    if (output === undefined) {
        output = /\.html?$/i.test(input) ? input.replace(/\.html?$/i, '.png') : input + '.png';
    }
    output = path.resolve(output);
    if (output === input) usageError('output path would overwrite the input');
    return { input, output, viewport, full: opts.full };
}

const INSTALL_HINT = [
    'mockshot needs playwright-core (no npm dependency is added to this repo). Either:',
    '  - set MOCKSHOT_PLAYWRIGHT_CORE=/path/to/node_modules/playwright-core, or',
    '  - install it globally: npm install -g playwright-core && npx playwright-core install chromium-headless-shell',
    'The Chromium revision installed must match that playwright-core version; mockshot never downloads browsers.',
].join('\n');

function loadPlaywright() {
    const explicit = process.env.MOCKSHOT_PLAYWRIGHT_CORE;
    if (explicit !== undefined && explicit !== '') {
        try {
            return require(path.resolve(explicit));
        } catch (e) {
            process.stderr.write(`mockshot: MOCKSHOT_PLAYWRIGHT_CORE=${explicit} could not be loaded: ${e.message.split('\n')[0]}\n${INSTALL_HINT}\n`);
            process.exit(2);
        }
    }
    const paths = (process.env.NODE_PATH || '').split(path.delimiter).filter(Boolean);
    try {
        const root = execFileSync('npm', ['root', '-g'], { encoding: 'utf8', stdio: ['ignore', 'pipe', 'ignore'] }).trim();
        if (root) paths.push(root);
    } catch (e) {
        // npm absent: NODE_PATH alone is searched.
    }
    let resolved;
    try {
        resolved = require.resolve('playwright-core', { paths });
    } catch (e) {
        process.stderr.write(`mockshot: playwright-core not found (searched NODE_PATH and the global npm root: ${paths.join(', ') || 'none'})\n${INSTALL_HINT}\n`);
        process.exit(2);
    }
    return require(resolved);
}

async function render(pw, { input, output, viewport, full }) {
    const blocked = [];
    const browser = await pw.chromium.launch({ headless: true });
    try {
        const context = await browser.newContext({
            viewport,
            deviceScaleFactor: SCALE,
            serviceWorkers: 'block',
        });
        const page = await context.newPage();
        await page.route('**/*', (route) => {
            const url = route.request().url();
            if (url.startsWith('file:') || url.startsWith('data:')) return route.continue();
            blocked.push(url);
            return route.abort('blockedbyclient');
        });
        page.on('pageerror', (err) => process.stderr.write(`mockshot: page error: ${err.message}\n`));
        await page.goto(pathToFileURL(input).href, { waitUntil: 'networkidle', timeout: 30000 });
        await page.evaluate(() => document.fonts.ready.then(() => true));
        await page.waitForLoadState('networkidle', { timeout: 30000 });
        fs.mkdirSync(path.dirname(output), { recursive: true });
        await page.screenshot({ path: output, fullPage: full });
    } finally {
        await browser.close();
    }
    return blocked;
}

async function main() {
    const opts = parseArgs(process.argv.slice(2));
    let st;
    try {
        st = fs.statSync(opts.input);
    } catch (e) {
        usageError(`no such file: ${opts.input}`);
    }
    if (!st.isFile()) usageError(`not a file: ${opts.input}`);

    const pw = loadPlaywright();
    let blocked;
    try {
        blocked = await render(pw, opts);
    } catch (e) {
        process.stderr.write(`mockshot: render failed: ${e.message}\n`);
        process.exit(3);
    }
    if (blocked.length > 0) {
        const unique = [...new Set(blocked)];
        process.stderr.write(`mockshot: WARNING blocked ${unique.length} external request(s) — mockups must not use CDNs or remote assets:\n`);
        for (const u of unique) process.stderr.write(`  ${u}\n`);
    }
    const { size } = fs.statSync(opts.output);
    process.stdout.write(`${opts.output} (${opts.viewport.width}x${opts.viewport.height} @${SCALE}x${opts.full ? ', full page' : ''}, ${size} bytes)\n`);
}

main().catch((e) => {
    process.stderr.write(`mockshot: ${e && e.stack ? e.stack : e}\n`);
    process.exit(3);
});
