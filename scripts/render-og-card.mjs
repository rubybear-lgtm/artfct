/**
 * Renders the Open Graph card to a 1200x630 PNG.
 *
 * Social crawlers do not render SVG, so og:image must be raster. The card is
 * markup rather than a drawing so the letterforms stay crisp at any size and
 * re-render when copy changes. Fonts are read from the Vite build output, so
 * the card matches the live site exactly and needs no network access.
 *
 *   npm run og:card
 */
import { execFileSync } from 'node:child_process';
import { existsSync, readdirSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

import { chromium } from 'playwright';

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const BUILD_ASSETS = join(ROOT, 'public/build/assets');
const OUT = join(ROOT, 'public/og-image.png');

const WIDTH = 1200;
const HEIGHT = 630;

/** Resolves a hashed Vite font asset to a file:// URL. */
function fontUrl(family, weight, style) {
    const match = readdirSync(BUILD_ASSETS).find(
        (name) =>
            name.startsWith(`${family}-${weight}-${style}-`) &&
            name.endsWith('.woff2'),
    );

    if (!match) {
        throw new Error(
            `Missing ${family} ${weight} ${style} in public/build/assets. Run \`npm run build\` first.`,
        );
    }

    return pathToFileURL(join(BUILD_ASSETS, match)).href;
}

const CARD = `<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<style>
  @font-face {
    font-family: 'Newsreader';
    font-weight: 400;
    font-style: normal;
    src: url('${fontUrl('newsreader', 400, 'normal')}') format('woff2');
  }
  @font-face {
    font-family: 'Newsreader';
    font-weight: 400;
    font-style: italic;
    src: url('${fontUrl('newsreader', 400, 'italic')}') format('woff2');
  }
  @font-face {
    font-family: 'Manrope';
    font-weight: 500;
    font-style: normal;
    src: url('${fontUrl('manrope', 500, 'normal')}') format('woff2');
  }
  @font-face {
    font-family: 'Manrope';
    font-weight: 600;
    font-style: normal;
    src: url('${fontUrl('manrope', 600, 'normal')}') format('woff2');
  }

  * { margin: 0; padding: 0; box-sizing: border-box; }

  body {
    width: ${WIDTH}px;
    height: ${HEIGHT}px;
    background: #f7f5f2;
    font-family: 'Manrope', system-ui, sans-serif;
    color: #262624;
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    padding: 72px 80px 64px;
  }

  .wordmark {
    display: flex;
    align-items: center;
    gap: 10px;
    font-family: 'Newsreader', Georgia, serif;
    font-size: 30px;
    font-weight: 400;
    letter-spacing: -0.02em;
  }

  .wordmark .dot {
    width: 9px;
    height: 9px;
    border-radius: 9999px;
    background: #701a24;
  }

  h1 {
    font-family: 'Newsreader', Georgia, serif;
    font-weight: 400;
    font-size: 78px;
    line-height: 1.04;
    letter-spacing: -0.02em;
    max-width: 940px;
    text-wrap: balance;
  }

  h1 em {
    font-style: italic;
    color: #701a24;
  }

  .sub {
    margin-top: 26px;
    font-size: 25px;
    line-height: 1.5;
    color: #66655f;
    max-width: 800px;
  }

  footer {
    display: flex;
    align-items: baseline;
    justify-content: space-between;
    border-top: 1px solid #dedad2;
    padding-top: 22px;
    font-size: 20px;
    font-weight: 500;
    color: #8c8a83;
  }

  .url { color: #262624; font-weight: 600; }
</style>
</head>
<body>
  <div class="wordmark"><span class="dot"></span>Artfct</div>

  <main>
    <h1>Your AI makes things. Artfct <em>remembers</em> them.</h1>
    <p class="sub">Share the reports, tables and documents worth keeping. Every AI tool on your team can read them — so nobody starts from scratch.</p>
  </main>

  <footer>
    <span class="url">artfct.dev</span>
    <span>Claude · ChatGPT · Copilot · Cursor</span>
  </footer>
</body>
</html>`;

if (!existsSync(BUILD_ASSETS)) {
    console.error(
        'public/build/assets is missing. Run `npm run build` before rendering the OG card.',
    );
    process.exit(1);
}

const browser = await chromium.launch();
const page = await browser.newPage({
    viewport: { width: WIDTH, height: HEIGHT },
    deviceScaleFactor: 1,
});

await page.setContent(CARD, { waitUntil: 'load' });
await page.evaluate(() => document.fonts.ready);
await page.screenshot({ path: OUT });
await browser.close();

const [w, h] = execFileSync('sips', ['-g', 'pixelWidth', '-g', 'pixelHeight', OUT])
    .toString()
    .trim()
    .split('\n')
    .map((line) => line.split(': ')[1]);

if (Number(w) !== WIDTH || Number(h) !== HEIGHT) {
    console.error(`Expected ${WIDTH}x${HEIGHT}, rendered ${w}x${h}.`);
    process.exit(1);
}

console.log(`Rendered public/og-image.png at ${w}x${h}`);
