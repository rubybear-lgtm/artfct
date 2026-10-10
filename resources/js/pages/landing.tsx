import { Link, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState, useSyncExternalStore } from 'react';

import { AiToolIcon } from '@/components/ai-tool-icon';
import { useAccountNav } from '@/components/site-chrome';
import { Button } from '@/components/ui/button';
import { lowerTier, pickTier } from '@/lib/flow-quality';
import type {
    FlowConnection,
    FlowCut,
    FlowMedia,
    FlowTier,
} from '@/lib/flow-quality';
import { blog, docs, free, home, login, privacy, terms } from '@/routes';

const FLOW_ALT =
    'Animated example in four steps. A marketer asks Claude for a pricing report and shares it with the team. It is saved and searchable by every AI tool. A product manager, in a new chat in a different tool, finds it and uses it, with the source.';

const REDUCED_MOTION = '(prefers-reduced-motion: reduce)';

const subscribeReducedMotion = (onChange: () => void) => {
    const query = window.matchMedia(REDUCED_MOTION);
    query.addEventListener('change', onChange);

    return () => query.removeEventListener('change', onChange);
};

const prefersReducedMotion = () => window.matchMedia(REDUCED_MOTION).matches;

/** Matches the width where the landing stylesheet switches to the stacked layout. */
const PHONE_WIDTH = '(max-width: 900px)';

const subscribePhoneWidth = (onChange: () => void) => {
    const query = window.matchMedia(PHONE_WIDTH);
    query.addEventListener('change', onChange);

    return () => query.removeEventListener('change', onChange);
};

const isPhoneWidth = () => window.matchMedia(PHONE_WIDTH).matches;

/** A frame near the end of the loop, where the finished story is on screen. */
const FINISHED_FRAME_SECONDS = 15.4;

/** Stalls in playback before the page moves to a lighter render. */
const STALLS_BEFORE_STEPPING_DOWN = 2;

type NavigatorWithConnection = Navigator & {
    connection?: FlowConnection & EventTarget;
};

const networkConnection = () =>
    (navigator as NavigatorWithConnection).connection;

/**
 * Keeps the hero headline and the demo on one screen: the demo takes whatever
 * height the headline leaves.
 */
function useHeroHeight(
    page: React.RefObject<HTMLDivElement | null>,
    hero: React.RefObject<HTMLElement | null>,
) {
    useEffect(() => {
        const root = page.current;
        const el = hero.current;

        if (!root || !el) {
            return;
        }

        const set = () =>
            root.style.setProperty('--hh', `${el.offsetHeight}px`);
        set();

        const observer = new ResizeObserver(set);
        observer.observe(el);

        return () => observer.disconnect();
    }, [page, hero]);
}

/**
 * The product animation. It starts muted (browsers require it), plays only
 * while visible, and with reduced motion shows a finished frame instead.
 */
function FlowDemo({ cut }: { cut: FlowCut }) {
    const video = useRef<HTMLVideoElement>(null);
    const resumeAt = useRef(0);
    const stalls = useRef(0);
    const [muted, setMuted] = useState(true);
    const [tier, setTier] = useState<FlowTier | null>(null);
    const reduced = useSyncExternalStore(
        subscribeReducedMotion,
        prefersReducedMotion,
        () => false,
    );
    const animated = !reduced;

    /** Moves to a lighter render and carries on from the same moment. */
    const stepDown = (from: FlowTier) => {
        const lighter = lowerTier(cut.tiers, from.name);

        if (!lighter) {
            return;
        }

        resumeAt.current = video.current?.currentTime ?? 0;
        stalls.current = 0;
        setTier(lighter);
    };

    /** Chooses the first render once the screen size and connection are known. */
    useEffect(() => {
        const el = video.current;
        const width =
            (el?.getBoundingClientRect().width ?? 0) * window.devicePixelRatio;

        setTier(pickTier(cut.tiers, width, networkConnection()));
    }, [cut]);

    /** A connection that gets worse mid-visit drops to a lighter render. */
    useEffect(() => {
        const connection = networkConnection();

        if (!connection || !tier) {
            return;
        }

        const onChange = () => {
            const wanted = pickTier(cut.tiers, 0, connection);

            if (wanted && wanted.width < tier.width) {
                stepDown(tier);
            }
        };

        connection.addEventListener('change', onChange);

        return () => connection.removeEventListener('change', onChange);
    });

    /** Repeated stalls mean the render is too heavy for the link. */
    useEffect(() => {
        const el = video.current;

        if (!el || !tier) {
            return;
        }

        const onWaiting = () => {
            if (el.currentTime < 0.5) {
                return;
            }

            stalls.current += 1;

            if (stalls.current >= STALLS_BEFORE_STEPPING_DOWN) {
                stepDown(tier);
            }
        };

        el.addEventListener('waiting', onWaiting);

        return () => el.removeEventListener('waiting', onWaiting);
    });

    /** A new render picks up where the last one stopped, with the same sound. */
    useEffect(() => {
        const el = video.current;

        if (!el || !tier) {
            return;
        }

        const onLoaded = () => {
            el.muted = muted;

            if (resumeAt.current > 0 && !reduced) {
                el.currentTime = resumeAt.current;
                resumeAt.current = 0;
            }
        };

        el.addEventListener('loadedmetadata', onLoaded);

        return () => el.removeEventListener('loadedmetadata', onLoaded);
        // `muted` is read when a render loads, not a reason to reload one.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [tier, reduced]);

    useEffect(() => {
        const el = video.current;

        if (!el || !tier) {
            return;
        }

        if (reduced) {
            el.pause();

            const seek = () => {
                el.currentTime = FINISHED_FRAME_SECONDS;
            };
            el.addEventListener('loadedmetadata', seek);

            return () => el.removeEventListener('loadedmetadata', seek);
        }

        const observer = new IntersectionObserver(
            ([entry]) => {
                if (entry.isIntersecting) {
                    void el.play().catch(() => undefined);
                } else {
                    el.pause();
                }
            },
            { threshold: 0.25 },
        );
        observer.observe(el);

        return () => observer.disconnect();
    }, [reduced, tier]);

    const toggleSound = () => {
        const el = video.current;

        if (!el) {
            return;
        }

        el.muted = !el.muted;
        setMuted(el.muted);
        void el.play().catch(() => undefined);
    };

    return (
        <div className="demo" id="how">
            <video
                ref={video}
                src={tier?.src}
                poster={cut.poster}
                autoPlay
                muted
                loop
                playsInline
                preload="auto"
                aria-label={FLOW_ALT}
                data-testid="landing-flow"
                data-quality={tier?.name}
            />
            {animated && (
                <button
                    type="button"
                    className="sound"
                    onClick={toggleSound}
                    aria-pressed={!muted}
                    data-testid="landing-sound"
                >
                    {muted ? 'Sound on' : 'Sound off'}
                </button>
            )}
        </div>
    );
}

type MiniRowProps = {
    icon: string;
    title: string;
    meta: string;
    match?: boolean;
};

function MiniRow({ icon, title, meta, match }: MiniRowProps) {
    return (
        <div className={match ? 'mrow m' : 'mrow'}>
            <span className="ic">{icon}</span>
            <span>
                <b>{title}</b>
                <small>{meta}</small>
            </span>
            {match && <span className="tag">Match</span>}
        </div>
    );
}

const USE_CASES: {
    role: string;
    title: string;
    text: string;
    rows: MiniRowProps[];
    open?: boolean;
}[] = [
    {
        role: 'Product',
        title: 'Briefs and research stay findable.',
        text: 'A product manager drafts a brief in one AI tool. A teammate asks a different tool what was decided last quarter, and gets the brief back with a link.',
        rows: [
            {
                icon: 'D',
                title: 'Q3 product brief',
                meta: 'Doc · Marketing',
                match: true,
            },
            {
                icon: 'D',
                title: 'Customer interview notes',
                meta: 'Doc · Product',
            },
        ],
        open: true,
    },
    {
        role: 'Analysis',
        title: 'Tables and charts don’t get rebuilt.',
        text: 'An analyst shares a table once. Anyone asking about the numbers gets the table and its source, instead of a fresh guess.',
        rows: [
            {
                icon: 'T',
                title: 'Renewals by plan',
                meta: 'Table · Jo',
                match: true,
            },
            { icon: 'C', title: 'Churn chart', meta: 'Chart · Jo' },
        ],
    },
    {
        role: 'Design',
        title: 'Mockups travel with their context.',
        text: 'A designer shares a mockup. The people and AI tools working on the launch can find it and see who made it and when.',
        rows: [
            {
                icon: 'M',
                title: 'Launch page mockup',
                meta: 'Mockup · Engineering',
                match: true,
            },
        ],
    },
    {
        role: 'Marketing',
        title: 'Copy and campaigns build on each other.',
        text: 'A new campaign starts from what the team already wrote, not a blank chat.',
        rows: [
            {
                icon: 'D',
                title: 'Spring campaign copy',
                meta: 'Doc · Lena',
                match: true,
            },
        ],
    },
    {
        role: 'Operations',
        title: 'Processes are written down once.',
        text: 'A process doc written with AI is shared and found by the next person who needs it.',
        rows: [
            {
                icon: 'D',
                title: 'Vendor onboarding steps',
                meta: 'Doc · Design',
                match: true,
            },
        ],
    },
];

const FOUR: { title: string; text: string; icon: React.ReactNode }[] = [
    {
        title: 'Share',
        text: 'One click shares a report, table or mockup with your team. Nothing is shared automatically.',
        icon: <path d="M12 15V4M7 9l5-5 5 5M5 20h14" />,
    },
    {
        title: 'Find',
        text: 'One search across everything the team has made, whichever AI tool made it.',
        icon: (
            <>
                <circle cx="11" cy="11" r="6" />
                <path d="M20 20l-4.5-4.5" />
            </>
        ),
    },
    {
        title: 'Connect',
        text: 'Connect your AI tools once. They can then read the team’s shared work.',
        icon: (
            <>
                <circle cx="5" cy="12" r="2" />
                <circle cx="19" cy="6" r="2" />
                <circle cx="19" cy="18" r="2" />
                <path d="M7 12h5l5-5M12 12l5 5" />
            </>
        ),
    },
    {
        title: 'Cite',
        text: 'Every answer lists the shared work it used, so people can check it.',
        icon: (
            <>
                <path d="M12 3l8 3v6c0 4.5-3.2 7.8-8 9-4.8-1.2-8-4.5-8-9V6z" />
                <path d="M9 12l2 2 4-4" />
            </>
        ),
    },
];

export default function Landing() {
    const { flow } = usePage<{ flow: FlowMedia }>().props;
    const phone = useSyncExternalStore(
        subscribePhoneWidth,
        isPhoneWidth,
        () => false,
    );
    const flowCut = phone ? flow.phone : flow.wide;
    const page = useRef<HTMLDivElement>(null);
    const hero = useRef<HTMLElement>(null);
    const navMenu = useRef<HTMLDetailsElement>(null);
    const { isAuthenticated, accountLabel, accountUrl } = useAccountNav();
    useHeroHeight(page, hero);

    return (
        <div ref={page} className="landing">
            <div className="frame">
                <nav className="pad">
                    <Link href={home.url()} className="logo">
                        Artfct
                    </Link>
                    <div className="navlinks">
                        <a href="#how">How it works</a>
                        <a href="#use">Use cases</a>
                        <a href="#plans">Pricing</a>
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
                            <Link href={docs.url()}>Docs</Link>
                            <Link href={blog.url()}>Blog</Link>
                        </div>
                    </details>
                    <div className="navright" data-testid="landing-account">
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
                    <header ref={hero} className="hero pad">
                        <div className="eyebrow">
                            The shared library for your team’s AI
                        </div>
                        <h1 className="two">
                            Every AI on your team,{' '}
                            <span>working from the same memory.</span>
                        </h1>
                        <p className="lede">
                            What your team’s AI tools create is shared in one
                            place. Every AI tool you use can find it and build
                            on it, and shows where each answer came from.
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

                    {flowCut && (
                        <FlowDemo
                            key={phone ? 'phone' : 'wide'}
                            cut={flowCut}
                        />
                    )}

                    <div className="strip rule">
                        <div className="lab">
                            Works with the AI tools your team already uses.
                        </div>
                        <div className="names">
                            <span>
                                <AiToolIcon tool="claude" size={26} />
                                Claude
                            </span>
                            <span>
                                <AiToolIcon tool="chatgpt" size={26} />
                                ChatGPT
                            </span>
                            <span>
                                <AiToolIcon tool="copilot" size={26} />
                                Copilot
                            </span>
                            <span>
                                <AiToolIcon tool="cursor" size={26} />
                                Cursor
                            </span>
                            <span className="more">
                                and other major AI tools
                            </span>
                        </div>
                    </div>

                    <section className="statement pad rule">
                        <div className="eyebrow">The problem</div>
                        <h2 className="two">
                            Your team uses many AI tools.{' '}
                            <span>
                                What each one makes stays locked inside it.
                            </span>{' '}
                            Artfct brings it together.
                        </h2>
                        <p className="def">
                            <b>Artifact:</b> any report, table, document or
                            mockup your AI makes.
                        </p>
                        <div className="cmp">
                            <div className="a">
                                <div className="eyebrow">Without Artfct</div>
                                <h3>Work scattered across chats</h3>
                                <ul>
                                    <li>
                                        A good report lives in one person’s chat
                                        history
                                    </li>
                                    <li>
                                        Each AI tool starts from zero every time
                                    </li>
                                    <li>
                                        No one can tell where an answer came
                                        from
                                    </li>
                                </ul>
                            </div>
                            <div className="b">
                                <div className="eyebrow">With Artfct</div>
                                <h3>One library every AI can read</h3>
                                <ul>
                                    <li>
                                        Reports, tables and docs are shared
                                        once, by choice
                                    </li>
                                    <li>
                                        Every AI tool can find and build on them
                                    </li>
                                    <li>
                                        Each answer points back to its sources
                                    </li>
                                </ul>
                            </div>
                        </div>
                    </section>

                    <section className="four rule">
                        {FOUR.map((item) => (
                            <div key={item.title}>
                                <svg
                                    viewBox="0 0 24 24"
                                    aria-hidden="true"
                                    strokeLinecap="round"
                                    strokeLinejoin="round"
                                >
                                    {item.icon}
                                </svg>
                                <h3>{item.title}</h3>
                                <p>{item.text}</p>
                            </div>
                        ))}
                    </section>

                    <section className="use pad rule" id="use">
                        <div className="head">
                            <div>
                                <div className="eyebrow">
                                    One library. Every team.
                                </div>
                                <h2 className="two">
                                    Made for whoever makes things with AI.{' '}
                                    <span>Not just engineers.</span>
                                </h2>
                            </div>
                            <p className="lede">
                                From a product brief to a budget table to a
                                campaign mockup, the same shared library holds
                                it.
                            </p>
                        </div>
                        <div className="acc">
                            {USE_CASES.map((useCase) => (
                                <details key={useCase.role} open={useCase.open}>
                                    <summary>
                                        <span className="plus">+</span>
                                        <span className="role">
                                            {useCase.role}
                                        </span>
                                        <h3>{useCase.title}</h3>
                                    </summary>
                                    <div className="body">
                                        <p>{useCase.text}</p>
                                        <div className="mini">
                                            {useCase.rows.map((row) => (
                                                <MiniRow
                                                    key={row.title}
                                                    {...row}
                                                />
                                            ))}
                                        </div>
                                    </div>
                                </details>
                            ))}
                        </div>
                    </section>

                    <section className="control rule" id="control">
                        <div>
                            <div className="eyebrow">You stay in control</div>
                            <h2 className="two">
                                Your library belongs to your company.{' '}
                                <span>Not to any one AI tool.</span>
                            </h2>
                            <p className="lede">
                                Teams can use whichever AI tools they like.
                                Where the work is kept and who can see it is up
                                to you.
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
                                            Nothing goes into the library unless
                                            someone shares it.
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
                                    Everything above, for the whole team. Priced
                                    per person.
                                </div>
                                <ul>
                                    <li>
                                        Every AI tool on your team can use
                                        what’s shared, with sources
                                    </li>
                                    <li>
                                        Share from your AI tool to your team
                                    </li>
                                    <li>Find things by describing them</li>
                                    <li>Your own web address</li>
                                </ul>
                                <p className="fine">
                                    Try Team free: everything except finding by
                                    description and your own web address. Your
                                    assistants can still open any shared
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
                            Give your team’s AI a <em>shared</em> memory.
                        </h2>
                        <p className="lede">
                            Sign up, add Artfct to your AI tool, and share
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

                <footer className="pad">
                    <div className="l">
                        <span className="logo">Artfct</span>
                        <span>© {new Date().getFullYear()} Artfct</span>
                    </div>
                    <div className="r">
                        <a href="#how">How it works</a>
                        <a href="#plans">Pricing</a>
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
