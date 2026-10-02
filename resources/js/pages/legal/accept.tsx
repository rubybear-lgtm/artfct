import { Head, Link, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';

import { Button } from '@/components/ui/button';
import AuthLayout from '@/layouts/auth-layout';
import { privacy, terms } from '@/routes';
import termsRoutes from '@/routes/terms';

export default function Accept({ version }: { version: string }) {
    const form = useForm({ accepted: false });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(termsRoutes.accept.url());
    };

    return (
        <>
            <Head title="Accept the terms" />
            <h1 className="mb-2 text-xl font-semibold">One more step</h1>
            <p className="mb-4 text-sm text-muted-foreground">
                Please read and accept the{' '}
                <Link className="underline" href={terms.url()} target="_blank">
                    terms
                </Link>{' '}
                and{' '}
                <Link
                    className="underline"
                    href={privacy.url()}
                    target="_blank"
                >
                    privacy policy
                </Link>{' '}
                (version {version}) to continue.
            </p>
            <form onSubmit={submit} className="flex flex-col gap-4">
                <label className="flex items-start gap-2 text-sm">
                    <input
                        type="checkbox"
                        id="accepted"
                        name="accepted"
                        className="mt-1"
                        checked={form.data.accepted}
                        onChange={(e) =>
                            form.setData('accepted', e.target.checked)
                        }
                    />
                    I have read and accept the terms and privacy policy.
                </label>
                {form.errors.accepted && (
                    <p className="text-sm text-destructive">
                        You need to accept to continue.
                    </p>
                )}
                <Button
                    type="submit"
                    disabled={!form.data.accepted || form.processing}
                >
                    Accept and continue
                </Button>
            </form>
        </>
    );
}

Accept.layout = (page: React.ReactNode) => <AuthLayout>{page}</AuthLayout>;
