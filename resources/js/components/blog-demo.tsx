import React, {
    useCallback,
    useEffect,
    useRef,
    useState,
    useSyncExternalStore,
} from 'react';

// ── shared playback hook ───────────────────────────────────────────────────────

const MOTION_QUERY = '(prefers-reduced-motion: reduce)';

function motionSnapshot(): boolean {
    return window.matchMedia(MOTION_QUERY).matches;
}

function serverMotionSnapshot(): boolean {
    return false;
}

/**
 * Steps a demo forward on an interval while playing. Content is always in the
 * DOM; `step` only drives opacity/transform emphasis, so the initial render is
 * fully readable without JavaScript.
 */
function usePlayback(stepCount: number, intervalMs: number) {
    const [playback, setPlayback] = useState({
        step: 0,
        playing: false,
        started: false,
    });
    const timerRef = useRef<number | null>(null);

    const clear = useCallback(() => {
        if (timerRef.current !== null) {
            window.clearTimeout(timerRef.current);
            timerRef.current = null;
        }
    }, []);

    const subscribe = useCallback(
        (notify: () => void) => {
            const media = window.matchMedia(MOTION_QUERY);
            const onChange = () => {
                clear();
                setPlayback({ step: 0, playing: false, started: false });
                notify();
            };
            media.addEventListener('change', onChange);

            return () => media.removeEventListener('change', onChange);
        },
        [clear],
    );
    const reduced = useSyncExternalStore(
        subscribe,
        motionSnapshot,
        serverMotionSnapshot,
    );
    const step = reduced ? stepCount : playback.step;
    const complete = step >= stepCount;
    const playing = playback.playing && !reduced && !complete;

    useEffect(() => {
        if (!playing || reduced) {
            return;
        }

        timerRef.current = window.setTimeout(() => {
            setPlayback((current) => {
                const nextStep = Math.min(stepCount, current.step + 1);

                return {
                    ...current,
                    step: nextStep,
                    playing: nextStep < stepCount,
                };
            });
        }, intervalMs);

        return clear;
    }, [playing, reduced, stepCount, intervalMs, playback, clear]);
    useEffect(() => clear, [clear]);

    const play = useCallback(() => {
        if (reduced) {
            return;
        }

        clear();
        setPlayback((current) => ({
            ...current,
            started: true,
            playing: true,
        }));
    }, [reduced, clear]);

    const replay = useCallback(() => {
        if (reduced) {
            return;
        }

        clear();
        setPlayback({ step: 0, started: true, playing: true });
    }, [reduced, clear]);

    const pause = useCallback(() => {
        clear();
        setPlayback((current) => ({ ...current, playing: false }));
    }, [clear]);

    return {
        step,
        playing,
        complete,
        controls: { play, pause, replay },
        state: { reduced, started: playback.started && !reduced },
    };
}

// ── shared chrome ──────────────────────────────────────────────────────────────

const CAPTION = 'Illustrative demo · playback speed is not indexing latency';

function DemoShell({
    kind,
    step,
    playback,
    label,
    children,
}: {
    kind: 'team' | 'handoff' | 'search';
    step: number;
    playback: ReturnType<typeof usePlayback>;
    label: string;
    children: React.ReactNode;
}) {
    const {
        playing,
        complete,
        controls: { play, pause, replay },
        state: { reduced },
    } = playback;

    return (
        <section
            data-testid="blog-demo"
            data-kind={kind}
            data-step={step}
            aria-label={label}
            className="my-8 rounded-xl border border-border bg-paper p-4 text-[15px] leading-relaxed text-foreground sm:p-7"
        >
            {children}
            <p className="mt-3 text-xs text-muted-foreground">{CAPTION}</p>
            {reduced ? null : (
                <div className="mt-4 flex flex-wrap gap-2">
                    <button
                        type="button"
                        onClick={play}
                        disabled={playing || complete}
                        className="min-h-11 rounded-md border border-foreground bg-foreground px-4 text-sm font-semibold text-paper transition-opacity disabled:opacity-45"
                    >
                        Play
                    </button>
                    <button
                        type="button"
                        onClick={pause}
                        disabled={!playing}
                        className="bg-highlight min-h-11 rounded-md border border-muted-foreground px-4 text-sm font-semibold text-foreground transition-opacity disabled:opacity-45"
                    >
                        Pause
                    </button>
                    <button
                        type="button"
                        onClick={replay}
                        className="bg-highlight min-h-11 rounded-md border border-muted-foreground px-4 text-sm font-semibold text-foreground transition-opacity disabled:opacity-45"
                    >
                        Replay
                    </button>
                </div>
            )}
            {reduced ? (
                <p className="mt-4 text-sm text-muted-foreground">
                    Reduced motion: the completed workflow is shown without
                    animation.
                </p>
            ) : null}
        </section>
    );
}

/**
 * Content that dims before its step plays back; always readable, never hidden.
 * Only opacity and transform animate, and only when motion is allowed.
 */
function StepItem({
    number,
    step,
    started,
    as: Tag = 'div',
    className = '',
    children,
}: {
    number: number;
    step: number;
    started: boolean;
    as?: 'div' | 'li';
    className?: string;
    children: React.ReactNode;
}) {
    const startedButPending = started && step < number;

    return (
        <Tag
            data-step={number}
            className={`transition-[opacity,transform] duration-250 ease-out motion-reduce:transition-none ${
                startedButPending ? 'opacity-35' : 'opacity-100'
            } ${className}`}
        >
            {children}
        </Tag>
    );
}

// ── team demo ──────────────────────────────────────────────────────────────────

const TEAM_STEPS = 5;
const TEAM_INTERVAL = 1800;

const TEAM_SUMMARIES = [
    'Ready. Play the handoff from investigation to a better proposal.',
    'Step 1 of 5. Claude Code identifies why a timed-out webhook can produce a duplicate.',
    'Step 2 of 5. Claude Code publishes the investigation as an HTML artifact to the team library.',
    'Step 3 of 5. Indexing completes. This playback delay does not represent real indexing latency.',
    'Step 4 of 5. Codex explicitly searches the team library and receives a relevant snippet and source link.',
    'Step 5 of 5. Codex uses the retrieved finding to propose deduplication before applying the operation.',
];

const TEAM_STEPS_LIST = [
    'Investigate',
    'Publish HTML',
    'Index artifact',
    'Search + retrieve',
    'Guide proposal',
];

function TeamDemo() {
    const { step, playing, complete, controls, state } = usePlayback(
        TEAM_STEPS,
        TEAM_INTERVAL,
    );

    // Derived on render; no state or effect needed.
    let summary = TEAM_SUMMARIES[0];

    if (state.started) {
        summary = TEAM_SUMMARIES[Math.min(step, TEAM_STEPS)];

        if (step > 0 && step < TEAM_STEPS && !playing) {
            summary += ' Playback paused.';
        }
    }

    return (
        <DemoShell
            kind="team"
            step={step}
            playback={{ step, playing, complete, controls, state }}
            label="Claude Code publishes to a team library; Codex can search it"
        >
            <h3 className="text-xl font-semibold tracking-tight">
                A finding crosses the tool boundary
            </h3>
            <div className="mt-4 grid grid-cols-1 items-stretch gap-3 sm:grid-cols-[1fr_auto_1fr] sm:gap-2">
                <FlowNode
                    title="Claude Code"
                    detail="Investigates duplicate billing webhooks."
                    badge="Agent A · publishes"
                />
                <span
                    aria-hidden="true"
                    className="hidden items-center justify-center font-mono text-xl text-muted-foreground sm:flex"
                >
                    →
                </span>
                <FlowNode
                    title="Team library"
                    detail="Published HTML artifact. Searchable after indexing completes."
                    badge="Same authenticated team"
                />
                <span
                    aria-hidden="true"
                    className="hidden items-center justify-center font-mono text-xl text-muted-foreground sm:flex"
                >
                    →
                </span>
                <FlowNode
                    title="Codex"
                    detail="Connected agent running the search in this example."
                    badge="Agent B · retrieves"
                />
            </div>

            <ol
                aria-label="Knowledge handoff steps"
                className="mt-5 grid grid-cols-1 gap-0 sm:grid-cols-5 sm:gap-2"
            >
                {TEAM_STEPS_LIST.map((label, index) => (
                    <StepItem
                        key={label}
                        as="li"
                        number={index + 1}
                        step={step}
                        started={state.started}
                        className="border-l-2 border-border px-3 py-1.5 text-xs leading-snug sm:border-t-2 sm:border-l-0 sm:px-2 sm:pt-2"
                    >
                        <span className="mb-1 block font-mono text-[11px] text-muted-foreground">
                            {`0${index + 1}`}
                        </span>
                        {label}
                    </StepItem>
                ))}
            </ol>

            <div
                aria-label="Illustrative terminal transcript"
                className="mt-5 rounded-lg bg-[color:var(--ink-muted)] p-4 font-mono text-xs leading-relaxed text-paper"
            >
                <StepItem
                    number={1}
                    step={step}
                    started={state.started}
                    className="mb-2"
                >
                    <span className="block font-semibold text-[color:var(--ink-quiet)]">
                        Claude Code / finding
                    </span>
                    A timeout can follow a successful delivery. Repeating the
                    operation risks a duplicate.
                </StepItem>
                <StepItem
                    number={2}
                    step={step}
                    started={state.started}
                    className="mb-2"
                >
                    <span className="block font-semibold text-[color:var(--ink-quiet)]">
                        Claude Code / deploy_artifact
                    </span>
                    Published HTML: “Billing webhook duplicates after timeouts.”
                </StepItem>
                <StepItem
                    number={3}
                    step={step}
                    started={state.started}
                    className="mb-2"
                >
                    <span className="block font-semibold text-[color:var(--ink-quiet)]">
                        Team library / indexing
                    </span>
                    Indexing complete. The finding can now appear in team
                    search.
                </StepItem>
                <StepItem
                    number={4}
                    step={step}
                    started={state.started}
                    className="mb-2"
                >
                    <span className="block font-semibold text-[color:var(--ink-quiet)]">
                        Codex / search_artifacts
                    </span>
                    Query: “billing webhook timeout duplicate delivery”
                    <br />
                    Result snippet: “Deduplicate by provider event ID before
                    applying the billing operation.”
                    <br />
                    Source: published investigation · viewing link returned
                </StepItem>
                <StepItem number={5} step={step} started={state.started}>
                    <span className="block font-semibold text-[color:var(--ink-quiet)]">
                        Codex / revised proposal
                    </span>
                    Deduplicate first, then apply the operation. Use the
                    investigation as a source; check its full details before
                    implementing.
                </StepItem>
            </div>

            <p
                aria-live="polite"
                aria-atomic="true"
                className="mt-3 min-h-[2.5em] text-sm text-muted-foreground"
            >
                {summary}
            </p>
            {state.reduced ? (
                <p className="mt-1 text-sm text-muted-foreground">
                    Reduced motion is enabled. The completed diagram and
                    transcript are shown; animated playback is disabled.
                </p>
            ) : null}
            <p className="mt-2 text-xs text-muted-foreground">
                Search is an explicit request, not a push notification. This
                example assumes indexing is configured and connected agents have
                access to the same team.
            </p>
        </DemoShell>
    );
}

function FlowNode({
    title,
    detail,
    badge,
}: {
    title: string;
    detail: string;
    badge: string;
}) {
    return (
        <div className="bg-highlight min-w-0 rounded-lg border border-border px-3 py-3">
            <b className="block text-sm">{title}</b>
            <small className="block text-xs leading-relaxed text-muted-foreground">
                {detail}
            </small>
            <span className="mt-2 inline-block rounded border border-border px-1.5 py-0.5 font-mono text-[11px]">
                {badge}
            </span>
        </div>
    );
}

// ── handoff demo ───────────────────────────────────────────────────────────────

const HANDOFF_STEPS = 7;
const HANDOFF_INTERVAL = 1500;

const HANDOFF_ROWS: Array<{
    speaker: string;
    tone: 'agent' | 'result' | 'human';
    text: string;
    phase: string;
}> = [
    {
        speaker: 'Claude Code · MCP TOOL',
        tone: 'agent',
        text: 'deploy_artifact({title: "Webhook retry findings", description: "Reviewed retry and acknowledgement investigation", tier: "secure", html: "[reviewed HTML report]"})',
        phase: 'Publishing reviewed findings',
    },
    {
        speaker: 'artfct · RESULT',
        tone: 'result',
        text: 'Published to the workspace. view_url: [openable report link]',
        phase: 'Artifact published',
    },
    {
        speaker: 'artfct · INDEX STATUS',
        tone: 'result',
        text: 'Indexing complete in this example. Completion happens asynchronously; no duration is implied.',
        phase: 'Indexing complete in this example',
    },
    {
        speaker: 'Codex · MCP TOOL',
        tone: 'agent',
        text: 'search_artifacts({query: "duplicate webhook deliveries, retries and acknowledgements"}) — authenticated to the same team workspace.',
        phase: 'Codex searches the same workspace',
    },
    {
        speaker: 'artfct · SEARCH RESULT',
        tone: 'result',
        text: 'Title: Webhook retry findings. Snippet: “Check deduplication before changing retry behavior.” view_url: [openable report link]. Search returns a snippet and link, not the full report.',
        phase: 'Search returns a snippet and link',
    },
    {
        speaker: 'Person · REVIEW',
        tone: 'human',
        text: 'Opens the report, verifies the relevant findings, and supplies those details to Codex.',
        phase: 'A person reviews the source',
    },
    {
        speaker: 'Codex · NEXT ACTION',
        tone: 'agent',
        text: 'Use the reviewed finding to inspect deduplication in the current checkout before proposing a retry patch. Check the affected tests.',
        phase: 'Codex checks the current implementation',
    },
];

const HANDOFF_IDLE =
    'Full illustrative transcript. Publishing and searching are explicit actions.';

const TONE_CLASS: Record<'agent' | 'result' | 'human', string> = {
    agent: 'text-primary',
    result: 'text-muted-foreground',
    human: 'text-[color:var(--alert)]',
};

function HandoffDemo() {
    const { step, playing, complete, controls, state } = usePlayback(
        HANDOFF_STEPS,
        HANDOFF_INTERVAL,
    );

    let phase = HANDOFF_IDLE;

    if (state.started) {
        if (step >= HANDOFF_STEPS) {
            phase = 'Complete · all steps remain available to read.';
        } else if (!playing) {
            phase = `Paused · ${step} of ${HANDOFF_STEPS} steps shown.`;
        } else {
            phase = `Step ${step} of ${HANDOFF_STEPS} · ${
                HANDOFF_ROWS[step - 1]?.phase ?? ''
            }`;
        }
    }

    return (
        <DemoShell
            kind="handoff"
            step={step}
            playback={{ step, playing, complete, controls, state }}
            label="Claude Code publishes to a workspace; Codex searches the same workspace"
        >
            <h3 className="text-xl font-semibold tracking-tight">
                One investigation. Two coding agents.
            </h3>
            <p className="mt-4 flex flex-wrap items-center gap-2 font-mono text-xs">
                <span className="bg-highlight rounded px-2 py-1">
                    Claude Code
                </span>
                <span
                    aria-hidden="true"
                    className="text-[color:var(--highlight)]"
                >
                    →
                </span>
                <span className="bg-highlight rounded px-2 py-1">
                    Same artfct workspace
                </span>
                <span
                    aria-hidden="true"
                    className="text-[color:var(--highlight)]"
                >
                    →
                </span>
                <span className="bg-highlight rounded px-2 py-1">Codex</span>
            </p>
            <div
                aria-label="Illustrative MCP tool transcript"
                className="bg-highlight mt-4 rounded-lg border border-border p-4"
            >
                <ol className="grid gap-3 font-mono text-xs leading-relaxed">
                    {HANDOFF_ROWS.map((row, index) => (
                        <StepItem
                            key={row.speaker}
                            as="li"
                            number={index + 1}
                            step={step}
                            started={state.started}
                        >
                            <span
                                className={`block font-bold ${TONE_CLASS[row.tone]}`}
                            >
                                {row.speaker}
                            </span>
                            <span className="break-words">{row.text}</span>
                        </StepItem>
                    ))}
                </ol>
            </div>
            <p
                aria-live="polite"
                aria-atomic="true"
                className="mt-3 text-sm text-muted-foreground"
            >
                {phase}
            </p>
        </DemoShell>
    );
}

// ── search demo ────────────────────────────────────────────────────────────────

const SEARCH_STEPS = 5;
const SEARCH_INTERVAL = 1700;

const SEARCH_NODES: Array<{
    kicker: string;
    title: string;
    detail: string;
}> = [
    {
        kicker: '01 / PUBLISH',
        title: 'Billing integration review',
        detail: 'HTML report published. Its link can open before indexing finishes.',
    },
    {
        kicker: '02 / EXTRACT',
        title: 'Read the HTML text',
        detail: 'Extract headings and paragraphs. Render when needed. Image and canvas content has limits.',
    },
    {
        kicker: '03 / INDEX',
        title: 'Prepare searchable passages',
        detail: 'Split text into chunks and create embeddings. Requires configured indexing; queued work takes time.',
    },
    {
        kicker: '04 / ASK',
        title: 'Search by the problem',
        detail: '“Why did webhook retries create duplicate charges?” Meaning and full-text candidates are combined and reranked within the team workspace.',
    },
];

const SEARCH_MESSAGES = [
    'Published: the report link is available. Searchable text may still be pending.',
    'Extraction: readable HTML text becomes the material for the index.',
    'Indexing: chunks and embeddings prepare the report for search.',
    'Query: describe the billing problem in your own words.',
    'Possible result: inspect the snippet, then open the source report. A match is not guaranteed.',
];

const SEARCH_IDLE =
    'A possible result gives you a passage to inspect and a link to its source. The complete workflow is shown above.';

function SearchDemo() {
    const { step, playing, complete, controls, state } = usePlayback(
        SEARCH_STEPS,
        SEARCH_INTERVAL,
    );

    let message = SEARCH_IDLE;

    if (state.started) {
        message = SEARCH_MESSAGES[Math.min(step, SEARCH_STEPS) - 1];
    }

    return (
        <DemoShell
            kind="search"
            step={step}
            playback={{ step, playing, complete, controls, state }}
            label="From published report to a useful search result"
        >
            <h3 className="text-xl font-semibold tracking-tight">
                From published report to a useful search result
            </h3>
            <ul
                aria-label="Publish, index and search workflow"
                className="mt-4 grid grid-cols-1 gap-6 sm:grid-cols-3"
            >
                {SEARCH_NODES.map((node, index) => (
                    <li
                        key={node.kicker}
                        data-active={step === index ? 'true' : 'false'}
                        className="bg-highlight rounded-lg border border-border p-4 transition-transform duration-250 data-[active=true]:-translate-y-0.5 data-[active=true]:outline-2 data-[active=true]:outline-offset-2 data-[active=true]:outline-primary motion-reduce:transition-none"
                    >
                        <span className="block font-mono text-xs text-[color:var(--alert)]">
                            {node.kicker}
                        </span>
                        <strong className="mt-1.5 block text-sm">
                            {node.title}
                        </strong>
                        <p className="mt-1.5 text-xs leading-relaxed text-muted-foreground">
                            {node.detail}
                        </p>
                    </li>
                ))}
                <li className="rounded-lg border border-[color:var(--highlight)] bg-paper p-4 sm:col-span-2">
                    <span className="block font-mono text-xs text-[color:var(--alert)]">
                        05 / INSPECT
                    </span>
                    <strong className="mt-1.5 block text-sm">
                        Possible match: Billing integration review
                    </strong>
                    <blockquote className="my-3 border-l-2 border-[color:var(--highlight)] pl-3 text-sm">
                        Repeated webhook deliveries can create duplicate charges
                        when an idempotency check is missing.
                    </blockquote>
                    <p className="text-xs text-muted-foreground">
                        Illustrative snippet · billing collection · source link
                        available in a real result. Open the report to check its
                        argument. Search returns a snippet and link, not the
                        full HTML. This match is not guaranteed.
                    </p>
                </li>
            </ul>
            <p
                aria-live="polite"
                aria-atomic="true"
                className="mt-4 border-t border-border pt-3 text-sm text-muted-foreground"
            >
                {message}
            </p>
        </DemoShell>
    );
}

// ── public component ───────────────────────────────────────────────────────────

export type BlogDemoKind = 'team' | 'handoff' | 'search';

export function BlogDemo({ kind }: { kind: BlogDemoKind }) {
    if (kind === 'team') {
        return <TeamDemo />;
    }

    if (kind === 'handoff') {
        return <HandoffDemo />;
    }

    return <SearchDemo />;
}
