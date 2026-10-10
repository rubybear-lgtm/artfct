import { createContext, useContext } from 'react';
import type { CSSProperties, ReactNode } from 'react';

import { CheckGlyph, ChevronGlyph, DocGlyph, glyphs, Row } from './icons';
import type { GlyphName } from './icons';
import { token, u } from './theme';
import { step } from './timeline';

const TYPE = {
    title: u(11.5),
    body: u(11.5),
    answer: u(12.5),
    meta: u(9.5),
    chip: u(10),
    label: u(9),
    badge: u(9),
    number: u(8.5),
    caption: u(14),
} as const;

/**
 * How far the surrounding panel has stepped back. Each face dims itself with
 * a paper veil rather than opacity, so product marks keep their full colour.
 */
const PanelDim = createContext(1);

export function Panel({
    top,
    height,
    enter,
    dim,
    children,
}: {
    top: number;
    height: number;
    enter: number;
    dim: number;
    children: ReactNode;
}) {
    return (
        <div
            style={{
                position: 'absolute',
                left: 0,
                right: 0,
                top: u(top),
                height: u(height),
                overflow: 'hidden',
                background: token.paper,
                border: `${u(1)}px solid ${token.line}`,
                borderRadius: u(10),
                boxShadow: `0 ${u(1)}px ${u(2)}px rgba(38, 38, 36, 0.04), 0 ${u(6)}px ${u(18)}px rgba(38, 38, 36, 0.05)`,
                opacity: enter,
                transform: `translateY(${u(14) * (1 - enter)}px)`,
            }}
        >
            <PanelDim.Provider value={dim}>{children}</PanelDim.Provider>
        </div>
    );
}

/** Expanded and compact content cross-fade in place, so the panel can resize. */
export function PanelFace({
    mode,
    visible,
    children,
}: {
    mode: 'expanded' | 'compact';
    visible: number;
    children: ReactNode;
}) {
    const dim = useContext(PanelDim);

    return (
        <div
            style={{
                position: 'absolute',
                inset: 0,
                opacity: visible,
                padding: u(10),
                ...(mode === 'compact'
                    ? {
                          display: 'flex',
                          flexDirection: 'column',
                          justifyContent: 'center',
                      }
                    : null),
            }}
        >
            {children}
            <div
                style={{
                    position: 'absolute',
                    inset: 0,
                    background: token.paper,
                    opacity: 1 - dim,
                    pointerEvents: 'none',
                    zIndex: 1,
                }}
            />
        </div>
    );
}

export function AppChip({
    label,
    glyph,
    tone = 'ink',
}: {
    label: string;
    glyph: GlyphName;
    tone?: 'ink' | 'ox';
}) {
    const Glyph = glyphs[glyph];
    const color = tone === 'ox' ? token.ox : token.ink;

    return (
        <span
            style={{
                display: 'inline-flex',
                alignItems: 'center',
                gap: u(5),
                padding: `${u(3.5)}px ${u(7)}px`,
                border: `${u(1)}px solid ${token.line}`,
                borderRadius: u(6),
                background: '#fff',
                fontSize: TYPE.chip,
                fontWeight: 600,
                color: token.ink,
                lineHeight: 1,
            }}
        >
            <Glyph size={u(14)} color={color} />
            {label}
        </span>
    );
}

export function Person({ name, right }: { name: string; right?: ReactNode }) {
    return (
        <Row style={{ justifyContent: 'space-between', flex: 1 }}>
            <span
                style={{
                    fontSize: TYPE.chip,
                    color: token.inkGhost,
                    lineHeight: 1,
                }}
            >
                {name}
            </span>
            {right}
        </Row>
    );
}

export function Well({
    text,
    minHeight,
    caret,
}: {
    text: string;
    minHeight: number;
    caret?: boolean;
}) {
    return (
        <div
            style={{
                background: token.well,
                borderRadius: u(7),
                padding: `${u(6)}px ${u(8)}px`,
                minHeight: u(minHeight),
                fontSize: TYPE.body,
                lineHeight: 1.35,
                color: token.ink,
            }}
        >
            {text}
            {caret ? (
                <span
                    style={{
                        display: 'inline-block',
                        width: u(1.2),
                        height: u(11),
                        marginLeft: u(1),
                        background: token.ink,
                        verticalAlign: '-0.15em',
                    }}
                />
            ) : null}
        </div>
    );
}

export function ArtifactCard({
    title,
    meta,
    style,
}: {
    title: string;
    meta: string;
    style?: CSSProperties;
}) {
    return (
        <Row
            gap={8}
            style={{
                background: '#fff',
                border: `${u(1)}px solid ${token.line}`,
                borderRadius: u(8),
                padding: u(7),
                boxShadow: `0 ${u(1)}px ${u(2)}px rgba(38, 38, 36, 0.04)`,
                ...style,
            }}
        >
            <span
                style={{
                    display: 'flex',
                    alignItems: 'center',
                    justifyContent: 'center',
                    width: u(22),
                    height: u(22),
                    borderRadius: u(6),
                    background: token.well,
                }}
            >
                <DocGlyph size={u(12)} color={token.ink3} />
            </span>
            <span style={{ display: 'flex', flexDirection: 'column' }}>
                <span
                    style={{
                        fontSize: TYPE.title,
                        fontWeight: 600,
                        color: token.ink,
                        lineHeight: 1.25,
                    }}
                >
                    {title}
                </span>
                <span
                    style={{
                        fontSize: TYPE.meta,
                        color: token.ink3,
                        lineHeight: 1.3,
                    }}
                >
                    {meta}
                </span>
            </span>
        </Row>
    );
}

export function Pill({ label, tone }: { label: string; tone: 'ok' | 'ox' }) {
    const background = tone === 'ok' ? token.okTint : token.oxTint;
    const color = tone === 'ok' ? token.ok : token.ox;

    return (
        <span
            style={{
                padding: `${u(3)}px ${u(7)}px`,
                borderRadius: u(6),
                background,
                color,
                fontSize: TYPE.meta,
                fontWeight: 600,
                lineHeight: 1.25,
            }}
        >
            {label}
        </span>
    );
}

export function ShareFooter({ shared }: { shared: boolean }) {
    return (
        <Row style={{ justifyContent: 'space-between' }}>
            <span
                style={{
                    fontSize: TYPE.meta,
                    color: shared ? token.ok : token.ink3,
                    lineHeight: 1.3,
                }}
            >
                {shared ? 'Shared with your team' : 'Share with team?'}
            </span>
            {shared ? (
                <Pill label="Shared" tone="ok" />
            ) : (
                <span
                    style={{
                        padding: `${u(4)}px ${u(9)}px`,
                        borderRadius: u(6),
                        background: token.ox,
                        color: '#fff',
                        fontSize: TYPE.meta,
                        fontWeight: 600,
                        lineHeight: 1.2,
                    }}
                >
                    Share
                </span>
            )}
        </Row>
    );
}

export function LibraryRow({
    glyph,
    title,
    meta,
    highlighted,
    badge,
    pulse,
    style,
}: {
    glyph: GlyphName;
    title: string;
    meta: string;
    highlighted?: boolean;
    badge?: { label: string; ready: boolean };
    pulse?: number;
    style?: CSSProperties;
}) {
    const Glyph = glyphs[glyph];
    const glow = pulse ?? 0;

    return (
        <div
            style={{
                display: 'flex',
                alignItems: 'center',
                gap: u(8),
                padding: `${u(5)}px ${u(7)}px`,
                borderRadius: u(7),
                background: highlighted ? token.oxTint : 'transparent',
                borderLeft: highlighted
                    ? `${u(2)}px solid ${token.ox}`
                    : `${u(2)}px solid transparent`,
                ...(glow > 0
                    ? {
                          boxShadow: `0 0 0 ${u(2) * glow}px rgba(112, 26, 36, ${0.14 * glow})`,
                      }
                    : null),
                ...style,
            }}
        >
            <span
                style={{
                    display: 'flex',
                    alignItems: 'center',
                    justifyContent: 'center',
                    width: u(20),
                    height: u(20),
                    borderRadius: u(5),
                    background: '#fff',
                    border: `${u(1)}px solid ${token.line}`,
                }}
            >
                <Glyph size={u(15)} color={token.ink} />
            </span>
            <span
                style={{
                    display: 'flex',
                    flexDirection: 'column',
                    flex: 1,
                    minWidth: 0,
                }}
            >
                <span
                    style={{
                        fontSize: TYPE.title,
                        fontWeight: 600,
                        color: token.ink,
                        lineHeight: 1.25,
                    }}
                >
                    {title}
                </span>
                <span
                    style={{
                        fontSize: TYPE.meta,
                        color: token.ink3,
                        lineHeight: 1.3,
                    }}
                >
                    {meta}
                </span>
            </span>
            {badge ? (
                <span
                    style={{
                        display: 'inline-flex',
                        alignItems: 'center',
                        gap: u(3),
                        padding: `${u(3)}px ${u(6)}px`,
                        borderRadius: u(6),
                        background: badge.ready ? token.paper : token.oxTint,
                        fontSize: TYPE.badge,
                        fontWeight: 600,
                        color: badge.ready ? token.ink2 : token.ox,
                        lineHeight: 1.2,
                        whiteSpace: 'nowrap',
                    }}
                >
                    {badge.ready ? (
                        <CheckGlyph size={u(10)} color={token.ok} />
                    ) : null}
                    {badge.label}
                </span>
            ) : null}
        </div>
    );
}

export function PlaceholderSlot() {
    return (
        <div
            style={{
                height: u(30),
                borderRadius: u(7),
                border: `${u(1)}px dashed ${token.line}`,
                background: 'rgba(255, 255, 255, 0.5)',
            }}
        />
    );
}

export function ToolRow({ reveals }: { reveals: number[] }) {
    const order: GlyphName[] = ['star', 'chat', 'pointer', 'spark'];

    return (
        <Row gap={6}>
            {order.map((name, index) => {
                const Glyph = glyphs[name];
                const revealed = reveals[index] ?? 0;

                return (
                    <span
                        key={name}
                        style={{
                            display: 'flex',
                            alignItems: 'center',
                            justifyContent: 'center',
                            width: u(46),
                            height: u(26),
                            borderRadius: u(6),
                            background: '#fff',
                            border: `${u(1)}px solid ${token.oxTint}`,
                            opacity: revealed,
                            transform: `translateY(${u(6) * (1 - revealed)}px)`,
                        }}
                    >
                        <Glyph size={u(17)} color={token.ink} />
                    </span>
                );
            })}
        </Row>
    );
}

export function Connector({
    label,
    revealed,
    highlighted,
}: {
    label: string;
    revealed: number;
    highlighted?: boolean;
}) {
    const color = highlighted ? token.ox : token.ink3;

    return (
        <Row
            gap={4}
            style={{
                justifyContent: 'center',
                height: u(20),
                opacity: revealed,
            }}
        >
            <span
                style={{
                    display: 'flex',
                    transform: `translateY(${u(-4) * (1 - revealed)}px)`,
                }}
            >
                <ChevronGlyph size={u(12)} color={color} />
            </span>
            <span
                style={{
                    fontSize: TYPE.label,
                    color,
                    lineHeight: 1,
                }}
            >
                {label}
            </span>
        </Row>
    );
}

export function StepDots({ frame, fill }: { frame: number; fill: number[] }) {
    const starts = [step.asks, step.shares, step.findable, step.answers];

    return (
        <Row style={{ justifyContent: 'center', height: u(18) }}>
            {starts.map((start, index) => {
                const reached = frame >= start;
                const active =
                    frame >= start &&
                    (index === 3 || frame < starts[index + 1]);
                const progress = fill[index] ?? 0;

                return (
                    <Row key={start} gap={0}>
                        {index > 0 ? (
                            <span
                                style={{
                                    width: u(22),
                                    height: u(1),
                                    background: reached ? token.ox : token.line,
                                    transform: `scaleX(${progress})`,
                                    transformOrigin: 'left center',
                                    marginInline: u(3),
                                }}
                            />
                        ) : null}
                        <span
                            style={{
                                display: 'flex',
                                alignItems: 'center',
                                justifyContent: 'center',
                                width: u(16),
                                height: u(16),
                                borderRadius: u(16),
                                background: active
                                    ? token.ox
                                    : reached
                                      ? token.oxTint
                                      : '#eceae6',
                                color: active
                                    ? '#fff'
                                    : reached
                                      ? token.ox
                                      : token.inkGhost,
                                fontSize: TYPE.number,
                                fontWeight: 600,
                                lineHeight: 1,
                                transform: `scale(${1 + 0.12 * (1 - progress)})`,
                            }}
                        >
                            {index + 1}
                        </span>
                    </Row>
                );
            })}
        </Row>
    );
}

export function Caption({ text }: { text: string }) {
    return (
        <div
            style={{
                fontFamily: 'Newsreader, Georgia, serif',
                fontSize: TYPE.caption,
                lineHeight: 1.4,
                color: token.ink,
                textAlign: 'center',
            }}
        >
            {text}
        </div>
    );
}
