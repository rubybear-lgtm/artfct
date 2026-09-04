import { Head, useForm } from '@inertiajs/react';
import AuthKitDevLoginController from '@/actions/App/Http/Controllers/Auth/AuthKitDevLoginController';

/**
 * Dev/test stand-in for WorkOS AuthKit's hosted login screen (spec 06).
 * Only reachable when AuthKitLoginController resolves the fake client —
 * see App\Http\Controllers\Auth\AuthKitLoginController.
 */
export default function Login() {
    const { data, setData, post, processing, errors } = useForm({
        email: '',
        name: '',
        provider: 'GoogleOAuth',
    });

    const submit = (provider: string) => {
        setData((current) => ({ ...current, provider }));
    };

    return (
        <>
            <Head title="Log in" />
            <div
                style={{
                    maxWidth: 360,
                    margin: '4rem auto',
                    fontFamily: 'ui-sans-serif, system-ui',
                }}
            >
                <h1>Continue to artfct</h1>
                <p style={{ color: 'var(--sol-base00, #657B83)' }}>
                    Dev login — stands in for WorkOS AuthKit (Google, passkeys,
                    etc.) locally.
                </p>

                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        post(AuthKitDevLoginController.url());
                    }}
                >
                    <label htmlFor="email">Email</label>
                    <input
                        id="email"
                        type="email"
                        value={data.email}
                        onChange={(e) => setData('email', e.target.value)}
                        style={{
                            display: 'block',
                            width: '100%',
                            marginBottom: 8,
                        }}
                    />
                    {errors.email && <div role="alert">{errors.email}</div>}

                    <label htmlFor="name">Name (first registration only)</label>
                    <input
                        id="name"
                        type="text"
                        value={data.name}
                        onChange={(e) => setData('name', e.target.value)}
                        style={{
                            display: 'block',
                            width: '100%',
                            marginBottom: 16,
                        }}
                    />

                    <button
                        type="submit"
                        disabled={processing}
                        onClick={() => submit('GoogleOAuth')}
                    >
                        Continue with Google
                    </button>
                    <button
                        type="submit"
                        disabled={processing}
                        onClick={() => submit('Passkey')}
                        style={{ marginLeft: 8 }}
                    >
                        Continue with passkey
                    </button>
                </form>
            </div>
        </>
    );
}
