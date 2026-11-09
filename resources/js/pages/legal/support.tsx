import { Head, Link } from '@inertiajs/react';

import { SitePage } from '@/components/site-chrome';
import { docs, privacy, terms } from '@/routes';

export default function Support() {
    return (
        <SitePage>
            <Head title="Support" />
            <main className="mx-auto max-w-2xl px-5 py-12">
                <h1 className="mb-2 text-2xl font-semibold">Support</h1>
                <div className="mt-6 flex min-w-0 flex-col gap-4 text-sm leading-6 break-words">
                    <p>
                        For help with Artfct, email{' '}
                        <a href="mailto:support@artfct.dev">
                            support@artfct.dev
                        </a>
                        .
                    </p>
                    <p>
                        Setup guides live in the{' '}
                        <Link href={docs.url()}>Docs</Link>. Privacy and data
                        requests go to{' '}
                        <a href="mailto:privacy@artfct.dev">
                            privacy@artfct.dev
                        </a>
                        ; see the <Link href={privacy.url()}>Privacy</Link> and{' '}
                        <Link href={terms.url()}>Terms</Link> pages.
                    </p>
                </div>
            </main>
        </SitePage>
    );
}
