import { Link } from '@inertiajs/react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { docs } from '@/routes';

const MONO = 'ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace';

const S = {
    base3: 'var(--sol-base3)',
    base2: 'var(--sol-base2)',
    base1: 'var(--sol-base1)',
    base00: 'var(--sol-base00)',
    base0: 'var(--sol-base0)',
    blue: 'var(--sol-blue)',
    green: 'var(--sol-green)',
} as const;

interface InstallCommandProps {
    command: string;
    copied: boolean;
    onCopy: () => void;
}

function InstallCommand({ command, copied, onCopy }: InstallCommandProps) {
    return (
        <div
            style={{
                display: 'flex',
                border: `1px solid ${S.base1}`,
                borderRadius: '2px',
                backgroundColor: S.base3,
            }}
        >
            <pre
                style={{
                    fontFamily: MONO,
                    fontSize: '11px',
                    color: S.base0,
                    padding: '0.6rem 0.8rem',
                    margin: 0,
                    flexGrow: 1,
                    overflowX: 'auto',
                }}
            >
                <span style={{ color: S.base1 }}>$ </span>
                {command}
            </pre>
            <Button
                onClick={onCopy}
                style={{
                    padding: '0 0.8rem',
                    fontFamily: MONO,
                    fontSize: '11px',
                    backgroundColor: copied ? S.green : S.base2,
                    color: copied ? S.base3 : S.base00,
                    border: 'none',
                    borderLeft: `1px solid ${S.base1}`,
                    cursor: 'pointer',
                }}
            >
                {copied ? 'copied' : 'copy'}
            </Button>
        </div>
    );
}

export function WelcomeCliCallout() {
    const [mcpExpanded, setMcpExpanded] = useState(false);
    const [copiedAgentPrompt, setCopiedAgentPrompt] = useState(false);
    const [copiedSelfInstall, setCopiedSelfInstall] = useState(false);
    const [copiedSkillsInstall, setCopiedSkillsInstall] = useState(false);

    const copy = async (
        value: string,
        setCopied: (copied: boolean) => void,
    ): Promise<void> => {
        await navigator.clipboard.writeText(value);
        setCopied(true);
        setTimeout(() => setCopied(false), 2000);
    };

    const skillInstall = 'npx skills add rubybear-lgtm/artfct@artfct';
    const cliInstall = 'curl -fsSL https://artfct.dev/install.sh | sh';

    return (
        <div
            style={{
                width: '100%',
                paddingTop: '1.5rem',
                borderTop: `1px solid ${S.base2}`,
                display: 'flex',
                flexDirection: 'column',
                gap: '0.75rem',
            }}
        >
            <span
                style={{
                    fontFamily: MONO,
                    fontSize: '12px',
                    color: S.base00,
                }}
            >
                cli, mcp & skills
            </span>
            <span
                style={{
                    fontFamily: MONO,
                    fontSize: '12px',
                    color: S.base1,
                }}
            >
                install the CLI, configure MCP for native agent tool calls, or
                add the artfct skills to guide your agent&apos;s deployment
                workflows.
            </span>

            <div
                style={{
                    display: 'flex',
                    flexDirection: 'column',
                    gap: '0.6rem',
                    marginTop: '0.25rem',
                }}
            >
                <div
                    style={{
                        display: 'flex',
                        alignItems: 'center',
                        gap: '0.75rem',
                    }}
                >
                    <Button
                        onClick={async () => {
                            const nextState = !mcpExpanded;
                            setMcpExpanded(nextState);

                            if (nextState) {
                                await copy(
                                    `Please add the artfct skill to guide your deployment workflows:\nnpx skills add rubybear-lgtm/artfct@artfct\n\nNote: The MCP server is not required to use the skill, but is highly encouraged for native agent tool calls:\ncurl -fsSL https://artfct.dev/install.sh | sh && artfct setup`,
                                    setCopiedAgentPrompt,
                                );
                            }
                        }}
                        className="result-action-btn"
                        style={{
                            padding: '0.4rem 0.8rem',
                            fontFamily: MONO,
                            fontSize: '11px',
                            backgroundColor: mcpExpanded ? S.base1 : S.base2,
                            color: mcpExpanded ? S.base3 : S.base00,
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
                            {mcpExpanded ? '▲' : '▼'}
                        </span>
                    </Button>
                    {copiedAgentPrompt && (
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

                {mcpExpanded && (
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
                            guidance. MCP is not a requirement to the skills
                            (they fall back to API deploys), but it is highly
                            encouraged for a native tool call:
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

                <div
                    style={{
                        display: 'flex',
                        flexDirection: 'column',
                        gap: '0.4rem',
                        marginTop: '0.5rem',
                    }}
                >
                    <span
                        style={{
                            fontFamily: MONO,
                            fontSize: '11px',
                            color: S.base1,
                        }}
                    >
                        or run the command to install it yourself:
                    </span>
                    <InstallCommand
                        command={cliInstall}
                        copied={copiedSelfInstall}
                        onCopy={() => copy(cliInstall, setCopiedSelfInstall)}
                    />
                </div>

                <div
                    style={{
                        display: 'flex',
                        flexDirection: 'column',
                        gap: '0.4rem',
                        marginTop: '0.5rem',
                    }}
                >
                    <span
                        style={{
                            fontFamily: MONO,
                            fontSize: '11px',
                            color: S.base1,
                        }}
                    >
                        or install only the agent skills:
                    </span>
                    <InstallCommand
                        command={skillInstall}
                        copied={copiedSkillsInstall}
                        onCopy={() =>
                            copy(skillInstall, setCopiedSkillsInstall)
                        }
                    />
                </div>
            </div>

            <Link
                href={`${docs.url()}#cli`}
                style={{
                    fontFamily: MONO,
                    fontSize: '12px',
                    color: S.blue,
                    textDecoration: 'none',
                    marginTop: '0.25rem',
                }}
            >
                install & usage docs
            </Link>
        </div>
    );
}
