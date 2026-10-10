import { FPS, PHONE_HEIGHT, PHONE_WIDTH } from './theme';

/** Seconds to frames, so the schedule reads like the story it tells. */
export const at = (seconds: number): number => Math.round(seconds * FPS);

/**
 * The four steps of the story. These land on the same seconds as the
 * landscape render, which keeps this variant in sync with the shared audio.
 */
export const step = {
    asks: at(0),
    shares: at(3),
    findable: at(6.5),
    answers: at(9),
} as const;

export const beat = {
    typingStart: at(0.4),
    typingEnd: at(1.75),
    artifactIn: at(1.8),
    shareRowIn: at(2.25),
    libraryIn: at(2.8),
    placeholderIn: at(3.25),
    libraryRowIn: at(3.9),
    readingIn: at(4.05),
    readyIn: at(6.1),
    toolsLabelIn: at(6.6),
    toolIn: at(6.8),
    toolStagger: at(0.35),
    cursorIn: at(8.85),
    cursorWellIn: at(9.1),
    foundLineIn: at(10),
    sourceCardIn: at(10.6),
    libraryPulse: at(11.1),
    answerIn: at(11.7),
    answerFramesPerCharacter: 1.5,
    sourcePillIn: at(15),
    fadeOut: at(15.5),
} as const;

export const caption = [
    'A marketer asks Claude for a pricing report.',
    'They share it with the team. Now it is saved and searchable.',
    'Now every AI tool on the team can find it.',
    "A product manager's AI, in a different tool, finds it and uses it, with the source.",
] as const;

export const CONTENT = {
    prompt: 'Write up what we learned about pricing',
    artifact: 'Pricing teardown',
    madeIn: 'Made in Claude',
    editor: 'Marketing',
    libraryTitle: 'Team library',
    libraryToolLabel: "Available to your team's AI tools",
    neighbour: [
        { title: 'Brand voice guide', meta: 'ChatGPT · Design', glyph: 'chat' },
        {
            title: 'Launch page mockup',
            meta: 'Cursor · Engineering',
            glyph: 'pointer',
        },
    ],
    reading: 'Reading it…',
    ready: 'Ready to find',
    reader: 'Product',
    readerPrompt: 'Draft the launch pricing page',
    found: 'Cursor found this in your team library',
    answer: 'Lead with the annual plan, as the pricing teardown recommends.',
    source: 'Source: Pricing teardown, by Marketing',
} as const;

/** The stacked panels, in phone pixels. */
export const layout = {
    padInline: 13,
    top: 11,
    gap: 7,
    connector: 20,
    compact: 42,
    claude: 176,
    library: 218,
    cursor: 228,
    dots: 18,
    dotsGapTop: 7,
    captionGap: 6,
    captionLines: 2,
    bottom: 13.5,
} as const;

export const width = PHONE_WIDTH;
export const height = PHONE_HEIGHT;
