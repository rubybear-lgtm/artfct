import { useState } from 'react';
import { Button } from '@/components/ui/button';

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
            <div className="welcome-agent-toggle-row">
                <Button
                    onClick={toggle}
                    className={`result-action-btn welcome-agent-toggle ${expanded ? 'is-expanded' : ''}`}
                >
                    <span>ask an ai agent</span>
                    <span className="welcome-agent-toggle-icon">
                        {expanded ? '▲' : '▼'}
                    </span>
                </Button>
                {copied && (
                    <span className="fade-in welcome-agent-copied">
                        copied prompt to clipboard!
                    </span>
                )}
            </div>

            {expanded && (
                <div className="fade-in welcome-agent-details">
                    <span className="welcome-agent-description">
                        install the artfct skill to give your agent built-in
                        guidance. MCP is not a requirement to the skills (they
                        fall back to API deploys), but it is highly encouraged
                        for a native tool call:
                    </span>
                    <pre className="welcome-agent-command">
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
