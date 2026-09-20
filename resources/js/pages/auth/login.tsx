import { Head, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';

import AuthKitDevLoginController from '@/actions/App/Http/Controllers/Auth/AuthKitDevLoginController';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AuthLayout from '@/layouts/auth-layout';

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
            <Card>
                <CardHeader>
                    <CardTitle className="text-xl">
                        Continue to artfct
                    </CardTitle>
                    <CardDescription>
                        Test sign-in. It stands in for Google, passkeys and the
                        other methods until real sign-in is switched on.
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    <form
                        className="flex flex-col gap-3"
                        onSubmit={(e: FormEvent) => {
                            e.preventDefault();
                            post(AuthKitDevLoginController.url());
                        }}
                    >
                        <div className="flex flex-col gap-1.5">
                            <Label htmlFor="email">Email</Label>
                            <Input
                                id="email"
                                name="email"
                                type="email"
                                autoComplete="email"
                                value={data.email}
                                onChange={(e) =>
                                    setData('email', e.target.value)
                                }
                            />
                            {errors.email && (
                                <div
                                    role="alert"
                                    className="text-sm text-destructive"
                                >
                                    {errors.email}
                                </div>
                            )}
                        </div>

                        <div className="flex flex-col gap-1.5">
                            <Label htmlFor="name">
                                Name (first registration only)
                            </Label>
                            <Input
                                id="name"
                                name="name"
                                type="text"
                                autoComplete="name"
                                value={data.name}
                                onChange={(e) =>
                                    setData('name', e.target.value)
                                }
                            />
                        </div>

                        <Button
                            type="submit"
                            disabled={processing}
                            onClick={() => submit('GoogleOAuth')}
                        >
                            Continue with Google
                        </Button>
                        <Button
                            type="submit"
                            variant="outline"
                            disabled={processing}
                            onClick={() => submit('Passkey')}
                        >
                            Continue with passkey
                        </Button>
                    </form>
                </CardContent>
            </Card>
        </>
    );
}

Login.layout = (page: React.ReactNode) => <AuthLayout>{page}</AuthLayout>;
