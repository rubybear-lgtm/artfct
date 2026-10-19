import { loadFont } from '@remotion/fonts';
import React, { useEffect } from 'react';
import {
    AbsoluteFill,
    Audio,
    Easing,
    Sequence,
    interpolate,
    staticFile,
    useCurrentFrame,
    useVideoConfig,
} from 'remotion';

import { AiToolIcon } from '../js/components/ai-tool-icon';
import type { FontAsset } from './LandingFlowMobile';
import { caption } from './timeline';

const sans = 'Manrope';
const serif = 'Newsreader';

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

const ease = Easing.bezier(0.16, 1, 0.3, 1);
const flight = Easing.bezier(0.45, 0, 0.2, 1);
const ramp = (f: number, a: number, b: number) =>
    interpolate(f, [a, b], [0, 1], {
        extrapolateLeft: 'clamp',
        extrapolateRight: 'clamp',
        easing: ease,
    });
const lin = (f: number, a: number, b: number, x: number, y: number) =>
    interpolate(f, [a, b], [x, y], {
        extrapolateLeft: 'clamp',
        extrapolateRight: 'clamp',
    });

export const WIDE_FRAMES = 480;

export type ToolKey = 'claude' | 'chatgpt' | 'cursor' | 'copilot';
export type ArtType = 'report' | 'table' | 'mockup';
export type FlowConfig = {
    maker: {
        name: string;
        tool: ToolKey;
        toolName: string;
        prompt: string;
        artifact: string;
        type: ArtType;
        thing: string;
    };
    reader: {
        name: string;
        tool: ToolKey;
        toolName: string;
        prompt: string;
        reply: string[];
    };
    others: [
        {
            title: string;
            type: ArtType;
            who: string;
            tool: ToolKey;
            toolName: string;
        },
        {
            title: string;
            type: ArtType;
            who: string;
            tool: ToolKey;
            toolName: string;
        },
    ];
};

const TOOLS: { k: ToolKey; n: string }[] = [
    { k: 'claude', n: 'Claude' },
    { k: 'chatgpt', n: 'ChatGPT' },
    { k: 'cursor', n: 'Cursor' },
    { k: 'copilot', n: 'Copilot' },
];

const CARD_W = 420;
const CARD_Y = 30;
const CARD_H = 470;
const LX = 40;
const MX = 590;
const RX = 1140;

/* ---------- marks and glyphs ---------- */
const ToolMark: React.FC<{ k: ToolKey; size?: number; color?: string }> = ({
    k,
    size = 26,
}) => (
    <span
        style={{
            position: 'relative',
            zIndex: 2,
            display: 'inline-flex',
        }}
    >
        <AiToolIcon tool={k} size={size} />
    </span>
);

const Glyph: React.FC<{ t: ArtType; size?: number }> = ({ t, size = 26 }) => {
    const s = {
        fill: 'none',
        stroke: C.quiet,
        strokeWidth: 1.8,
        strokeLinecap: 'round' as const,
        strokeLinejoin: 'round' as const,
    };

    return (
        <svg width={size} height={size} viewBox="0 0 24 24">
            {t === 'report' ? (
                <g {...s}>
                    <path d="M6 3 H14 L18 7 V21 H6 Z" />
                    <line x1="9" y1="12" x2="15" y2="12" />
                    <line x1="9" y1="16" x2="15" y2="16" />
                </g>
            ) : null}
            {t === 'table' ? (
                <g {...s}>
                    <rect x="3.5" y="5" width="17" height="14" rx="1.5" />
                    <line x1="3.5" y1="10" x2="20.5" y2="10" />
                    <line x1="9.5" y1="5" x2="9.5" y2="19" />
                </g>
            ) : null}
            {t === 'mockup' ? (
                <g {...s}>
                    <rect x="3.5" y="4.5" width="17" height="15" rx="2" />
                    <line x1="3.5" y1="9" x2="20.5" y2="9" />
                    <rect x="7" y="12" width="6" height="4.5" rx="1" />
                </g>
            ) : null}
        </svg>
    );
};

const GlyphWell: React.FC<{ t: ArtType; size: number }> = ({ t, size }) => (
    <div
        style={{
            width: size,
            height: size,
            borderRadius: 10,
            background: C.well,
            display: 'grid',
            placeItems: 'center',
            flex: 'none',
        }}
    >
        <Glyph t={t} size={Math.round(size * 0.56)} />
    </div>
);

/* ---------- building blocks ---------- */
const Shell: React.FC<{
    x: number;
    o: number;
    dim?: number;
    children: React.ReactNode;
}> = ({ x, o, dim = 1, children }) => (
    <div
        style={{
            position: 'absolute',
            left: x,
            top: CARD_Y,
            width: CARD_W,
            height: CARD_H,
            background: C.paper,
            border: `1px solid ${C.hair}`,
            borderRadius: 16,
            boxShadow: '0 30px 70px rgba(38,38,36,0.12)',
            fontFamily: sans,
            opacity: o,
            translate: `0 ${(1 - o) * 18}px`,
            padding: 26,
        }}
    >
        {children}
        <div
            style={{
                position: 'absolute',
                inset: 0,
                borderRadius: 16,
                background: C.paper,
                opacity: 1 - dim,
                pointerEvents: 'none',
                zIndex: 1,
            }}
        />
    </div>
);

const ToolPill: React.FC<{ k: ToolKey; name: string }> = ({ k, name }) => (
    <span
        style={{
            display: 'inline-flex',
            alignItems: 'center',
            gap: 9,
            border: `1px solid ${C.hair}`,
            background: C.bone,
            borderRadius: 8,
            padding: '5px 14px 5px 10px',
            fontWeight: 600,
            fontSize: 23,
            color: C.ink,
        }}
    >
        <ToolMark k={k} size={28} />
        {name}
    </span>
);

const Head: React.FC<{
    left: React.ReactNode;
    who: string;
    right?: React.ReactNode;
}> = ({ left, who, right }) => (
    <div
        style={{
            display: 'flex',
            alignItems: 'center',
            gap: 14,
            marginBottom: 20,
            height: 44,
        }}
    >
        {left}
        <span style={{ fontSize: 23, color: C.quiet }}>{who}</span>
        <span style={{ marginLeft: 'auto' }}>{right}</span>
    </div>
);

const Bubble: React.FC<{
    text: string;
    chars: number;
    caret: boolean;
    placeholder?: string;
}> = ({ text, chars, caret, placeholder }) => (
    <div
        style={{
            background: C.well,
            borderRadius: 12,
            padding: '13px 18px',
            fontSize: 24,
            lineHeight: 1.28,
            color: chars === 0 && placeholder ? C.quiet : C.ink,
            minHeight: 82,
        }}
    >
        {chars === 0 && placeholder ? placeholder : text.slice(0, chars)}
        {caret ? (
            <span
                style={{
                    display: 'inline-block',
                    width: 2,
                    height: 26,
                    background: C.ox,
                    marginLeft: 2,
                    verticalAlign: 'middle',
                }}
            />
        ) : null}
    </div>
);

const ArtifactChip: React.FC<{
    title: string;
    type: ArtType;
    sub: string;
    w?: number;
    border?: string;
}> = ({ title, type, sub, w = 368, border = C.hair }) => (
    <div
        style={{
            width: w,
            display: 'flex',
            alignItems: 'center',
            gap: 14,
            padding: '11px 16px',
            border: `1.5px solid ${border}`,
            borderRadius: 12,
            background: C.paper,
            fontFamily: sans,
            boxShadow: '0 8px 22px rgba(38,38,36,0.10)',
        }}
    >
        <GlyphWell t={type} size={46} />
        <div>
            <div
                style={{
                    fontWeight: 600,
                    fontSize: 24,
                    color: C.ink,
                    lineHeight: 1.15,
                }}
            >
                {title}
            </div>
            <div style={{ fontSize: 19, color: C.quiet }}>{sub}</div>
        </div>
    </div>
);

const LibRow: React.FC<{
    title: string;
    type: ArtType;
    who: string;
    tool: ToolKey;
    toolName: string;
    y: number;
    o: number;
}> = ({ title, type, who, tool, toolName, y, o }) => (
    <div
        style={{
            position: 'absolute',
            left: 0,
            right: 0,
            top: y,
            height: 74,
            opacity: o,
            display: 'flex',
            alignItems: 'center',
            gap: 14,
            padding: '0 14px',
            fontFamily: sans,
        }}
    >
        <GlyphWell t={type} size={42} />
        <div>
            <div
                style={{
                    fontWeight: 600,
                    fontSize: 24,
                    color: C.ink,
                    lineHeight: 1.15,
                }}
            >
                {title}
            </div>
            <div
                style={{
                    fontSize: 19,
                    color: C.quiet,
                    display: 'flex',
                    alignItems: 'center',
                    gap: 7,
                }}
            >
                <ToolMark k={tool} size={22} />
                {toolName} · {who}
            </div>
        </div>
    </div>
);

const ArrowLabel: React.FC<{
    x1: number;
    x2: number;
    y: number;
    o: number;
    label: string;
}> = ({ x1, x2, y, o, label }) => (
    <>
        <svg
            width={1600}
            height={720}
            style={{ position: 'absolute', inset: 0, opacity: o }}
        >
            <line
                x1={x1}
                y1={y}
                x2={x2 - 12}
                y2={y}
                stroke={C.hair}
                strokeWidth={3}
            />
            <path
                d={`M${x2 - 20} ${y - 10} L${x2 - 4} ${y} L${x2 - 20} ${y + 10}`}
                fill="none"
                stroke={C.quiet}
                strokeWidth={3}
            />
        </svg>
        <div
            style={{
                position: 'absolute',
                left: x1 - 6,
                width: x2 - x1 + 12,
                top: y - 70,
                textAlign: 'center',
                fontFamily: sans,
                fontWeight: 600,
                fontSize: 20,
                lineHeight: 1.2,
                color: C.quiet,
                opacity: o,
            }}
        >
            {label}
        </div>
    </>
);

const Sfx: React.FC<{
    at: number;
    file: string;
    volume: number;
    dur?: number;
    fadeOut?: boolean;
}> = ({ at, file, volume, dur, fadeOut }) => {
    const { fps } = useVideoConfig();

    return (
        <Sequence from={at} durationInFrames={dur} premountFor={fps}>
            <Audio
                src={staticFile(`sfx/${file}`)}
                volume={
                    fadeOut && dur
                        ? (fr: number) =>
                              volume *
                              interpolate(fr, [0, dur * 0.4, dur], [1, 1, 0], {
                                  extrapolateRight: 'clamp',
                              })
                        : volume
                }
            />
        </Sequence>
    );
};

/** Both cuts tell the story in the same words. */
const CAPTION = (i: number) => caption[i];

const FlowV2: React.FC<{ cfg: FlowConfig }> = ({ cfg }) => {
    const f = useCurrentFrame();
    const m = cfg.maker;
    const r = cfg.reader;

    const step = f < 90 ? 0 : f < 195 ? 1 : f < 270 ? 2 : 3;
    const fade = interpolate(f, [0, 10, 466, 478], [0, 1, 1, 0], {
        extrapolateLeft: 'clamp',
        extrapolateRight: 'clamp',
    });

    const leftIn = ramp(f, 4, 22);
    const libIn = ramp(f, 84, 104);
    const rightIn = ramp(f, 266, 286);

    const typed1 = Math.floor(lin(f, 22, 52, 0, m.prompt.length));
    const art1 = ramp(f, 54, 72);
    const press = interpolate(f, [72, 78, 84], [1, 0.92, 1], {
        extrapolateLeft: 'clamp',
        extrapolateRight: 'clamp',
    });
    const shared = f >= 80;

    const fly1 = interpolate(f, [96, 138], [0, 1], {
        extrapolateLeft: 'clamp',
        extrapolateRight: 'clamp',
        easing: flight,
    });
    const fly1On = f >= 96 && f < 140;
    const sx = LX + 26 + 8;
    const ex = MX + 26 + 8;
    const fx1 = sx + (ex - sx) * fly1;
    const fy1 = 262 + (126 - 262) * fly1 - Math.sin(fly1 * Math.PI) * 46;

    const rowIn = ramp(f, 136, 150);
    const readP = lin(f, 150, 184, 0, 1);
    const ready = f >= 184;

    const toolsLabel = ramp(f, 198, 214);
    const toolLit = (i: number) => ramp(f, 206 + i * 12, 220 + i * 12);

    const dimLeft = 1 - 0.45 * ramp(f, 150, 170);
    const dimMid = 1 - 0.4 * ramp(f, 300, 322);

    const typed2 = Math.floor(lin(f, 296, 326, 0, r.prompt.length));
    const fly2 = interpolate(f, [332, 374], [0, 1], {
        extrapolateLeft: 'clamp',
        extrapolateRight: 'clamp',
        easing: flight,
    });
    const fly2On = f >= 332 && f < 376;
    const sx2 = MX + 26 + 8;
    const ex2 = RX + 26 + 8;
    const fx2 = sx2 + (ex2 - sx2) * fly2;
    const fy2 = 126 + (272 - 126) * fly2 - Math.sin(fly2 * Math.PI) * 46;
    const found = ramp(f, 374, 388);
    const words = Math.floor(lin(f, 392, 430, 0, r.reply.length));
    const src = ramp(f, 432, 446);

    return (
        <AbsoluteFill style={{ background: C.bone }}>
            <AbsoluteFill style={{ opacity: fade }}>
                <ArrowLabel
                    x1={LX + CARD_W + 10}
                    x2={MX - 10}
                    y={262}
                    o={libIn}
                    label="Share"
                />
                <ArrowLabel
                    x1={MX + CARD_W + 10}
                    x2={RX - 10}
                    y={262}
                    o={rightIn}
                    label="Any AI tool can find it"
                />

                {/* left: create */}
                <Shell x={LX} o={leftIn} dim={dimLeft}>
                    <Head
                        left={<ToolPill k={m.tool} name={m.toolName} />}
                        who={m.name}
                    />
                    <Bubble
                        text={m.prompt}
                        chars={typed1}
                        caret={f >= 22 && f < 56 && f % 16 < 8}
                    />
                    <div
                        style={{
                            marginTop: 22,
                            opacity: art1,
                            translate: `0 ${(1 - art1) * 14}px`,
                        }}
                    >
                        <ArtifactChip
                            title={m.artifact}
                            type={m.type}
                            sub={`Made in ${m.toolName}`}
                        />
                    </div>
                    <div
                        style={{
                            marginTop: 26,
                            display: 'flex',
                            justifyContent: 'space-between',
                            alignItems: 'center',
                            opacity: art1,
                            fontSize: 22,
                            color: shared ? C.green : C.muted,
                            fontWeight: shared ? 600 : 400,
                        }}
                    >
                        <span>
                            {shared
                                ? 'Shared with your team'
                                : 'Share with team?'}
                        </span>
                        <span
                            style={{
                                scale: String(press),
                                background: shared ? C.greenTint : C.ox,
                                color: shared ? C.green : '#fff',
                                fontWeight: 600,
                                fontSize: 21,
                                borderRadius: 8,
                                padding: '9px 20px',
                            }}
                        >
                            {shared ? 'Shared' : 'Share'}
                        </span>
                    </div>
                </Shell>

                {/* middle: library */}
                <Shell x={MX} o={libIn} dim={dimMid}>
                    <Head
                        left={
                            <span
                                style={{
                                    fontWeight: 600,
                                    fontSize: 25,
                                    color: C.ink,
                                }}
                            >
                                Team library
                            </span>
                        }
                        who=""
                    />
                    <div style={{ position: 'relative', height: 252 }}>
                        <div
                            style={{
                                position: 'absolute',
                                left: 0,
                                right: 0,
                                top: 0,
                                height: 80,
                                borderRadius: 12,
                                border: `2px dashed ${C.hair}`,
                                opacity: 1 - rowIn,
                            }}
                        />
                        <div
                            style={{
                                position: 'absolute',
                                left: 0,
                                right: 0,
                                top: 0,
                                height: 80,
                                borderRadius: 12,
                                background: C.tint,
                                boxShadow: `inset 4px 0 0 ${C.ox}`,
                                opacity: rowIn,
                            }}
                        >
                            <div
                                style={{
                                    position: 'absolute',
                                    left: 0,
                                    right: 0,
                                    top: 3,
                                }}
                            >
                                <LibRow
                                    title={m.artifact}
                                    type={m.type}
                                    who={m.name}
                                    tool={m.tool}
                                    toolName={m.toolName}
                                    y={0}
                                    o={rowIn}
                                />
                            </div>
                            <div
                                style={{
                                    position: 'absolute',
                                    right: 12,
                                    top: 42,
                                    fontSize: 17,
                                    fontWeight: 700,
                                    borderRadius: 6,
                                    padding: '3px 10px',
                                    background: ready ? '#fff' : C.well,
                                    color: ready ? C.ox : C.quiet,
                                    opacity: rowIn,
                                }}
                            >
                                {ready ? '✓ Ready' : 'Reading…'}
                            </div>
                            <div
                                style={{
                                    position: 'absolute',
                                    left: 14,
                                    right: 14,
                                    bottom: 7,
                                    height: 7,
                                    borderRadius: 4,
                                    background: 'rgba(112,26,36,0.14)',
                                    opacity: rowIn * (1 - ramp(f, 184, 198)),
                                }}
                            >
                                <div
                                    style={{
                                        width: `${readP * 100}%`,
                                        height: 7,
                                        borderRadius: 4,
                                        background: C.ox,
                                    }}
                                />
                            </div>
                        </div>
                        <LibRow
                            title={cfg.others[0].title}
                            type={cfg.others[0].type}
                            who={cfg.others[0].who}
                            tool={cfg.others[0].tool}
                            toolName={cfg.others[0].toolName}
                            y={88}
                            o={libIn}
                        />
                        <LibRow
                            title={cfg.others[1].title}
                            type={cfg.others[1].type}
                            who={cfg.others[1].who}
                            tool={cfg.others[1].tool}
                            toolName={cfg.others[1].toolName}
                            y={166}
                            o={libIn}
                        />
                    </div>
                    <div style={{ marginTop: 8, opacity: toolsLabel }}>
                        <div
                            style={{
                                fontSize: 20,
                                fontWeight: 600,
                                color: C.muted,
                                marginBottom: 10,
                            }}
                        >
                            Available to your team's AI tools
                        </div>
                        <div style={{ display: 'flex', gap: 10 }}>
                            {TOOLS.map((t, i) => {
                                const lit = toolLit(i);

                                return (
                                    <div
                                        key={t.k}
                                        style={{
                                            display: 'flex',
                                            alignItems: 'center',
                                            justifyContent: 'center',
                                            width: 86,
                                            height: 50,
                                            borderRadius: 10,
                                            border: `1.5px solid ${lit > 0.5 ? C.ox : C.hair}`,
                                            background:
                                                lit > 0.5 ? C.tint : C.bone,
                                        }}
                                    >
                                        <ToolMark k={t.k} size={32} />
                                    </div>
                                );
                            })}
                        </div>
                    </div>
                </Shell>

                {/* right: use */}
                <Shell x={RX} o={rightIn}>
                    <Head
                        left={<ToolPill k={r.tool} name={r.toolName} />}
                        who={r.name}
                        right={
                            <span
                                style={{
                                    fontSize: 18,
                                    fontWeight: 600,
                                    color: C.ox,
                                    border: `1.5px solid ${C.ox}`,
                                    borderRadius: 7,
                                    padding: '2px 10px',
                                }}
                            >
                                New chat
                            </span>
                        }
                    />
                    <Bubble
                        text={r.prompt}
                        chars={typed2}
                        caret={f >= 290 && f < 330 && f % 16 < 8}
                        placeholder="Ask anything…"
                    />
                    <div
                        style={{
                            marginTop: 16,
                            opacity: found,
                            translate: `0 ${(1 - found) * 12}px`,
                        }}
                    >
                        <div
                            style={{
                                fontSize: 20,
                                color: C.quiet,
                                marginBottom: 8,
                            }}
                        >
                            {r.toolName} found this in your team library
                        </div>
                        <ArtifactChip
                            title={m.artifact}
                            type={m.type}
                            sub={`${m.toolName} · ${m.name}`}
                            border="#E3C9CC"
                        />
                    </div>
                    <div
                        style={{
                            marginTop: 12,
                            fontSize: 25,
                            lineHeight: 1.28,
                            color: C.ink,
                            fontWeight: 500,
                            minHeight: 96,
                        }}
                    >
                        {r.reply.slice(0, words).join(' ')}
                    </div>
                    <div
                        style={{
                            opacity: src,
                            display: 'inline-block',
                            fontSize: 20,
                            color: C.ox,
                            background: C.tint,
                            border: '1px solid #E3C9CC',
                            borderRadius: 8,
                            padding: '5px 12px',
                        }}
                    >
                        Source: {m.artifact}
                    </div>
                </Shell>

                {/* flying copies */}
                {fly1On ? (
                    <div
                        style={{
                            position: 'absolute',
                            left: fx1,
                            top: fy1 - 30,
                        }}
                    >
                        <ArtifactChip
                            title={m.artifact}
                            type={m.type}
                            sub={`Made in ${m.toolName}`}
                            border={C.ox}
                            w={360}
                        />
                    </div>
                ) : null}
                {fly2On ? (
                    <div
                        style={{
                            position: 'absolute',
                            left: fx2,
                            top: fy2 - 30,
                        }}
                    >
                        <ArtifactChip
                            title={m.artifact}
                            type={m.type}
                            sub={`Made in ${m.toolName}`}
                            border={C.ox}
                            w={360}
                        />
                    </div>
                ) : null}

                {/* step dots + caption */}
                <div
                    style={{
                        position: 'absolute',
                        left: 0,
                        right: 0,
                        top: 524,
                        display: 'flex',
                        justifyContent: 'center',
                        alignItems: 'center',
                        gap: 0,
                    }}
                >
                    {[0, 1, 2, 3].map((i) => (
                        <React.Fragment key={i}>
                            {i > 0 ? (
                                <div
                                    style={{
                                        width: 54,
                                        height: 2,
                                        background: i <= step ? C.ox : C.hair,
                                    }}
                                />
                            ) : null}
                            <div
                                style={{
                                    width: 30,
                                    height: 30,
                                    borderRadius: 15,
                                    display: 'grid',
                                    placeItems: 'center',
                                    fontFamily: sans,
                                    fontWeight: 700,
                                    fontSize: 17,
                                    background:
                                        i === step
                                            ? C.ox
                                            : i < step
                                              ? C.tint
                                              : C.well,
                                    color:
                                        i === step
                                            ? '#fff'
                                            : i < step
                                              ? C.ox
                                              : C.quiet,
                                }}
                            >
                                {i + 1}
                            </div>
                        </React.Fragment>
                    ))}
                </div>
                <div
                    style={{
                        position: 'absolute',
                        left: 60,
                        right: 60,
                        top: 576,
                        textAlign: 'center',
                        fontFamily: serif,
                        fontWeight: 400,
                        fontSize: 42,
                        lineHeight: 1.15,
                        letterSpacing: '-0.01em',
                        color: C.ink,
                    }}
                >
                    {CAPTION(step)}
                </div>
            </AbsoluteFill>

            <Sfx at={22} file="typing.wav" volume={0.3} dur={32} />
            <Sfx at={56} file="switch.wav" volume={0.18} dur={20} />
            <Sfx at={77} file="mouse-click.wav" volume={0.5} />
            <Sfx at={96} file="whoosh.wav" volume={0.18} dur={42} />
            <Sfx at={138} file="switch.wav" volume={0.3} dur={24} />
            <Sfx at={184} file="tick.wav" volume={0.55} />
            <Sfx at={206} file="tick.wav" volume={0.3} />
            <Sfx at={218} file="tick.wav" volume={0.3} />
            <Sfx at={230} file="tick.wav" volume={0.3} />
            <Sfx at={242} file="tick.wav" volume={0.3} />
            <Sfx at={296} file="typing.wav" volume={0.3} dur={32} />
            <Sfx at={332} file="whoosh.wav" volume={0.18} dur={42} />
            <Sfx at={374} file="ding.wav" volume={0.12} dur={26} fadeOut />
            <Sfx at={434} file="ding.wav" volume={0.22} dur={34} fadeOut />
        </AbsoluteFill>
    );
};

const FLOW_A: FlowConfig = {
    maker: {
        name: 'Marketing',
        tool: 'claude',
        toolName: 'Claude',
        prompt: 'Write up what we learned about pricing',
        artifact: 'Pricing teardown',
        type: 'report',
        thing: 'pricing report',
    },
    reader: {
        name: 'Product',
        tool: 'cursor',
        toolName: 'Cursor',
        prompt: 'Draft the launch pricing page',
        reply: 'Lead with the annual plan, as the pricing teardown recommends.'.split(
            ' ',
        ),
    },
    others: [
        {
            title: 'Brand voice guide',
            type: 'report',
            who: 'Design',
            tool: 'chatgpt',
            toolName: 'ChatGPT',
        },
        {
            title: 'Launch page mockup',
            type: 'mockup',
            who: 'Engineering',
            tool: 'cursor',
            toolName: 'Cursor',
        },
    ],
};

export type LandingFlowWideProps = {
    fonts: FontAsset[];
};

export const defaultLandingFlowWideProps: LandingFlowWideProps = {
    fonts: [],
};

export const LandingFlowWide: React.FC<LandingFlowWideProps> = ({ fonts }) => {
    useEffect(() => {
        for (const font of fonts) {
            void loadFont({
                family: font.family,
                url: staticFile(font.url),
                weight: font.weight,
                style: font.style ?? 'normal',
            });
        }
    }, [fonts]);

    return <FlowV2 cfg={FLOW_A} />;
};
