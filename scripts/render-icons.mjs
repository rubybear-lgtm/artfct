/**
 * Installs the hand-authored Artfct A icon set into public/.
 *
 * The mark is a full-bleed Bone serif "A" on Oxblood. Raster sizes are
 * committed under scripts/icons/ (the 16px favicon has intentionally
 * thickened strokes so the crossbar stays visible). This script copies
 * those PNGs into public/ and packs favicon.ico from the 16 + 32 sources.
 * It does not rasterize favicon.svg — regenerating from the SVG would
 * lose the 16px stroke tuning and reintroduce the old inset/Bone padding.
 *
 *   npm run icons
 */
import { copyFileSync, readFileSync, writeFileSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const SOURCES = join(ROOT, 'scripts/icons');
const PUBLIC = join(ROOT, 'public');

/**
 * Pack one or more PNG buffers into a multi-size .ico (PNG-in-ICO).
 *
 * @param {Buffer[]} pngs
 * @returns {Buffer}
 */
function packPngIco(pngs) {
    const count = pngs.length;
    const headerSize = 6 + count * 16;
    let offset = headerSize;
    const entries = pngs.map((png) => {
        const width = png.readUInt32BE(16);
        const height = png.readUInt32BE(20);
        const entry = {
            width: width >= 256 ? 0 : width,
            height: height >= 256 ? 0 : height,
            bytes: png.length,
            offset,
        };
        offset += png.length;

        return entry;
    });

    const out = Buffer.alloc(offset);
    out.writeUInt16LE(0, 0); // reserved
    out.writeUInt16LE(1, 2); // type: icon
    out.writeUInt16LE(count, 4);

    entries.forEach((entry, index) => {
        const base = 6 + index * 16;
        out.writeUInt8(entry.width, base);
        out.writeUInt8(entry.height, base + 1);
        out.writeUInt8(0, base + 2); // color count
        out.writeUInt8(0, base + 3); // reserved
        out.writeUInt16LE(1, base + 4); // planes
        out.writeUInt16LE(32, base + 6); // bit count
        out.writeUInt32LE(entry.bytes, base + 8);
        out.writeUInt32LE(entry.offset, base + 12);
    });

    let writeAt = headerSize;
    for (const png of pngs) {
        png.copy(out, writeAt);
        writeAt += png.length;
    }

    return out;
}

const copies = [
    { from: 'apple-touch-icon-180.png', to: 'apple-touch-icon.png' },
    { from: 'icon-512.png', to: 'icon-512.png' },
];

for (const { from, to } of copies) {
    copyFileSync(join(SOURCES, from), join(PUBLIC, to));
    console.log(`Copied public/${to} from scripts/icons/${from}`);
}

const png16 = readFileSync(join(SOURCES, 'favicon-16.png'));
const png32 = readFileSync(join(SOURCES, 'favicon-32.png'));

if (png16.subarray(0, 8).toString('hex') !== '89504e470d0a1a0a') {
    console.error('scripts/icons/favicon-16.png is not a PNG.');
    process.exit(1);
}

if (png32.subarray(0, 8).toString('hex') !== '89504e470d0a1a0a') {
    console.error('scripts/icons/favicon-32.png is not a PNG.');
    process.exit(1);
}

writeFileSync(join(PUBLIC, 'favicon.ico'), packPngIco([png16, png32]));
console.log('Packed public/favicon.ico from favicon-16.png + favicon-32.png');
