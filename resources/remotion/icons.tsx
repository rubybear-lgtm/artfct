import type { CSSProperties, ReactNode } from 'react';

import { AiToolIcon } from '../js/components/ai-tool-icon';
import { u } from './theme';

type GlyphProps = {
    size: number;
    color: string;
    weight?: number;
};

export function DocGlyph({ size, color, weight = 1.4 }: GlyphProps) {
    return (
        <svg
            width={size}
            height={size}
            viewBox="0 0 24 24"
            fill="none"
            stroke={color}
            strokeWidth={weight}
            strokeLinecap="round"
            strokeLinejoin="round"
            aria-hidden="true"
        >
            <path d="M6.8 3.6h6.4L18 8.4v12H6.8Z" />
            <path d="M13.2 3.6v4.8H18" />
            <path d="M9.6 13.2h5.4M9.6 16.8h3.6" />
        </svg>
    );
}

export function CheckGlyph({ size, color, weight = 2.2 }: GlyphProps) {
    return (
        <svg
            width={size}
            height={size}
            viewBox="0 0 24 24"
            fill="none"
            stroke={color}
            strokeWidth={weight}
            strokeLinecap="round"
            strokeLinejoin="round"
            aria-hidden="true"
        >
            <path d="M4.5 12.8 9.4 17.7 19.5 6.8" />
        </svg>
    );
}

export function ChevronGlyph({ size, color, weight = 1.8 }: GlyphProps) {
    return (
        <svg
            width={size}
            height={size}
            viewBox="0 0 24 24"
            fill="none"
            stroke={color}
            strokeWidth={weight}
            strokeLinecap="round"
            strokeLinejoin="round"
            aria-hidden="true"
        >
            <path d="M6.5 9.5 12 15l5.5-5.5" />
        </svg>
    );
}

/**
 * A product mark for one AI tool. The ChatGPT mark follows `color`; the other
 * marks keep their own brand colours.
 */
function toolMark(tool: string) {
    return function ToolMark({ size, color }: GlyphProps) {
        return (
            <span
                style={{
                    position: 'relative',
                    zIndex: 2,
                    display: 'inline-flex',
                    color,
                }}
            >
                <AiToolIcon tool={tool} size={size} />
            </span>
        );
    };
}

export const glyphs = {
    star: toolMark('claude'),
    chat: toolMark('chatgpt'),
    pointer: toolMark('cursor'),
    spark: toolMark('copilot'),
} as const;

export type GlyphName = keyof typeof glyphs;

export function Stack({
    children,
    gap,
    style,
}: {
    children: ReactNode;
    gap: number;
    style?: CSSProperties;
}) {
    return (
        <div
            style={{
                display: 'flex',
                flexDirection: 'column',
                gap: u(gap),
                ...style,
            }}
        >
            {children}
        </div>
    );
}

export function Row({
    children,
    gap = 0,
    style,
}: {
    children: ReactNode;
    gap?: number;
    style?: CSSProperties;
}) {
    return (
        <div
            style={{
                display: 'flex',
                alignItems: 'center',
                gap: u(gap),
                ...style,
            }}
        >
            {children}
        </div>
    );
}
