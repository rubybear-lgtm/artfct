import { useState } from 'react';
import { Button } from '@/components/ui/button';

export function WelcomeAgentPrompt({ mcpEndpoint }: { mcpEndpoint: string }) {
    const [expanded, setExpanded] = useState(false);
    const [copied, setCopied] = useState(false);
    const connectionUrl = mcpEndpoint;

    const toggle = async (): Promise<void> => {
        const nextState = !expanded;
        setExpanded(nextState);

        if (nextState) {
            await navigator.clipboard.writeText(
                `To let you share to artfct directly, add this connection address in your settings and approve the sign-in in my browser:\n${connectionUrl}`,
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
                        add this connection address in your tool&apos;s
                        settings, then approve the sign-in in your browser:
                    </span>
                    <span className="welcome-agent-command">
                        {connectionUrl}
                    </span>
                </div>
            )}
        </>
    );
}
