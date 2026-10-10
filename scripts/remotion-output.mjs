import { createHash } from 'node:crypto';
import {
    copyFileSync,
    existsSync,
    mkdirSync,
    readdirSync,
    readFileSync,
    rmSync,
    statSync,
    writeFileSync,
} from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

export const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..');
export const VIDEO_DIR = join(ROOT, 'resources/video');
export const MANIFEST = join(VIDEO_DIR, 'landing-flow.json');

/**
 * Quality tiers for each cut of the landing animation. `scale` multiplies the
 * composition size, so the high tier of the wide cut is drawn at 2400x1080 for
 * sharp playback on retina screens, and the low tier at 800x360 for slow links.
 */
export const TIERS = {
    wide: {
        high: { scale: 1.5, crf: 20, audioBitrate: '128k' },
        medium: { scale: 1, crf: 24, audioBitrate: '96k' },
        low: { scale: 0.5, crf: 30, audioBitrate: '64k' },
    },
    phone: {
        high: { scale: 1, crf: 20, audioBitrate: '128k' },
        medium: { scale: 2 / 3, crf: 24, audioBitrate: '96k' },
        low: { scale: 4 / 9, crf: 30, audioBitrate: '64k' },
    },
};

export function readManifest() {
    return existsSync(MANIFEST)
        ? JSON.parse(readFileSync(MANIFEST, 'utf8'))
        : {};
}

/**
 * Copies a render into resources/video under a name that carries a hash of
 * its bytes. A changed render gets a new name, so the file can be cached
 * forever and a stale copy can never be served.
 */
export function publish(cut, name, extension, source) {
    mkdirSync(VIDEO_DIR, { recursive: true });

    const hash = createHash('sha1')
        .update(readFileSync(source))
        .digest('hex')
        .slice(0, 10);
    const file = `landing-flow-${cut}-${name}.${hash}.${extension}`;

    copyFileSync(source, join(VIDEO_DIR, file));

    return { file, bytes: statSync(source).size };
}

/** Saves one cut into the manifest and removes files nothing points to. */
export function saveCut(cut, entry) {
    const manifest = { ...readManifest(), [cut]: entry };
    const keep = new Set(
        Object.values(manifest).flatMap((value) =>
            Object.values(value).map((item) => item.file),
        ),
    );

    writeFileSync(MANIFEST, `${JSON.stringify(manifest, null, 4)}\n`);

    for (const file of readdirSync(VIDEO_DIR)) {
        if (file.startsWith('landing-flow-') && !keep.has(file)) {
            rmSync(join(VIDEO_DIR, file));
        }
    }
}
