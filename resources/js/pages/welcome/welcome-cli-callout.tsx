import { Link } from '@inertiajs/react';
import { WelcomeAgentPrompt } from '@/pages/welcome/welcome-agent-prompt';
import { WelcomeInstallOptions } from '@/pages/welcome/welcome-install-options';
import { docs } from '@/routes';

export function WelcomeCliCallout({ mcpEndpoint }: { mcpEndpoint: string }) {
    return (
        <div className="welcome-cli-callout">
            <span className="welcome-cli-heading">connect your ai agent</span>
            <span className="welcome-cli-description">
                add the artfct connection url to your agent, or add the artfct
                skills to guide its deployment workflows.
            </span>

            <div className="welcome-cli-options">
                <WelcomeAgentPrompt mcpEndpoint={mcpEndpoint} />
                <WelcomeInstallOptions />
            </div>

            <Link href={`${docs.url()}#mcp`} className="welcome-cli-docs-link">
                connection docs
            </Link>
        </div>
    );
}
