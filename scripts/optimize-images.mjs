/**
 * Writes WebP siblings for the PNGs under public/images.
 *
 * Chromium does the encoding, because neither macOS `sips` nor `cwebp` is
 * available here and adding an image dependency for three files is not worth
 * it. Smooth gradients are close to a worst case for PNG and a best case for
 * WebP, so the saving is large; the PNGs stay on disk as the fallback that
 * <picture> only serves to browsers without WebP.
 *
 *   npm run images:webp
 */
import { readdirSync, readFileSync, statSync, writeFileSync } from 'node:fs';
import { basename, dirname, extname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

import { chromium } from 'playwright';

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const IMAGES = join(ROOT, 'public/images');

/** Flat gradients carry no fine detail, so 85 is indistinguishable here. */
const QUALITY = 0.85;

function pngsUnder(dir) {
    const found = [];

    for (const entry of readdirSync(dir, { withFileTypes: true })) {
        const full = join(dir, entry.name);

        if (entry.isDirectory()) {
            found.push(...pngsUnder(full));
        } else if (extname(entry.name).toLowerCase() === '.png') {
            found.push(full);
        }
    }

    return found;
}

const sources = pngsUnder(IMAGES);

if (sources.length === 0) {
    console.error(`No PNGs found under ${IMAGES}`);
    process.exit(1);
}

const browser = await chromium.launch();
const page = await browser.newPage();

let before = 0;
let after = 0;

for (const source of sources) {
    const png = readFileSync(source);
    const beforeBytes = statSync(source).size;

    const dataUrl = `data:image/png;base64,${png.toString('base64')}`;

    const result = await page.evaluate(
        async ({ dataUrl, quality }) => {
            const img = new Image();
            img.src = dataUrl;
            await img.decode();

            const canvas = document.createElement('canvas');
            canvas.width = img.naturalWidth;
            canvas.height = img.naturalHeight;
            canvas.getContext('2d').drawImage(img, 0, 0);

            const blob = await new Promise((r) =>
                canvas.toBlob(r, 'image/webp', quality),
            );

            if (!blob) {
                return { error: 'encoder returned no blob' };
            }

            const buffer = await blob.arrayBuffer();
            let binary = '';

            for (const byte of new Uint8Array(buffer)) {
                binary += String.fromCharCode(byte);
            }

            return {
                base64: btoa(binary),
                width: canvas.width,
                height: canvas.height,
            };
        },
        { dataUrl, quality: QUALITY },
    );

    if (result.error) {
        console.error(`✗ ${basename(source)}: ${result.error}`);
        process.exitCode = 1;
        continue;
    }

    const out = source.replace(/\.png$/i, '.webp');
    const webp = Buffer.from(result.base64, 'base64');
    writeFileSync(out, webp);

    const afterBytes = statSync(out).size;
    before += beforeBytes;
    after += afterBytes;

    const ratio = ((1 - afterBytes / beforeBytes) * 100).toFixed(0);
    console.log(
        `${basename(out).padEnd(26)} ${result.width}x${result.height}  ` +
            `${(beforeBytes / 1024).toFixed(0)} KB → ${(afterBytes / 1024).toFixed(0)} KB  (−${ratio}%)`,
    );

    // A WebP larger than its PNG means the encoder did not do what we think.
    if (afterBytes >= beforeBytes) {
        console.error(
            `  ! ${basename(out)} is not smaller than its PNG — check the encoder.`,
        );
        process.exitCode = 1;
    }
}

await browser.close();

console.log(
    `\nTotal ${(before / 1024).toFixed(0)} KB → ${(after / 1024).toFixed(0)} KB ` +
        `(${(before / after).toFixed(1)}x smaller)`,
);
