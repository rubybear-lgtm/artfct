import { Link } from '@inertiajs/react';
import { WelcomeAgentPrompt } from '@/pages/welcome/welcome-agent-prompt';
import { docs } from '@/routes';

export function WelcomeCliCallout({ mcpEndpoint }: { mcpEndpoint: string }) {
    return (
        <div className="welcome-cli-callout">
            <span className="welcome-cli-heading">connect your ai tool</span>
            <span className="welcome-cli-description">
                add this connection address in your AI tool&apos;s settings and
                approve the sign-in.
            </span>

            <div className="welcome-cli-options">
                <WelcomeAgentPrompt mcpEndpoint={mcpEndpoint} />
            </div>

            <Link href={`${docs.url()}#mcp`} className="welcome-cli-docs-link">
                connection docs
            </Link>
        </div>
    );
}
