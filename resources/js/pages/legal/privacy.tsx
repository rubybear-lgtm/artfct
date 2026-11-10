import { LegalPage, Section } from '@/components/legal-page';

export default function Privacy({ version }: { version: string }) {
    return (
        <LegalPage title="Privacy policy" version={version}>
            <Section title="What we collect">
                <p>
                    Your name and email address from your sign-in provider; the
                    files you upload and their titles, descriptions and
                    provenance (agent, repository, commit); your team, role and
                    invitation records; usage counts for quotas and billing; an
                    audit log of actions in your team, including IP address and
                    browser; and product analytics events (for example sign-in,
                    team creation, artifact share and search) with properties
                    such as team id/slug, plan, whether the actor is a bot,
                    acquisition source tags (UTM and referrer on first visit),
                    and optional &quot;how did you hear about us&quot; answers.
                    We do not send artifact file contents, raw search query
                    text, or invitee email addresses to our analytics processor.
                </p>
            </Section>
            <Section title="Why we use it">
                <p>
                    To sign you in, serve and search your artifacts, enforce
                    plan limits, bill your team, keep the service secure, show
                    your team what happened in it, and understand product usage
                    and growth (for example weekly active teams). We do not sell
                    your data or use your files to train models.
                </p>
            </Section>
            <Section title="What our staff can see">
                <p>
                    A small number of artfct staff can see your team&apos;s
                    plan, billing state, members, connections and usage so they
                    can run and support the service. The tools they use do not
                    show your files, their titles, your comments, your
                    collections or what you search for.
                </p>
                <p>
                    When staff look at your team or change something, such as
                    its plan, its limits, a reindex or an erase, that appears in
                    your team&apos;s audit log as &quot;artfct staff&quot;, and
                    every change includes the reason. We do not show which
                    person it was.
                </p>
                <p>
                    There is one exception. If we are legally required to keep a
                    request confidential, or we are investigating abuse and
                    telling you would put the investigation at risk, we may
                    withhold the record of staff looking at your team from your
                    audit log for a limited time. We always keep our own record
                    of it, and changes staff make to your team always stay
                    visible to you.
                </p>
            </Section>
            <Section title="Who processes it for us">
                <p>
                    WorkOS (sign-in), Stripe (payments), Cloudflare (file
                    storage, delivery and email), Railway (application hosting)
                    and PostHog (product analytics, United States cloud). Each
                    handles data only to provide its part of the service.
                    Browser analytics uses cookieless/memory persistence for
                    anonymous visitors; session replay is off.
                </p>
            </Section>
            <Section title="How long we keep it">
                <p>
                    Artifacts are kept until you delete them or your team&apos;s
                    retention period removes them; artifacts under legal hold
                    are kept until the hold is released. Audit records are
                    append-only and kept with the team, and we keep an internal
                    record of every action our staff take on a team. Deleting
                    your account removes your sign-in identities and memberships
                    and revokes your tokens, and anonymises your profile.
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
                <p>
                    For privacy and data requests, email{' '}
                    <a href="mailto:privacy@artfct.dev">privacy@artfct.dev</a>.
                </p>
            </Section>
        </LegalPage>
    );
}
