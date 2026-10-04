import { useState } from 'react';
import { Button } from '@/components/ui/button';

export function WelcomeAgentPrompt({ mcpEndpoint }: { mcpEndpoint: string }) {
    const [expanded, setExpanded] = useState(false);
    const [copied, setCopied] = useState(false);
    const skillInstall = 'npx skills add rubybear-lgtm/artfct@artfct';
    const connectionUrl = mcpEndpoint;

    const toggle = async (): Promise<void> => {
        const nextState = !expanded;
        setExpanded(nextState);

        if (nextState) {
            await navigator.clipboard.writeText(
                `Please add the artfct skill to guide your deployment workflows:\n${skillInstall}\n\nTo let you deploy to artfct directly, add this connection URL in your settings and approve the sign-in in my browser:\n${connectionUrl}`,
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
                        guidance (optional). to let it deploy directly, add the
                        connection url in its settings and approve the sign-in
                        in your browser. connecting needs no install:
                    </span>
                    <pre className="welcome-agent-command">
                        {`# 1. (optional) add the skill:
${skillInstall}

# 2. connect your agent (add this url, then approve the sign-in):
${connectionUrl}`}
                    </pre>
                </div>
            )}
        </>
    );
}
