import { Link } from '@inertiajs/react';
import { WelcomeAgentPrompt } from '@/pages/welcome/welcome-agent-prompt';
import { WelcomeInstallOptions } from '@/pages/welcome/welcome-install-options';
import { docs } from '@/routes';

const MONO = 'ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace';

const S = {
    base3: 'var(--sol-base3)',
    base2: 'var(--sol-base2)',
    base1: 'var(--sol-base1)',
    base00: 'var(--sol-base00)',
    blue: 'var(--sol-blue)',
} as const;

export function WelcomeCliCallout() {
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
                <WelcomeAgentPrompt />
                <WelcomeInstallOptions />
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
