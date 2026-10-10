import { Head, router, useForm, usePage } from '@inertiajs/react';
import { Copy } from 'lucide-react';
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
import AppLayout from '@/layouts/app-layout';
import teamRoutes from '@/routes/teams';
import type { SharedProps } from '@/types/shared';

interface Member {
    id: number;
    name: string;
    email: string;
    role: string;
    role_label: string;
    deactivated: boolean;
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
}: Props) {
    const inviteForm = useForm({ email: '', role: 'member' });
    const renameForm = useForm({ name: team.name });
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

            <div className="mb-6 flex min-w-0 flex-wrap items-center gap-3">
                <h1 className="min-w-0 text-2xl font-semibold break-words">
                    {team.name}
                </h1>
                <Badge variant="outline">{team.plan}</Badge>
                <span className="text-sm break-all text-muted-foreground tabular-nums">
                    {team.slug}
                </span>
            </div>

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

                <Card>
                    <CardHeader>
                        <CardTitle>Members</CardTitle>
                        <CardDescription>
                            {members.length} people on this team.
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
                                                {member.name}
                                                {member.id === team.ownerId && (
                                                    <Badge
                                                        className="ml-2"
                                                        variant="success"
                                                    >
                                                        owner
                                                    </Badge>
                                                )}
                                                {member.deactivated && (
                                                    <Badge
                                                        className="ml-2"
                                                        variant="outline"
                                                    >
                                                        deactivated
                                                    </Badge>
                                                )}
                                            </td>
                                            <td className="px-3 py-2 align-middle break-all max-md:block max-md:border-0 max-md:px-0 max-md:py-1">
                                                <span
                                                    aria-hidden="true"
                                                    className="mb-1 block text-xs font-medium tracking-wide text-muted-foreground uppercase md:hidden"
                                                >
                                                    Email
                                                </span>
                                                {member.email}
                                            </td>
                                            <td className="px-3 py-2 align-middle max-md:block max-md:border-0 max-md:px-0 max-md:py-1">
                                                <span
                                                    aria-hidden="true"
                                                    className="mb-1 block text-xs font-medium tracking-wide text-muted-foreground uppercase md:hidden"
                                                >
                                                    Role
                                                </span>
                                                {viewer.canUpdateMember ? (
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
                                                {viewer.canRemoveMember &&
                                                    member.id !== viewer.id && (
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
                                        <option value="authkit">authkit</option>
                                        <option value="dual">dual</option>
                                        <option value="polis">polis</option>
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
                        They lose access to {team.name} immediately.
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
                                        TeamMemberController.destroy.url({
                                            team: team.slug,
                                            user: removing.id,
                                        }),
                                    );
                                }

                                setRemoving(null);
                            }}
                        >
                            Remove member
                        </Button>
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
