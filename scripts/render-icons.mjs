/**
 * Renders the raster app icons from public/favicon.svg.
 *
 * favicon.svg covers modern browsers, but iOS home screens still want a PNG
 * and will composite transparency onto black, so the touch icon is drawn on an
 * opaque bone ground. The .ico is left alone; browsers have preferred the SVG
 * for years and regenerating it adds nothing.
 *
 *   npm run icons
 */
import { readFileSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

import { chromium } from 'playwright';

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..');

/** Bone, matching DESIGN.md. */
const GROUND = '#f7f5f2';

const TARGETS = [
    { file: 'apple-touch-icon.png', size: 180, ground: GROUND },
    { file: 'icon-512.png', size: 512, ground: GROUND },
];

const svg = readFileSync(join(ROOT, 'public/favicon.svg'), 'utf8');
const markUrl = pathToFileURL(join(ROOT, 'public/favicon.svg')).href;

if (!svg.includes('<svg')) {
    console.error('public/favicon.svg does not look like an SVG.');
    process.exit(1);
}

const browser = await chromium.launch();

for (const { file, size, ground } of TARGETS) {
    const page = await browser.newPage({
        viewport: { width: size, height: size },
        deviceScaleFactor: 1,
    });

    // The mark is scaled to 72% of the icon so it keeps a margin on a home
    // screen, then centred on an opaque ground.
    const inset = Math.round(size * 0.14);
    await page.setContent(
        `<!DOCTYPE html><html><body style="margin:0;width:${size}px;height:${size}px;background:${ground};display:flex;align-items:center;justify-content:center">
           <img src="${markUrl}" width="${size - inset * 2}" height="${size - inset * 2}" alt="">
         </body></html>`,
        { waitUntil: 'load' },
    );

    await page.screenshot({ path: join(ROOT, 'public', file) });
    await page.close();

    console.log(`Rendered public/${file} at ${size}x${size}`);
}

await browser.close();
