import { Fragment, useEffect, useRef, useState } from 'react';
import type { CSSProperties, ReactNode } from 'react';

import { AiToolIcon } from '@/components/ai-tool-icon';

import { caption, CONTENT } from '../../../remotion/timeline';

/**
 * The landing story. A hero stage plays the whole animation (ask, share, find,
 * use) once the page loads. Four pinned sections then replay one step each as
 * they arrive, and again whenever they are re-entered. Windows are plain DOM
 * so the copy stays readable text and the layout stays responsive.
 */
const STEPS = [
    { key: 'ask', label: 'Ask', image: '/images/landing/ask.jpg' },
    { key: 'share', label: 'Share', image: '/images/landing/share.jpg' },
    { key: 'find', label: 'Find', image: '/images/landing/find.jpg' },
    { key: 'use', label: 'Use', image: '/images/landing/use.jpg' },
] as const;

const REDUCED_QUERY = '(prefers-reduced-motion: reduce)';

const PROMPT = CONTENT.prompt;
const ARTIFACT = CONTENT.artifact;
const ASSISTANT =
    "I've put together a short report on what we learned about pricing, with the main findings and what to try next.".split(
        ' ',
    );
const SEARCH = 'pricing';
const READER_PROMPT = 'Draft the launch pricing page';
const REPLY =
    'Lead with the annual plan, as the pricing teardown recommends.'.split(' ');
const TOOLS = [
    { id: 'claude', name: 'Claude' },
    { id: 'chatgpt', name: 'ChatGPT' },
    { id: 'cursor', name: 'Cursor' },
    { id: 'copilot', name: 'Copilot' },
] as const;
const TEAMS = ['Marketing', 'Product', 'Design'] as const;

const C = {
    bone: '#F7F5F2',
    paper: '#FBFAF8',
    hair: '#DEDAD2',
    ink: '#262624',
    muted: '#55544E',
    quiet: '#69675F',
    ox: '#701A24',
    tint: '#F1E4E5',
    well: '#EFECE6',
    green: '#2F6B4F',
    greenTint: '#E3EEE8',
};

const clamp = (v: number, a: number, b: number) => Math.max(a, Math.min(b, v));
const lin = (t: number, a: number, b: number) => clamp((t - a) / (b - a), 0, 1);
const ramp = (t: number, a: number, b: number) =>
    1 - Math.pow(1 - lin(t, a, b), 3);

function useMedia(query: string) {
    const [matches, setMatches] = useState(false);

    useEffect(() => {
        const list = window.matchMedia(query);
        const update = () => setMatches(list.matches);
        update();
        list.addEventListener('change', update);

        return () => list.removeEventListener('change', update);
    }, [query]);

    return matches;
}

/* ── small pieces ─────────────────────────────────────────────────────── */

function Doc({ size = 26 }: { size?: number }) {
    return (
        <svg
            width={size}
            height={size}
            viewBox="0 0 24 24"
            fill="none"
            stroke={C.ox}
            strokeWidth="1.8"
            strokeLinecap="round"
            strokeLinejoin="round"
            aria-hidden="true"
        >
            <path d="M7 3h7l5 5v13H7z" />
            <path d="M14 3v5h5" />
            <path d="M10 13h6M10 17h6" />
        </svg>
    );
}

function Check({ color = C.green }: { color?: string }) {
    return (
        <svg
            viewBox="0 0 24 24"
            width="1em"
            height="1em"
            fill="none"
            stroke={color}
            strokeWidth="3"
            strokeLinecap="round"
            strokeLinejoin="round"
            aria-hidden="true"
        >
            <path d="m5 12.5 4.5 4.5L19 7" />
        </svg>
    );
}

function Caret() {
    return <i className="fs-caret" />;
}

function Chrome({
    title,
    left,
    right,
}: {
    title?: string;
    left?: ReactNode;
    right?: ReactNode;
}) {
    return (
        <div className="fs-chrome">
            <span className="fs-dots">
                <i />
                <i />
                <i />
            </span>
            {left}
            <span className="fs-title">{title}</span>
            <span className="fs-right">{right}</span>
        </div>
    );
}

function Composer({
    placeholder,
    chips,
}: {
    placeholder: string;
    chips: ReactNode;
}) {
    return (
        <div className="fs-composer">
            <span className="fs-composer-text">{placeholder}</span>
            <span className="fs-composer-row">
                <span className="fs-plus">+</span>
                {chips}
                <span className="fs-send">↑</span>
            </span>
        </div>
    );
}

function ArtifactCard({
    sub,
    badge,
    hot = false,
    style,
}: {
    sub: string;
    badge?: ReactNode;
    hot?: boolean;
    style?: CSSProperties;
}) {
    return (
        <div className={`fs-artifact${hot ? 'hot' : ''}`} style={style}>
            <span className="fs-art-ico">
                <Doc />
            </span>
            <span className="fs-art-text">
                <b>{ARTIFACT}</b>
                <small>{sub}</small>
            </span>
            {badge}
        </div>
    );
}

/* ── the three windows ───────────────────────────────────────────────── */

function ClaudeWindow({ step, p }: { step: 'ask' | 'share'; p: number }) {
    const ask = step === 'ask';
    const typed = ask
        ? Math.floor(lin(p, 0.04, 0.3) * PROMPT.length)
        : PROMPT.length;
    const typing = ask && p > 0.04 && p < 0.32;
    const thinking = ask && p >= 0.32 && p < 0.38;
    const words = ask
        ? Math.floor(lin(p, 0.38, 0.7) * ASSISTANT.length)
        : ASSISTANT.length;
    const artifact = ask ? ramp(p, 0.74, 0.9) : 1;

    const hot = !ask && p > 0.08 && p < 0.7;
    const pop = ask ? 0 : ramp(p, 0.16, 0.3) * (1 - ramp(p, 0.84, 0.94));
    const row = (i: number) => ramp(p, 0.3 + i * 0.06, 0.38 + i * 0.06);
    const press = lin(p, 0.58, 0.68);
    const shared = !ask && p >= 0.66;
    const badge = ask ? 0 : ramp(p, 0.88, 0.98);

    return (
        <>
            <Chrome
                title="Pricing report"
                right={
                    <span
                        className={`fs-sharebtn${hot ? 'hot' : ''}`}
                        style={{ opacity: ask ? 0.5 : 1 }}
                    >
                        Share
                    </span>
                }
            />
            <div className="fs-body">
                <div className="fs-msg-user">
                    {PROMPT.slice(0, typed)}
                    {typing && <Caret />}
                </div>
                {thinking && (
                    <div className="fs-thinking">
                        <i />
                        <i />
                        <i />
                    </div>
                )}
                {words > 0 && (
                    <p className="fs-msg-ai">
                        {ASSISTANT.slice(0, words).join(' ')}
                    </p>
                )}
                {artifact > 0.01 && (
                    <ArtifactCard
                        sub={`Report · ${CONTENT.madeIn}`}
                        style={{
                            opacity: artifact,
                            translate: `0 ${(1 - artifact) * 22}px`,
                        }}
                        badge={
                            badge > 0.01 ? (
                                <span
                                    className="fs-badge"
                                    style={{ opacity: badge }}
                                >
                                    <Check /> Shared with your team
                                </span>
                            ) : undefined
                        }
                    />
                )}
            </div>
            <Composer
                placeholder="Reply to Claude…"
                chips={
                    <span className="fs-chipbtn">
                        <AiToolIcon tool="claude" size={22} /> Claude
                    </span>
                }
            />

            {pop > 0.01 && (
                <div
                    className="fs-pop"
                    style={{
                        opacity: pop,
                        translate: `0 ${(1 - pop) * -14}px`,
                    }}
                >
                    <div className="fs-pop-title">Share with your team</div>
                    <div className="fs-pop-sub">
                        Your team and their AI tools will be able to find it.
                    </div>
                    {TEAMS.map((team, i) => (
                        <div
                            key={team}
                            className="fs-pop-row"
                            style={{
                                opacity: row(i),
                                translate: `${(1 - row(i)) * -10}px 0`,
                            }}
                        >
                            <span className="fs-av">{team[0]}</span>
                            <span>{team}</span>
                            <span className="fs-pop-can">Can view</span>
                        </div>
                    ))}
                    <span
                        className="fs-pop-btn"
                        style={{
                            background: shared ? C.greenTint : C.ox,
                            color: shared ? C.green : '#fff',
                            scale: String(1 - 0.06 * Math.sin(Math.PI * press)),
                        }}
                    >
                        {shared ? (
                            <>
                                <Check /> Shared
                            </>
                        ) : (
                            'Share'
                        )}
                    </span>
                </div>
            )}
        </>
    );
}

function LibraryWindow({ p }: { p: number }) {
    const typed = Math.floor(lin(p, 0.04, 0.22) * SEARCH.length);
    const typing = p > 0.04 && p < 0.24;
    const rowIn = (i: number) => ramp(p, 0.22 + i * 0.05, 0.3 + i * 0.05);
    const reading = lin(p, 0.34, 0.62);
    const ready = p >= 0.62;
    const lit = (i: number) => ramp(p, 0.66 + i * 0.07, 0.72 + i * 0.07);

    return (
        <>
            <Chrome title="Team library" />
            <div className="fs-lib">
                <aside className="fs-side">
                    <div className="fs-side-h">Library</div>
                    <div className="fs-side-i on">All files</div>
                    {TOOLS.slice(0, 3).map((t) => (
                        <div key={t.id} className="fs-side-i">
                            <AiToolIcon tool={t.id} size={20} /> Made in{' '}
                            {t.name}
                        </div>
                    ))}
                </aside>
                <div className="fs-lib-main">
                    <div className="fs-search">
                        <span className="fs-mag">⌕</span>
                        <span>
                            {typed === 0 && !typing ? (
                                <span className="fs-ph">
                                    Search your team's AI work
                                </span>
                            ) : (
                                SEARCH.slice(0, typed)
                            )}
                            {typing && <Caret />}
                        </span>
                    </div>

                    <div
                        className="fs-lrow ours"
                        style={{
                            opacity: rowIn(0),
                            translate: `0 ${(1 - rowIn(0)) * 18}px`,
                        }}
                    >
                        <span className="fs-art-ico">
                            <Doc />
                        </span>
                        <span className="fs-art-text">
                            <b>{ARTIFACT}</b>
                            <small>
                                <AiToolIcon tool="claude" size={18} /> Claude ·{' '}
                                {CONTENT.editor}
                            </small>
                        </span>
                        <span
                            className="fs-status"
                            style={{
                                background: ready ? '#fff' : C.well,
                                color: ready ? C.ox : C.quiet,
                            }}
                        >
                            {ready ? '✓ Ready' : 'Reading…'}
                        </span>
                        {!ready && (
                            <span className="fs-read">
                                <i style={{ width: `${reading * 100}%` }} />
                            </span>
                        )}
                    </div>
                    {CONTENT.neighbour.map((n, i) => (
                        <div
                            key={n.title}
                            className="fs-lrow"
                            style={{
                                opacity: rowIn(i + 1) * 0.55,
                                translate: `0 ${(1 - rowIn(i + 1)) * 18}px`,
                            }}
                        >
                            <span className="fs-art-ico">
                                <Doc />
                            </span>
                            <span className="fs-art-text">
                                <b>{n.title}</b>
                                <small>{n.meta}</small>
                            </span>
                        </div>
                    ))}

                    <div
                        className="fs-tools-h"
                        style={{ opacity: ramp(p, 0.62, 0.68) }}
                    >
                        {CONTENT.libraryToolLabel}
                    </div>
                    <div className="fs-tools">
                        {TOOLS.map((t, i) => (
                            <span
                                key={t.id}
                                className="fs-tool"
                                style={{
                                    borderColor: lit(i) > 0.5 ? C.ox : C.hair,
                                    background: lit(i) > 0.5 ? C.tint : '#fff',
                                }}
                            >
                                <AiToolIcon tool={t.id} size={30} />
                                {t.name}
                                <span
                                    className="fs-tool-ok"
                                    style={{ opacity: lit(i) }}
                                >
                                    <Check color={C.ox} />
                                </span>
                            </span>
                        ))}
                    </div>
                </div>
            </div>
        </>
    );
}

function CursorWindow({ p }: { p: number }) {
    const typed = Math.floor(lin(p, 0.04, 0.2) * READER_PROMPT.length);
    const typing = p > 0.04 && p < 0.22;
    const s1 = ramp(p, 0.24, 0.3);
    const s1done = p >= 0.4;
    const s2 = ramp(p, 0.42, 0.48);
    const s2done = p >= 0.54;
    const chip = ramp(p, 0.5, 0.58);
    const words = Math.floor(lin(p, 0.6, 0.86) * REPLY.length);
    const source = ramp(p, 0.88, 0.95);

    return (
        <>
            <Chrome
                left={
                    <span className="fs-pillbtn">
                        <AiToolIcon tool="cursor" size={22} /> Cursor
                    </span>
                }
                title="Product"
                right={<span className="fs-new">New chat</span>}
            />
            <div className="fs-body">
                <div className="fs-msg-user">
                    {READER_PROMPT.slice(0, typed)}
                    {typing && <Caret />}
                </div>
                <div className="fs-steps-list">
                    <div
                        className="fs-step-row"
                        style={{
                            opacity: s1,
                            translate: `0 ${(1 - s1) * 12}px`,
                        }}
                    >
                        <span className="fs-step-ico">
                            {s1done ? <Check /> : <i className="fs-spin" />}
                        </span>
                        {s1done
                            ? 'Searched your team library'
                            : 'Searching your team library…'}
                    </div>
                    <div
                        className="fs-step-row"
                        style={{
                            opacity: s2,
                            translate: `0 ${(1 - s2) * 12}px`,
                        }}
                    >
                        <span className="fs-step-ico">
                            {s2done ? <Check /> : <i className="fs-spin" />}
                        </span>
                        {s2done ? 'Read' : 'Reading'}
                        <span className="fs-inline-chip">
                            <Doc size={18} /> {ARTIFACT}
                        </span>
                    </div>
                </div>
                {chip > 0.01 && (
                    <ArtifactCard
                        hot
                        sub={`Claude · ${CONTENT.editor}`}
                        style={{
                            opacity: chip,
                            translate: `0 ${(1 - chip) * 26}px`,
                        }}
                    />
                )}
                {words > 0 && (
                    <p className="fs-msg-ai plain">
                        {REPLY.slice(0, words).join(' ')}
                    </p>
                )}
                {source > 0.01 && (
                    <span
                        className="fs-source"
                        style={{
                            opacity: source,
                            translate: `0 ${(1 - source) * 8}px`,
                        }}
                    >
                        Source: {ARTIFACT}
                    </span>
                )}
            </div>
            <Composer
                placeholder="Plan, search, build anything"
                chips={
                    <>
                        <span className="fs-chipbtn">Agent</span>
                        <span className="fs-chipbtn">Auto</span>
                    </>
                }
            />
        </>
    );
}

const STEP_MS = [8000, 8000, 9000, 9000];
const HERO_MS = 18000;
const LAYOUTS = ['right', 'left', 'middle', 'right'] as const;
/** Index 0 is the hero stage; 1 to 4 are the Ask, Share, Find and Use sections. */
const DURATIONS = [HERO_MS, ...STEP_MS];
const HERO_ART = {
    burst: '/images/landing/hero-burst.jpg',
    clay: '/images/landing/hero-clay.jpg',
    ox: '/images/landing/hero-ox.jpg',
    sage: '/images/landing/hero-sage.jpg',
};

export const STORY_ALT =
    'Animated example in four steps. A marketer asks Claude for a pricing report and shares it with the team. It is saved and searchable by every AI tool. A product manager, in a new chat in a different tool, finds it and uses it, with the source.';

export type Story = {
    reduced: boolean;
    progress: number[];
    register: (index: number) => (el: HTMLElement | null) => void;
    replay: () => void;
};

/**
 * Time-driven playback for the hero stage and the four sections. The hero
 * plays while mostly on screen. A section plays once it has arrived and is not
 * yet covered by the next one, and resets when it is scrolled away.
 */
export function useStory(): Story {
    const reduced = useMedia(REDUCED_QUERY);
    const els = useRef<Array<HTMLElement | null>>([]);
    const playing = useRef<boolean[]>(DURATIONS.map(() => false));
    const startedAt = useRef<number[]>(DURATIONS.map(() => 0));
    const progRef = useRef<number[]>(DURATIONS.map(() => 0));
    const [progress, setProgress] = useState<number[]>(() =>
        DURATIONS.map(() => 0),
    );

    const start = (i: number) => {
        playing.current[i] = true;
        startedAt.current[i] = performance.now();
    };

    useEffect(() => {
        if (reduced) {
            return;
        }

        let raf = 0;
        const tick = (now: number) => {
            const vh = window.innerHeight;
            const rects = els.current.map((el) => el?.getBoundingClientRect());
            const resets: number[] = [];

            rects.forEach((rect, i) => {
                if (!rect) {
                    return;
                }

                let on: boolean;
                let off: boolean;

                if (i === 0) {
                    const visible =
                        clamp(
                            Math.min(rect.bottom, vh) - Math.max(rect.top, 0),
                            0,
                            rect.height,
                        ) / Math.max(rect.height, 1);
                    on = visible >= 0.55;
                    off = visible < 0.15;
                } else {
                    const nextTop = rects[i + 1]?.top ?? vh * 2;
                    const covered = clamp(1 - nextTop / vh, 0, 1);
                    on = rect.top <= vh * 0.45 && covered < 0.55;
                    off = rect.top > vh * 0.8 || covered > 0.75;
                }

                if (on && !playing.current[i]) {
                    start(i);
                }

                if (off && playing.current[i]) {
                    playing.current[i] = false;
                    startedAt.current[i] = 0;
                    resets.push(i);
                }
            });

            const next = progRef.current.map((v, i) => {
                if (resets.includes(i)) {
                    return 0;
                }

                if (!playing.current[i]) {
                    return v;
                }

                return clamp((now - startedAt.current[i]) / DURATIONS[i], 0, 1);
            });

            if (next.some((v, i) => v !== progRef.current[i])) {
                progRef.current = next;
                setProgress(next);
            }

            raf = requestAnimationFrame(tick);
        };
        raf = requestAnimationFrame(tick);

        return () => cancelAnimationFrame(raf);
    }, [reduced]);

    const register = (index: number) => (el: HTMLElement | null) => {
        els.current[index] = el;
    };

    return { reduced, progress, register, replay: () => start(0) };
}

const view = (i: number, p: number) => {
    if (i === 0) {
        return <ClaudeWindow step="ask" p={p} />;
    }

    if (i === 1) {
        return <ClaudeWindow step="share" p={p} />;
    }

    if (i === 2) {
        return <LibraryWindow p={p} />;
    }

    return <CursorWindow p={p} />;
};

/** The hero demo: the whole story in one window over textured paper. */
export function StoryStage({ story }: { story: Story }) {
    const p = story.reduced ? 1 : story.progress[0];
    const chapter = Math.min(3, Math.floor(p * 4));
    const local = story.reduced ? 1 : p * 4 - chapter;

    return (
        <div
            className="story-stage"
            ref={story.register(0)}
            role="img"
            aria-label={STORY_ALT}
            data-testid="landing-flow"
        >
            <img className="a-sage" src={HERO_ART.sage} alt="" />
            <img className="a-burst" src={HERO_ART.burst} alt="" />
            <img className="a-clay" src={HERO_ART.clay} alt="" />
            <img className="a-ox" src={HERO_ART.ox} alt="" />
            <div className="story-win">
                <div className="fs-win">
                    <div
                        key={chapter}
                        className={`fs-view${chapter > 0 ? 'fs-swap' : ''}`}
                    >
                        {view(chapter, local)}
                    </div>
                </div>
            </div>
            <div className="story-prog">
                {STEPS.map((s, i) => (
                    <span key={s.key} className={i === chapter ? 'on' : ''}>
                        {s.label}
                    </span>
                ))}
                <button
                    type="button"
                    className="story-replay"
                    onClick={story.replay}
                    data-testid="landing-flow-replay"
                >
                    Replay
                </button>
            </div>
        </div>
    );
}

/** Ask, Share, Find and Use: one pinned section each, stacked as you scroll. */
export function StorySteps({ story }: { story: Story }) {
    return (
        <div className="story-steps" id="how">
            {STEPS.map((s, i) => {
                const p = story.reduced ? 1 : story.progress[i + 1];
                const layout = LAYOUTS[i];
                const cls =
                    layout === 'left'
                        ? ' left'
                        : layout === 'middle'
                          ? ' mid'
                          : '';

                return (
                    <Fragment key={s.key}>
                        <section
                            className="fs-sec"
                            ref={story.register(i + 1)}
                            data-testid={`landing-step-${s.key}`}
                        >
                            <div className={`fs-in${cls}`}>
                                <div className="fs-text">
                                    <h2>{s.label}</h2>
                                    <p>{caption[i]}</p>
                                </div>
                                <div
                                    className="fs-frame"
                                    style={{
                                        backgroundImage: `url(${s.image})`,
                                    }}
                                >
                                    <div className="fs-win">
                                        <div className="fs-view">
                                            {view(i, p)}
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </section>
                        <div className="fs-spacer" />
                    </Fragment>
                );
            })}
        </div>
    );
}
