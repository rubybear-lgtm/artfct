import { Head, router, useForm } from '@inertiajs/react';
import { Copy } from 'lucide-react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import { toast } from 'sonner';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Table, TableCell, TableHead, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';

interface Member {
    id: number;
    name: string;
    email: string;
    role: string;
    role_label: string;
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
    'h-9 rounded-md border border-border bg-transparent px-2 text-sm';

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
    const domainForm = useForm({ domain: '' });
    const authModeForm = useForm({ auth_mode: team.authMode });
    const deleteForm = useForm({ name: '' });
    const [removing, setRemoving] = useState<Member | null>(null);
    const [deleting, setDeleting] = useState(false);
    const base = `/settings/teams/${team.slug}`;
    const admins = members.filter(
        (member) => member.role === 'admin' && member.id !== viewer.id,
    );

    const invite = (e: FormEvent) => {
        e.preventDefault();
        inviteForm.post(`${base}/invitations`, {
            onSuccess: () => inviteForm.reset('email'),
        });
    };

    const copyLink = async (url: string) => {
        await navigator.clipboard.writeText(url);
        toast.success('Invitation link copied.');
    };

    return (
        <>
            <Head title={team.name} />

            <div className="mb-6 flex flex-wrap items-center gap-3">
                <h1 className="text-2xl font-semibold">{team.name}</h1>
                <Badge variant="outline">{team.plan}</Badge>
                <span className="font-mono text-sm text-muted-foreground">
                    {team.slug}
                </span>
            </div>

            <div className="flex flex-col gap-6">
                <Card>
                    <CardHeader>
                        <CardTitle>Members</CardTitle>
                        <CardDescription>
                            {members.length} people on this team.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <Table>
                            <thead>
                                <tr>
                                    <TableHead>Name</TableHead>
                                    <TableHead>Email</TableHead>
                                    <TableHead>Role</TableHead>
                                    <TableHead />
                                </tr>
                            </thead>
                            <tbody>
                                {members.map((member) => (
                                    <TableRow key={member.id}>
                                        <TableCell>
                                            {member.name}
                                            {member.id === team.ownerId && (
                                                <Badge
                                                    className="ml-2"
                                                    variant="success"
                                                >
                                                    owner
                                                </Badge>
                                            )}
                                        </TableCell>
                                        <TableCell>{member.email}</TableCell>
                                        <TableCell>
                                            {viewer.canUpdateMember ? (
                                                <select
                                                    aria-label={`Role for ${member.name}`}
                                                    className={selectClass}
                                                    value={member.role}
                                                    onChange={(e) =>
                                                        router.patch(
                                                            `${base}/members/${member.id}`,
                                                            {
                                                                role: e.target
                                                                    .value,
                                                            },
                                                        )
                                                    }
                                                >
                                                    {availableRoles.map(
                                                        (role) => (
                                                            <option
                                                                key={role.value}
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
                                        </TableCell>
                                        <TableCell className="text-right">
                                            {viewer.canRemoveMember &&
                                                member.id !== viewer.id && (
                                                    <Button
                                                        variant="ghost"
                                                        size="sm"
                                                        onClick={() =>
                                                            setRemoving(member)
                                                        }
                                                    >
                                                        Remove
                                                    </Button>
                                                )}
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </tbody>
                        </Table>
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
                                <Table className="mt-5">
                                    <thead>
                                        <tr>
                                            <TableHead>Pending</TableHead>
                                            <TableHead>Role</TableHead>
                                            <TableHead />
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {invitations.map((invitation) => (
                                            <TableRow key={invitation.code}>
                                                <TableCell>
                                                    {invitation.email}
                                                </TableCell>
                                                <TableCell>
                                                    {invitation.role_label}
                                                </TableCell>
                                                <TableCell className="flex justify-end gap-1">
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
                                                            router.delete(
                                                                `${base}/invitations/${invitation.code}`,
                                                            )
                                                        }
                                                    >
                                                        Cancel
                                                    </Button>
                                                </TableCell>
                                            </TableRow>
                                        ))}
                                    </tbody>
                                </Table>
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
                                        router.patch(`${base}/owner`, {
                                            user_id: admin.id,
                                        })
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
                                    className="rounded-md border border-border p-3 text-sm"
                                >
                                    <div className="flex items-center gap-2">
                                        <strong>{domain.domain}</strong>
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
                                            <p className="mt-2 text-muted-foreground">
                                                Add a TXT record{' '}
                                                <code>
                                                    {domain.txt_record_name}
                                                </code>{' '}
                                                ={' '}
                                                <code>
                                                    {domain.verification_token}
                                                </code>
                                            </p>
                                            <Button
                                                className="mt-2"
                                                size="sm"
                                                variant="outline"
                                                onClick={() =>
                                                    router.post(
                                                        `${base}/domains/${domain.id}/verify`,
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
                                    domainForm.post(`${base}/domains`, {
                                        onSuccess: () => domainForm.reset(),
                                    });
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
                                    authModeForm.patch(`${base}/auth-mode`);
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
                                    onClick={() =>
                                        router.delete(`${base}/leave`)
                                    }
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
                                        `${base}/members/${removing.id}`,
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
                            deleteForm.delete(base);
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
        </>
    );
}

TeamEdit.layout = (page: React.ReactNode) => <AppLayout>{page}</AppLayout>;
