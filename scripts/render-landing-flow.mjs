/**
 * Renders the landscape hero animation in three quality tiers, plus its
 * poster, from the Remotion composition in resources/remotion.
 *
 * Files land in resources/video under content-hashed names, and the manifest
 * resources/video/landing-flow.json tells the app which file is which. The
 * high tier is the audio master for the portrait cut, so render this before
 * `npm run video:mobile`.
 *
 * The sound effects live in resources/remotion/sfx and the fonts come from the
 * Vite build output, so the render needs no network access. Both are exposed to
 * the bundler through a throwaway public directory.
 *
 *   npm run video:wide
 */
import { mkdtempSync, rmSync, symlinkSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { bundle } from '@remotion/bundler';
import {
    renderMedia,
    renderStill,
    selectComposition,
} from '@remotion/renderer';

import { resolveFonts } from './remotion-fonts.mjs';
import { publish, ROOT, saveCut, TIERS } from './remotion-output.mjs';

const ENTRY = join(ROOT, 'resources/remotion/index.ts');
const COMPOSITION_ID = 'LandingFlowWide';

/** The last beat, once the answer and its source are on screen. */
const POSTER_FRAME = 462;

const inputProps = { fonts: resolveFonts(ROOT) };

const publicDir = mkdtempSync(join(tmpdir(), 'artfct-remotion-'));
const work = mkdtempSync(join(tmpdir(), 'artfct-render-'));
symlinkSync(join(ROOT, 'public/build'), join(publicDir, 'build'));
symlinkSync(join(ROOT, 'resources/remotion/sfx'), join(publicDir, 'sfx'));

const entry = {};

try {
    console.log(`Bundling ${ENTRY}...`);

    const serveUrl = await bundle({ entryPoint: ENTRY, publicDir });
    const composition = await selectComposition({
        serveUrl,
        id: COMPOSITION_ID,
        inputProps,
    });

    for (const [name, tier] of Object.entries(TIERS.wide)) {
        const output = join(work, `${name}.mp4`);

        console.log(
            `Rendering ${name}: ${Math.round(composition.width * tier.scale)}x${Math.round(composition.height * tier.scale)}`,
        );

        await renderMedia({
            composition,
            serveUrl,
            inputProps,
            codec: 'h264',
            outputLocation: output,
            scale: tier.scale,
            crf: tier.crf,
            pixelFormat: 'yuv420p',
            imageFormat: 'jpeg',
            jpegQuality: 92,
            audioCodec: 'aac',
            audioBitrate: tier.audioBitrate,
        });

        entry[name] = {
            ...publish('wide', name, 'mp4', output),
            width: Math.round(composition.width * tier.scale),
        };
    }

    const poster = join(work, 'poster.jpg');

    await renderStill({
        composition,
        serveUrl,
        inputProps,
        output: poster,
        frame: POSTER_FRAME,
        scale: TIERS.wide.high.scale,
        imageFormat: 'jpeg',
        jpegQuality: 82,
    });

    entry.poster = publish('wide', 'poster', 'jpg', poster);
} finally {
    rmSync(publicDir, { recursive: true, force: true });
    rmSync(work, { recursive: true, force: true });
}

saveCut('wide', entry);

for (const [name, item] of Object.entries(entry)) {
    console.log(`${name}: ${item.file} (${(item.bytes / 1024).toFixed(0)} KB)`);
}
