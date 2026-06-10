const ARTFCT_LOGO_SOURCE = [
    ' █████╗ ██████╗ ████████╗███████╗ ██████╗████████╗',
    '██╔══██╗██╔══██╗╚══██╔══╝██╔════╝██╔════╝╚══██╔══╝',
    '███████║██████╔╝   ██║   █████╗  ██║        ██║',
    '██╔══██║██╔══██╗   ██║   ██╔══╝  ██║        ██║',
    '██║  ██║██║  ██║   ██║   ██║     ╚██████╗   ██║',
    '╚═╝  ╚═╝╚═╝  ╚═╝   ╚═╝   ╚═╝      ╚═════╝   ╚═╝',
    '',
] as const;

export const ARTFCT_LOGO_COLUMNS = 50;

export const ARTFCT_LOGO_LINES = ARTFCT_LOGO_SOURCE.map((line) =>
    line.padEnd(ARTFCT_LOGO_COLUMNS, ' '),
);

export const ARTFCT_LOGO_TEXT = ARTFCT_LOGO_LINES.join('\n');

export function AsciiLogo({
    colorA,
    colorB,
    className,
}: {
    colorA: string;
    colorB: string;
    className?: string;
}) {
    return (
        <pre
            aria-label="artfct"
            className={className}
            style={{
                display: 'block',
                width: `${ARTFCT_LOGO_COLUMNS}ch`,
                maxWidth: '100%',
                overflow: 'visible',
                margin: 0,
                padding: 0,
                fontFamily:
                    'ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace',
                fontSize: 'clamp(8px, 2.65vw, 16px)',
                fontKerning: 'none',
                fontVariantLigatures: 'none',
                lineHeight: 1,
                letterSpacing: 0,
                textAlign: 'left',
                whiteSpace: 'pre',
                background: `linear-gradient(to right, ${colorA}, ${colorB})`,
                backgroundClip: 'text',
                WebkitBackgroundClip: 'text',
                WebkitTextFillColor: 'transparent',
                userSelect: 'none',
            }}
        >
            {ARTFCT_LOGO_TEXT}
        </pre>
    );
}
