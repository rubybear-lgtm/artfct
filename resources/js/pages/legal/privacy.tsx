import { LegalPage, Section } from '@/components/legal-page';

export default function Privacy({ version }: { version: string }) {
    return (
        <LegalPage title="Privacy policy" version={version}>
            <Section title="What we collect">
                <p>
                    Your name and email address from your sign-in provider; the
                    files you upload and their titles, descriptions and
                    provenance (agent, repository, commit); your team, role and
                    invitation records; usage counts for quotas and billing; and
                    an audit log of actions in your team, including IP address
                    and browser.
                </p>
            </Section>
            <Section title="Why we use it">
                <p>
                    To sign you in, serve and search your artifacts, enforce
                    plan limits, bill your team, keep the service secure and
                    show your team what happened in it. We do not sell your data
                    or use your files to train models.
                </p>
            </Section>
            <Section title="Who processes it for us">
                <p>
                    WorkOS (sign-in), Stripe (payments), Cloudflare (file
                    storage, delivery and email) and Railway (application
                    hosting). Each handles data only to provide its part of the
                    service.
                </p>
            </Section>
            <Section title="How long we keep it">
                <p>
                    Artifacts are kept until you delete them or your team&apos;s
                    retention period removes them; artifacts under legal hold
                    are kept until the hold is released. Audit records are
                    append-only and kept with the team. Deleting your account
                    removes your sign-in identities and memberships and revokes
                    your tokens, and anonymises your profile.
                </p>
            </Section>
            <Section title="Your choices">
                <p>
                    You can change your name, leave teams and delete your
                    account from Account settings. To ask for access to or
                    erasure of your data, contact us; erasure is carried out by
                    an operator.
                </p>
            </Section>
            <Section title="Contact">
                <p>Contact address: to be set before public launch.</p>
            </Section>
        </LegalPage>
    );
}
