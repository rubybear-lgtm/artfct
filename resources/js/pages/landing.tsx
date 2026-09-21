import { Link } from '@inertiajs/react';
import { useEffect, useRef } from 'react';

const QUERY = 'churn retry policy';
const ANSWER =
    'Maya Chen’s analysis shows churn was concentrated in early-month onboarding drop-offs. Dev Patel’s payments plan recommends a longer retry grace period to reduce payment-related churn.';
const CAPTION_SAVE = 'You choose what to share.';
const CAPTION_FIND = 'Found by search, or by any assistant.';

const wait = (ms: number) =>
    new Promise<void>((resolve) => setTimeout(resolve, ms));

/**
 * Drives the hero demo: a new artifact is offered and shared, a search is
 * typed, matching rows highlight, an assistant answer streams in and its
 * source chips pulse the rows they came from. The markup renders in its
 * finished state, so with reduced motion (or before this runs) it is static
 * and fully readable.
 */
function useHeroDemo(demo: React.RefObject<HTMLDivElement | null>) {
    useEffect(() => {
        const root = demo.current;
        const reduce = window.matchMedia('(prefers-reduced-motion: reduce)');

        if (!root || reduce.matches) {
            return;
        }

        const find = <T extends HTMLElement>(selector: string) =>
            root.querySelector<T>(selector)!;
        const q = find('[data-q]');
        const caret = find('[data-caret]');
        const search = find('[data-search]');
        const fresh = find('[data-fresh]');
        const freshTag = find('[data-fresh-tag]');
        const count = find('[data-count]');
        const bubble = find('[data-bubble]');
        const caption = find('[data-caption]');
        const words = [...root.querySelectorAll<HTMLElement>('[data-w]')];
        const chips = [...root.querySelectorAll<HTMLElement>('.chip')];
        const rows = [...root.querySelectorAll<HTMLElement>('.row')];
        const isMatch = (row: HTMLElement) =>
            row.dataset.k === 'churn' || row.dataset.k === 'rfc';

        let visible = false;
        let running = false;
        let stopped = false;

        const play = async () => {
            if (running) {
                return;
            }

            running = true;

            while (visible && !stopped) {
                await wait(3600);

                if (!visible || stopped) {
                    break;
                }

                q.textContent = '';
                caret.hidden = true;
                search.classList.remove('active');
                rows.forEach((row) => row.classList.remove('match', 'dim'));
                fresh.classList.add('entering');
                freshTag.textContent = 'Share with team?';
                count.textContent = '3 shared';
                words.forEach((word) => (word.style.opacity = '0'));
                chips.forEach((chip) => {
                    chip.style.opacity = '0';
                    chip.style.transform = 'translateY(6px)';
                    chip.classList.remove('on');
                });
                bubble.style.opacity = '0';
                bubble.style.transform = 'translateY(6px)';
                caption.textContent = CAPTION_SAVE;
                await wait(700);

                fresh.classList.remove('entering');
                await wait(900);
                freshTag.textContent = 'Shared';
                count.textContent = '4 shared';
                await wait(1100);

                caption.textContent = CAPTION_FIND;
                caret.hidden = false;
                search.classList.add('active');

                for (let i = 1; i <= QUERY.length; i++) {
                    q.textContent = QUERY.slice(0, i);
                    await wait(46 + Math.random() * 40);
                }

                await wait(350);
                rows.forEach((row) => {
                    const hit = isMatch(row);
                    row.classList.toggle('match', hit);
                    row.classList.toggle('dim', !hit);
                });
                caret.hidden = true;
                search.classList.remove('active');
                await wait(600);

                bubble.style.opacity = '1';
                bubble.style.transform = 'none';
                await wait(600);

                for (const word of words) {
                    word.style.opacity = '1';
                    await wait(46);
                }

                await wait(250);

                for (const chip of chips) {
                    chip.style.opacity = '1';
                    chip.style.transform = 'none';
                    chip.classList.add('on');
                    const row = rows.find(
                        (r) => r.dataset.k === chip.dataset.k,
                    );

                    if (row) {
                        row.classList.remove('pulse');
                        void row.offsetWidth;
                        row.classList.add('pulse');
                    }

                    await wait(500);
                }

                await wait(4200);
                rows.forEach((row) => row.classList.remove('dim'));
            }

            running = false;
        };

        const observer = new IntersectionObserver(
            ([entry]) => {
                visible = entry.isIntersecting;

                if (visible) {
                    void play();
                }
            },
            { threshold: 0.25 },
        );
        observer.observe(root);

        return () => {
            stopped = true;
            observer.disconnect();
        };
    }, [demo]);
}

/** Sections settle in as they scroll into view (movement only, never hidden). */
function useSettleOnScroll(page: React.RefObject<HTMLDivElement | null>) {
    useEffect(() => {
        const root = page.current;

        if (
            !root ||
            window.matchMedia('(prefers-reduced-motion: reduce)').matches
        ) {
            return;
        }

        const observer = new IntersectionObserver(
            (entries) =>
                entries.forEach((entry) => {
                    if (entry.isIntersecting) {
                        entry.target.classList.add('in');
                        observer.unobserve(entry.target);
                    }
                }),
            { threshold: 0.12 },
        );
        root.querySelectorAll('.rv').forEach((el) => observer.observe(el));

        return () => observer.disconnect();
    }, [page]);
}

const icon = {
    props: {
        width: 16,
        height: 16,
        viewBox: '0 0 24 24',
        fill: 'none',
        stroke: 'currentColor',
        strokeWidth: 2,
    },
};

export default function Landing() {
    const page = useRef<HTMLDivElement>(null);
    const demo = useRef<HTMLDivElement>(null);
    useHeroDemo(demo);
    useSettleOnScroll(page);

    return (
        <div ref={page} className="landing">
            <div className="wrap">
                <nav>
                    <Link href="/" className="logo">
                        Artfct
                    </Link>
                    <div className="navlinks">
                        <a href="#how">How it works</a>
                        <a href="#plans">Pricing</a>
                        <Link href="/docs">Help</Link>
                    </div>
                    <div className="navright">
                        <Link href="/login">Sign in</Link>
                        <Link className="btn btn-primary sm" href="/login">
                            Try free
                        </Link>
                    </div>
                </nav>

                <header className="hero">
                    <h1>
                        Your AI makes things. Artfct <em>remembers</em> them.
                    </h1>
                    <p className="sub">
                        Your AI makes reports, tables and documents. Share the
                        ones worth keeping, and every AI tool on your team can
                        read them, so nobody starts from scratch.
                    </p>
                    <p className="def">
                        <b>Artifact:</b> any report, table, document or mockup
                        your AI makes.
                    </p>
                    <div className="cta">
                        <Link className="btn btn-primary" href="/login">
                            Try Team free <span className="arrow">→</span>
                        </Link>
                        <a className="link" href="#how">
                            See how it works <span className="arrow">→</span>
                        </a>
                    </div>

                    <div
                        ref={demo}
                        className="demo"
                        aria-label="Example: a new artifact is shared to the team, then found by search and by an assistant"
                    >
                        <div className="cols">
                            <div>
                                <div className="search" data-search>
                                    <svg
                                        width="18"
                                        height="18"
                                        viewBox="0 0 24 24"
                                        fill="none"
                                        stroke="currentColor"
                                        strokeWidth="2"
                                        strokeLinecap="round"
                                    >
                                        <circle cx="11" cy="11" r="7" />
                                        <path d="m20 20-3.5-3.5" />
                                    </svg>
                                    <div className="query">
                                        <span data-q>{QUERY}</span>
                                        <span
                                            className="caret"
                                            data-caret
                                            hidden
                                        />
                                    </div>
                                </div>
                                <div className="label">
                                    <span>Your team’s artifacts</span>
                                    <span data-count>4 shared</span>
                                </div>
                                <div className="rows">
                                    <div className="row fresh" data-fresh>
                                        <span className="ico">
                                            <svg {...icon.props}>
                                                <path d="M5 20V10M12 20V4M19 20v-7" />
                                            </svg>
                                        </span>
                                        <div>
                                            <div className="t">
                                                Weekly signups report
                                            </div>
                                            <div className="m">
                                                Made by your AI · just now
                                            </div>
                                        </div>
                                        <span className="tag" data-fresh-tag>
                                            Shared
                                        </span>
                                    </div>
                                    <div className="row match" data-k="churn">
                                        <span className="ico">
                                            <svg {...icon.props}>
                                                <rect
                                                    x="3"
                                                    y="4"
                                                    width="18"
                                                    height="16"
                                                    rx="2"
                                                />
                                                <path d="M3 10h18M9 4v16" />
                                            </svg>
                                        </span>
                                        <div>
                                            <div className="t">
                                                Q3 churn analysis
                                            </div>
                                            <div className="m">
                                                Maya Chen · 2 days ago
                                            </div>
                                        </div>
                                        <span className="tag">Analysis</span>
                                    </div>
                                    <div className="row match" data-k="rfc">
                                        <span className="ico">
                                            <svg {...icon.props}>
                                                <path d="M6 3h9l4 4v14H6z" />
                                                <path d="M9 12h7M9 16h7" />
                                            </svg>
                                        </span>
                                        <div>
                                            <div className="t">
                                                Payments plan: retry policy
                                            </div>
                                            <div className="m">
                                                Dev Patel · last week
                                            </div>
                                        </div>
                                        <span className="tag">Document</span>
                                    </div>
                                    <div className="row" data-k="mock">
                                        <span className="ico">
                                            <svg {...icon.props}>
                                                <rect
                                                    x="3"
                                                    y="4"
                                                    width="18"
                                                    height="16"
                                                    rx="2"
                                                />
                                                <circle
                                                    cx="9"
                                                    cy="10"
                                                    r="1.5"
                                                />
                                                <path d="m21 16-5-5-8 8" />
                                            </svg>
                                        </span>
                                        <div>
                                            <div className="t">
                                                Checkout redesign mockups
                                            </div>
                                            <div className="m">
                                                Lena Ortiz · yesterday
                                            </div>
                                        </div>
                                        <span className="tag">Design</span>
                                    </div>
                                </div>
                            </div>
                            <div className="assistant">
                                <div className="ahead">
                                    <svg
                                        width="16"
                                        height="16"
                                        viewBox="0 0 24 24"
                                        fill="currentColor"
                                    >
                                        <path d="M12 2l2.2 6.3L21 10l-6.8 1.7L12 18l-2.2-6.3L3 10l6.8-1.7z" />
                                    </svg>
                                    Ask any assistant on your team
                                </div>
                                <div className="bubble" data-bubble>
                                    What did we conclude about Q3 churn?
                                </div>
                                <div className="answer">
                                    <div>
                                        {ANSWER.split(' ').map(
                                            (word, index) => (
                                                <span key={index}>
                                                    <span className="w" data-w>
                                                        {word}
                                                    </span>{' '}
                                                </span>
                                            ),
                                        )}
                                    </div>
                                    <div className="sources">
                                        <div className="label">Sources</div>
                                        <div className="chips">
                                            <span
                                                className="chip"
                                                data-k="churn"
                                            >
                                                Q3 churn analysis
                                            </span>
                                            <span className="chip" data-k="rfc">
                                                Payments plan: retry policy
                                            </span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div className="caption" data-caption>
                            {CAPTION_SAVE} {CAPTION_FIND}
                        </div>
                    </div>
                </header>

                <section className="rv block" id="how">
                    <div className="kicker">How it works</div>
                    <h2>Set it up once. Then just share.</h2>
                    <div className="steps">
                        <div>
                            <span className="n">Step 1</span>
                            <h3>Set up once</h3>
                            <p>
                                Sign up, invite your team, and add Artfct to
                                your AI tool. That’s the whole setup, and most
                                teams don’t need IT.
                            </p>
                        </div>
                        <div>
                            <span className="n">Step 2</span>
                            <h3>Tell your AI to share it</h3>
                            <p>
                                When it makes something worth keeping, ask it to
                                share it with your team, or say yes when it
                                offers. It happens in the tool you’re already
                                using. Nothing to download or upload.
                            </p>
                        </div>
                        <div>
                            <span className="n">Step 3</span>
                            <h3>Every AI starts with what your team knows</h3>
                            <p>
                                Your team’s AI tools read what’s been shared, so
                                they start from what your team already knows,
                                not from scratch. Answers cite their sources.
                                You can search it too.
                            </p>
                        </div>
                    </div>
                </section>

                <section className="rv block">
                    <div className="kicker">Like Drive, Notion or Slack</div>
                    <h2>
                        Team knowledge is available to everyone as soon as it’s
                        shared.
                    </h2>
                    <div className="compare">
                        <div className="cmp">
                            <div>
                                <h4>Drive, Notion, Slack</h4>
                                <ul>
                                    <li>
                                        Someone has to save the file, name it
                                        and find the right folder
                                    </li>
                                    <li>
                                        Your AI’s work stays in chat history
                                    </li>
                                    <li>Search finds names and keywords</li>
                                    <li>
                                        Each AI tool sees only its own
                                        conversations
                                    </li>
                                </ul>
                            </div>
                            <div>
                                <h4>Artfct</h4>
                                <ul>
                                    <li>
                                        Every AI tool on your team can read it,
                                        and cites its sources
                                    </li>
                                    <li>
                                        Your AI shares it to your team in one
                                        step
                                    </li>
                                    <li>
                                        So nobody rebuilds what already exists
                                    </li>
                                    <li>
                                        People can find it too, by describing it
                                    </li>
                                </ul>
                            </div>
                        </div>
                    </div>
                    <p className="fine">
                        A shared workspace that your team’s AI fills for you.
                        Drive holds files. Artfct is built for your AI to share
                        to, and for every AI tool to read from, with sources.
                    </p>
                </section>

                <section className="rv block">
                    <div className="split">
                        <div>
                            <div className="kicker">Works with your tools</div>
                            <h2>Every AI tool. The same team knowledge.</h2>
                            <p className="lead">
                                Your team uses different AI tools. With Artfct
                                they all start from the same shared knowledge,
                                and your company owns it.
                            </p>
                            <p className="fine">
                                Works with Claude, ChatGPT, Copilot, Cursor and
                                other major AI tools.
                            </p>
                        </div>
                        <div className="diagram">
                            <svg
                                viewBox="0 0 520 300"
                                role="img"
                                aria-label="Claude, ChatGPT, Copilot, Cursor and other AI tools all sharing the same knowledge, owned by your company"
                            >
                                <text
                                    x="260"
                                    y="20"
                                    textAnchor="middle"
                                    fontSize="11"
                                    letterSpacing="1.2"
                                    fill="#8c8a83"
                                    fontWeight="600"
                                >
                                    YOUR TEAM’S AI TOOLS
                                </text>
                                <g
                                    fontSize="12"
                                    fontWeight="600"
                                    textAnchor="middle"
                                    fill="#262624"
                                >
                                    {[
                                        { x: 12, label: 'Claude' },
                                        { x: 140, label: 'ChatGPT' },
                                        { x: 268, label: 'Copilot' },
                                        { x: 396, label: 'Cursor' },
                                    ].map((tool) => (
                                        <g key={tool.label}>
                                            <rect
                                                x={tool.x}
                                                y="36"
                                                width="112"
                                                height="52"
                                                rx="8"
                                                fill="#f7f5f2"
                                                stroke="#dedad2"
                                            />
                                            <circle
                                                cx={tool.x + 56}
                                                cy="52"
                                                r="4"
                                                fill="none"
                                                stroke="#701a24"
                                                strokeWidth="1.6"
                                            />
                                            <text x={tool.x + 56} y="78">
                                                {tool.label}
                                            </text>
                                        </g>
                                    ))}
                                </g>
                                <g
                                    fill="none"
                                    stroke="#c9c4ba"
                                    strokeWidth="1.4"
                                >
                                    <path
                                        id="p1"
                                        d="M68 88 C68 150 200 150 260 196"
                                    />
                                    <path
                                        id="p2"
                                        d="M196 88 C196 150 240 150 260 196"
                                    />
                                    <path
                                        id="p3"
                                        d="M324 88 C324 150 280 150 260 196"
                                    />
                                    <path
                                        id="p4"
                                        d="M452 88 C452 150 320 150 260 196"
                                    />
                                </g>
                                <g className="dotset" fill="#701a24">
                                    {['p1', 'p2', 'p3', 'p4'].map(
                                        (path, index) => (
                                            <circle
                                                key={path}
                                                r="3.5"
                                                opacity="0"
                                            >
                                                <animate
                                                    attributeName="opacity"
                                                    from="1"
                                                    to="1"
                                                    dur="3.2s"
                                                    repeatCount="indefinite"
                                                    begin={`${index * 0.8}s`}
                                                />
                                                <animateMotion
                                                    dur="3.2s"
                                                    repeatCount="indefinite"
                                                    begin={`${index * 0.8}s`}
                                                >
                                                    <mpath href={`#${path}`} />
                                                </animateMotion>
                                            </circle>
                                        ),
                                    )}
                                </g>
                                <rect
                                    x="130"
                                    y="196"
                                    width="260"
                                    height="84"
                                    rx="10"
                                    fill="#f1e4e5"
                                    stroke="#701a24"
                                />
                                <circle
                                    cx="222"
                                    cy="226"
                                    r="4"
                                    fill="#701a24"
                                />
                                <text
                                    x="234"
                                    y="231"
                                    fontFamily="Newsreader, Georgia, serif"
                                    fontSize="20"
                                    fill="#262624"
                                >
                                    Artfct
                                </text>
                                <text
                                    x="260"
                                    y="254"
                                    textAnchor="middle"
                                    fontSize="13"
                                    fontWeight="700"
                                    fill="#701a24"
                                >
                                    Shared knowledge for every AI tool
                                </text>
                                <text
                                    x="260"
                                    y="271"
                                    textAnchor="middle"
                                    fontSize="11.5"
                                    fill="#66655f"
                                >
                                    Owned by your company
                                </text>
                            </svg>
                        </div>
                    </div>
                </section>

                <section className="rv block">
                    <div className="kicker">Yours</div>
                    <h2>Your work stays yours.</h2>
                    <div className="trust">
                        <div>
                            <h3>Never used to train AI</h3>
                            <p>
                                Your artifacts are never used to train any
                                model.
                            </p>
                        </div>
                        <div>
                            <h3>You choose who sees it</h3>
                            <p>
                                Nothing is shared until you say so. Share with
                                your team, or only with the people you pick.
                            </p>
                        </div>
                        <div>
                            <h3>Take it with you</h3>
                            <p>
                                Export everything your company has shared, any
                                time.
                            </p>
                        </div>
                    </div>
                </section>

                <section className="rv block" id="plans">
                    <div className="kicker">Pricing</div>
                    <h2>Start free. Add your team when you’re ready.</h2>
                    <div className="plans">
                        <div className="plan">
                            <div className="nm">Free</div>
                            <div className="for">
                                Share a page as a link. A good way to try it.
                            </div>
                            <ul>
                                <li>Share a page with anyone</li>
                                <li>Private link, no account needed to view</li>
                            </ul>
                            <Link className="btn btn-ghost" href="/free">
                                Use Free
                            </Link>
                        </div>
                        <div className="plan hl">
                            <div className="nm">Team</div>
                            <div className="for">
                                Everything above, for the whole team. Priced per
                                person.
                            </div>
                            <ul>
                                <li>
                                    Every AI tool on your team can use what’s
                                    shared, with sources
                                </li>
                                <li>Share from your AI tool to your team</li>
                                <li>Find things by describing them</li>
                                <li>Your own web address</li>
                            </ul>
                            <p className="fine" style={{ margin: 0 }}>
                                Try Team free: everything except finding by
                                description and your own web address. Your
                                assistants can still open any shared document in
                                full.
                            </p>
                            <Link className="btn btn-primary" href="/login">
                                Try Team free
                            </Link>
                        </div>
                        <div className="plan">
                            <div className="nm">Enterprise</div>
                            <div className="for">
                                For companies with security and compliance
                                needs.
                            </div>
                            <ul>
                                <li>Single sign-on with your company login</li>
                                <li>A full record of who did what</li>
                                <li>Rules for how long work is kept</li>
                            </ul>
                        </div>
                    </div>
                </section>

                <section className="closing rv">
                    <h2>Stop losing what your AI makes.</h2>
                    <p>
                        Sign up, add Artfct to your AI tool, and share what’s
                        worth keeping.
                    </p>
                    <div className="cta" style={{ marginTop: 0 }}>
                        <Link className="btn btn-primary" href="/login">
                            Try Team free <span className="arrow">→</span>
                        </Link>
                    </div>
                </section>

                <footer>
                    <div className="l">
                        <span className="logo">Artfct</span>
                        <span>© {new Date().getFullYear()} Artfct</span>
                    </div>
                    <div className="r">
                        <a href="#how">How it works</a>
                        <a href="#plans">Pricing</a>
                        <Link href="/docs">Help</Link>
                        <Link href="/privacy">Privacy</Link>
                        <Link href="/terms">Terms</Link>
                    </div>
                </footer>
            </div>
        </div>
    );
}
