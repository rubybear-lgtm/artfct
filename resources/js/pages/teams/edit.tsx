import { Head, router, useForm, usePage } from '@inertiajs/react';
import { Bot, Copy } from 'lucide-react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import { toast } from 'sonner';

import AuthModeController from '@/actions/App/Http/Controllers/Teams/AuthModeController';
import TeamController from '@/actions/App/Http/Controllers/Teams/TeamController';
import TeamDomainController from '@/actions/App/Http/Controllers/Teams/TeamDomainController';
import TeamInvitationController from '@/actions/App/Http/Controllers/Teams/TeamInvitationController';
import TeamMemberController from '@/actions/App/Http/Controllers/Teams/TeamMemberController';
import TeamOwnerController from '@/actions/App/Http/Controllers/Teams/TeamOwnerController';
import { Alert } from '@/components/ui/alert';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { ConfirmDialog } from '@/components/ui/confirm-dialog';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { PageHeader } from '@/components/ui/page-header';
import AppLayout from '@/layouts/app-layout';
import teamRoutes from '@/routes/teams';
import type { SharedProps } from '@/types/shared';

function csrfToken(): string {
    const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : '';
}

function memberInitials(name: string): string {
    return name
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part[0]?.toUpperCase() ?? '')
        .join('');
}

function errorMessageFromBody(payload: unknown, fallback: string): string {
    if (!payload || typeof payload !== 'object') {
        return fallback;
    }

    const body = payload as {
        message?: string;
        errors?: { name?: string[] };
    };

    return body.message ?? body.errors?.name?.[0] ?? fallback;
}

interface Member {
    id: number;
    name: string;
    email: string | null;
    avatar: string | null;
    isBot: boolean;
    role: string;
    role_label: string;
    deactivated: boolean;
    /** Last four of the active agent JWT, or null when none / not a bot. */
    agentTokenLastFour: string | null;
}

interface Invitation {
    code: string;
    email: string;
    role: string;
    role_label: string;
    url: string;
}

interface DomainRow {
    id: number;
    domain: string;
    verification_token: string;
    txt_record_name: string;
    verified_at: string | null;
}

interface Props {
    team: {
        id: number;
        name: string;
        slug: string;
        isPersonal: boolean;
        authMode: string;
        plan: string;
        ownerId: number | null;
        publicSharingAllowed: boolean;
    };
    viewer: {
        id: number;
        isOwner: boolean;
        canUpdateMember: boolean;
        canRemoveMember: boolean;
        canDelete: boolean;
        canTransfer: boolean;
        canLeave: boolean;
        canUpgrade: boolean;
    };
    members: Member[];
    invitations: Invitation[];
    domains: DomainRow[];
    permissions: {
        canUpdateTeam: boolean;
        canAddMember: boolean;
        canCreateInvitation: boolean;
        canChangeAuthMode: boolean;
    };
    availableRoles: { value: string; label: string }[];
    canAddBot: boolean;
    botScopeOptions: string[];
    botScopeLabels: Record<string, string>;
    botDefaultScopes: string[];
    mcpEndpoint: string;
}

const selectClass =
    'h-9 rounded-md border border-border bg-transparent px-2 text-sm max-md:min-h-[44px] max-md:text-base';

export default function TeamEdit({
    team,
    viewer,
    members,
    invitations,
    domains,
    permissions,
    availableRoles,
    canAddBot,
    botScopeOptions,
    botScopeLabels,
    botDefaultScopes,
    mcpEndpoint,
}: Props) {
    const inviteForm = useForm({ email: '', role: 'member' });
    const renameForm = useForm({ name: team.name });
    const upgradeForm = useForm({ name: '' });
    const sharingForm = useForm({
        name: team.name,
        public_sharing_allowed: team.publicSharingAllowed,
    });
    const domainForm = useForm({ domain: '' });
    const authModeForm = useForm({ auth_mode: team.authMode });
    const deleteForm = useForm({ name: '' });
    const [removing, setRemoving] = useState<Member | null>(null);
    const [cancellingInvitation, setCancellingInvitation] =
        useState<Invitation | null>(null);
    const [deleting, setDeleting] = useState(false);
    const [leaving, setLeaving] = useState(false);
    const [botName, setBotName] = useState('');
    const [botScopes, setBotScopes] = useState<string[]>(botDefaultScopes);
    const [botAvatarFile, setBotAvatarFile] = useState<File | null>(null);
    const [botBusy, setBotBusy] = useState(false);
    const [botError, setBotError] = useState<string | null>(null);
    const [createdBotToken, setCreatedBotToken] = useState<{
        name: string;
        token: string;
        mcpEndpoint: string;
        authorizationHeader: string;
    } | null>(null);
    const [rollingBot, setRollingBot] = useState<Member | null>(null);
    const [rollBusy, setRollBusy] = useState(false);
    const [avatarUploadingId, setAvatarUploadingId] = useState<number | null>(
        null,
    );
    const { errors } = usePage<SharedProps>().props;
    // Errors already shown next to their field, so they are not repeated here.
    const inlineErrorKeys = ['email', 'domain', 'auth_mode', 'name'];
    const inlineMessages = [
        inviteForm,
        renameForm,
        domainForm,
        authModeForm,
        deleteForm,
    ].flatMap((form) => Object.values(form.errors));
    const pageErrors = Object.entries(errors).filter(
        ([key, message]) =>
            !inlineErrorKeys.includes(key) && !inlineMessages.includes(message),
    );
    const admins = members.filter(
        (member) => member.role === 'admin' && member.id !== viewer.id,
    );

    const invite = (e: FormEvent) => {
        e.preventDefault();
        inviteForm.post(
            TeamInvitationController.store.url({ team: team.slug }),
            {
                onSuccess: () => inviteForm.reset('email'),
            },
        );
    };

    const copyLink = async (url: string) => {
        await navigator.clipboard.writeText(url);
        toast.success('Invitation link copied.');
    };

    return (
        <>
            <Head title={team.name} />

            <PageHeader
                eyebrow={
                    permissions.canUpdateTeam ? 'Admin · Members' : 'Workspace'
                }
                title={team.name}
                description="Manage workspace members, pending invitations, and roles."
                actions={
                    <>
                        <Badge variant="outline">{team.plan}</Badge>
                        <span className="text-sm break-all text-muted-foreground tabular-nums">
                            {team.slug}
                        </span>
                    </>
                }
            />

            <div className="flex flex-col gap-6">
                {pageErrors.length > 0 && (
                    <Alert variant="destructive">
                        <ul className="flex flex-col gap-1">
                            {pageErrors.map(([key, message]) => (
                                <li key={key}>{message}</li>
                            ))}
                        </ul>
                    </Alert>
                )}

                <Card>
                    <CardHeader>
                        <CardTitle>Team name</CardTitle>
                        <CardDescription>
                            Shown across the app and in invitation emails.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <form
                            onSubmit={(e) => {
                                e.preventDefault();
                                renameForm.patch(
                                    TeamController.update.url({
                                        team: team.slug,
                                    }),
                                );
                            }}
                            className="flex flex-wrap items-end gap-3"
                        >
                            <div className="flex min-w-64 flex-1 flex-col gap-1.5">
                                <Label htmlFor="team-name">Name</Label>
                                <Input
                                    id="team-name"
                                    name="name"
                                    value={renameForm.data.name}
                                    onChange={(e) =>
                                        renameForm.setData(
                                            'name',
                                            e.target.value,
                                        )
                                    }
                                />
                            </div>
                            <Button
                                type="submit"
                                disabled={renameForm.processing}
                            >
                                Save name
                            </Button>
                        </form>
                        {renameForm.errors.name && (
                            <p
                                role="alert"
                                className="mt-2 text-sm text-destructive"
                            >
                                {renameForm.errors.name}
                            </p>
                        )}
                    </CardContent>
                </Card>

                {viewer.canUpgrade && (
                    <Card>
                        <CardHeader>
                            <CardTitle>Turn this into a team</CardTitle>
                            <CardDescription>
                                Everything here moves with it, so your artifacts
                                stay put. You get a new personal workspace, and
                                you can invite people and start your plan next.
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <form
                                onSubmit={(e) => {
                                    e.preventDefault();
                                    upgradeForm.post(
                                        TeamController.upgrade.url({
                                            team: team.slug,
                                        }),
                                    );
                                }}
                                className="flex flex-wrap items-end gap-3"
                            >
                                <div className="flex min-w-64 flex-1 flex-col gap-1.5">
                                    <Label htmlFor="upgrade-name">
                                        Team name
                                    </Label>
                                    <Input
                                        id="upgrade-name"
                                        name="name"
                                        value={upgradeForm.data.name}
                                        onChange={(e) =>
                                            upgradeForm.setData(
                                                'name',
                                                e.target.value,
                                            )
                                        }
                                    />
                                </div>
                                <Button
                                    type="submit"
                                    disabled={upgradeForm.processing}
                                >
                                    Create team
                                </Button>
                            </form>
                            {upgradeForm.errors.name && (
                                <p
                                    role="alert"
                                    className="mt-2 text-sm text-destructive"
                                >
                                    {upgradeForm.errors.name}
                                </p>
                            )}
                        </CardContent>
                    </Card>
                )}

                {permissions.canUpdateTeam && (
                    <Card>
                        <CardHeader>
                            <CardTitle>Public links</CardTitle>
                            <CardDescription>
                                When this is off, nobody on the team can share
                                an artifact with people outside it, and
                                artifacts that are public now become visible to
                                the team only.
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <label className="flex items-center gap-3">
                                <input
                                    type="checkbox"
                                    role="switch"
                                    aria-checked={team.publicSharingAllowed}
                                    checked={team.publicSharingAllowed}
                                    disabled={sharingForm.processing}
                                    onChange={(e) => {
                                        // The switch reads from the server prop,
                                        // not local form state: a push the
                                        // Worker rejects must not leave it
                                        // showing a state the Worker refused.
                                        sharingForm.setData('name', team.name);
                                        sharingForm.setData(
                                            'public_sharing_allowed',
                                            e.target.checked,
                                        );
                                        sharingForm.patch(
                                            TeamController.update.url({
                                                team: team.slug,
                                            }),
                                            { preserveScroll: true },
                                        );
                                    }}
                                />
                                <span className="text-sm font-medium">
                                    Allow public links
                                </span>
                            </label>
                        </CardContent>
                    </Card>
                )}

                {canAddBot && (
                    <Card>
                        <CardHeader>
                            <CardTitle>Add a bot</CardTitle>
                            <CardDescription>
                                Give an AI assistant a name on this team. Bots
                                are free (no seat), need no email, and connect
                                with a Bearer token at{' '}
                                <code className="text-xs">{mcpEndpoint}</code>.
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <form
                                className="flex flex-col gap-4"
                                onSubmit={async (e) => {
                                    e.preventDefault();
                                    setBotBusy(true);
                                    setBotError(null);

                                    const headers: Record<string, string> = {
                                        Accept: 'application/json',
                                        'X-XSRF-TOKEN': csrfToken(),
                                    };
                                    let body: BodyInit;

                                    if (botAvatarFile) {
                                        const formData = new FormData();
                                        formData.append('name', botName);
                                        botScopes.forEach((scope) =>
                                            formData.append('scopes[]', scope),
                                        );
                                        formData.append(
                                            'avatar',
                                            botAvatarFile,
                                        );
                                        body = formData;
                                    } else {
                                        headers['Content-Type'] =
                                            'application/json';
                                        body = JSON.stringify({
                                            name: botName,
                                            scopes: botScopes,
                                        });
                                    }

                                    try {
                                        const response = await fetch(
                                            teamRoutes.bots.store.url({
                                                team: team.slug,
                                            }),
                                            {
                                                method: 'POST',
                                                headers,
                                                body,
                                            },
                                        );

                                        if (!response.ok) {
                                            const payload = await response
                                                .json()
                                                .catch(() => null);
                                            setBotError(
                                                errorMessageFromBody(
                                                    payload,
                                                    `Could not add the bot (HTTP ${response.status}).`,
                                                ),
                                            );

                                            return;
                                        }

                                        const payload = await response.json();
                                        setCreatedBotToken({
                                            name: payload.name,
                                            token: payload.token,
                                            mcpEndpoint:
                                                payload.mcp_endpoint ??
                                                mcpEndpoint,
                                            authorizationHeader:
                                                payload.authorization_header ??
                                                `Bearer ${payload.token}`,
                                        });
                                        setBotName('');
                                        setBotAvatarFile(null);
                                        setBotScopes(botDefaultScopes);
                                        router.reload({
                                            only: ['members'],
                                        });
                                    } catch {
                                        setBotError(
                                            'Could not reach the server. Check your connection and try again.',
                                        );
                                    } finally {
                                        setBotBusy(false);
                                    }
                                }}
                            >
                                <div className="flex flex-wrap items-end gap-3">
                                    <div className="flex min-w-56 flex-1 flex-col gap-1.5">
                                        <Label htmlFor="bot-name">Name</Label>
                                        <Input
                                            id="bot-name"
                                            placeholder="Hoku"
                                            value={botName}
                                            onChange={(e) =>
                                                setBotName(e.target.value)
                                            }
                                            required
                                        />
                                    </div>
                                    <div className="flex min-w-48 flex-col gap-1.5">
                                        <Label htmlFor="bot-avatar">
                                            Avatar (optional)
                                        </Label>
                                        <Input
                                            id="bot-avatar"
                                            type="file"
                                            accept="image/png,image/jpeg,image/webp"
                                            onChange={(e) =>
                                                setBotAvatarFile(
                                                    e.target.files?.[0] ?? null,
                                                )
                                            }
                                        />
                                    </div>
                                    <Button
                                        type="submit"
                                        disabled={
                                            botBusy ||
                                            botName === '' ||
                                            botScopes.length === 0
                                        }
                                    >
                                        <Bot className="size-4" /> Add bot
                                    </Button>
                                </div>
                                <fieldset className="flex flex-col gap-2">
                                    <legend className="text-sm font-medium">
                                        Permissions
                                    </legend>
                                    <div className="grid gap-2 sm:grid-cols-2">
                                        {botScopeOptions.map((scope) => (
                                            <label
                                                key={scope}
                                                className="flex items-start gap-2 text-sm"
                                            >
                                                <input
                                                    type="checkbox"
                                                    className="mt-1"
                                                    checked={botScopes.includes(
                                                        scope,
                                                    )}
                                                    onChange={(e) =>
                                                        setBotScopes(
                                                            (current) =>
                                                                e.target.checked
                                                                    ? [
                                                                          ...current,
                                                                          scope,
                                                                      ]
                                                                    : current.filter(
                                                                          (
                                                                              item,
                                                                          ) =>
                                                                              item !==
                                                                              scope,
                                                                      ),
                                                        )
                                                    }
                                                />
                                                <span>
                                                    <span className="font-medium">
                                                        {scope}
                                                    </span>
                                                    <span className="block text-muted-foreground">
                                                        {botScopeLabels[
                                                            scope
                                                        ] ?? scope}
                                                    </span>
                                                </span>
                                            </label>
                                        ))}
                                    </div>
                                </fieldset>
                            </form>
                            {botError && (
                                <Alert className="mt-3" variant="destructive">
                                    {botError}
                                </Alert>
                            )}
                        </CardContent>
                    </Card>
                )}

                <Card>
                    <CardHeader>
                        <CardTitle>Members</CardTitle>
                        <CardDescription>
                            {members.filter((m) => !m.isBot).length} people
                            {members.some((m) => m.isBot)
                                ? ` and ${members.filter((m) => m.isBot).length} bots`
                                : ''}{' '}
                            on this team.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <div className="max-md:p-1 md:overflow-x-auto">
                            <table className="w-full text-left text-sm">
                                <thead className="max-md:hidden">
                                    <tr>
                                        <th className="px-3 py-2 text-xs font-medium text-muted-foreground">
                                            Name
                                        </th>
                                        <th className="px-3 py-2 text-xs font-medium text-muted-foreground">
                                            Email
                                        </th>
                                        <th className="px-3 py-2 text-xs font-medium text-muted-foreground">
                                            Role
                                        </th>
                                        <th className="px-3 py-2">
                                            <span className="sr-only">
                                                Actions
                                            </span>
                                        </th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-border max-md:block max-md:space-y-3 max-md:divide-y-0">
                                    {members.map((member) => (
                                        <tr
                                            key={member.id}
                                            className="border-t border-border max-md:block max-md:rounded-lg max-md:border max-md:p-4"
                                        >
                                            <td className="min-w-0 px-3 py-2 align-middle break-words max-md:block max-md:border-0 max-md:px-0 max-md:py-1">
                                                <span
                                                    aria-hidden="true"
                                                    className="mb-1 block text-xs font-medium tracking-wide text-muted-foreground uppercase md:hidden"
                                                >
                                                    Name
                                                </span>
                                                <div className="flex items-center gap-2">
                                                    <Avatar className="size-8">
                                                        {member.avatar ? (
                                                            <AvatarImage
                                                                src={
                                                                    member.avatar
                                                                }
                                                                alt=""
                                                            />
                                                        ) : null}
                                                        <AvatarFallback>
                                                            {member.isBot ? (
                                                                <Bot className="size-3.5" />
                                                            ) : (
                                                                memberInitials(
                                                                    member.name,
                                                                )
                                                            )}
                                                        </AvatarFallback>
                                                    </Avatar>
                                                    <span>{member.name}</span>
                                                    {member.isBot && (
                                                        <Badge variant="outline">
                                                            Bot
                                                        </Badge>
                                                    )}
                                                    {member.id ===
                                                        team.ownerId && (
                                                        <Badge variant="success">
                                                            owner
                                                        </Badge>
                                                    )}
                                                    {member.deactivated && (
                                                        <Badge variant="outline">
                                                            deactivated
                                                        </Badge>
                                                    )}
                                                </div>
                                            </td>
                                            <td className="px-3 py-2 align-middle break-all max-md:block max-md:border-0 max-md:px-0 max-md:py-1">
                                                <span
                                                    aria-hidden="true"
                                                    className="mb-1 block text-xs font-medium tracking-wide text-muted-foreground uppercase md:hidden"
                                                >
                                                    Email
                                                </span>
                                                {member.isBot
                                                    ? '—'
                                                    : member.email}
                                            </td>
                                            <td className="px-3 py-2 align-middle max-md:block max-md:border-0 max-md:px-0 max-md:py-1">
                                                <span
                                                    aria-hidden="true"
                                                    className="mb-1 block text-xs font-medium tracking-wide text-muted-foreground uppercase md:hidden"
                                                >
                                                    Role
                                                </span>
                                                {member.isBot ? (
                                                    member.role_label
                                                ) : viewer.canUpdateMember ? (
                                                    <select
                                                        aria-label={`Role for ${member.name}`}
                                                        className={selectClass}
                                                        value={member.role}
                                                        onChange={(e) =>
                                                            router.patch(
                                                                TeamMemberController.update.url(
                                                                    {
                                                                        team: team.slug,
                                                                        user: member.id,
                                                                    },
                                                                ),
                                                                {
                                                                    role: e
                                                                        .target
                                                                        .value,
                                                                },
                                                            )
                                                        }
                                                    >
                                                        {availableRoles.map(
                                                            (role) => (
                                                                <option
                                                                    key={
                                                                        role.value
                                                                    }
                                                                    value={
                                                                        role.value
                                                                    }
                                                                >
                                                                    {role.label}
                                                                </option>
                                                            ),
                                                        )}
                                                    </select>
                                                ) : (
                                                    member.role_label
                                                )}
                                            </td>
                                            <td className="px-3 py-2 align-middle max-md:block max-md:border-0 max-md:px-0 max-md:py-1 md:text-right">
                                                <span
                                                    aria-hidden="true"
                                                    className="mb-1 block text-xs font-medium tracking-wide text-muted-foreground uppercase md:hidden"
                                                >
                                                    Actions
                                                </span>
                                                <div className="flex flex-wrap items-center justify-end gap-2">
                                                    {member.isBot &&
                                                        canAddBot && (
                                                            <>
                                                                {member.agentTokenLastFour ? (
                                                                    <span
                                                                        className="text-xs text-muted-foreground"
                                                                        title="Agent tokens are shown only when created or rolled. The raw token is never stored."
                                                                    >
                                                                        Token
                                                                        ends in
                                                                        …
                                                                        {
                                                                            member.agentTokenLastFour
                                                                        }
                                                                    </span>
                                                                ) : (
                                                                    <span className="text-xs text-muted-foreground">
                                                                        No
                                                                        active
                                                                        token
                                                                    </span>
                                                                )}
                                                                <Button
                                                                    type="button"
                                                                    variant="ghost"
                                                                    size="sm"
                                                                    onClick={() =>
                                                                        setRollingBot(
                                                                            member,
                                                                        )
                                                                    }
                                                                >
                                                                    Roll token
                                                                </Button>
                                                                <label className="inline-flex cursor-pointer items-center">
                                                                    <span className="sr-only">
                                                                        Upload
                                                                        avatar
                                                                        for{' '}
                                                                        {
                                                                            member.name
                                                                        }
                                                                    </span>
                                                                    <input
                                                                        type="file"
                                                                        accept="image/png,image/jpeg,image/webp"
                                                                        className="hidden"
                                                                        disabled={
                                                                            avatarUploadingId ===
                                                                            member.id
                                                                        }
                                                                        onChange={(
                                                                            e,
                                                                        ) => {
                                                                            const file =
                                                                                e
                                                                                    .target
                                                                                    .files?.[0];

                                                                            if (
                                                                                !file
                                                                            ) {
                                                                                return;
                                                                            }

                                                                            const body =
                                                                                new FormData();
                                                                            body.append(
                                                                                'avatar',
                                                                                file,
                                                                            );
                                                                            setAvatarUploadingId(
                                                                                member.id,
                                                                            );
                                                                            router.post(
                                                                                teamRoutes.bots.avatar.url(
                                                                                    {
                                                                                        team: team.slug,
                                                                                        user: member.id,
                                                                                    },
                                                                                ),
                                                                                body,
                                                                                {
                                                                                    forceFormData: true,
                                                                                    preserveScroll: true,
                                                                                    onFinish:
                                                                                        () =>
                                                                                            setAvatarUploadingId(
                                                                                                null,
                                                                                            ),
                                                                                },
                                                                            );
                                                                        }}
                                                                    />
                                                                    <Button
                                                                        type="button"
                                                                        variant="ghost"
                                                                        size="sm"
                                                                        disabled={
                                                                            avatarUploadingId ===
                                                                            member.id
                                                                        }
                                                                        onClick={(
                                                                            e,
                                                                        ) => {
                                                                            const input =
                                                                                e.currentTarget.parentElement?.querySelector(
                                                                                    'input[type="file"]',
                                                                                );

                                                                            if (
                                                                                input instanceof
                                                                                HTMLInputElement
                                                                            ) {
                                                                                input.click();
                                                                            }
                                                                        }}
                                                                    >
                                                                        Avatar
                                                                    </Button>
                                                                </label>
                                                            </>
                                                        )}
                                                    {(member.isBot
                                                        ? canAddBot
                                                        : viewer.canRemoveMember) &&
                                                        member.id !==
                                                            viewer.id && (
                                                            <Button
                                                                variant="ghost"
                                                                size="sm"
                                                                onClick={() =>
                                                                    setRemoving(
                                                                        member,
                                                                    )
                                                                }
                                                            >
                                                                Remove
                                                            </Button>
                                                        )}
                                                </div>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </CardContent>
                </Card>

                {permissions.canCreateInvitation && (
                    <Card>
                        <CardHeader>
                            <CardTitle>Invite a teammate</CardTitle>
                            <CardDescription>
                                They get a link to join with the role you
                                choose.
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <form
                                onSubmit={invite}
                                className="flex flex-wrap items-end gap-3"
                            >
                                <div className="flex min-w-64 flex-1 flex-col gap-1.5">
                                    <Label htmlFor="invite-email">Email</Label>
                                    <Input
                                        id="invite-email"
                                        name="email"
                                        type="email"
                                        placeholder="teammate@company.com"
                                        value={inviteForm.data.email}
                                        onChange={(e) =>
                                            inviteForm.setData(
                                                'email',
                                                e.target.value,
                                            )
                                        }
                                    />
                                </div>
                                <div className="flex flex-col gap-1.5">
                                    <Label htmlFor="invite-role">Role</Label>
                                    <select
                                        id="invite-role"
                                        name="role"
                                        className={selectClass}
                                        value={inviteForm.data.role}
                                        onChange={(e) =>
                                            inviteForm.setData(
                                                'role',
                                                e.target.value,
                                            )
                                        }
                                    >
                                        {availableRoles.map((role) => (
                                            <option
                                                key={role.value}
                                                value={role.value}
                                            >
                                                {role.label}
                                            </option>
                                        ))}
                                    </select>
                                    {inviteForm.errors.role && (
                                        <p
                                            role="alert"
                                            className="text-sm text-destructive"
                                        >
                                            {inviteForm.errors.role}
                                        </p>
                                    )}
                                </div>
                                <Button
                                    type="submit"
                                    disabled={inviteForm.processing}
                                >
                                    Send invite
                                </Button>
                            </form>
                            {inviteForm.errors.email && (
                                <p
                                    role="alert"
                                    className="mt-2 text-sm text-destructive"
                                >
                                    {inviteForm.errors.email}
                                </p>
                            )}

                            {invitations.length > 0 && (
                                <div className="mt-5 max-md:p-1 md:overflow-x-auto">
                                    <table className="w-full text-left text-sm">
                                        <thead className="max-md:hidden">
                                            <tr>
                                                <th className="px-3 py-2 text-xs font-medium text-muted-foreground">
                                                    Pending
                                                </th>
                                                <th className="px-3 py-2 text-xs font-medium text-muted-foreground">
                                                    Role
                                                </th>
                                                <th className="px-3 py-2">
                                                    <span className="sr-only">
                                                        Actions
                                                    </span>
                                                </th>
                                            </tr>
                                        </thead>
                                        <tbody className="divide-y divide-border max-md:block max-md:space-y-3 max-md:divide-y-0">
                                            {invitations.map((invitation) => (
                                                <tr
                                                    key={invitation.code}
                                                    className="border-t border-border max-md:block max-md:rounded-lg max-md:border max-md:p-4"
                                                >
                                                    <td className="min-w-0 px-3 py-2 align-middle break-all max-md:block max-md:border-0 max-md:px-0 max-md:py-1">
                                                        <span
                                                            aria-hidden="true"
                                                            className="mb-1 block text-xs font-medium tracking-wide text-muted-foreground uppercase md:hidden"
                                                        >
                                                            Pending
                                                        </span>
                                                        {invitation.email}
                                                    </td>
                                                    <td className="px-3 py-2 align-middle max-md:block max-md:border-0 max-md:px-0 max-md:py-1">
                                                        <span
                                                            aria-hidden="true"
                                                            className="mb-1 block text-xs font-medium tracking-wide text-muted-foreground uppercase md:hidden"
                                                        >
                                                            Role
                                                        </span>
                                                        {invitation.role_label}
                                                    </td>
                                                    <td className="px-3 py-2 align-middle max-md:block max-md:border-0 max-md:px-0 max-md:py-1 md:text-right">
                                                        <span
                                                            aria-hidden="true"
                                                            className="mb-1 block text-xs font-medium tracking-wide text-muted-foreground uppercase md:hidden"
                                                        >
                                                            Actions
                                                        </span>
                                                        <div className="flex flex-wrap gap-1 md:justify-end">
                                                            <Button
                                                                variant="ghost"
                                                                size="sm"
                                                                onClick={() =>
                                                                    copyLink(
                                                                        invitation.url,
                                                                    )
                                                                }
                                                            >
                                                                <Copy className="size-3.5" />{' '}
                                                                Copy link
                                                            </Button>
                                                            <Button
                                                                variant="ghost"
                                                                size="sm"
                                                                onClick={() =>
                                                                    router.post(
                                                                        TeamInvitationController.resend.url(
                                                                            {
                                                                                team: team.slug,
                                                                                invitation:
                                                                                    invitation.code,
                                                                            },
                                                                        ),
                                                                    )
                                                                }
                                                            >
                                                                Resend
                                                            </Button>
                                                            <Button
                                                                variant="ghost"
                                                                size="sm"
                                                                onClick={() =>
                                                                    setCancellingInvitation(
                                                                        invitation,
                                                                    )
                                                                }
                                                            >
                                                                Cancel
                                                            </Button>
                                                        </div>
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            )}
                        </CardContent>
                    </Card>
                )}

                {viewer.canTransfer && admins.length > 0 && (
                    <Card>
                        <CardHeader>
                            <CardTitle>Transfer ownership</CardTitle>
                            <CardDescription>
                                The new owner must be an admin. You stay an
                                admin.
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="flex flex-wrap gap-2">
                            {admins.map((admin) => (
                                <Button
                                    key={admin.id}
                                    variant="outline"
                                    size="sm"
                                    onClick={() =>
                                        router.patch(
                                            TeamOwnerController.url({
                                                team: team.slug,
                                            }),
                                            {
                                                user_id: admin.id,
                                            },
                                        )
                                    }
                                >
                                    Make {admin.name} owner
                                </Button>
                            ))}
                        </CardContent>
                    </Card>
                )}

                {permissions.canChangeAuthMode && (
                    <Card>
                        <CardHeader>
                            <CardTitle>Authentication</CardTitle>
                            <CardDescription>
                                Verified domains and how your team signs in.
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="flex flex-col gap-4">
                            {domains.map((domain) => (
                                <div
                                    key={domain.id}
                                    className="min-w-0 rounded-md border border-border p-3 text-sm"
                                >
                                    <div className="flex min-w-0 flex-wrap items-center gap-2">
                                        <strong className="min-w-0 break-all">
                                            {domain.domain}
                                        </strong>
                                        <Badge
                                            variant={
                                                domain.verified_at
                                                    ? 'success'
                                                    : 'warning'
                                            }
                                        >
                                            {domain.verified_at
                                                ? 'verified'
                                                : 'unverified'}
                                        </Badge>
                                    </div>
                                    {!domain.verified_at && (
                                        <>
                                            <p className="mt-2 min-w-0 break-words text-muted-foreground">
                                                Add a TXT record{' '}
                                                <code className="break-all">
                                                    {domain.txt_record_name}
                                                </code>{' '}
                                                ={' '}
                                                <code className="break-all">
                                                    {domain.verification_token}
                                                </code>
                                            </p>
                                            <Button
                                                className="mt-2"
                                                size="sm"
                                                variant="outline"
                                                onClick={() =>
                                                    router.post(
                                                        TeamDomainController.verify.url(
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
                                        </>
                                    )}
                                </div>
                            ))}
                            <form
                                onSubmit={(e) => {
                                    e.preventDefault();
                                    domainForm.post(
                                        TeamDomainController.store.url({
                                            team: team.slug,
                                        }),
                                        {
                                            onSuccess: () => domainForm.reset(),
                                        },
                                    );
                                }}
                                className="flex items-end gap-3"
                            >
                                <div className="flex flex-1 flex-col gap-1.5">
                                    <Label htmlFor="domain">Add a domain</Label>
                                    <Input
                                        id="domain"
                                        name="domain"
                                        placeholder="acme.com"
                                        value={domainForm.data.domain}
                                        onChange={(e) =>
                                            domainForm.setData(
                                                'domain',
                                                e.target.value,
                                            )
                                        }
                                    />
                                </div>
                                <Button
                                    type="submit"
                                    disabled={domainForm.processing}
                                >
                                    Add domain
                                </Button>
                            </form>
                            {domainForm.errors.domain && (
                                <p
                                    role="alert"
                                    className="text-sm text-destructive"
                                >
                                    {domainForm.errors.domain}
                                </p>
                            )}
                            <form
                                onSubmit={(e) => {
                                    e.preventDefault();
                                    authModeForm.patch(
                                        AuthModeController.update.url({
                                            team: team.slug,
                                        }),
                                    );
                                }}
                                className="flex items-end gap-3"
                            >
                                <div className="flex flex-col gap-1.5">
                                    <Label htmlFor="auth-mode">
                                        Sign-in mode
                                    </Label>
                                    <select
                                        id="auth-mode"
                                        name="auth_mode"
                                        className={selectClass}
                                        value={authModeForm.data.auth_mode}
                                        onChange={(e) =>
                                            authModeForm.setData(
                                                'auth_mode',
                                                e.target.value,
                                            )
                                        }
                                    >
                                        <option value="authkit">
                                            Standard sign-in
                                        </option>
                                        <option value="dual">
                                            Standard sign-in or SSO
                                        </option>
                                        <option value="polis">SSO only</option>
                                    </select>
                                </div>
                                <Button
                                    type="submit"
                                    variant="outline"
                                    disabled={authModeForm.processing}
                                >
                                    Save
                                </Button>
                            </form>
                            {authModeForm.errors.auth_mode && (
                                <p
                                    role="alert"
                                    className="text-sm text-destructive"
                                >
                                    {authModeForm.errors.auth_mode}
                                </p>
                            )}
                        </CardContent>
                    </Card>
                )}

                {!team.isPersonal && (viewer.canLeave || viewer.canDelete) && (
                    <Card className="border-destructive/40">
                        <CardHeader>
                            <CardTitle>Danger zone</CardTitle>
                            <CardDescription>
                                {viewer.isOwner
                                    ? 'Transfer ownership before you can leave. Deleting the team is permanent.'
                                    : 'Leaving removes your access to this team.'}
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="flex flex-wrap gap-2">
                            {viewer.canLeave && !viewer.isOwner && (
                                <Button
                                    variant="outline"
                                    onClick={() => setLeaving(true)}
                                >
                                    Leave team
                                </Button>
                            )}
                            {viewer.canDelete && (
                                <Button
                                    variant="destructive"
                                    onClick={() => setDeleting(true)}
                                >
                                    Delete team
                                </Button>
                            )}
                        </CardContent>
                    </Card>
                )}
            </div>

            <Dialog
                open={removing !== null}
                onOpenChange={(open) => !open && setRemoving(null)}
            >
                <DialogContent>
                    <DialogTitle>Remove {removing?.name}?</DialogTitle>
                    <DialogDescription>
                        {removing?.isBot
                            ? `This bot loses access to ${team.name} and its tokens stop working.`
                            : `They lose access to ${team.name} immediately.`}
                    </DialogDescription>
                    <div className="mt-5 flex justify-end gap-2">
                        <DialogClose asChild>
                            <Button variant="outline">Cancel</Button>
                        </DialogClose>
                        <Button
                            variant="destructive"
                            onClick={() => {
                                if (removing) {
                                    router.delete(
                                        removing.isBot
                                            ? teamRoutes.bots.destroy.url({
                                                  team: team.slug,
                                                  user: removing.id,
                                              })
                                            : TeamMemberController.destroy.url({
                                                  team: team.slug,
                                                  user: removing.id,
                                              }),
                                    );
                                }

                                setRemoving(null);
                            }}
                        >
                            {removing?.isBot ? 'Remove bot' : 'Remove member'}
                        </Button>
                    </div>
                </DialogContent>
            </Dialog>

            <ConfirmDialog
                open={rollingBot !== null}
                onOpenChange={(open) => {
                    if (!open && !rollBusy) {
                        setRollingBot(null);
                    }
                }}
                title={`Roll ${rollingBot?.name}'s token?`}
                description={
                    <>
                        The current token stops working immediately. A new one
                        is shown once — we never store the raw token, so this is
                        the only way to get it again.
                    </>
                }
                confirmLabel="Roll token"
                cancelLabel="Keep current"
                processing={rollBusy}
                onConfirm={async () => {
                    if (!rollingBot) {
                        return;
                    }

                    setRollBusy(true);

                    try {
                        const response = await fetch(
                            teamRoutes.bots.roll.url({
                                team: team.slug,
                                user: rollingBot.id,
                            }),
                            {
                                method: 'POST',
                                headers: {
                                    Accept: 'application/json',
                                    'Content-Type': 'application/json',
                                    'X-XSRF-TOKEN': csrfToken(),
                                },
                                body: '{}',
                            },
                        );

                        if (!response.ok) {
                            const payload = await response
                                .json()
                                .catch(() => null);
                            toast.error(
                                errorMessageFromBody(
                                    payload,
                                    `Could not roll the token (HTTP ${response.status}).`,
                                ),
                            );

                            return;
                        }

                        const payload = await response.json();
                        setRollingBot(null);
                        setCreatedBotToken({
                            name: payload.name,
                            token: payload.token,
                            mcpEndpoint: payload.mcp_endpoint ?? mcpEndpoint,
                            authorizationHeader:
                                payload.authorization_header ??
                                `Bearer ${payload.token}`,
                        });
                        router.reload({ only: ['members'] });
                    } catch {
                        toast.error(
                            'Could not reach the server. Check your connection and try again.',
                        );
                    } finally {
                        setRollBusy(false);
                    }
                }}
            />

            <Dialog
                open={createdBotToken !== null}
                onOpenChange={(open) => !open && setCreatedBotToken(null)}
            >
                <DialogContent>
                    <DialogTitle>
                        Copy {createdBotToken?.name}&apos;s token now
                    </DialogTitle>
                    <DialogDescription>
                        It will not be shown again. Add it as an Authorization
                        header on your MCP client.
                    </DialogDescription>
                    <code className="mt-4 block max-h-32 overflow-auto rounded-md bg-muted p-3 text-xs break-all">
                        {createdBotToken?.token}
                    </code>
                    <div className="mt-3 space-y-2 text-sm">
                        <p className="text-muted-foreground">MCP URL</p>
                        <code className="block overflow-auto rounded-md bg-muted p-2 text-xs break-all">
                            {createdBotToken?.mcpEndpoint}
                        </code>
                        <p className="text-muted-foreground">Header</p>
                        <code className="block overflow-auto rounded-md bg-muted p-2 text-xs break-all">
                            Authorization:{' '}
                            {createdBotToken?.authorizationHeader}
                        </code>
                    </div>
                    <div className="mt-4 flex justify-end gap-2">
                        <Button
                            variant="outline"
                            onClick={async () => {
                                await navigator.clipboard.writeText(
                                    createdBotToken?.token ?? '',
                                );
                                toast.success('Token copied.');
                            }}
                        >
                            <Copy className="size-4" /> Copy
                        </Button>
                        <DialogClose asChild>
                            <Button>Done</Button>
                        </DialogClose>
                    </div>
                </DialogContent>
            </Dialog>

            <Dialog open={deleting} onOpenChange={setDeleting}>
                <DialogContent>
                    <DialogTitle>Delete {team.name}?</DialogTitle>
                    <DialogDescription>
                        This cannot be undone. Type the team name{' '}
                        <strong>{team.name}</strong> to confirm.
                    </DialogDescription>
                    <form
                        className="mt-4 flex flex-col gap-3"
                        onSubmit={(e) => {
                            e.preventDefault();
                            deleteForm.delete(
                                TeamController.destroy.url({ team: team.slug }),
                            );
                        }}
                    >
                        <Input
                            aria-label="Team name"
                            value={deleteForm.data.name}
                            onChange={(e) =>
                                deleteForm.setData('name', e.target.value)
                            }
                        />
                        {deleteForm.errors.name && (
                            <p
                                role="alert"
                                className="text-sm text-destructive"
                            >
                                {deleteForm.errors.name}
                            </p>
                        )}
                        <div className="flex justify-end gap-2">
                            <DialogClose asChild>
                                <Button type="button" variant="outline">
                                    Cancel
                                </Button>
                            </DialogClose>
                            <Button
                                type="submit"
                                variant="destructive"
                                disabled={deleteForm.data.name !== team.name}
                            >
                                Delete team
                            </Button>
                        </div>
                    </form>
                </DialogContent>
            </Dialog>

            <ConfirmDialog
                open={cancellingInvitation !== null}
                onOpenChange={(open) => !open && setCancellingInvitation(null)}
                title={`Cancel the invitation to ${cancellingInvitation?.email}?`}
                description="The link in their email will stop working."
                confirmLabel="Cancel invitation"
                cancelLabel="Keep invitation"
                onConfirm={() => {
                    if (cancellingInvitation) {
                        router.delete(
                            TeamInvitationController.destroy.url({
                                team: team.slug,
                                invitation: cancellingInvitation.code,
                            }),
                        );
                    }

                    setCancellingInvitation(null);
                }}
            />

            <ConfirmDialog
                open={leaving}
                onOpenChange={setLeaving}
                title={`Leave ${team.name}?`}
                description={`You lose access to everything shared in ${team.name}. An admin will need to invite you again.`}
                confirmLabel="Leave team"
                onConfirm={() => {
                    router.delete(teamRoutes.leave.url({ team: team.slug }));
                    setLeaving(false);
                }}
            />
        </>
    );
}

TeamEdit.layout = (page: React.ReactNode) => <AppLayout>{page}</AppLayout>;
