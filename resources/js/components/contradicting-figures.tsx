import React, { useCallback, useState, useSyncExternalStore } from 'react';

const MOTION_QUERY = '(prefers-reduced-motion: reduce)';

function motionSnapshot(): boolean {
    if (typeof window === 'undefined') {
        return false;
    }

    return window.matchMedia(MOTION_QUERY).matches;
}

function serverMotionSnapshot(): boolean {
    return false;
}

function useFigureMotion() {
    const subscribe = useCallback((notify: () => void) => {
        if (typeof window === 'undefined') {
            return () => {};
        }

        const media = window.matchMedia(MOTION_QUERY);

        media.addEventListener('change', notify);

        return () => media.removeEventListener('change', notify);
    }, []);

    const reduced = useSyncExternalStore(
        subscribe,
        motionSnapshot,
        serverMotionSnapshot,
    );

    const [isPaused, setIsPaused] = useState(false);

    const togglePause = useCallback(() => {
        setIsPaused((prev) => !prev);
    }, []);

    return {
        reduced,
        isPaused: reduced || isPaused,
        togglePause,
        pausedByControl: isPaused,
    };
}

export function BreakFigure() {
    const { reduced, isPaused, togglePause, pausedByControl } =
        useFigureMotion();

    return (
        <figure
            aria-labelledby="fig1-cap"
            className="my-9 rounded-xl border border-border bg-paper p-5 text-[15px] leading-relaxed text-foreground shadow-sm sm:p-6"
        >
            <p className="mb-4 text-xs font-semibold tracking-wider text-muted-foreground uppercase">
                The problem · decisions stay in the tool where they were made
            </p>
            <div className="grid grid-cols-1 items-center gap-3 sm:grid-cols-[1fr_120px_1fr]">
                {/* Claude Desktop */}
                <div className="rounded-lg border border-border bg-muted/40 p-3.5">
                    <div className="mb-2.5 flex items-center gap-2 text-sm font-bold">
                        <span className="flex h-6 w-6 items-center justify-center rounded bg-muted/80">
                            <svg
                                viewBox="0 0 16 16"
                                className="h-3.5 w-3.5 stroke-foreground"
                                fill="none"
                                strokeWidth="1.6"
                            >
                                <path d="M3 3h10v8H7l-3 2v-2H3z" />
                            </svg>
                        </span>
                        Claude Desktop
                    </div>
                    <p className="text-xs text-muted-foreground">
                        Morning research session
                    </p>
                    <div className="mt-2 border-l-2 border-primary py-1.5 pl-2.5 text-xs">
                        <s className="text-muted-foreground/80">
                            &ldquo;Shared AI brain&rdquo;
                        </s>
                        <br />
                        <b className="font-bold text-primary">Decision:</b> drop
                        it. Lead with sources and clear access.
                    </div>
                </div>

                {/* Gap */}
                <div
                    className="relative flex h-14 w-full items-center justify-center sm:h-16"
                    aria-hidden="true"
                >
                    <span className="absolute top-1/2 left-0 w-[55%] border-t-2 border-dashed border-border" />
                    <span className="absolute top-[calc(50%-9px)] left-[55%] h-[18px] w-0.5 bg-muted-foreground/60" />
                    {!reduced && (
                        <span
                            className="figure-fizzle-dot absolute top-[calc(50%-5px)] left-0 h-2.5 w-2.5 rounded-sm bg-primary"
                            style={{
                                animationPlayState: isPaused
                                    ? 'paused'
                                    : 'running',
                            }}
                        />
                    )}
                    <span className="absolute top-[calc(50%+14px)] right-0 left-0 text-center text-[11px] text-muted-foreground/80">
                        no shared memory
                    </span>
                </div>

                {/* Cursor */}
                <div className="rounded-lg border border-border bg-muted/40 p-3.5">
                    <div className="mb-2.5 flex items-center gap-2 text-sm font-bold">
                        <span className="flex h-6 w-6 items-center justify-center rounded bg-muted/80">
                            <svg
                                viewBox="0 0 16 16"
                                className="h-3.5 w-3.5 stroke-foreground"
                                fill="none"
                                strokeWidth="1.6"
                            >
                                <path d="M5 4 2 8l3 4M11 4l3 4-3 4" />
                            </svg>
                        </span>
                        Cursor
                    </div>
                    <p className="text-xs text-muted-foreground">
                        Same afternoon, building the site
                    </p>
                    <div className="mt-2 rounded border border-border bg-paper p-2 text-xs">
                        <span className="inline-block rounded bg-muted/80 px-1.5 py-0.5 text-[11px] font-semibold">
                            The shared AI brain for modern teams
                        </span>
                    </div>
                    <div
                        className="figure-flag mt-2.5 flex items-center gap-2 rounded bg-primary/10 px-2 py-1.5 text-xs font-semibold text-primary"
                        style={{
                            animationPlayState: isPaused ? 'paused' : 'running',
                        }}
                    >
                        <span className="h-2 w-2 shrink-0 rounded-xs bg-primary" />
                        The phrase we dropped is back
                    </div>
                </div>
            </div>

            <figcaption
                id="fig1-cap"
                className="mt-5 flex flex-wrap items-center justify-between gap-3 border-t border-border pt-3 text-xs text-muted-foreground"
            >
                <span>
                    The decision never leaves the first tool, so the second one
                    writes from old material.
                </span>
                {!reduced && (
                    <button
                        type="button"
                        onClick={togglePause}
                        className="cursor-pointer rounded border border-border bg-muted/30 px-3 py-1 font-semibold text-foreground transition hover:border-muted-foreground"
                    >
                        {pausedByControl ? 'Play' : 'Pause'}
                    </button>
                )}
            </figcaption>
        </figure>
    );
}

export function LoopFigure() {
    const { reduced, isPaused, togglePause, pausedByControl } =
        useFigureMotion();

    return (
        <figure
            aria-labelledby="fig2-cap"
            className="my-9 rounded-xl border border-border bg-paper p-5 text-[15px] leading-relaxed text-foreground shadow-sm sm:p-6"
        >
            <p className="mb-4 text-xs font-semibold tracking-wider text-muted-foreground uppercase">
                The fix · one library every AI tool can read
            </p>
            <div className="grid grid-cols-1 items-stretch gap-3 lg:grid-cols-[1fr_36px_1.3fr_36px_1fr]">
                {/* Claude Desktop */}
                <div className="flex flex-col justify-between rounded-lg border border-border bg-muted/40 p-3.5">
                    <div>
                        <div className="mb-2.5 flex items-center gap-2 text-sm font-bold">
                            <span className="flex h-6 w-6 items-center justify-center rounded bg-muted/80">
                                <svg
                                    viewBox="0 0 16 16"
                                    className="h-3.5 w-3.5 stroke-foreground"
                                    fill="none"
                                    strokeWidth="1.6"
                                >
                                    <path d="M3 3h10v8H7l-3 2v-2H3z" />
                                </svg>
                            </span>
                            Claude Desktop
                        </div>
                        <p className="text-xs text-muted-foreground">
                            Positioning brief, reviewed and ready.
                        </p>
                    </div>
                    <div className="mt-3">
                        <span
                            className="figure-share-btn inline-flex items-center gap-1.5 rounded bg-primary px-2.5 py-1.5 text-xs font-semibold text-white"
                            style={{
                                animationPlayState: isPaused
                                    ? 'paused'
                                    : 'running',
                            }}
                        >
                            Share with team
                        </span>
                    </div>
                </div>

                {/* Lane In */}
                <div
                    className="relative flex h-8 items-center justify-center lg:h-auto"
                    aria-hidden="true"
                >
                    <span className="absolute top-1/2 right-0 left-0 border-t border-border" />
                    {!reduced && (
                        <span
                            className="figure-travel-in-dot absolute top-[calc(50%-4px)] left-0 h-2 w-2 rounded-xs bg-primary"
                            style={{
                                animationPlayState: isPaused
                                    ? 'paused'
                                    : 'running',
                            }}
                        />
                    )}
                </div>

                {/* Team library */}
                <div className="rounded-lg border border-border bg-paper p-3 shadow-2xs">
                    <div className="mb-2 flex items-baseline justify-between text-xs text-muted-foreground">
                        <b className="font-serif text-base font-medium text-foreground">
                            Team library
                        </b>
                        <span>Messaging · pinned</span>
                    </div>
                    <div className="space-y-1.5">
                        <div
                            className="figure-row-matched flex items-center gap-2 rounded bg-muted/40 p-2 text-xs"
                            style={{
                                animationPlayState: isPaused
                                    ? 'paused'
                                    : 'running',
                            }}
                        >
                            <span className="flex h-6 w-6 shrink-0 items-center justify-center rounded bg-muted/80">
                                <svg
                                    viewBox="0 0 16 16"
                                    className="h-3.5 w-3.5 stroke-foreground"
                                    fill="none"
                                    strokeWidth="1.6"
                                >
                                    <path d="M4 2h6l2 2v10H4z" />
                                </svg>
                            </span>
                            <div className="min-w-0 flex-1 leading-tight">
                                <span className="block truncate font-semibold">
                                    Q4 2026 positioning: what we say instead
                                </span>
                                <span className="text-[11px] text-muted-foreground">
                                    Shared from Claude Desktop · Messaging
                                </span>
                            </div>
                            <span className="rounded bg-[var(--sol-green)]/15 px-1.5 py-0.5 text-[11px] font-semibold text-[var(--sol-green)]">
                                Shared
                            </span>
                        </div>
                        <div className="flex items-center gap-2 rounded bg-muted/40 p-2 text-xs">
                            <span className="flex h-6 w-6 shrink-0 items-center justify-center rounded bg-muted/80">
                                <svg
                                    viewBox="0 0 16 16"
                                    className="h-3.5 w-3.5 stroke-foreground"
                                    fill="none"
                                    strokeWidth="1.6"
                                >
                                    <path d="M3 12V4M7 12V7M11 12V5" />
                                </svg>
                            </span>
                            <div className="min-w-0 flex-1 leading-tight">
                                <span className="block truncate font-semibold">
                                    User conversation notes, September
                                </span>
                                <span className="text-[11px] text-muted-foreground">
                                    Research
                                </span>
                            </div>
                            <span className="rounded bg-muted/80 px-1.5 py-0.5 text-[11px] font-semibold">
                                Report
                            </span>
                        </div>
                        <div className="flex items-center gap-2 rounded bg-muted/40 p-2 text-xs">
                            <span className="flex h-6 w-6 shrink-0 items-center justify-center rounded bg-muted/80">
                                <svg
                                    viewBox="0 0 16 16"
                                    className="h-3.5 w-3.5 stroke-foreground"
                                    fill="none"
                                    strokeWidth="1.6"
                                >
                                    <path d="M2 4h12M2 8h12M2 12h12" />
                                </svg>
                            </span>
                            <div className="min-w-0 flex-1 leading-tight">
                                <span className="block truncate font-semibold">
                                    Competitor comparison table
                                </span>
                                <span className="text-[11px] text-muted-foreground">
                                    Research
                                </span>
                            </div>
                            <span className="rounded bg-muted/80 px-1.5 py-0.5 text-[11px] font-semibold">
                                Table
                            </span>
                        </div>
                    </div>
                </div>

                {/* Lane Out */}
                <div
                    className="relative flex h-8 items-center justify-center lg:h-auto"
                    aria-hidden="true"
                >
                    <span className="absolute top-1/2 right-0 left-0 border-t border-border" />
                    {!reduced && (
                        <span
                            className="figure-travel-out-dot absolute top-[calc(50%-4px)] right-0 h-2 w-2 rounded-xs bg-primary"
                            style={{
                                animationPlayState: isPaused
                                    ? 'paused'
                                    : 'running',
                            }}
                        />
                    )}
                </div>

                {/* Cursor */}
                <div className="flex flex-col justify-between rounded-lg border border-border bg-muted/40 p-3.5">
                    <div>
                        <div className="mb-2 flex items-center gap-2 text-sm font-bold">
                            <span className="flex h-6 w-6 items-center justify-center rounded bg-muted/80">
                                <svg
                                    viewBox="0 0 16 16"
                                    className="h-3.5 w-3.5 stroke-foreground"
                                    fill="none"
                                    strokeWidth="1.6"
                                >
                                    <path d="M5 4 2 8l3 4M11 4l3 4-3 4" />
                                </svg>
                            </span>
                            Cursor
                        </div>
                        <div
                            className="figure-search flex items-center gap-1.5 rounded-lg border border-border bg-paper px-2 py-1.5 text-xs"
                            style={{
                                animationPlayState: isPaused
                                    ? 'paused'
                                    : 'running',
                            }}
                        >
                            <svg
                                viewBox="0 0 16 16"
                                className="h-3.5 w-3.5 shrink-0 stroke-muted-foreground"
                                fill="none"
                                strokeWidth="1.8"
                            >
                                <circle cx="7" cy="7" r="4.5" />
                                <path d="m10.5 10.5 3 3" />
                            </svg>
                            <span className="text-foreground">
                                feature comparison messaging
                                <span className="figure-caret inline-block h-3 w-0.5 bg-primary align-middle" />
                            </span>
                        </div>
                    </div>
                    <div className="mt-2.5 text-xs leading-relaxed">
                        <p className="mb-1 text-muted-foreground">
                            Avoid &ldquo;shared AI brain&rdquo;. Lead with
                            sources and clear access.
                        </p>
                        <span
                            className="figure-source-chip inline-flex items-center gap-1 rounded bg-muted/80 px-1.5 py-0.5 text-[11px] font-semibold text-foreground"
                            style={{
                                animationPlayState: isPaused
                                    ? 'paused'
                                    : 'running',
                            }}
                        >
                            Source: Q4 2026 positioning
                        </span>
                    </div>
                </div>
            </div>

            {/* Steps */}
            <div className="mt-4.5 grid grid-cols-2 gap-3 border-t border-border pt-3 text-xs sm:grid-cols-4">
                <div>
                    <b className="block text-[11px] font-bold tracking-wider text-primary uppercase">
                        Share
                    </b>
                    <span className="text-muted-foreground">
                        You choose what goes to the team.
                    </span>
                </div>
                <div>
                    <b className="block text-[11px] font-bold tracking-wider text-primary uppercase">
                        Index
                    </b>
                    <span className="text-muted-foreground">
                        The library reads it in the background.
                    </span>
                </div>
                <div>
                    <b className="block text-[11px] font-bold tracking-wider text-primary uppercase">
                        Search
                    </b>
                    <span className="text-muted-foreground">
                        Any connected tool can ask for it.
                    </span>
                </div>
                <div>
                    <b className="block text-[11px] font-bold tracking-wider text-primary uppercase">
                        Cite
                    </b>
                    <span className="text-muted-foreground">
                        The answer links back to the source.
                    </span>
                </div>
            </div>

            <figcaption
                id="fig2-cap"
                className="mt-5 flex flex-wrap items-center justify-between gap-3 border-t border-border pt-3 text-xs text-muted-foreground"
            >
                <span>
                    Illustration only. Indexing takes a little time after
                    sharing; the animation is faster than real life.
                </span>
                {!reduced && (
                    <button
                        type="button"
                        onClick={togglePause}
                        className="cursor-pointer rounded border border-border bg-muted/30 px-3 py-1 font-semibold text-foreground transition hover:border-muted-foreground"
                    >
                        {pausedByControl ? 'Play' : 'Pause'}
                    </button>
                )}
            </figcaption>
        </figure>
    );
}
