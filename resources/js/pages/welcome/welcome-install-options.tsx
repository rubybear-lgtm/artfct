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

function InstallCommand({ command }: { command: string }) {
    const [copied, setCopied] = useState(false);

    const copy = async (): Promise<void> => {
        await navigator.clipboard.writeText(command);
        setCopied(true);
        setTimeout(() => setCopied(false), 2000);
    };

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
                onClick={copy}
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

export function WelcomeInstallOptions() {
    return (
        <>
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
                <InstallCommand command="curl -fsSL https://artfct.dev/install.sh | sh" />
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
                <InstallCommand command="npx skills add rubybear-lgtm/artfct@artfct" />
            </div>
        </>
    );
}
