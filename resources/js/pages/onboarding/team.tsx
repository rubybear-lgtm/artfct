import { Head, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AuthLayout from '@/layouts/auth-layout';

export default function OnboardingTeam({
    suggestedName,
}: {
    suggestedName: string;
}) {
    const form = useForm({ name: suggestedName });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post('/onboarding/team');
    };

    return (
        <>
            <Head title="Create your team" />
            <h1 className="mb-2 text-xl font-semibold">
                Create your first team
            </h1>
            <p className="mb-4 text-sm text-muted-foreground">
                A team holds your artifacts, API tokens and billing, and you are
                its owner. You can invite teammates next.
            </p>
            <form onSubmit={submit} className="flex flex-col gap-3">
                <Label htmlFor="name">Team name</Label>
                <Input
                    id="name"
                    name="name"
                    value={form.data.name}
                    onChange={(event) =>
                        form.setData('name', event.target.value)
                    }
                    autoFocus
                />
                {form.errors.name && (
                    <p className="text-sm text-destructive">
                        {form.errors.name}
                    </p>
                )}
                <Button type="submit" disabled={form.processing}>
                    Create team
                </Button>
            </form>
        </>
    );
}

OnboardingTeam.layout = (page: React.ReactNode) => (
    <AuthLayout>{page}</AuthLayout>
);
