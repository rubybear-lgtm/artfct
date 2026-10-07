import { Head } from '@inertiajs/react';

import AppLayout from '@/layouts/app-layout';

type Props = {
    team: { slug: string; name: string; plan: 'free' | 'team' | 'enterprise' };
};

export default function TeamAdmin({ team }: Props) {
    return (
        <AppLayout>
            <Head title={`Admin · ${team.name}`} />
        </AppLayout>
    );
}
