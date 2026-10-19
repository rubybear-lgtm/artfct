import { existsSync, readFileSync } from 'node:fs';
import { join } from 'node:path';

const WANTED = [
    { family: 'manrope', weight: '400' },
    { family: 'manrope', weight: '500' },
    { family: 'manrope', weight: '600' },
    { family: 'manrope', weight: '700' },
    { family: 'newsreader', weight: '400' },
];

/**
 * Resolves the Latin subset of each weight from the Vite font manifest. The
 * manifest lists every unicode subset Bunny serves, and the Latin one is the
 * only subset that carries the copy in this video.
 */
export function resolveFonts(root) {
    const manifestPath = join(root, 'public/build', 'fonts-manifest.json');

    if (!existsSync(manifestPath)) {
        throw new Error(
            'Missing public/build/fonts-manifest.json. Run `npm run build` first.',
        );
    }

    const manifest = JSON.parse(readFileSync(manifestPath, 'utf8'));

    return WANTED.map(({ family, weight }) => {
        const variant = manifest.families[family]?.variants[`${weight}:normal`];
        const file = variant?.files.find(
            (candidate) =>
                candidate.format === 'woff2' &&
                candidate.unicodeRange.includes('U+0000-00FF'),
        );

        if (!file) {
            throw new Error(
                `Missing ${family} ${weight} latin woff2 in public/build. Run \`npm run build\` first.`,
            );
        }

        return {
            family: manifest.families[family].family,
            weight,
            style: 'normal',
            url: join('build', file.file),
        };
    });
}
