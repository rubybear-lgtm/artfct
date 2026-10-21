import { Link } from '@inertiajs/react';
import type { CSSProperties, RefObject } from 'react';
import {
    useEffect,
    useId,
    useLayoutEffect,
    useRef,
    useState,
    useSyncExternalStore,
} from 'react';

import { AiToolIcon } from '@/components/ai-tool-icon';
import { CopyPrompt } from '@/components/connect/copy-prompt';
import {
    AgentWindow,
    AuthorMessages,
    ReaderMessages,
} from '@/components/home/agent-window';
import type {
    AgentSide,
    AuthorContent,
    ReaderContent,
} from '@/components/home/agent-window';
import { StorySteps, StoryStage, useStory } from '@/components/home/story';
import { ThemeSwitch } from '@/components/home/theme-switch';
import { useAccountNav } from '@/components/site-chrome';
import { Button } from '@/components/ui/button';
import { backgroundPair, randomBackgroundPairs } from '@/lib/backgrounds';
import { blog, docs, free, home, login, privacy, terms } from '@/routes';
import blogRoutes from '@/routes/blog';
import connectRoutes from '@/routes/connect';

type UseScene = {
    frame: { light: string; dark: string };
    author: AgentSide;
    authorContent: AuthorContent;
    reader: AgentSide;
    readerContent: ReaderContent;
};

type UseCase = {
    role: string;
    title: string;
    text: string;
    scene: UseScene;
};

/* Each use case is two live agent sessions: someone changes an artifact in
   their agent, and a teammate's agent is already working from the change. */
const USE_CASES: UseCase[] = [
    {
        role: 'Product',
        title: 'Agents work from the newest brief.',
        text: 'A product manager moves the launch date in the brief. An engineer’s agent in the terminal is already planning around the new date. Nobody pastes it or shares it again.',
        scene: {
            frame: backgroundPair('light-05-dunes', 'dark-04-moon-dunes'),
            author: {
                skin: 'claude',
                tool: 'claude',
                role: 'Product manager',
                title: 'Q3 launch',
            },
            authorContent: {
                ask: 'Move the Q3 launch to May and let the team know.',
                answer: 'Done. I changed the launch date in the Q3 product brief and shared it with the Product team.',
                card: {
                    title: 'Q3 product brief',
                    label: 'Launch date',
                    before: 'June',
                    after: 'May',
                    meta: 'Product team · edited just now',
                },
            },
            reader: {
                skin: 'terminal',
                tool: 'chatgpt',
                role: 'Engineer',
                title: 'launch-plan — codex',
                terminalName: 'OpenAI Codex',
                directory: '~/launch-plan',
            },
            readerContent: {
                ask: 'Plan the Q3 launch work for engineering.',
                intro: 'Here is the plan.',
                before: [
                    { text: 'Confirm the scope', done: true },
                    { text: 'Write the announcement', done: true },
                ],
                change: { prefix: 'Ship in', before: 'June', after: 'May' },
                after: ['Brief support'],
                read: 'Read the latest Q3 product brief',
            },
        },
    },
    {
        role: 'Analysis',
        title: 'Every agent sees the latest table.',
        text: 'An analyst updates the renewals table in ChatGPT. A marketer’s agent in Claude is already drafting around the change, with no export and no paste.',
        scene: {
            frame: backgroundPair('light-02-beach', 'dark-05-black-sand'),
            author: {
                skin: 'codex',
                tool: 'chatgpt',
                role: 'Analyst',
                title: 'Renewals',
            },
            authorContent: {
                ask: 'Update the renewals table and share it with the team.',
                answer: 'Done. Annual is now the plan that renews most, and the table is shared with the Analytics team.',
                card: {
                    title: 'Renewals by plan',
                    label: 'Top plan',
                    before: 'Monthly',
                    after: 'Annual',
                    meta: 'Analytics team · edited just now',
                },
            },
            reader: {
                skin: 'claude',
                tool: 'claude',
                role: 'Marketer',
                title: 'Renewals email',
            },
            readerContent: {
                ask: 'Draft the renewals email for customers.',
                intro: 'Here is the outline.',
                before: [{ text: 'Thank customers for renewing', done: true }],
                change: {
                    prefix: 'Lead with the',
                    before: 'Monthly',
                    after: 'Annual',
                    suffix: 'plan',
                },
                after: ['Link the help center'],
                read: 'Read the latest Renewals by plan',
            },
        },
    },
    {
        role: 'Design',
        title: 'Mockups stay current in every tool.',
        text: 'A designer changes the headline on the launch mockup. The engineer’s agent in the terminal builds the page with the new headline.',
        scene: {
            frame: backgroundPair('light-06-lake', 'dark-10-fjord'),
            author: {
                skin: 'claude',
                tool: 'claude',
                role: 'Designer',
                title: 'Launch page',
            },
            authorContent: {
                ask: 'Change the headline on the launch page mockup to “Launch week is here”.',
                answer: 'Done. The headline is updated in the Launch page mockup and shared with the Design team.',
                card: {
                    title: 'Launch page mockup',
                    label: 'Headline',
                    before: 'Coming soon',
                    after: 'Launch week is here',
                    meta: 'Design team · edited just now',
                },
            },
            reader: {
                skin: 'terminal',
                tool: 'cursor',
                role: 'Engineer',
                title: 'launch-page — cursor-agent',
                terminalName: 'Cursor Agent',
                directory: '~/launch-page',
            },
            readerContent: {
                ask: 'Build the launch page from the mockup.',
                intro: 'Here is the build plan.',
                before: [{ text: 'Add the hero section', done: true }],
                change: {
                    prefix: 'Set the headline to',
                    before: 'Coming soon',
                    after: 'Launch week is here',
                },
                after: ['Wire up the sign-up button'],
                read: 'Read the latest Launch page mockup',
            },
        },
    },
    {
        role: 'Marketing',
        title: 'Campaign copy stays in sync.',
        text: 'A marketer approves a new subject line in Claude. A teammate’s agent in Cursor drafts the email with it, not the old one.',
        scene: {
            frame: backgroundPair('light-10-ridges', 'dark-07-valley'),
            author: {
                skin: 'claude',
                tool: 'claude',
                role: 'Campaign lead',
                title: 'Spring campaign',
            },
            authorContent: {
                ask: 'Approve the new subject line for the spring email.',
                answer: 'Done. The subject line is updated in the Spring campaign copy and shared with the Marketing team.',
                card: {
                    title: 'Spring campaign copy',
                    label: 'Subject line',
                    before: 'Spring is here',
                    after: 'Fresh starts, one page at a time',
                    meta: 'Marketing team · edited just now',
                },
            },
            reader: {
                skin: 'cursor',
                tool: 'cursor',
                role: 'Marketer',
                title: 'Spring email',
            },
            readerContent: {
                ask: 'Draft the spring launch email.',
                intro: 'Here is the draft plan.',
                before: [],
                change: {
                    prefix: 'Use the subject line',
                    before: 'Spring is here',
                    after: 'Fresh starts, one page at a time',
                },
                after: ['Add the sign-up link'],
                read: 'Read the latest Spring campaign copy',
            },
        },
    },
    {
        role: 'Operations',
        title: 'Everyone’s checklist is the current one.',
        text: 'An operations lead adds an approval step in ChatGPT. A teammate’s agent in Claude walks the new process, not the old one.',
        scene: {
            frame: backgroundPair('light-01-meadow', 'dark-02-pines'),
            author: {
                skin: 'codex',
                tool: 'chatgpt',
                role: 'Operations lead',
                title: 'Vendor onboarding',
            },
            authorContent: {
                ask: 'Add a manager approval step to the vendor onboarding steps.',
                answer: 'Done. Vendor onboarding steps now include a manager approval, and the Operations team has the update.',
                card: {
                    title: 'Vendor onboarding steps',
                    label: 'Step 3',
                    before: 'Send contract',
                    after: 'Manager approval',
                    meta: 'Operations team · edited just now',
                },
            },
            reader: {
                skin: 'claude',
                tool: 'claude',
                role: 'Procurement lead',
                title: 'New vendor',
            },
            readerContent: {
                ask: 'Walk me through onboarding a new vendor.',
                intro: 'Here are the steps.',
                before: [
                    { text: 'Collect the vendor details', done: true },
                    { text: 'Check the paperwork', done: true },
                ],
                change: {
                    prefix: 'Step 3:',
                    before: 'Send contract',
                    after: 'Manager approval',
                },
                after: ['Set up payment'],
                read: 'Read the latest Vendor onboarding steps',
            },
        },
    },
];

/** Re-measures the two ends of the connector, in the frame's own pixels,
 * whenever layout or webfonts move them. */
function useConnector(
    frame: RefObject<HTMLElement | null>,
    ends: [
        RefObject<HTMLElement | null>,
        RefObject<HTMLElement | null>,
        RefObject<HTMLElement | null>,
        RefObject<HTMLElement | null>,
    ],
): { d: string; a: [number, number]; b: [number, number] } | null {
    const [geometry, setGeometry] = useState<{
        d: string;
        a: [number, number];
        b: [number, number];
    } | null>(null);
    const [fromWin, fromLine, toWin, toLine] = ends;

    useLayoutEffect(() => {
        const root = frame.current;

        if (!root) {
            return;
        }

        const measure = () => {
            const a = fromWin.current;
            const al = fromLine.current;
            const b = toWin.current;
            const bl = toLine.current;

            if (!a || !al || !b || !bl) {
                return;
            }

            const origin = root.getBoundingClientRect();
            const aw = a.getBoundingClientRect();
            const alr = al.getBoundingClientRect();
            const bw = b.getBoundingClientRect();
            const blr = bl.getBoundingClientRect();
            const ax = aw.right - origin.left;
            const ay = alr.top + alr.height / 2 - origin.top;
            const bx = bw.left - origin.left;
            const by = blr.top + blr.height / 2 - origin.top;
            const mid = (ax + bx) / 2;

            setGeometry({
                d: `M ${ax.toFixed(1)} ${ay.toFixed(1)} C ${mid.toFixed(1)} ${ay.toFixed(1)}, ${mid.toFixed(1)} ${by.toFixed(1)}, ${bx.toFixed(1)} ${by.toFixed(1)}`,
                a: [ax, ay],
                b: [bx, by],
            });
        };

        measure();

        const observer =
            typeof ResizeObserver === 'undefined'
                ? null
                : new ResizeObserver(measure);

        observer?.observe(root);
        [fromWin, fromLine, toWin, toLine].forEach((ref) => {
            if (ref.current) {
                observer?.observe(ref.current);
            }
        });
        document.fonts?.ready.then(measure);
        window.addEventListener('resize', measure);

        return () => {
            observer?.disconnect();
            window.removeEventListener('resize', measure);
        };
    }, [frame, fromWin, fromLine, toWin, toLine]);

    return geometry;
}

function UseSceneWindows({ scene }: { scene: UseScene }) {
    const frame = useRef<HTMLDivElement>(null);
    const winA = useRef<HTMLDivElement>(null);
    const source = useRef<HTMLSpanElement>(null);
    const winB = useRef<HTMLDivElement>(null);
    const target = useRef<HTMLDivElement>(null);
    const connector = useConnector(frame, [winA, source, winB, target]);
    const fade = useId();

    return (
        <div
            ref={frame}
            className="aw-frame"
            style={
                {
                    '--frame-light': `url(${scene.frame.light})`,
                    '--frame-dark': `url(${scene.frame.dark})`,
                } as CSSProperties
            }
            aria-hidden="true"
        >
            <AgentWindow side={scene.author} winRef={winA}>
                <AuthorMessages
                    side={scene.author}
                    content={scene.authorContent}
                    sourceRef={source}
                />
            </AgentWindow>
            {connector && (
                <svg className="up-flow" aria-hidden="true">
                    <defs>
                        <linearGradient
                            id={fade}
                            gradientUnits="userSpaceOnUse"
                            x1={connector.a[0]}
                            x2={connector.b[0]}
                            y1="0"
                            y2="0"
                        >
                            <stop
                                offset="0"
                                stopColor="#701a24"
                                stopOpacity="0"
                            />
                            <stop
                                offset="0.2"
                                stopColor="#701a24"
                                stopOpacity="0.85"
                            />
                            <stop
                                offset="0.8"
                                stopColor="#701a24"
                                stopOpacity="0.85"
                            />
                            <stop
                                offset="1"
                                stopColor="#701a24"
                                stopOpacity="0"
                            />
                        </linearGradient>
                    </defs>
                    <path className="up-wire" d={connector.d} />
                    <path
                        className="up-stream"
                        d={connector.d}
                        pathLength={1}
                        stroke={`url(#${fade})`}
                    />
                    <path className="up-pulse" d={connector.d} pathLength={1} />
                    <circle
                        className="up-ring up-ring-a"
                        cx={connector.a[0]}
                        cy={connector.a[1]}
                        r="5"
                    />
                    <circle
                        className="up-ring up-ring-b"
                        cx={connector.b[0]}
                        cy={connector.b[1]}
                        r="5"
                    />
                    <circle
                        className="up-port up-port-a"
                        cx={connector.a[0]}
                        cy={connector.a[1]}
                        r="5"
                    />
                    <circle
                        className="up-port up-port-b"
                        cx={connector.b[0]}
                        cy={connector.b[1]}
                        r="5"
                    />
                </svg>
            )}
            <AgentWindow side={scene.reader} winRef={winB}>
                <ReaderMessages
                    side={scene.reader}
                    content={scene.readerContent}
                    targetRef={target}
                />
            </AgentWindow>
        </div>
    );
}

function UseCaseRow({
    useCase,
    frame,
}: {
    useCase: UseCase;
    frame?: { light: string; dark: string };
}) {
    const row = useRef<HTMLElement>(null);
    const [armed, setArmed] = useState(false);
    const [seen, setSeen] = useState(false);

    useEffect(() => {
        const node = row.current;

        if (!node || typeof IntersectionObserver === 'undefined') {
            return;
        }

        /* Arm early, while the row is still below the fold, so its animations
           hold their start state before anyone can see it. Play only once the
           row is actually in view. */
        const arm = new IntersectionObserver(
            ([entry]) => {
                if (entry.isIntersecting) {
                    /* Only a row that is still below the fold may arm; one
                       that first appears already in view stays finished. */
                    if (entry.boundingClientRect.top >= window.innerHeight) {
                        setArmed(true);
                    }

                    arm.disconnect();
                }
            },
            { rootMargin: '0px 0px 60% 0px' },
        );
        const play = new IntersectionObserver(
            ([entry]) => {
                if (entry.isIntersecting) {
                    setSeen(true);
                    play.disconnect();
                }
            },
            { threshold: 0.4 },
        );

        arm.observe(node);
        play.observe(node);

        return () => {
            arm.disconnect();
            play.disconnect();
        };
    }, []);

    return (
        <article
            ref={row}
            className="usecase"
            data-armed={armed ? 'true' : undefined}
            data-seen={seen ? 'true' : undefined}
        >
            <div className="usecase-copy">
                <span className="role">{useCase.role}</span>
                <h3>{useCase.title}</h3>
                <p>{useCase.text}</p>
            </div>
            <UseSceneWindows
                scene={frame ? { ...useCase.scene, frame } : useCase.scene}
            />
        </article>
    );
}

type FramePair = { light: string; dark: string };

type ConnectGuide = { id: string; label: string };

type LatestPost = { slug: string; title: string };

type LandingProps = {
    agentSetupPrompt: string;
    connectGuides: ConnectGuide[];
    latestPosts: LatestPost[];
};

/* Questions agent-native teams search for (RUB-494), each with the posts
   that answer it. Setup links live in "Connect your agent" just below. */
const GUIDES: {
    title: string;
    text: string;
    posts: { label: string; slug: string }[];
}[] = [
    {
        title: 'Share context between Claude Code and Cursor',
        text: 'Connect both to the same Artfct workspace. Each one publishes there and searches what the other published.',
        posts: [
            {
                label: 'Stop them contradicting each other',
                slug: 'stop-ai-tools-contradicting-each-other',
            },
        ],
    },
    {
        title: 'Let ChatGPT read what you made in Claude',
        text: 'Connect both to your Artfct workspace. Share from Claude, and ChatGPT can search for it there.',
        posts: [],
    },
    {
        title: 'Search your team’s AI artifacts, with sources',
        text: 'Any connected agent can search by meaning, read the right part and link back to where it came from.',
        posts: [
            {
                label: 'Finding what your AI already made',
                slug: 'finding-what-your-ai-already-made',
            },
            {
                label: 'What is an AI artifact?',
                slug: 'what-is-an-ai-artifact',
            },
        ],
    },
];

const subscribeNothing = () => () => {};
let framesForThisLoad: FramePair[] | null = null;

/** A different set of backgrounds on every page load. The server renders the
 * defaults, and the browser swaps to the random set after hydrating. */
function randomFramesOnce(): FramePair[] {
    framesForThisLoad ??= randomBackgroundPairs(USE_CASES.length);

    return framesForThisLoad;
}

export default function Landing({
    agentSetupPrompt,
    connectGuides,
    latestPosts,
}: LandingProps) {
    const story = useStory();
    const frames = useSyncExternalStore(
        subscribeNothing,
        randomFramesOnce,
        () => null,
    );
    const navMenu = useRef<HTMLDetailsElement>(null);
    const { isAuthenticated, accountLabel, accountUrl } = useAccountNav();

    return (
        <div className="landing">
            <div className="frame">
                <nav className="pad">
                    <Link href={home.url()} className="logo">
                        Artfct
                    </Link>
                    <div className="navlinks">
                        <a href="#how">How it works</a>
                        <a href="#use">Use cases</a>
                        <a href="#plans">Pricing</a>
                        <Link href={connectRoutes.index().url}>Connect</Link>
                        <Link href={docs.url()}>Docs</Link>
                        <Link href={blog.url()}>Blog</Link>
                    </div>
                    <details ref={navMenu} className="navmenu">
                        <summary>Menu</summary>
                        <div
                            className="navmenu-links"
                            onClick={() => {
                                if (navMenu.current) {
                                    navMenu.current.open = false;
                                }
                            }}
                        >
                            <a href="#how">How it works</a>
                            <a href="#use">Use cases</a>
                            <a href="#plans">Pricing</a>
                            <Link href={connectRoutes.index().url}>
                                Connect
                            </Link>
                            <Link href={docs.url()}>Docs</Link>
                            <Link href={blog.url()}>Blog</Link>
                        </div>
                    </details>
                    <div className="navright" data-testid="landing-account">
                        <ThemeSwitch />
                        {!isAuthenticated && (
                            <Link
                                href={login.url()}
                                data-testid="landing-signin"
                            >
                                Sign in
                            </Link>
                        )}
                        <Button asChild className="btn btn-primary sm">
                            <Link
                                href={accountUrl}
                                data-testid="landing-account-cta"
                            >
                                {accountLabel}
                            </Link>
                        </Button>
                    </div>
                </nav>

                <main>
                    <div className="herosplit">
                        <header className="hero pad">
                            <div className="eyebrow">
                                The team knowledge base for every AI tool
                            </div>
                            <h1 className="two">
                                Every agent sees{' '}
                                <span>what the others made.</span>
                            </h1>
                            <p className="lede">
                                Artfct indexes every AI artifact your agents
                                publish: reports, specs, tables and mockups.
                                Claude, Cursor, Codex and ChatGPT can search
                                across your team’s work, read the right part and
                                cite where it came from.
                            </p>
                            <div className="cta">
                                <Button asChild className="btn btn-primary">
                                    <Link
                                        href={accountUrl}
                                        data-testid="landing-hero-cta"
                                    >
                                        {accountLabel}{' '}
                                        <span className="arrow">→</span>
                                    </Link>
                                </Button>
                                <a className="btn btn-ghost" href="#how">
                                    See how it works{' '}
                                    <span className="arrow">→</span>
                                </a>
                            </div>
                        </header>
                        <StoryStage story={story} />
                    </div>

                    <div className="strip rule">
                        <div className="lab">
                            Works with the AI tools your team already uses.
                        </div>
                        <div className="names">
                            <Link
                                href={connectRoutes.show('connect-claude').url}
                            >
                                <AiToolIcon tool="claude" size={26} />
                                Claude
                            </Link>
                            <Link
                                href={connectRoutes.show('connect-chatgpt').url}
                            >
                                <AiToolIcon tool="chatgpt" size={26} />
                                ChatGPT
                            </Link>
                            <Link
                                href={connectRoutes.show('connect-vscode').url}
                            >
                                <AiToolIcon tool="copilot" size={26} />
                                Copilot
                            </Link>
                            <Link
                                href={connectRoutes.show('connect-cursor').url}
                            >
                                <AiToolIcon tool="cursor" size={26} />
                                Cursor
                            </Link>
                            <Link
                                className="more"
                                href={connectRoutes.show('connect-other').url}
                            >
                                and other AI tools
                            </Link>
                        </div>
                    </div>

                    <section className="guides pad rule" id="guides">
                        <div className="guide-list">
                            {GUIDES.map((guide) => (
                                <div key={guide.title} className="guide">
                                    <h2>{guide.title}</h2>
                                    <p>{guide.text}</p>
                                    {guide.posts.length > 0 && (
                                        <ul>
                                            {guide.posts.map((post) => (
                                                <li key={post.slug}>
                                                    <Link
                                                        href={
                                                            blogRoutes.show(
                                                                post.slug,
                                                            ).url
                                                        }
                                                    >
                                                        {post.label}{' '}
                                                        <span className="arrow">
                                                            →
                                                        </span>
                                                    </Link>
                                                </li>
                                            ))}
                                        </ul>
                                    )}
                                </div>
                            ))}
                        </div>
                    </section>

                    <section
                        className="connect pad rule"
                        id="connect"
                        aria-labelledby="connect-title"
                    >
                        <h2 id="connect-title">Connect your agent</h2>
                        <p className="lede">
                            Paste one prompt into Claude Code, Codex, Cursor,
                            ChatGPT or any agent you use. It finds the steps for
                            that app, connects to your team’s workspace and asks
                            you to approve the sign-in.
                        </p>
                        <div className="actions">
                            <CopyPrompt prompt={agentSetupPrompt} />
                            <Button asChild className="btn btn-primary">
                                <Link href={connectRoutes.index().url}>
                                    Setup guides{' '}
                                    <span className="arrow">→</span>
                                </Link>
                            </Button>
                        </div>
                        <ol className="beats">
                            <li>
                                <b>Paste</b> into the agent you already use
                            </li>
                            <li>
                                <b>Approve</b> the sign-in in your browser
                            </li>
                            <li>
                                <b>Search</b> what every other agent made
                            </li>
                        </ol>
                    </section>

                    <section className="statement pad rule">
                        <h2 className="two">
                            Your team uses many AI tools.{' '}
                            <span>
                                What each one makes stays locked inside it.
                            </span>
                        </h2>
                        <p className="def">
                            <b>Artifact:</b> any report, table, document or
                            mockup your AI makes. Artfct brings them together
                            and puts them in reach of every tool.
                        </p>
                    </section>

                    <StorySteps story={story} />

                    <section className="control band rule" id="control">
                        <div>
                            <h2 className="two">
                                Change AI tools.{' '}
                                <span>Keep everything they made.</span>
                            </h2>
                            <p className="lede">
                                Your team’s knowledge base belongs to your
                                company, not to any one vendor. Use whichever AI
                                tools you like; where the work is kept and who
                                can see it is up to you.
                            </p>
                        </div>
                        <div className="r">
                            <ul>
                                <li>
                                    <svg viewBox="0 0 24 24" aria-hidden="true">
                                        <path d="M12 3l8 3v6c0 4.5-3.2 7.8-8 9-4.8-1.2-8-4.5-8-9V6z" />
                                    </svg>
                                    <div>
                                        <b>Sharing is your choice</b>
                                        <span>
                                            Nothing goes into the knowledge base
                                            unless someone shares it.
                                        </span>
                                    </div>
                                </li>
                                <li>
                                    <svg viewBox="0 0 24 24" aria-hidden="true">
                                        <circle cx="9" cy="8" r="3" />
                                        <path d="M3 20c0-3.3 2.7-6 6-6s6 2.7 6 6M16 11a3 3 0 100-6M21 20c0-2.5-1.5-4.6-3.6-5.5" />
                                    </svg>
                                    <div>
                                        <b>Admins manage access</b>
                                        <span>
                                            Team admins decide who is on the
                                            team.
                                        </span>
                                    </div>
                                </li>
                                <li>
                                    <svg viewBox="0 0 24 24" aria-hidden="true">
                                        <path d="M4 12h16M4 6h16M4 18h10" />
                                    </svg>
                                    <div>
                                        <b>Answers show their sources</b>
                                        <span>
                                            Anyone can open the work an answer
                                            came from.
                                        </span>
                                    </div>
                                </li>
                            </ul>
                        </div>
                    </section>

                    <section className="use pad rule" id="use">
                        <div className="head">
                            <div>
                                <h2 className="two">
                                    Made for whoever makes things with AI.{' '}
                                    <span>Not just engineers.</span>
                                </h2>
                            </div>
                            <p className="lede">
                                Whatever your team’s AI makes, every agent on
                                the team works from the latest version.
                            </p>
                        </div>
                        <div className="usecases">
                            {USE_CASES.map((useCase, index) => (
                                <UseCaseRow
                                    key={useCase.role}
                                    useCase={useCase}
                                    frame={frames?.[index]}
                                />
                            ))}
                        </div>
                    </section>

                    <section className="plansec pad rule" id="plans">
                        <div className="eyebrow">Pricing</div>
                        <h2>Start free. Add your team when you’re ready.</h2>
                        <div className="plans">
                            <div className="plan">
                                <div className="nm">Free</div>
                                <div className="for">
                                    Share a page as a link. A good way to try
                                    it.
                                </div>
                                <ul>
                                    <li>Share a page with anyone</li>
                                    <li>
                                        Private link, no account needed to view
                                    </li>
                                </ul>
                                <Button asChild className="btn btn-ghost">
                                    <Link href={free.url()}>Use Free</Link>
                                </Button>
                            </div>
                            <div className="plan hl">
                                <div className="nm">Team</div>
                                <div className="for">
                                    Everything in Free, for the whole team.
                                    Billed per person; Team pricing isn’t
                                    published yet.
                                </div>
                                <ul>
                                    <li>
                                        Every AI tool on your team can use
                                        what’s shared, with sources
                                    </li>
                                    <li>
                                        Share from your AI tool to your team
                                    </li>
                                    <li>
                                        Search by describing what you need, in
                                        plain words
                                    </li>
                                    <li>
                                        Your company’s own web address for your
                                        knowledge base
                                    </li>
                                </ul>
                                <p className="fine">
                                    Try Team free with everything except search
                                    by description and your own web address.
                                    Your AI tools can still open any shared
                                    document in full.
                                </p>
                                <Button asChild className="btn btn-primary">
                                    <Link
                                        href={accountUrl}
                                        data-testid="landing-pricing-cta"
                                    >
                                        {accountLabel}
                                    </Link>
                                </Button>
                            </div>
                            <div className="plan">
                                <div className="nm">Enterprise</div>
                                <div className="for">
                                    For companies with security and compliance
                                    needs.
                                </div>
                                <ul>
                                    <li>
                                        Single sign-on with your company login
                                    </li>
                                    <li>A full record of who did what</li>
                                    <li>Rules for how long work is kept</li>
                                </ul>
                            </div>
                        </div>
                    </section>

                    <section className="close pad rule">
                        <h2 className="two">
                            Start sharing{' '}
                            <span>what your team’s AI makes.</span>
                        </h2>
                        <p className="lede">
                            Sign up, connect your AI tools once, and share
                            what’s worth keeping.
                        </p>
                        <div className="cta">
                            <Button asChild className="btn btn-primary">
                                <Link
                                    href={accountUrl}
                                    data-testid="landing-closing-cta"
                                >
                                    {accountLabel}{' '}
                                    <span className="arrow">→</span>
                                </Link>
                            </Button>
                        </div>
                    </section>
                </main>

                <nav className="sitemap pad" aria-label="Site">
                    <div>
                        <h3>Connect</h3>
                        <ul>
                            <li>
                                <Link href={connectRoutes.index().url}>
                                    All agents
                                </Link>
                            </li>
                            {connectGuides.map((guide) => (
                                <li key={guide.id}>
                                    <Link
                                        href={connectRoutes.show(guide.id).url}
                                    >
                                        {guide.label}
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    </div>
                    <div>
                        <h3>Latest</h3>
                        <ul>
                            {latestPosts.map((post) => (
                                <li key={post.slug}>
                                    <Link href={blogRoutes.show(post.slug).url}>
                                        {post.title}
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    </div>
                </nav>

                <footer className="pad">
                    <div className="l">
                        <span className="logo">Artfct</span>
                        <span>© {new Date().getFullYear()} Artfct</span>
                    </div>
                    <div className="r">
                        <a href="#how">How it works</a>
                        <a href="#plans">Pricing</a>
                        <Link href={connectRoutes.index().url}>Connect</Link>
                        <Link href={docs.url()}>Docs</Link>
                        <Link href={blog.url()}>Blog</Link>
                        <Link href={privacy.url()}>Privacy</Link>
                        <Link href={terms.url()}>Terms</Link>
                    </div>
                </footer>
            </div>
        </div>
    );
}
