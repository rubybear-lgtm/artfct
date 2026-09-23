import { LegalPage, Section } from '@/components/legal-page';

export default function Terms({ version }: { version: string }) {
    return (
        <LegalPage title="Terms of service" version={version}>
            <Section title="What artfct is">
                <p>
                    artfct hosts HTML and Markdown files you upload and gives
                    you a link to share them. Teams add sign-in, members,
                    billing and search on top.
                </p>
            </Section>
            <Section title="Your content">
                <p>
                    You keep ownership of what you upload. You give us the right
                    to store it, serve it to whoever you share the link with,
                    and index it for your team&apos;s search, only to run the
                    service for you.
                </p>
                <p>
                    You are responsible for what you publish and who you share
                    it with. Do not upload anything unlawful, anything you do
                    not have the right to share, malware, or content meant to
                    deceive or harm others.
                </p>
            </Section>
            <Section title="Accounts and teams">
                <p>
                    You sign in through a third-party identity provider. Keep
                    your sign-in secure and your API tokens private: anything
                    done with a token counts as done by you. A team&apos;s owner
                    controls its members, billing and data.
                </p>
            </Section>
            <Section title="Plans and payment">
                <p>
                    Paid plans are billed per seat through Stripe and renew
                    until cancelled. Cancelling takes effect at the end of the
                    period you paid for. If a payment fails, existing artifacts
                    keep serving but new ones are blocked until it is fixed.
                </p>
            </Section>
            <Section title="Acceptable use and enforcement">
                <p>
                    We may remove content or suspend access that breaks these
                    terms or puts the service or other people at risk. We aim to
                    tell you why when we do.
                </p>
            </Section>
            <Section title="No warranty">
                <p>
                    The service is provided as is. We work to keep it available
                    and your data safe, but we cannot promise it will never fail
                    or lose data, so keep your own copies of anything important.
                </p>
            </Section>
            <Section title="Changes and contact">
                <p>
                    We will ask you to accept updated terms when they change
                    materially. Contact address: to be set before public launch.
                </p>
            </Section>
        </LegalPage>
    );
}
