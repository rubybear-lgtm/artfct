import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import type { FormEvent } from 'react';

import AuthenticationController from '@/actions/App/Http/Controllers/Teams/AuthenticationController';
import { Alert } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import ssoRoutes from '@/routes/sso';
import teamRoutes from '@/routes/teams';

type Mode = 'authkit' | 'dual' | 'polis';

interface Preview {
    allowed: boolean;
    reason: string | null;
    needsConfirmation: boolean;
    atRisk: string[];
}

interface Props {
    team: { slug: string; name: string };
    isEnterprise: boolean;
    authMode: Mode;
    domains: {
        id: number;
        domain: string;
        verificationToken: string;
        txtRecordName: string;
        verified: boolean;
    }[];
    previews: Record<Mode, Preview>;
    connections: { type: string; name: string }[] | null;
}

const MODE_LABELS: Record<Mode, string> = {
    authkit: 'Standard sign-in',
    dual: 'Standard sign-in or SSO',
    polis: 'SSO only',
};

export default function Authentication({
    team,
    isEnterprise,
    authMode,
    domains,
    previews,
    connections,
}: Props) {
    const domainForm = useForm({ domain: '' });
    const connectionForm = useForm({ metadata_url: '' });
    const [confirmed, setConfirmed] = useState(false);

    const addDomain = (event: FormEvent) => {
        event.preventDefault();
        domainForm.post(teamRoutes.domains.store.url({ team: team.slug }), {
            onSuccess: () => domainForm.reset(),
        });
    };

    const addConnection = (event: FormEvent) => {
        event.preventDefault();
        connectionForm.post(
            AuthenticationController.storeConnection.url({ team: team.slug }),
            {
                onSuccess: () => connectionForm.reset(),
            },
        );
    };

    const switchTo = (mode: Mode) =>
        router.patch(teamRoutes.authMode.update.url({ team: team.slug }), {
            auth_mode: mode,
            confirmed,
        });

    return (
        <>
            <Head title="Authentication" />
            <h1 className="mb-1 text-2xl font-semibold">Authentication</h1>
            <p className="mb-6 text-sm text-muted-foreground">
                How people sign in to {team.name}.
            </p>

            {!isEnterprise && (
                <Alert>
                    Single sign-on is an Enterprise feature.{' '}
                    <Link
                        className="underline"
                        href={teamRoutes.billing.show.url({ team: team.slug })}
                    >
                        See plans
                    </Link>
                    . Standard sign-in stays available.
                </Alert>
            )}

            <div className="mt-4 flex flex-col gap-6">
                <Card>
                    <CardHeader>
                        <CardTitle>Verified domains</CardTitle>
                        <CardDescription>
                            SSO can only be turned on for a domain you have
                            proven you control, by adding a DNS TXT record.
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="flex flex-col gap-4">
                        {domains.length === 0 && (
                            <EmptyState title="No domains yet." />
                        )}
                        {domains.map((domain) => (
                            <div key={domain.id} className="text-sm">
                                <div className="flex items-center gap-2">
                                    <span className="font-medium">
                                        {domain.domain}
                                    </span>
                                    <Badge
                                        variant={
                                            domain.verified
                                                ? 'success'
                                                : 'outline'
                                        }
                                    >
                                        {domain.verified
                                            ? 'verified'
                                            : 'pending'}
                                    </Badge>
                                    <span className="ml-auto flex gap-2">
                                        {!domain.verified && (
                                            <Button
                                                size="sm"
                                                variant="outline"
                                                onClick={() =>
                                                    router.post(
                                                        teamRoutes.domains.verify.url(
                                                            {
                                                                team: team.slug,
                                                                domain: domain.id,
                                                            },
                                                        ),
                                                    )
                                                }
                                            >
                                                Verify
                                            </Button>
                                        )}
                                        <Button
                                            size="sm"
                                            variant="ghost"
                                            onClick={() =>
                                                router.delete(
                                                    teamRoutes.domains.destroy.url(
                                                        {
                                                            team: team.slug,
                                                            domain: domain.id,
                                                        },
                                                    ),
                                                )
                                            }
                                        >
                                            Remove
                                        </Button>
                                    </span>
                                </div>
                                {!domain.verified && (
                                    <p className="mt-1 text-xs text-muted-foreground tabular-nums">
                                        Add a TXT record named{' '}
                                        {domain.txtRecordName} with the value{' '}
                                        {domain.verificationToken}
                                    </p>
                                )}
                            </div>
                        ))}
                        <form onSubmit={addDomain} className="flex gap-2">
                            <Input
                                aria-label="Domain"
                                placeholder="acme.com"
                                value={domainForm.data.domain}
                                onChange={(e) =>
                                    domainForm.setData('domain', e.target.value)
                                }
                            />
                            <Button type="submit" variant="outline">
                                Add domain
                            </Button>
                        </form>
                        {domainForm.errors.domain && (
                            <p className="text-sm text-destructive">
                                {domainForm.errors.domain}
                            </p>
                        )}
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Sign-in method</CardTitle>
                        <CardDescription>
                            Currently: <strong>{MODE_LABELS[authMode]}</strong>.
                            Each option shows what changing to it would do.
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="flex flex-col gap-4">
                        {(Object.keys(MODE_LABELS) as Mode[]).map((mode) => {
                            const preview = previews[mode];
                            const current = mode === authMode;

                            return (
                                <div
                                    key={mode}
                                    className="rounded-md border border-border p-3 text-sm"
                                >
                                    <div className="flex items-center gap-2">
                                        <span className="font-medium">
                                            {MODE_LABELS[mode]}
                                        </span>
                                        {current && <Badge>current</Badge>}
                                        {!current && (
                                            <Button
                                                className="ml-auto"
                                                size="sm"
                                                variant="outline"
                                                disabled={
                                                    !preview.allowed ||
                                                    (mode !== 'authkit' &&
                                                        !isEnterprise) ||
                                                    (preview.needsConfirmation &&
                                                        !confirmed)
                                                }
                                                onClick={() => switchTo(mode)}
                                            >
                                                Switch
                                            </Button>
                                        )}
                                    </div>
                                    {!current && !preview.allowed && (
                                        <p className="mt-2 text-destructive">
                                            {preview.reason}
                                        </p>
                                    )}
                                    {!current && preview.needsConfirmation && (
                                        <div className="mt-2">
                                            <p>
                                                These members have no SSO
                                                identity and will lose access:{' '}
                                                {preview.atRisk.join(', ')}
                                            </p>
                                            <label className="mt-1 flex items-center gap-2">
                                                <input
                                                    type="checkbox"
                                                    checked={confirmed}
                                                    onChange={(e) =>
                                                        setConfirmed(
                                                            e.target.checked,
                                                        )
                                                    }
                                                />
                                                I understand and want to
                                                continue
                                            </label>
                                        </div>
                                    )}
                                    {!current &&
                                        preview.allowed &&
                                        !preview.needsConfirmation &&
                                        mode === 'authkit' && (
                                            <p className="mt-2 text-muted-foreground">
                                                Safe to switch back at any time;
                                                no data is lost.
                                            </p>
                                        )}
                                </div>
                            );
                        })}
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>SSO connection</CardTitle>
                        <CardDescription>
                            Connect your identity provider (SAML) with its
                            metadata URL, then test a sign-in before enforcing
                            it.
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="flex flex-col gap-4">
                        {connections === null ? (
                            <p className="text-sm text-muted-foreground">
                                Connection status is unavailable right now.
                            </p>
                        ) : connections.length === 0 ? (
                            <EmptyState title="No identity provider is connected." />
                        ) : (
                            <ul className="text-sm">
                                {connections.map((connection, index) => (
                                    <li
                                        key={index}
                                        className="flex items-center gap-2"
                                    >
                                        <Badge variant="success">
                                            connected
                                        </Badge>
                                        {connection.type} · {connection.name}
                                    </li>
                                ))}
                            </ul>
                        )}
                        {isEnterprise && (
                            <form
                                onSubmit={addConnection}
                                className="flex gap-2"
                            >
                                <Input
                                    aria-label="SAML metadata URL"
                                    placeholder="https://idp.example.com/metadata"
                                    value={connectionForm.data.metadata_url}
                                    onChange={(e) =>
                                        connectionForm.setData(
                                            'metadata_url',
                                            e.target.value,
                                        )
                                    }
                                />
                                <Button type="submit" variant="outline">
                                    Connect
                                </Button>
                            </form>
                        )}
                        {connectionForm.errors.metadata_url && (
                            <p className="text-sm text-destructive">
                                {connectionForm.errors.metadata_url}
                            </p>
                        )}
                        <div className="flex gap-2">
                            {connections && connections.length > 0 && (
                                <>
                                    <Button asChild variant="outline" size="sm">
                                        <a
                                            href={ssoRoutes.login.url({
                                                team: team.slug,
                                            })}
                                        >
                                            Test sign-in
                                        </a>
                                    </Button>
                                    <Button
                                        size="sm"
                                        variant="ghost"
                                        onClick={() =>
                                            router.delete(
                                                AuthenticationController.destroyConnection.url(
                                                    { team: team.slug },
                                                ),
                                            )
                                        }
                                    >
                                        Remove connection
                                    </Button>
                                </>
                            )}
                        </div>
                    </CardContent>
                </Card>
            </div>
        </>
    );
}

Authentication.layout = (page: React.ReactNode) => (
    <AppLayout>{page}</AppLayout>
);
