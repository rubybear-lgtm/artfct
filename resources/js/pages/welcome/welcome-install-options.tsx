import { useState } from 'react';
import { Button } from '@/components/ui/button';

function InstallCommand({ command }: { command: string }) {
    const [copied, setCopied] = useState(false);

    const copy = async (): Promise<void> => {
        await navigator.clipboard.writeText(command);
        setCopied(true);
        setTimeout(() => setCopied(false), 2000);
    };

    return (
        <div className="welcome-install-command">
            <pre className="welcome-install-code">
                <span className="welcome-install-prompt">$ </span>
                {command}
            </pre>
            <Button
                onClick={copy}
                className={`welcome-install-copy ${copied ? 'is-copied' : ''}`}
            >
                {copied ? 'copied' : 'copy'}
            </Button>
        </div>
    );
}

function InstallOption({
    description,
    command,
}: {
    description: string;
    command: string;
}) {
    return (
        <div className="welcome-install-option">
            <span className="welcome-install-description">{description}</span>
            <InstallCommand command={command} />
        </div>
    );
}

export function WelcomeInstallOptions() {
    return (
        <>
            <InstallOption
                description="or run the command to install it yourself:"
                command="curl -fsSL https://artfct.dev/install.sh | sh"
            />
            <InstallOption
                description="or install only the agent skills:"
                command="npx skills add rubybear-lgtm/artfct@artfct"
            />
        </>
    );
}
