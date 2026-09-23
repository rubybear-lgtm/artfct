import { Link } from '@inertiajs/react';
import { WelcomeAgentPrompt } from '@/pages/welcome/welcome-agent-prompt';
import { WelcomeInstallOptions } from '@/pages/welcome/welcome-install-options';
import { docs } from '@/routes';

export function WelcomeCliCallout() {
    return (
        <div className="welcome-cli-callout">
            <span className="welcome-cli-heading">cli, mcp & skills</span>
            <span className="welcome-cli-description">
                install the CLI, configure MCP for native agent tool calls, or
                add the artfct skills to guide your agent&apos;s deployment
                workflows.
            </span>

            <div className="welcome-cli-options">
                <WelcomeAgentPrompt />
                <WelcomeInstallOptions />
            </div>

            <Link href={`${docs.url()}#cli`} className="welcome-cli-docs-link">
                install & usage docs
            </Link>
        </div>
    );
}
