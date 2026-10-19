/**
 * The landing page's own palette, so the animation matches the page it plays on.
 * Values are copied from the `.landing` custom properties in
 * `resources/css/landing.css`.
 */
export const token = {
    bone: '#f7f5f1',
    paper: '#fcf9f8',
    line: '#dedad2',
    ink: '#262624',
    ink2: '#55544e',
    ink3: '#69675f',
    inkGhost: '#8b8980',
    ox: '#701a24',
    oxTint: '#f1e4e5',
    ok: '#2f6b4f',
    okTint: '#e4ede8',
    well: '#efebe6',
} as const;

export const WIDTH = 1080;
export const HEIGHT = 1350;
export const FPS = 30;
export const DURATION_IN_SECONDS = 16;
export const DURATION_IN_FRAMES = FPS * DURATION_IN_SECONDS;

/**
 * The frame is 4:5 and fills a phone screen, so the layout is authored in
 * phone pixels and scaled up: text at 12 phone pixels lands at 33 canvas
 * pixels, which is the size that stays legible on a phone.
 */
export const PHONE_WIDTH = 390;
export const PHONE_HEIGHT = HEIGHT / (WIDTH / PHONE_WIDTH);
export const SCALE = WIDTH / PHONE_WIDTH;

/** Phone pixels to canvas pixels. */
export const u = (phonePixels: number): number => phonePixels * SCALE;

export const fontSans = 'Manrope, system-ui, -apple-system, sans-serif';
export const fontSerif = 'Newsreader, Iowan Old Style, Georgia, serif';

export const panel = {
    radius: u(10),
    border: `${u(1)}px solid ${token.line}`,
    shadow: `0 ${u(1)}px ${u(2)}px rgba(38, 38, 36, 0.04), 0 ${u(6)}px ${u(18)}px rgba(38, 38, 36, 0.05)`,
    padding: u(10),
} as const;
