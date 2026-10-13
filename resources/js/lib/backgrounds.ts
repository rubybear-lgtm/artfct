/**
 * Calm landscape backgrounds, tagged by the theme they were made for.
 * Light ones are airy and high-key; dark ones are low-light, so windows and
 * text stay readable on either. All are photographs: no people, no text.
 */
export type BackgroundMode = 'light' | 'dark';

export type Background = {
    id: string;
    mode: BackgroundMode;
    src: string;
    label: string;
};

const entry = (
    mode: BackgroundMode,
    slug: string,
    label: string,
): Background => ({
    id: `${mode}-${slug}`,
    mode,
    src: `/images/backgrounds/${mode}/${slug}.jpg`,
    label,
});

export const BACKGROUNDS: Background[] = [
    entry('light', '01-meadow', 'Misty meadow at dawn'),
    entry('light', '02-beach', 'Empty beach, pale surf'),
    entry('light', '03-snow', 'Snow plain, pale sky'),
    entry('light', '04-wheat', 'Wheat field under cream sky'),
    entry('light', '05-dunes', 'Pale desert dunes'),
    entry('light', '06-lake', 'Misty lake, grey-green'),
    entry('light', '07-hills', 'Lavender-grey hills'),
    entry('light', '08-chalk', 'Chalk cliffs and calm sea'),
    entry('light', '09-salt', 'Salt flats, peach sky'),
    entry('light', '10-ridges', 'Rose-haze mountain ridges'),
    entry('dark', '01-stars', 'Still lake under stars'),
    entry('dark', '02-pines', 'Pine ridge at blue hour'),
    entry('dark', '03-coast', 'Basalt coast at dusk'),
    entry('dark', '04-moon-dunes', 'Moonlit dunes'),
    entry('dark', '05-black-sand', 'Black sand beach at twilight'),
    entry('dark', '06-moor', 'Foggy moor, wine-red afterglow'),
    entry('dark', '07-valley', 'Plum valley after sunset'),
    entry('dark', '08-sea-night', 'Night sea, faint horizon glow'),
    entry('dark', '09-canyon', 'Red-rock canyon at dusk'),
    entry('dark', '10-fjord', 'Steel-blue fjord at twilight'),
    entry('dark', '11-lake-mist', 'Moonlit lake mist'),
    entry('dark', '13-ridges', 'Blue-grey night ridges'),
    entry('dark', '14-grey-sea', 'Charcoal sea under cloud'),
    entry('dark', '15-dusk-dunes', 'Violet dusk dunes'),
    entry('dark', '16-forest-fog', 'Foggy forest, dim light shaft'),
    entry('dark', '17-salt-night', 'Salt flat at night'),
];

/** Dark backgrounds the landing's story and hero use, kept out of the random
 * pool so no image appears twice on one page. */
export const STORY_DARK: Record<'ask' | 'share' | 'find' | 'use', string> = {
    ask: 'dark-04-moon-dunes',
    share: 'dark-13-ridges',
    find: 'dark-11-lake-mist',
    use: 'dark-14-grey-sea',
};

export const HERO_DARK: Record<'sage' | 'burst' | 'clay' | 'ox', string> = {
    sage: 'dark-16-forest-fog',
    burst: 'dark-06-moor',
    clay: 'dark-15-dusk-dunes',
    ox: 'dark-09-canyon',
};

const RESERVED = new Set<string>([
    ...Object.values(STORY_DARK),
    ...Object.values(HERO_DARK),
]);

/** The image path for a background id, e.g. `light-05-dunes`. */
export function backgroundSrc(id: string): string {
    const found = BACKGROUNDS.find((background) => background.id === id);

    if (!found) {
        throw new Error(`Unknown background: ${id}`);
    }

    return found.src;
}

/** A matched light and dark pair, for surfaces that follow the theme. */
export function backgroundPair(
    light: string,
    dark: string,
): { light: string; dark: string } {
    return { light: backgroundSrc(light), dark: backgroundSrc(dark) };
}

function shuffled<T>(items: T[]): T[] {
    const copy = [...items];

    for (let index = copy.length - 1; index > 0; index -= 1) {
        const other = Math.floor(Math.random() * (index + 1));
        [copy[index], copy[other]] = [copy[other], copy[index]];
    }

    return copy;
}

/** `count` different light and dark backgrounds, chosen at random. */
export function randomBackgroundPairs(
    count: number,
): { light: string; dark: string }[] {
    const lights = shuffled(
        BACKGROUNDS.filter((background) => background.mode === 'light'),
    );
    const darks = shuffled(
        BACKGROUNDS.filter(
            (background) =>
                background.mode === 'dark' && !RESERVED.has(background.id),
        ),
    );

    return Array.from({ length: count }, (_, index) => ({
        light: lights[index % lights.length].src,
        dark: darks[index % darks.length].src,
    }));
}
