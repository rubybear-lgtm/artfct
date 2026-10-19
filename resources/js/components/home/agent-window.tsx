import type { ReactNode, RefObject } from 'react';

import { AiToolIcon } from '@/components/ai-tool-icon';

/**
 * The agent windows used across the marketing pages. Each one is drawn in
 * that agent's own look: Claude, Cursor and ChatGPT/Codex as desktop apps,
 * or the terminal version for engineering work. Type and spacing scale from
 * the window's width, so a window reads the same at any size.
 */
export type AgentSkin = 'claude' | 'cursor' | 'codex' | 'terminal';

export type AgentSide = {
    skin: AgentSkin;
    /** The mark to show, as an AiToolIcon id. */
    tool: string;
    /** Who is working in this window, shown above it. */
    role: string;
    /** Window title (used by the terminal skin). */
    title: string;
    /** Terminal skin only: the agent's name and working directory. */
    terminalName?: string;
    directory?: string;
};

/** What the author's session shows: the change, made in their own agent. */
export type AuthorContent = {
    ask: string;
    answer: string;
    card: {
        title: string;
        label: string;
        before: string;
        after: string;
        meta: string;
    };
};

/** What a teammate's session shows: the same change, already in their work. */
export type ReaderContent = {
    ask: string;
    intro: string;
    before: { text: string; done?: boolean }[];
    change: { prefix: string; before: string; after: string; suffix?: string };
    after: string[];
    read: string;
};

const DEFAULTS: Record<AgentSkin, { model: string; placeholder: string }> = {
    claude: { model: 'Sonnet 4.5', placeholder: 'Reply to Claude…' },
    cursor: {
        model: 'claude-4-sonnet',
        placeholder: 'Plan, search, build anything',
    },
    codex: { model: 'GPT-5', placeholder: 'Do anything' },
    terminal: { model: '', placeholder: 'Ask to do anything' },
};

const ICONS: Record<string, string> = {
    sidebar: 'M4 5h16v14H4zM9 5v14',
    plus: 'M12 5v14M5 12h14',
    sliders: 'M4 8h10M18 8h2M4 16h2M10 16h10M14 6v4M6 14v4',
    clock: 'M12 7v5l3 2M12 3a9 9 0 100 18 9 9 0 000-18z',
    chevron: 'm7 10 5 5 5-5',
    cloud: 'M7 18a4 4 0 010-8 5 5 0 019.6-1A4.5 4.5 0 0117 18z',
    dots: 'M6 12h.01M12 12h.01M18 12h.01',
    close: 'm6 6 12 12M18 6 6 18',
    mic: 'M12 4a3 3 0 00-3 3v5a3 3 0 006 0V7a3 3 0 00-3-3zM6 11a6 6 0 0012 0M12 17v3',
    image: 'M4 5h16v14H4zM4 16l5-5 4 4 3-3 4 4M9 9h.01',
    arrow: 'M12 19V6M6 12l6-6 6 6',
    at: 'M16 12a4 4 0 11-8 0 4 4 0 018 0zM16 12v1.5a2.5 2.5 0 005 0V12a9 9 0 10-3.5 7',
    back: 'M19 12H5M11 6l-6 6 6 6',
    forward: 'M5 12h14M13 6l6 6-6 6',
    panel: 'M4 5h16v14H4zM4 15h16',
    gear: 'M12 9a3 3 0 100 6 3 3 0 000-6zM19 12l2 1-2 3-2-.5-1.5 1-.5 2.5h-3l-.5-2.5-1.5-1-2 .5-2-3 2-1v-2l-2-1 2-3 2 .5 1.5-1 .5-2.5h3l.5 2.5 1.5 1 2-.5 2 3-2 1z',
};

function Ico({ name }: { name: string }) {
    return (
        <svg className="aw-ico" viewBox="0 0 24 24" aria-hidden="true">
            <path d={ICONS[name]} />
        </svg>
    );
}

function Dots({ tone }: { tone?: 'claude' }) {
    return (
        <span className={tone === 'claude' ? 'aw-dots claude' : 'aw-dots'}>
            <i />
            <i />
            <i />
        </span>
    );
}

function Chrome({ side }: { side: AgentSide }) {
    if (side.skin === 'claude') {
        return (
            <div className="aw-bar">
                <Dots tone="claude" />
                <Ico name="sidebar" />
                <span className="aw-spacer" />
                <Ico name="dots" />
            </div>
        );
    }

    if (side.skin === 'codex') {
        return (
            <div className="aw-bar">
                <Dots />
                <Ico name="sidebar" />
                <Ico name="back" />
                <Ico name="forward" />
                <span className="aw-spacer" />
                <Ico name="plus" />
            </div>
        );
    }

    if (side.skin === 'cursor') {
        return (
            <>
                <div className="aw-bar">
                    <Dots />
                    <span className="aw-spacer" />
                    <Ico name="panel" />
                    <Ico name="sidebar" />
                    <Ico name="gear" />
                </div>
                <div className="aw-subbar">
                    <span className="aw-tab">New Chat</span>
                    <span className="aw-spacer" />
                    <Ico name="plus" />
                    <Ico name="cloud" />
                    <Ico name="clock" />
                    <Ico name="dots" />
                    <Ico name="close" />
                </div>
            </>
        );
    }

    return (
        <div className="aw-bar">
            <Dots />
            <span className="aw-title">{side.title}</span>
        </div>
    );
}

function Composer({ side }: { side: AgentSide }) {
    const { model, placeholder } = DEFAULTS[side.skin];

    if (side.skin === 'terminal') {
        return (
            <div className="aw-term-input">
                <span className="aw-prompt">
                    <b>›</b> Ask {terminalShortName(side)} to do anything
                </span>
                <span className="aw-hint">⏎ send · ⌃C exit</span>
            </div>
        );
    }

    return (
        <div className="aw-composer">
            {side.skin === 'cursor' && (
                <span className="aw-chip">
                    <Ico name="at" /> Add Context
                </span>
            )}
            <span className="aw-ph">{placeholder}</span>
            <span className="aw-crow">
                {side.skin === 'claude' && (
                    <>
                        <span className="aw-btn">
                            <Ico name="plus" />
                        </span>
                        <span className="aw-btn">
                            <Ico name="sliders" />
                        </span>
                        <span className="aw-btn">
                            <Ico name="clock" />
                        </span>
                    </>
                )}
                {side.skin === 'cursor' && (
                    <span className="aw-pill">∞ Agent ⌘I</span>
                )}
                {side.skin === 'codex' && <Ico name="plus" />}
                <span className="aw-spacer" />
                <span className="aw-model">
                    {model} <Ico name="chevron" />
                </span>
                {side.skin === 'cursor' && <Ico name="image" />}
                {side.skin === 'codex' && <Ico name="mic" />}
                <span className="aw-send">
                    <Ico name="arrow" />
                </span>
            </span>
        </div>
    );
}

function terminalShortName(side: AgentSide): string {
    return (side.terminalName ?? 'the agent').split(' ').pop() as string;
}

/** A window in its agent's own look, with its role above it. */
export function AgentWindow({
    side,
    winRef,
    children,
}: {
    side: AgentSide;
    winRef?: RefObject<HTMLDivElement | null>;
    children: ReactNode;
}) {
    return (
        <div className="aw-cell">
            <span className="aw-cap">
                <AiToolIcon tool={side.tool} size={16} /> {side.role}
            </span>
            <div ref={winRef} className={`aw aw-${side.skin}`}>
                <div className="aw-in">
                    <Chrome side={side} />
                    <div className="aw-body">{children}</div>
                    <Composer side={side} />
                </div>
            </div>
        </div>
    );
}

/** The author's side: they ask for a change, the agent makes it, and the
 * changed artifact is shown. */
export function AuthorMessages({
    side,
    content,
    sourceRef,
}: {
    side: AgentSide;
    content: AuthorContent;
    sourceRef?: RefObject<HTMLSpanElement | null>;
}) {
    const { card } = content;
    const change = (
        <>
            {card.label}: <del className="up-del up-d1">{card.before}</del>{' '}
            <ins className="up-ins up-i1">{card.after}</ins>
        </>
    );
    const line = <span ref={sourceRef}>{change}</span>;

    if (side.skin === 'terminal') {
        return (
            <>
                <TerminalHead side={side} />
                <div className="aw-term-user">
                    <b>›</b> {content.ask}
                </div>
                <div className="aw-term-line">
                    <b>•</b> {content.answer}
                </div>
                <div className="aw-term-card">
                    <b>{card.title}</b>
                    {line}
                    <small>{card.meta}</small>
                </div>
            </>
        );
    }

    return (
        <>
            <div className="aw-user">{content.ask}</div>
            <div className="aw-reply">
                <AiToolIcon tool={side.tool} size={22} />
                <p>{content.answer}</p>
            </div>
            <div className="up-card">
                <b>{card.title}</b>
                <p>{line}</p>
                <small className="up-quiet up-edited">{card.meta}</small>
            </div>
        </>
    );
}

/** A teammate's side: the agent was already working, and its plan carries
 * the change. */
export function ReaderMessages({
    side,
    content,
    targetRef,
}: {
    side: AgentSide;
    content: ReaderContent;
    targetRef?: RefObject<HTMLDivElement | null>;
}) {
    const { change } = content;
    const changeLine = (
        <>
            {change.prefix} <del className="up-del up-d2">{change.before}</del>{' '}
            <ins className="up-ins up-i2">{change.after}</ins>
            {change.suffix ? ` ${change.suffix}` : ''}
        </>
    );

    if (side.skin === 'terminal') {
        return (
            <>
                <TerminalHead side={side} />
                <div className="aw-term-user">
                    <b>›</b> {content.ask}
                </div>
                <div className="aw-term-line">
                    <b>•</b> {content.intro}
                </div>
                <div className="aw-term-list">
                    {content.before.map((item) => (
                        <div
                            key={item.text}
                            className={item.done ? 'done' : ''}
                        >
                            [{item.done ? 'x' : ' '}] {item.text}
                        </div>
                    ))}
                    <div ref={targetRef} className="up-ship">
                        [ ] {changeLine}
                    </div>
                    {content.after.map((text) => (
                        <div key={text}>[ ] {text}</div>
                    ))}
                </div>
                <div className="aw-term-read up-read">
                    <b>✓</b> {content.read}
                </div>
            </>
        );
    }

    return (
        <>
            <div className="aw-user">{content.ask}</div>
            <div className="aw-reply">
                <AiToolIcon tool={side.tool} size={22} />
                <p>{content.intro}</p>
            </div>
            <ul className="aw-list">
                {content.before.map((item) => (
                    <li key={item.text} className={item.done ? 'done' : ''}>
                        <span className="up-box" />
                        {item.text}
                    </li>
                ))}
                <li>
                    <span className="up-box" />
                    <div ref={targetRef} className="up-ship">
                        {changeLine}
                    </div>
                </li>
                {content.after.map((text) => (
                    <li key={text}>
                        <span className="up-box" />
                        {text}
                    </li>
                ))}
            </ul>
            <p className="aw-read up-read">
                <svg viewBox="0 0 24 24" aria-hidden="true">
                    <path d="m5 12.5 4.5 4.5L19 7" />
                </svg>
                {content.read}
            </p>
        </>
    );
}

function TerminalHead({ side }: { side: AgentSide }) {
    return (
        <div className="aw-term-head">
            <b>&gt;_ {side.terminalName ?? 'Agent'}</b>
            <span>{side.directory ?? '~/work'}</span>
        </div>
    );
}
