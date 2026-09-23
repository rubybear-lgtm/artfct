import { useState } from 'react';
import { Button } from '@/components/ui/button';

const MONO = 'ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace';

const S = {
    base3: 'var(--sol-base3)',
    base2: 'var(--sol-base2)',
    base1: 'var(--sol-base1)',
    base00: 'var(--sol-base00)',
    base0: 'var(--sol-base0)',
    green: 'var(--sol-green)',
} as const;

export function WelcomeAgentPrompt() {
    const [expanded, setExpanded] = useState(false);
    const [copied, setCopied] = useState(false);
    const skillInstall = 'npx skills add rubybear-lgtm/artfct@artfct';
    const cliInstall = 'curl -fsSL https://artfct.dev/install.sh | sh';

    const toggle = async (): Promise<void> => {
        const nextState = !expanded;
        setExpanded(nextState);

        if (nextState) {
            await navigator.clipboard.writeText(
                `Please add the artfct skill to guide your deployment workflows:\n${skillInstall}\n\nNote: The MCP server is not required to use the skill, but is highly encouraged for native agent tool calls:\n${cliInstall} && artfct setup`,
            );
            setCopied(true);
            setTimeout(() => setCopied(false), 2000);
        }
    };

    return (
        <>
            <div
                style={{
                    display: 'flex',
                    alignItems: 'center',
                    gap: '0.75rem',
                }}
            >
                <Button
                    onClick={toggle}
                    className="result-action-btn"
                    style={{
                        padding: '0.4rem 0.8rem',
                        fontFamily: MONO,
                        fontSize: '11px',
                        backgroundColor: expanded ? S.base1 : S.base2,
                        color: expanded ? S.base3 : S.base00,
                        border: `1px solid ${S.base1}`,
                        borderRadius: '3px',
                        cursor: 'pointer',
                        letterSpacing: '0.04em',
                        display: 'inline-flex',
                        alignItems: 'center',
                        gap: '0.4rem',
                    }}
                >
                    <span>ask an ai agent</span>
                    <span style={{ fontSize: '9px', opacity: 0.8 }}>
                        {expanded ? '▲' : '▼'}
                    </span>
                </Button>
                {copied && (
                    <span
                        className="fade-in"
                        style={{
                            fontFamily: MONO,
                            fontSize: '11px',
                            color: S.green,
                        }}
                    >
                        copied prompt to clipboard!
                    </span>
                )}
            </div>

            {expanded && (
                <div
                    className="fade-in"
                    style={{
                        padding: '0.9rem 1rem',
                        backgroundColor: S.base2,
                        border: `1px solid ${S.base1}`,
                        borderRadius: '3px',
                        display: 'flex',
                        flexDirection: 'column',
                        gap: '0.4rem',
                    }}
                >
                    <span
                        style={{
                            fontFamily: MONO,
                            fontSize: '11px',
                            color: S.base1,
                        }}
                    >
                        install the artfct skill to give your agent built-in
                        guidance. MCP is not a requirement to the skills (they
                        fall back to API deploys), but it is highly encouraged
                        for a native tool call:
                    </span>
                    <pre
                        style={{
                            fontFamily: MONO,
                            fontSize: '11px',
                            color: S.base0,
                            backgroundColor: S.base3,
                            padding: '0.75rem',
                            margin: 0,
                            borderRadius: '2px',
                            border: `1px solid ${S.base2}`,
                            whiteSpace: 'pre-wrap',
                            wordBreak: 'break-word',
                            lineHeight: 1.5,
                        }}
                    >
                        {`# 1. install the skill (MCP optional but encouraged):
${skillInstall}

# 2. (optional but highly encouraged) setup MCP for native tool calls:
${cliInstall} && artfct setup`}
                    </pre>
                </div>
            )}
        </>
    );
}
