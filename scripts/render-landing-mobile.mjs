/**
 * Renders the portrait hero animation in three quality tiers, plus its
 * poster, from the Remotion composition in resources/remotion.
 *
 * The composition is the 4:5 cut of the landscape hero: the same four story
 * beats on the same seconds, with the audio of the landscape high tier muxed
 * in, so the two cuts stay in sync. Fonts are read from the Vite build output,
 * so the render matches the live site and needs no network access.
 *
 *   npm run video:wide && npm run video:mobile
 */
import { copyFileSync, mkdtempSync, rmSync, symlinkSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { bundle } from '@remotion/bundler';
import {
    renderMedia,
    renderStill,
    selectComposition,
} from '@remotion/renderer';

import { resolveFonts } from './remotion-fonts.mjs';
import {
    publish,
    readManifest,
    ROOT,
    saveCut,
    TIERS,
    VIDEO_DIR,
} from './remotion-output.mjs';

const ENTRY = join(ROOT, 'resources/remotion/index.ts');
const COMPOSITION_ID = 'LandingFlowMobile';

/** The last beat, once the answer and its source are on screen. */
const POSTER_FRAME = 462;

const master = readManifest().wide?.high?.file;

if (!master) {
    throw new Error(
        'The landscape cut has not been rendered. Run `npm run video:wide` first.',
    );
}

/** The audio comes from the landscape master, so both cuts share one track. */
const AUDIO = 'master.mp4';

const inputProps = { fonts: resolveFonts(ROOT), audio: AUDIO };

const publicDir = mkdtempSync(join(tmpdir(), 'artfct-remotion-'));
const work = mkdtempSync(join(tmpdir(), 'artfct-render-'));
symlinkSync(join(ROOT, 'public/build'), join(publicDir, 'build'));
copyFileSync(join(VIDEO_DIR, master), join(publicDir, AUDIO));

const entry = {};

try {
    console.log(`Bundling ${ENTRY}...`);

    const serveUrl = await bundle({ entryPoint: ENTRY, publicDir });
    const composition = await selectComposition({
        serveUrl,
        id: COMPOSITION_ID,
        inputProps,
    });

    for (const [name, tier] of Object.entries(TIERS.phone)) {
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
            ...publish('phone', name, 'mp4', output),
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
        imageFormat: 'jpeg',
        jpegQuality: 82,
    });

    entry.poster = publish('phone', 'poster', 'jpg', poster);
} finally {
    rmSync(publicDir, { recursive: true, force: true });
    rmSync(work, { recursive: true, force: true });
}

saveCut('phone', entry);

for (const [name, item] of Object.entries(entry)) {
    console.log(`${name}: ${item.file} (${(item.bytes / 1024).toFixed(0)} KB)`);
}
