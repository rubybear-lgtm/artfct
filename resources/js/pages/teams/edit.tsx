import { Head, router, useForm } from '@inertiajs/react';

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
}

interface DomainRow {
    id: number;
    domain: string;
    verification_token: string;
    txt_record_name: string;
    verified_at: string | null;
}

interface Permissions {
    canUpdateTeam: boolean;
    canAddMember: boolean;
    canCreateInvitation: boolean;
    canChangeAuthMode: boolean;
}

interface Props {
    team: {
        id: number;
        name: string;
        slug: string;
        isPersonal: boolean;
        authMode: string;
    };
    members: Member[];
    invitations: Invitation[];
    domains: DomainRow[];
    permissions: Permissions;
    availableRoles: { value: string; label: string }[];
}

export default function TeamEdit({
    team,
    members,
    invitations,
    domains,
    permissions,
    availableRoles,
}: Props) {
    const inviteForm = useForm({ email: '', role: 'member' });
    const domainForm = useForm({ domain: '' });
    const authModeForm = useForm({ auth_mode: team.authMode });

    const invite = (e: React.FormEvent) => {
        e.preventDefault();
        inviteForm.post(`/settings/teams/${team.slug}/invitations`, {
            onSuccess: () => inviteForm.reset(),
        });
    };

    const addDomain = (e: React.FormEvent) => {
        e.preventDefault();
        domainForm.post(`/settings/teams/${team.slug}/domains`, {
            onSuccess: () => domainForm.reset(),
        });
    };

    const updateAuthMode = (e: React.FormEvent) => {
        e.preventDefault();
        authModeForm.patch(`/settings/teams/${team.slug}/auth-mode`);
    };

    return (
        <>
            <Head title={team.name} />
            <div
                style={{
                    maxWidth: 720,
                    margin: '2rem auto',
                    fontFamily: 'ui-sans-serif, system-ui',
                }}
            >
                <h1>{team.name}</h1>
                <p>Slug: {team.slug}</p>
                <p>Auth mode: {team.authMode}</p>

                <h2>Members</h2>
                <ul>
                    {members.map((member) => (
                        <li key={member.id}>
                            {member.name} ({member.email}) — {member.role_label}
                        </li>
                    ))}
                </ul>

                {permissions.canCreateInvitation && (
                    <>
                        <h2>Invite a member</h2>
                        <form onSubmit={invite}>
                            <input
                                type="email"
                                placeholder="email"
                                value={inviteForm.data.email}
                                onChange={(e) =>
                                    inviteForm.setData('email', e.target.value)
                                }
                            />
                            <select
                                value={inviteForm.data.role}
                                onChange={(e) =>
                                    inviteForm.setData('role', e.target.value)
                                }
                            >
                                {availableRoles.map((role) => (
                                    <option key={role.value} value={role.value}>
                                        {role.label}
                                    </option>
                                ))}
                            </select>
                            <button
                                type="submit"
                                disabled={inviteForm.processing}
                            >
                                Send invite
                            </button>
                            {inviteForm.errors.email && (
                                <div role="alert">
                                    {inviteForm.errors.email}
                                </div>
                            )}
                        </form>
                    </>
                )}

                <h2>Pending invitations</h2>
                <ul>
                    {invitations.map((invitation) => (
                        <li key={invitation.code}>
                            {invitation.email} — {invitation.role_label}
                        </li>
                    ))}
                </ul>

                {permissions.canChangeAuthMode && (
                    <>
                        <h2>Domains</h2>
                        <ul>
                            {domains.map((domain) => (
                                <li key={domain.id}>
                                    {domain.domain} —{' '}
                                    {domain.verified_at
                                        ? 'verified'
                                        : 'unverified'}
                                    <br />
                                    TXT record:{' '}
                                    <code>{domain.txt_record_name}</code> ={' '}
                                    <code>{domain.verification_token}</code>
                                    {!domain.verified_at && (
                                        <button
                                            type="button"
                                            onClick={() =>
                                                router.post(
                                                    `/settings/teams/${team.slug}/domains/${domain.id}/verify`,
                                                )
                                            }
                                        >
                                            Verify
                                        </button>
                                    )}
                                </li>
                            ))}
                        </ul>
                        <form onSubmit={addDomain}>
                            <input
                                placeholder="acme.com"
                                value={domainForm.data.domain}
                                onChange={(e) =>
                                    domainForm.setData('domain', e.target.value)
                                }
                            />
                            <button
                                type="submit"
                                disabled={domainForm.processing}
                            >
                                Add domain
                            </button>
                            {domainForm.errors.domain && (
                                <div role="alert">
                                    {domainForm.errors.domain}
                                </div>
                            )}
                        </form>

                        <h2>Auth mode</h2>
                        <form onSubmit={updateAuthMode}>
                            <select
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
                            <button
                                type="submit"
                                disabled={authModeForm.processing}
                            >
                                Save
                            </button>
                            {authModeForm.errors.auth_mode && (
                                <div role="alert">
                                    {authModeForm.errors.auth_mode}
                                </div>
                            )}
                        </form>
                    </>
                )}
            </div>
        </>
    );
}
