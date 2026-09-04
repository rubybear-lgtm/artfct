import { Head, Link, router, useForm } from '@inertiajs/react';

interface UserTeam {
    id: number;
    name: string;
    slug: string;
    isPersonal: boolean;
    role: string | null;
    roleLabel: string | null;
    isCurrent: boolean | null;
}

export default function TeamsIndex({ teams }: { teams: UserTeam[] }) {
    const { data, setData, post, processing, errors, reset } = useForm({
        name: '',
        slug: '',
    });

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        post('/settings/teams', { onSuccess: () => reset() });
    };

    return (
        <>
            <Head title="Your orgs" />
            <div
                style={{
                    maxWidth: 640,
                    margin: '2rem auto',
                    fontFamily: 'ui-sans-serif, system-ui',
                }}
            >
                <h1>Your orgs</h1>

                <ul>
                    {teams.map((team) => (
                        <li key={team.id}>
                            <Link href={`/settings/teams/${team.slug}`}>
                                {team.name}
                            </Link>{' '}
                            {team.isCurrent && <strong>(current)</strong>}
                            {!team.isCurrent && (
                                <button
                                    type="button"
                                    onClick={() =>
                                        router.post(
                                            `/settings/teams/${team.slug}/switch`,
                                        )
                                    }
                                >
                                    Switch
                                </button>
                            )}
                        </li>
                    ))}
                </ul>

                <h2>Create a new org</h2>
                <form onSubmit={submit}>
                    <label htmlFor="name">Name</label>
                    <input
                        id="name"
                        value={data.name}
                        onChange={(e) => setData('name', e.target.value)}
                    />
                    {errors.name && <div role="alert">{errors.name}</div>}

                    <label htmlFor="slug">Slug (optional)</label>
                    <input
                        id="slug"
                        value={data.slug}
                        onChange={(e) => setData('slug', e.target.value)}
                    />
                    {errors.slug && <div role="alert">{errors.slug}</div>}

                    <button type="submit" disabled={processing}>
                        Create org
                    </button>
                </form>
            </div>
        </>
    );
}
