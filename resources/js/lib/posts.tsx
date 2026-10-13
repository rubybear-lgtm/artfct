import React from 'react';

import { BreakFigure, LoopFigure } from '@/components/contradicting-figures';
import { docs } from '@/routes';
import { free } from '@/routes';
import blogRoutes from '@/routes/blog';
import llmsRoutes from '@/routes/llms';

// ── post type ──────────────────────────────────────────────────────────────────

export interface Post {
    slug: string;
    date: string;
    title: string;
    tag: string;
    description: string;
    /** Header image for the article, relative to /public. */
    image?: string;
    body: React.ReactNode;
}

// ── subcomponents ──────────────────────────────────────────────────────────────

export function P({ children }: { children: React.ReactNode }) {
    return (
        <p className="mb-5 leading-[1.75] text-muted-foreground">{children}</p>
    );
}

export function H2({ children }: { children: React.ReactNode }) {
    return (
        <h2 className="mt-12 mb-4 font-serif text-2xl leading-tight font-medium tracking-tight text-foreground">
            {children}
        </h2>
    );
}

export function H3({ children }: { children: React.ReactNode }) {
    return (
        <h3 className="mt-12 mb-4 font-serif text-2xl leading-tight font-medium tracking-tight text-foreground">
            {children}
        </h3>
    );
}

export function Mono({ children }: { children: React.ReactNode }) {
    return (
        <code className="rounded bg-muted px-1.5 py-0.5 font-mono text-[0.85em] text-foreground">
            {children}
        </code>
    );
}

export function A({
    href,
    children,
}: {
    href: string;
    children: React.ReactNode;
}) {
    return (
        <a
            href={href}
            target={href.startsWith('http') ? '_blank' : undefined}
            rel={href.startsWith('http') ? 'noreferrer' : undefined}
            className="font-semibold text-primary underline-offset-4 hover:underline"
        >
            {children}
        </a>
    );
}

export function Quote({ children }: { children: React.ReactNode }) {
    return (
        <blockquote className="mb-5 border-l-2 border-border pl-4 leading-[1.75] text-muted-foreground italic">
            {children}
        </blockquote>
    );
}

export function FaqItem({
    question,
    children,
}: {
    question: string;
    children: React.ReactNode;
}) {
    return (
        <>
            <h3 className="mt-8 mb-2 font-serif text-lg leading-snug font-medium text-foreground">
                {question}
            </h3>
            <P>{children}</P>
        </>
    );
}

// ── post content ───────────────────────────────────────────────────────────────

const POSTS_UNSORTED: Post[] = [
    {
        slug: 'artfct-vs-a-shared-drive-folder',
        date: '2026-10-04',
        title: 'Artfct vs a shared drive: what works better',
        tag: 'comparisons',
        description:
            'A shared drive folder holds the files people open. Artfct holds the pages AI tools make, records which tool produced each one, and answers search for the next agent. Where each one fits, and when a team should keep both.',
        body: (
            <>
                <P>
                    A shared drive folder and Artfct both hold work, and they
                    answer different questions. A drive stores the files a
                    person will open, in the formats the team already uses, at
                    no extra cost. Artfct stores the pages an AI tool made,
                    records which tool and client produced each one, and lets
                    the next agent find the page without a person filing it.
                </P>
                ,<H2>What is a shared drive folder for?</H2>,
                <P>
                    A shared drive folder is a storage location that a group of
                    colleagues can all open. It is organized as folders and
                    files rather than as one store. The platform that hosts it
                    manages access: a folder or an individual file is shared
                    with named people, and an administrator can see what exists
                    and remove it.
                </P>
                ,
                <P>
                    Three qualities explain why teams keep using one. It is
                    universal, because a folder accepts any file a person
                    produces in any application, in the format that application
                    chose. It is familiar, because nearly everyone has used one
                    and no explanation is required. It is usually free at the
                    margin, because the storage already sits inside a
                    subscription the company pays for.
                </P>
                ,
                <P>
                    The reader it is built for is a person. Someone opens the
                    folder, recognizes the file by its name, and opens it in the
                    application that made it. That job is done well, and it is
                    not a small one.
                </P>
                ,<H2>What is Artfct for?</H2>,
                <P>
                    Artfct is a place to publish what an AI tool made, and to
                    find it again later. The unit is a page rather than a file
                    under revision. A publish carries a self-contained HTML
                    document, or a bundle of up to 500 files with an entry page
                    and nested assets, and it renders in a browser when the link
                    is opened.
                </P>
                ,
                <P>
                    Publishing is one deliberate action. Identical content
                    published twice resolves to one artifact instead of a
                    duplicate, because the identifier is derived from the
                    content itself. A later version can be published on the same
                    link, and earlier versions can be listed and restored. A
                    link a colleague saved last month therefore still opens the
                    current page.
                </P>
                ,
                <P>
                    The audience is double. A teammate opens the link, and a
                    connected AI tool reaches the same store through a hosted
                    server that advertises ten tools. Search there fuses
                    semantic similarity with keyword matching, and it orders
                    results by recency, by how often an artifact has been
                    opened, and by whether it sits in a pinned collection. The{' '}
                    <A href={docs().url}>documentation</A> lists what each
                    connection is allowed to ask for. Artfct keeps the record of
                    the work; it does not write or edit the page.
                </P>
                ,<H2>What can an AI agent read in each?</H2>,
                <P>
                    The axis that matters is what a machine reads without a
                    person in the loop. A shared drive is organized for human
                    eyes. Its hierarchy of folders, its names chosen by whoever
                    saved the file, and its formats all belong to the
                    applications that produced them. Nothing in that structure
                    is addressed to an agent, and Artfct ships no connection
                    that reads a drive. Where a team has built a path from a
                    folder into an agent, someone maintains that path and
                    decides what it may see.
                </P>
                ,
                <P>
                    Artfct inverts the arrangement. Its store is the set of
                    pages published to it, and every connected tool can search
                    that set. An agent asks in plain language and receives
                    summaries, excerpts and links. That is the difference
                    between rebuilding an analysis and finding the one the team
                    already paid for.
                </P>
                ,
                <P>
                    The result is a division of labor rather than a winner. A
                    drive holds the working material that a person edits, and
                    Artfct holds the finished pages that the tools produced. The
                    machine-readable reference at{' '}
                    <A href={llmsRoutes.full.url()}>llms-full.txt</A> lists
                    every tool and the arguments each one takes.
                </P>
                ,<H2>Which one records what produced the file?</H2>,
                <P>
                    A drive answers a different question from the one an
                    AI-built page raises. It records the name of the file, its
                    folder, its size and the time it last changed, along with
                    the account that saved it. That is enough when the author is
                    a person and the reader is a colleague. It does not record
                    which tool produced the contents, because the tool wrote
                    into an application and the person saved the output.
                </P>
                ,
                <P>
                    Artfct keeps a provenance entry for each artifact, with
                    source attribution recorded field by field. On the supported
                    connection path that entry holds the tool name, the client
                    name and version, and the session identifier. It holds the
                    model when the caller supplies one.
                </P>
                ,
                <P>
                    Some fields are absent, and the page states them plainly.
                    Repository, branch and commit are recorded as absent. An
                    earlier connection method supplied them, and the hosted
                    server does not. That absence is part of the record rather
                    than a defect in it.
                </P>
                ,
                <P>
                    The record earns its keep at the point of doubt. When two
                    versions of a number exist, knowing how a page was published
                    starts the conversation, and a timestamp alone rarely
                    settles it.
                </P>
                ,<H2>Who remembers to file the output?</H2>,
                <P>
                    A drive depends on a person remembering to file the output.
                    Someone exports the result, chooses a name, decides on a
                    folder, and tells a colleague where it went. If that person
                    is busy, or leaves, or reads the name differently from the
                    next person, the file exists and nobody finds it.
                </P>
                ,
                <P>
                    Artfct moves that obligation into the publish itself. The
                    tool that made the page publishes it in the same call, so
                    the link exists as soon as the work does. The filing step
                    belongs to the publish rather than to a person.
                </P>
                ,
                <P>
                    Repetition shows the effect most clearly. Because a
                    published artifact is content-addressed, the same page
                    published twice resolves to one artifact rather than two
                    copies with slightly different names. Two versions of a
                    number are worse than one, because nobody can tell which is
                    current.
                </P>
                ,<H2>How do the two compare at a glance?</H2>,
                <P>
                    A comparison between a drive and Artfct is clearer when it
                    runs on the jobs each one does, rather than on a count of
                    features both happen to share. The table below sets out the
                    six questions that decide most cases.
                </P>
                ,<H2>When is a shared drive the better choice?</H2>,
                <P>
                    The drive wins in the cases it was built for, and those
                    cases are common.
                </P>
                ,
                <P>
                    A team that already has one pays nothing extra to keep using
                    it, and adopting Artfct does not retire that storage. The
                    folder accepts a spreadsheet, a deck, a signed contract or a
                    photograph without conversion, so no existing material has
                    to move.
                </P>
                ,
                <P>
                    Editing is the second reason. A drive is where several
                    people open the same document and work on it together, and
                    document editing is outside the scope of Artfct by design.
                    Artfct holds finished pages rather than documents under
                    revision.
                </P>
                ,
                <P>
                    The third reason is approval cost. A drive needs no new
                    vendor, no new access review and no explanation to anyone.
                    Where the outputs of a team are read by colleagues and never
                    by an agent, that settles the question.
                </P>
                ,<H2>When does Artfct earn a place beside the drive?</H2>,
                <P>
                    Artfct earns its place when the work has already been made
                    by a tool and will be read by one.
                </P>
                ,
                <P>
                    The first sign is a store of rebuilt work. An engineer asks
                    for an analysis a colleague produced last month and receives
                    a second, slightly different one. The earlier page lived
                    inside the tool that made it, and no other tool could see
                    it. Each vendor indexes its own output, and a competitor's
                    pages are not part of that index. Artfct is neutral storage
                    for that gap, which neither the drive nor a single vendor
                    fills.
                </P>
                ,
                <P>
                    The second sign is a question about origin. A model changes,
                    a prompt is revised, a number is challenged in a review. A
                    page that records the tool and the client that produced it
                    gives that discussion a starting point.
                </P>
                ,
                <P>
                    The third sign is reach. A team artifact is open to every
                    member of the workspace, a private one is limited to the
                    owner and the team administrators, and a public link opens
                    for anyone holding it, with no account required. A single
                    page can also be published as a link at no cost, and how
                    each level is set is described in the{' '}
                    <A href={free().url}>free tier</A> and in{' '}
                    <A
                        href={blogRoutes.show.url({
                            slug: 'who-sees-what-your-ai-makes',
                        })}
                    >
                        who sees what your AI makes
                    </A>
                    .
                </P>
                ,
                <P>
                    The two arrangements coexist in most teams. A drive keeps
                    the material that people edit, and Artfct keeps the pages
                    that the tools made and answers the search that follows.
                </P>
                ,<H2>What this does not do</H2>,
                <ul className="mb-5 list-disc space-y-2 pl-6 leading-[1.75] text-muted-foreground">
                    <li>
                        It does not accept arbitrary office files, images or
                        archives, and it does not replace a drive as general
                        file storage. An artifact is something an AI made that
                        renders as a page, not a folder of working documents.
                    </li>
                    <li>
                        It does not record the repository, branch or commit an
                        artifact came from. Those fields are absent on the
                        supported connection path.
                    </li>
                    <li>
                        It does not offer per-person sharing. The levels are
                        team, private and public, plus the owner and the team
                        administrators.
                    </li>
                    <li>
                        It does not provide authoring or editing. It stores and
                        finds what an AI already made, and a page is published
                        rather than drafted inside it.
                    </li>
                    <li>
                        A link published without an account carries a five-day
                        sliding expiry and a 1 MB limit for a single file. The
                        permanent tier is what removes the expiry.
                    </li>
                </ul>
                ,<H2>Frequently asked questions</H2>,
                <FaqItem question="Does Artfct replace a shared drive?">
                    No. A drive holds the material people edit, and Artfct holds
                    the pages AI tools produced. Most teams keep both, and the
                    drive keeps its everyday role.
                </FaqItem>
                ,
                <FaqItem question="Is a shared drive folder still worth keeping?">
                    Yes. It is free at the margin, it accepts any file, and it
                    needs no explanation to anyone. A team whose outputs are
                    read only by colleagues has little reason to add a second
                    store.
                </FaqItem>
                ,
                <FaqItem question="Can an AI tool read a file in a shared drive through Artfct?">
                    No. Artfct searches the pages published to it, and it holds
                    no connection to a drive. An agent that reads a folder
                    reaches it through a path the team builds and maintains.
                </FaqItem>
                ,
                <FaqItem question="What is recorded about the origin of a page?">
                    The tool name, the client name and version, the session
                    identifier, and the model when the caller supplies one.
                    Repository, branch and commit are recorded as absent.
                </FaqItem>
                ,
                <FaqItem question="Does the link change when the page is updated?">
                    No. A new version is published on the same link, so a link
                    saved last month opens the current page, and earlier
                    versions can be listed and restored.
                </FaqItem>
                ,
                <FaqItem question="Who can open a team artifact?">
                    Every member of the workspace, and every connected tool that
                    searches the store. A private artifact is limited to the
                    owner and the team administrators, and a public link opens
                    for anyone holding it.
                </FaqItem>
                ,
            </>
        ),
    },
    {
        slug: 'artfct-vs-your-ai-tools-own-sharing',
        date: '2026-10-03',
        title: "Artfct vs your AI tool's own sharing: what works better",
        tag: 'comparisons',
        description:
            "Claude Code Artifacts and OpenAI's Sites share work inside one vendor. Artfct keeps the same artifacts findable across tools. A comparison of reach, controls, durability and retrieval.",
        body: (
            <>
                <P>
                    Every large AI vendor now lets a team share what its agent
                    makes. Claude Code Artifacts and OpenAI's Sites are both
                    governed by the vendor's own administration, with role-based
                    access and per-site sharing modes. Those controls are real,
                    and they work well inside one product. The difference is
                    reach, because a vendor indexes its own output and no vendor
                    indexes a competitor's output.
                </P>
                ,<H2>What is built-in sharing for?</H2>,
                <P>
                    Built-in sharing is a feature of the product that already
                    made the artifact, and it is designed around that fact.
                    Claude Code Artifacts ship inside Anthropic's own product,
                    where an administrator governs them through organization
                    settings and sharing stays inside that workspace. OpenAI's
                    Sites carry administrator-managed role-based access, and
                    each site's audience is drawn from the vendor's own list of
                    people and groups.
                </P>
                ,
                <P>
                    That design fits a team that lives in one tool. The artifact
                    appears where the conversation happened, the permissions
                    come from a directory the organisation already maintains,
                    and nobody adopts a second vendor in order to share a
                    dashboard. For a working session between colleagues who all
                    use the same assistant, this is the shorter path, and it
                    deserves to be said plainly.
                </P>
                ,
                <P>
                    The boundary is the vendor. A product indexes its own
                    conversations, its own files and its own artifacts, and it
                    has no commercial reason to read another vendor's. A report
                    published inside one tool therefore does not exist for a
                    person or an agent working in a different tool. That is a
                    structural limit rather than a missing feature, and it is
                    the whole of the difference this page is about.
                </P>
                ,<H2>What is Artfct for?</H2>,
                <P>
                    Artfct is a record of what a team's agents built. An
                    artifact is a page, a report, a table or a document that an
                    AI already made; Artfct stores it, shares it, and lets the
                    next agent find it. It does not write the work, and it does
                    not edit it.
                </P>
                ,
                <P>
                    There is one way in: the hosted server, added to a tool once
                    and signed into once. Claude Code, Codex, OpenCode and
                    Antigravity are verified against staging. ChatGPT, Cursor,
                    VS Code, Windsurf and Zed have documented setup that has not
                    been tested against staging, and any other client that can
                    reach a remote server can follow the same path. A tool that
                    can only run programs on the same machine cannot connect,
                    because the server is hosted rather than installed.
                </P>
                ,
                <P>
                    A team that only wants to hand someone a single page can{' '}
                    <A href={free().url}>publish it as a link</A> without an
                    account, and the recipient opens it at the same address. For
                    a team that runs several tools, the point is cumulative:
                    every connected tool can read what every other one
                    published.
                </P>
                ,<H2>Why does a single-vendor index leave gaps?</H2>,
                <P>
                    A vendor indexes what it can observe, which is its own
                    product. No laboratory will index a competitor's output,
                    because the artifact is the reason a customer stays. The
                    practical result is that a dashboard made in one tool is
                    invisible in another, and the second tool rebuilds it rather
                    than finding it.
                </P>
                ,
                <P>
                    The behaviour this meets is ordinary rather than exotic.
                    Survey work published by the Pragmatic Engineer in 2026
                    found that most engineers juggle two to four AI tools at
                    once, and JetBrains reported in August 2026 that 90% of
                    professional developers use an AI coding agent weekly, with
                    68% using one daily. Adoption of that shape produces two
                    histories per team, and neither history answers a question
                    asked in the other product.
                </P>
                ,
                <P>
                    Memory accumulates per product, while the work belongs to
                    the team. That mismatch is what an artifact record is
                    designed to close, and it is the axis on which the rest of
                    this comparison turns.
                </P>
                ,
                <H2>
                    Who can open an artifact, and who administers the controls?
                </H2>
                ,
                <P>
                    Both options carry real controls, and a comparison that
                    pretends otherwise is not worth reading. Built-in sharing
                    takes its permissions from the vendor's own administration.
                    An administrator enables the feature, access runs through
                    role-based controls, and public sharing is a separate grant
                    that the owner must make.
                </P>
                ,
                <P>
                    Artfct organises access around the artifact itself. A
                    published page is private, shared with the team, or public
                    to anyone holding the link, and edit access is a separate
                    permission so that a colleague can publish the next version.
                    Every sharing level uses one viewer route; a signed-out
                    visitor to a team artifact is sent to sign in and back, and
                    the route never confirms whether an identifier exists. Each
                    artifact also receives its own hostname with a short-lived
                    signed token and a content security policy derived from what
                    that artifact declared, so one artifact's scripts cannot
                    read another's.
                </P>
                ,
                <P>
                    Around that sit the controls an administrator would ask
                    about. Roles cover administrators, members and viewers.
                    Single sign-on runs through SAML or OIDC once a domain is
                    verified by a DNS record. Directory sync provisions and
                    de-provisions people from the identity provider. An audit
                    log records create, view, share, revoke and delete, and it
                    exports. Retention policy and legal hold are configurable,
                    and an administrator can export everything the organisation
                    holds as a set of files verified against their hashes. A
                    list of connected AI tools can be revoked from the same
                    console.
                </P>
                ,
                <P>
                    Sharing is exactly private, team or public, plus the owner
                    and the team administrators. There is no sharing with a
                    hand-picked individual, and a page that claims otherwise
                    would be describing a feature that does not exist.
                </P>
                ,
                <H2>
                    What happens when the tool that made the artifact changes?
                </H2>
                ,
                <P>
                    A link produced inside a vendor's product is tied to that
                    product. Where an artifact is served from, and for how long,
                    is the vendor's decision rather than the team's. Nothing
                    here is a criticism of that arrangement; it follows from the
                    artifact living inside the tool.
                </P>
                ,
                <P>
                    Artfct separates the artifact from the tool that produced
                    it. Publishing returns a link that does not change when the
                    content does, because a later version is published on the
                    same address, so a link pasted into a ticket still resolves
                    after the numbers are revised. Earlier versions can be
                    listed, and one can be restored.
                </P>
                ,
                <P>
                    Identical content resolves to one artifact rather than two,
                    because the identifier is derived from the content itself.
                    Two agents that produce the same report produce one page
                    rather than a duplicate with a second address. The record
                    kept about the work is bounded and honest: it carries the
                    publishing tool, the client and its version, the session
                    identifier, and the model when the caller supplies it.
                    Repository, branch and commit details are not captured on
                    the hosted connection path, so the record describes how the
                    artifact arrived rather than where it came from. How a
                    replacement version is published is described in the{' '}
                    <A href={docs().url}>documentation</A>.
                </P>
                ,<H2>How is the work found again later?</H2>,
                <P>
                    A published page answers people and agents through the same
                    index. A teammate describes what they are looking for
                    without knowing a filename, and an agent asks before it
                    starts work, so the next session begins from what the team
                    already concluded. A record of how this behaves in practice
                    is kept in{' '}
                    <A
                        href={blogRoutes.show.url({
                            slug: 'finding-what-your-ai-already-made',
                        })}
                    >
                        finding what your AI already made
                    </A>
                    .
                </P>
                ,
                <P>
                    Ranking fuses two signals. Semantic similarity finds work
                    about the same subject in different words, and keyword
                    matching finds the exact term when the exact term matters.
                    The fused result is then ordered by recency, by how often an
                    artifact has been opened, and by whether it sits in a pinned
                    collection. The consequence is deliberate: a canonical
                    artifact outranks a semantically closer one that nobody has
                    opened.
                </P>
                ,
                <P>
                    A search can be narrowed by collection and by date, and by
                    repository or producing agent for artifacts that carry those
                    values, which on the hosted path most do not. The list tool
                    a connected agent calls accepts a wider set — owner, sharing
                    level, collection, producing agent, a date window, title
                    text and file kind, sorted by most recently updated, most
                    opened, or title. The console's own list filters by
                    repository, by AI tool, and by title or description. A Slack
                    command returns the same results privately to the person who
                    asked, never to the channel. Setup for each tool is listed
                    in the <A href={llmsRoutes.full.url()}>full reference</A>.
                </P>
                ,<H2>How do the two compare side by side?</H2>,
                <P>
                    The dimensions below decide most of the question. Feature
                    counts do not, because both options share a page, both
                    render it, and both protect it.
                </P>
                ,<H2>Which should a team choose?</H2>,
                <P>
                    Choose built-in sharing when the team works in one AI tool
                    and the artifact is part of that working session. A
                    dashboard produced and read inside the same product, shared
                    with colleagues who already have accounts there, is served
                    well by the controls the vendor already maintains.
                    Administration stays in one place, the directory does the
                    work, and no second vendor enters the procurement
                    conversation. A team that has deliberately consolidated on
                    one assistant should use what that assistant ships.
                </P>
                ,
                <P>
                    Choose Artfct when more than one tool is in use and the
                    artifact has to outlive the session that produced it. A
                    report made in one product and read by an agent in another
                    is the case the vendor cannot serve, and it is the case that
                    produces silent duplication. Two tools, two histories, and a
                    second version of a number the team had settled is worse
                    than one version nobody can find.
                </P>
                ,
                <P>
                    The two are not rivals in most organisations. Built-in
                    sharing is where the work happens; an artifact record is
                    where it stays. Which pages see which audience is covered in{' '}
                    <A
                        href={blogRoutes.show.url({
                            slug: 'who-sees-what-your-ai-makes',
                        })}
                    >
                        who sees what your AI makes
                    </A>
                    , and how a link keeps its history is covered in{' '}
                    <A
                        href={blogRoutes.show.url({
                            slug: 'one-link-that-keeps-its-history',
                        })}
                    >
                        one link that keeps its history
                    </A>
                    .
                </P>
                ,<H2>What this does not do</H2>,
                <ul className="mb-5 list-disc space-y-2 pl-6 leading-[1.75] text-muted-foreground">
                    <li>
                        It does not author or edit documents. Artfct stores and
                        finds what an AI already made, and it is not a place to
                        write.
                    </li>
                    <li>
                        It does not accept arbitrary office files, images or
                        archives. An artifact is something an AI made that
                        renders as a page.
                    </li>
                    <li>
                        It does not record repository, branch or commit
                        provenance on the hosted connection path, so an artifact
                        does not name the codebase it came from.
                    </li>
                    <li>
                        Sharing is exactly private, team or public, plus the
                        owner and the team administrators. There is no
                        per-person share.
                    </li>
                    <li>
                        Indexing can be switched off for a deployment, and a
                        re-index is rate-limited to once per ten minutes per
                        artifact.
                    </li>
                    <li>
                        A link published without an account carries a five-day
                        sliding expiry and a 1 MB limit for a single file. The
                        permanent tier is what removes the expiry.
                    </li>
                </ul>
                ,<H2>Frequently asked questions</H2>,
                <FaqItem question="Do the AI vendors lack governance controls?">
                    No, and the opposite is closer to the truth. They have real
                    ones. Claude Code Artifacts and OpenAI's Sites are governed
                    by the vendor's own administration, with role-based access
                    and per-site sharing modes. The structural limit is
                    cross-vendor: a report made in one product is invisible to
                    an agent running in another, because no vendor indexes a
                    competitor's output.
                </FaqItem>
                ,
                <FaqItem question="Is Artfct a replacement for the AI tools a team already uses?">
                    No. It does not generate or edit anything. It receives a
                    finished artifact from whichever tool made it, stores it
                    under a stable link, and answers searches from every
                    connected tool. The tools keep doing the producing.
                </FaqItem>
                ,
                <FaqItem question="Can two colleagues share a page with each other alone?">
                    No. Sharing is private, team or public, plus the owner and
                    the team administrators. A narrower grant is not available,
                    so a page that needs to reach one person is published to the
                    team or by link instead.
                </FaqItem>
                ,
                <FaqItem question="What keeps one published page from reading another?">
                    Each artifact receives its own hostname, a short-lived
                    signed token, and a content security policy derived from
                    what that artifact declared. Pages are cookieless, and one
                    artifact's scripts cannot reach another's data.
                </FaqItem>
                ,
                <FaqItem question="Can an artifact be taken back?">
                    Yes. An administrator can revoke it from the console, the
                    artifact leaves every list and every search, and the
                    revocation is written to an audit log that exports.
                </FaqItem>
                ,
                <FaqItem question="What does it cost to try?">
                    A single page can be published as a link with no account,
                    encrypted and passcode-protected, and it expires five days
                    after the last visit. A durable artifact is held under the
                    organisation's own keys, with no expiry.
                </FaqItem>
                ,
            </>
        ),
    },
    {
        slug: 'why-your-ai-tools-cannot-see-each-others-work',
        date: '2026-10-02',
        title: "Why your AI tools cannot see each other's work",
        tag: 'explainers',
        description:
            'Every AI tool keeps its own record of what it made, so the next agent rebuilds work another tool already finished. Why that boundary exists, and what changes when the record is shared.',
        body: (
            <>
                <P>
                    Cross-tool blindness is what happens when each AI tool
                    remembers only its own output. It is a product boundary
                    rather than an oversight. A vendor indexes the work its own
                    model produced and has no commercial reason to index a
                    rival's, so the unit of memory is the tool while the unit of
                    work is the team.
                </P>
                ,
                <H2>
                    What does it mean for one AI tool to see another tool's
                    work?
                </H2>
                ,
                <P>
                    An AI tool sees its own work when the product around the
                    model keeps a searchable record of what that model produced.
                    The record holds the finished item rather than the
                    conversation that led to it. An artifact is something an AI
                    makes that stands on its own as a page: a dashboard, a
                    report, a table, a diagram or a short document.
                </P>
                ,
                <P>
                    The record is an index, and the index is what makes it
                    useful. An index is a structure built for retrieval, so a
                    question asked in plain language returns the items that
                    match rather than a list of files to open one by one. A tool
                    searches its own index every time it answers from history.
                </P>
                ,
                <P>
                    Seeing another tool's work means something stronger again.
                    The second tool must be able to find the first tool's
                    artifact, open it, and reuse it without a person copying
                    anything across by hand. The structural reason is that no
                    vendor indexes a competitor's output, so the gap is
                    reproduced by every product boundary rather than being a
                    feature anyone is late to ship. It exists inside a single
                    product line, where one vendor owns both the output and the
                    index of it.
                </P>
                ,<H2>Why does each AI tool keep its own memory?</H2>,
                <P>
                    Every AI tool ships with a product around its model, and
                    that product keeps the record of what the model made inside
                    it. The record serves the vendor's own customers, inside the
                    vendor's own workspace. There is no commercial reason for
                    one vendor to index a competitor's output, and there is a
                    strong reason to keep the record where the vendor can govern
                    it.
                </P>
                ,
                <P>
                    The consequence is structural rather than accidental. The
                    unit of memory is the tool, while the unit of work is the
                    team. A person moves between several tools in a single week,
                    and each tool sees a fragment of that movement.
                </P>
                ,
                <P>
                    Teams rarely standardise on one tool. Different roles reach
                    for different assistants, and the tools rarely agree on
                    where finished work should live. The natural fault line
                    therefore runs between the products, not inside them.
                </P>
                ,
                <P>
                    Closing the gap requires a record that no single tool owns.
                    That is a different product from any of the tools it
                    connects, because its whole value lies in spanning them.
                </P>
                ,
                <H2>
                    What actually breaks when the tools cannot see each other?
                </H2>
                ,
                <P>
                    The first cost is repeated work. When the next agent cannot
                    see what the last one produced, it produces the work again,
                    often differently. Two dashboards with the same title and
                    slightly different numbers are worse than one, because
                    nobody can tell which one to trust.
                </P>
                ,
                <P>
                    The second cost is accumulation that never happens. A good
                    report format, a useful dashboard pattern, a runbook that
                    worked, all stay one-off outputs instead of becoming the way
                    a team works.
                </P>
                ,
                <P>
                    The third cost falls on people who are not engineers. A
                    product manager who generates a report in one tool has
                    nowhere to put it that the rest of the team will look.
                    Multi-tool work is the norm:{' '}
                    <A href="https://newsletter.pragmaticengineer.com/p/ai-tooling-2026">
                        most engineers juggle two to four AI tools at once
                    </A>
                    ,{' '}
                    <A href="https://blog.jetbrains.com/research/2026/08/ai-coding-agent-adoption-2026/">
                        90 percent of professional developers use an AI coding
                        agent at work each week
                    </A>
                    , and 68 percent use one daily.
                </P>
                ,
                <P>
                    Those figures describe tools in use. They do not describe
                    work shared between tools, and nothing in them suggests that
                    outputs travel. The gap is not measured by adoption, because
                    adoption is precisely what creates it.
                </P>
                ,<H2>What does the gap look like in practice?</H2>,
                <P>
                    Consider a twelve-person product team that runs three tools:
                    one the analysts use, one the engineers use, and one that
                    marketing keeps for campaign work. In January, an analyst
                    asks the analytics tool for a monthly churn review. The tool
                    produces an interactive dashboard with a written summary,
                    the analyst shares the link, and the dashboard becomes the
                    version people quote in planning meetings.
                </P>
                ,
                <P>
                    In April, an engineer is asked for the same breakdown. The
                    engineer works in a different tool, which has no knowledge
                    of the January dashboard. The tool answers the question
                    well, because it can, and produces a second churn dashboard.
                    Its definition of an active account differs by a single
                    filter from the first one. Both documents are correct. The
                    numbers disagree.
                </P>
                ,
                <P>
                    The analyst and the engineer then spend a meeting
                    reconciling two artifacts that describe the same business.
                    Neither tool did anything wrong. Each answered inside the
                    history it keeps. The team paid for the same analysis twice,
                    and the second version is now the one in circulation.
                </P>
                ,
                <P>
                    The fix is not a better prompt or a longer context window. A
                    shared record changes the second half of that story. The
                    engineer's tool could ask whether the team already has a
                    churn review, find the January dashboard, and read it before
                    building anything. The engineer would still decide whether
                    to reuse it or start again. The difference is that the
                    decision is made with the earlier work in view.
                </P>
                ,<H2>Do the labs' own sharing controls close the gap?</H2>,
                <P>
                    This is the fair objection, and the honest answer begins
                    with a concession. During 2026 the major labs shipped
                    governance inside their own products.{' '}
                    <A href="https://venturebeat.com/data/anthropics-claude-code-artifacts-update-brings-live-shared-dashboards-and-interactive-workspaces-to-enterprises">
                        Claude Code Artifacts
                    </A>{' '}
                    require an administrator to enable them for an organization,
                    and the owner then grants permission before a page is shared
                    publicly.{' '}
                    <A href="https://intuitionlabs.ai/articles/enterprise-ai-dashboards-chatgpt-claude">
                        OpenAI Canvas Sites
                    </A>{' '}
                    arrived for business and enterprise workspaces with
                    administrator-managed role-based access and per-site access
                    modes that can be self-only, whole-workspace, or limited to
                    named user groups.
                </P>
                ,
                <P>
                    Those controls are real, and a platform lead who has
                    configured them will not be persuaded by a pitch that denies
                    they exist. What they govern is one vendor's output, inside
                    one vendor's workspace. The controls stop at the edge of the
                    product that shipped them.
                </P>
                ,
                <P>
                    The structural problem is subtler than an absence of
                    controls. No lab will index a competitor's output, because
                    the index is the vendor's own product. Anthropic indexing
                    Cursor's artifacts is not a feature that is running late; it
                    is competitively incoherent. The gap lives between products,
                    and every product boundary reproduces it.
                </P>
                ,<H2>What would make cross-tool visibility possible?</H2>,
                <P>
                    Three conditions have to hold at once. Finished work has to
                    be published somewhere outside any single tool. Every tool
                    has to be able to reach that place, which means one shared
                    connection rather than a separate integration for each pair
                    of tools. And the record has to be searchable by the people
                    who need it, not only by the tools.
                </P>
                ,
                <P>
                    Pairwise integration does not scale. Three tools need three
                    connections, and five tools need ten, which is why a single
                    shared endpoint matters more than any individual
                    integration. The open primitives for that endpoint now
                    exist: tools can speak a shared protocol, load shared
                    skills, and use shared plugins, as the growing{' '}
                    <A href="https://github.com/ARUNAGIRINATHAN-K/awesome-ai-agents-2026">
                        cross-vendor agent tooling
                    </A>{' '}
                    around them shows.
                </P>
                ,
                <P>
                    Artfct is one implementation of that shape. Its connection
                    is a hosted, authenticated server that every supported tool
                    reaches in the same way. One person on the team adds it and
                    signs in once, and members install nothing. Claude Code,
                    Codex, OpenCode and Antigravity are verified against
                    staging, while several other tools, including Claude,
                    ChatGPT, Cursor, VS Code, Windsurf and Zed, have setup paths
                    that are documented rather than tested.
                </P>
                ,
                <P>
                    Two audiences then share one record instead of two copies of
                    it. A teammate opens an artifact from a link or from Slack,
                    and a connected agent finds the same artifact through
                    retrieval. The <A href={docs().url}>documentation</A> lists
                    what each connection exposes.
                </P>
                ,<H2>How does a team keep the version people trust on top?</H2>,
                <P>
                    Publishing is one half of the problem, and retrieval is the
                    other. A store that returns ten plausible artifacts is not
                    obviously more useful than no store at all. Ranking is what
                    turns a pile of outputs into a record that people rely on.
                </P>
                ,
                <P>
                    Artfct ranks results by how recently an artifact was
                    updated, how often it has been opened, and whether it sits
                    in a pinned collection. Usage therefore decides what
                    surfaces. A dashboard the team keeps opening outranks one
                    that is semantically close but has never been read.
                </P>
                ,
                <P>
                    This is the mechanism behind a canonical version. Teams do
                    not need ten churn reviews; they need the one that is
                    current, and they need to know that it is. A record that
                    blends recency with real use keeps the working version near
                    the top without an administrator curating anything by hand.
                </P>
                ,
                <P>
                    Search is available to people as well as to agents. The
                    console has a search page, and a Slack command returns
                    results to the person who asked without posting them to the
                    channel. The retrieval tool returns summaries, short
                    excerpts and a link rather than raw files, so an agent can
                    judge relevance before it reads. The{' '}
                    <A href={llmsRoutes.full.url()}>full reference</A> lists the
                    retrieval tools and what each returns.
                </P>
                ,<H2>Where does a shared record cross into governance?</H2>,
                <P>
                    A store that tools can read is also a store that needs
                    rules. Artfct publishes every artifact at one of three
                    levels: private to the owner and administrators, visible to
                    the team, or public to anyone holding the link. Editing is a
                    separate decision from visibility, so a colleague can
                    publish the next version of an artifact while the link stays
                    the same.
                </P>
                ,
                <P>
                    The record is accountable. Creating, viewing, sharing,
                    revoking and deleting all write to an audit log that a team
                    can export, and a team can set a retention policy or place a
                    legal hold that prevents an artifact from being deleted.
                    Each artifact also carries a provenance record: the tool
                    that published it, the session it came from, and the model,
                    when the caller supplied one.
                </P>
                ,
                <P>
                    Two boundary cases are worth stating plainly. Sharing is
                    exactly three levels rather than a per-person list, so an
                    artifact is visible to the whole team or to its owner and
                    the team's administrators. And provenance records the
                    publishing tool and client rather than the repository or
                    commit, because those fields are not captured on the
                    supported connection path.
                </P>
                ,
                <P>
                    Those rules are what make a shared record safe to adopt
                    rather than a second place to lose track of work. Access is
                    re-checked whenever an artifact is opened, and the three
                    levels described here are the ones a team can change at any
                    time. The{' '}
                    <A
                        href={blogRoutes.show.url({
                            slug: 'who-sees-what-your-ai-makes',
                        })}
                    >
                        sharing model
                    </A>{' '}
                    is covered in more detail elsewhere.
                </P>
                ,<H2>What this does not do</H2>,
                <ul className="mb-5 list-disc space-y-2 pl-6 leading-[1.75] text-muted-foreground">
                    <li>
                        It does not make a model smarter. It moves finished work
                        between tools rather than reasoning, and it does not
                        change what a model knows.
                    </li>
                    <li>
                        It does not accept arbitrary office files, images or
                        archives. An artifact is something an AI made that
                        renders as a page.
                    </li>
                    <li>
                        Sharing is three levels, not a per-person permission
                        list. An artifact is visible to the whole team, or to
                        its owner and the team's administrators.
                    </li>
                    <li>
                        Provenance records the publishing tool, the client and
                        the model when the caller supplies one. It does not
                        record the repository, the branch or the commit on the
                        supported connection path.
                    </li>
                    <li>
                        A tool that can only start programs on a computer cannot
                        connect, because Artfct runs as a hosted server.
                    </li>
                    <li>
                        The free tier is encrypted and passcode-protected,
                        expires after five days of inactivity, and holds one
                        file of up to one megabyte.
                    </li>
                    <li>
                        Indexing can be switched off for a deployment, and a
                        re-index runs at most once every ten minutes for each
                        artifact.
                    </li>
                </ul>
                ,<H2>Frequently asked questions</H2>,
                <FaqItem question="Why cannot one AI tool read another tool's output?">
                    Because the record of that output lives inside the product
                    that made it. A tool searches its own history, and a
                    different vendor's product does not expose that history to
                    it. The two tools may run the same class of model and still
                    share nothing.
                </FaqItem>
                ,
                <FaqItem question="Does asking both tools the same question fix the problem?">
                    Partly. The second tool will answer, but it answers from its
                    own history rather than from the first tool's work, so the
                    team still ends up with two artifacts and two versions of
                    the same number. A shared record lets the second tool read
                    the first answer before producing a second one.
                </FaqItem>
                ,
                <FaqItem question="Do the AI vendors not provide sharing and access control already?">
                    They do, inside their own products, and those controls are
                    real. What they cannot provide is a record that spans two
                    vendors, because the index of a competitor's output is not a
                    product any vendor is placed to sell.
                </FaqItem>
                ,
                <FaqItem question="Does a shared record make a model smarter?">
                    No. It moves finished artifacts between tools. It does not
                    improve what a model knows or how it reasons, and it is not
                    a context layer for the model.
                </FaqItem>
                ,
                <FaqItem question="How does a teammate find something an agent published?">
                    The same way they would search anything else. They describe
                    what they are looking for, and the record returns matching
                    artifacts with a link, ranked by recency and by what the
                    team has actually opened.
                </FaqItem>
                ,
                <FaqItem question="What happens when two tools produce the same thing?">
                    Identical content resolves to a single artifact rather than
                    a duplicate, and a later version of the same artifact keeps
                    the original link. A teammate who saved the first link still
                    reaches the current version.
                </FaqItem>
                ,
                <FaqItem question="Can an administrator remove something a colleague published?">
                    Yes. An administrator can revoke any artifact in the team,
                    and the revocation is written to the audit log. Retention
                    and legal hold rules apply to that removal as well.
                </FaqItem>
                ,
            </>
        ),
    },
    {
        slug: 'what-is-an-ai-artifact',
        date: '2026-10-01',
        title: 'What is an AI artifact?',
        tag: 'explainers',
        description:
            'An AI artifact is a finished work product an AI tool made and a person can open: a report, dashboard, table or document. This explainer defines the term, separates it from chat scrollback and memory notes, and says where a record of finished work fits.',
        body: (
            <>
                <P>
                    An AI artifact is a finished work product that an AI tool
                    produced and that a person can open: a report, a dashboard,
                    a table, a document, or an interactive page. It is the
                    output, not the conversation that produced it. That
                    distinction matters, because most AI work today disappears
                    into a chat window when the session ends.
                </P>
                ,<H2>What counts as an artifact?</H2>,
                <P>
                    This page holds one definition and applies it throughout. An
                    artifact is a finished work product that an AI tool made and
                    that renders as a page a reader can open unaided. A revenue
                    report, a dashboard of pipeline numbers, a comparison table,
                    a short written document and an interactive page all
                    qualify, and the form varies while the test does not.
                </P>
                ,
                <P>
                    The test is whether a colleague can use the thing without
                    the conversation that produced it. A finished report passes,
                    because its numbers and its reasoning arrive together and
                    stand on their own. A half-finished outline fails, because
                    the reader cannot separate what was decided from what was
                    merely considered. An artifact sits at the finished end of
                    that spectrum, which is why the word carries the weight it
                    does.
                </P>
                ,
                <P>
                    The same word appears elsewhere in software, and those other
                    senses cause much of the confusion this explainer addresses.
                    In a build pipeline an artifact is a compiled file, a
                    package or a signed manifest. In a design tool it is a
                    mock-up handed between stages. Both senses are legitimate,
                    and neither is the one used here.
                </P>
                ,<H2>How is an artifact different from a chat transcript?</H2>,
                <P>
                    A conversation with an AI tool is reasoning made visible.
                    The transcript holds the question, the false starts, the
                    corrections and the final answer, and it is genuinely
                    valuable to the person who was present for it. It is much
                    less valuable to anyone else, because a reader has to
                    reconstruct which turn ended up mattering.
                </P>
                ,
                <P>
                    Scrollback is also bound to the tool that produced it. It
                    lives inside one account, it belongs to one session, and it
                    was never designed for a second person to open later. The
                    unit of memory is the tool, while the unit of work is the
                    team, and that mismatch is the reason finished work goes
                    missing. The problem is described in more detail in{' '}
                    <A
                        href={blogRoutes.show.url({
                            slug: 'finding-what-your-ai-already-made',
                        })}
                    >
                        finding the report a team already wrote
                    </A>
                    .
                </P>
                ,
                <P>
                    A second difference matters more than tidiness. A transcript
                    cannot be cited. A decision document can link to a report at
                    a stable address, and a reader follows that link to the same
                    table the decision rested on. Nobody links to a middle turn
                    of a session, and a record that cannot be cited does not
                    survive a review.
                </P>
                ,<H2>How is an artifact different from a memory note?</H2>,
                <P>
                    A memory note is an input. Assistants increasingly keep a
                    small store of facts, preferences and terminology, and they
                    read that store at the beginning of a session so that later
                    answers fit the organisation. The note is never the
                    deliverable itself; it shapes the deliverable, quietly and
                    continuously.
                </P>
                ,
                <P>
                    An artifact is an output. It is the thing that was asked
                    for, finished, and handed to a reader who may never know
                    which tool made it. The distinction is not cosmetic, because
                    the two stores change different behaviour in different
                    places. Editing a memory note changes what a model generates
                    next, while keeping an artifact changes what a team can find
                    long afterwards.
                </P>
                ,
                <P>
                    A useful way to hold the difference is to look at the
                    intended audience. A memory note is written to influence a
                    future model call, while an artifact is written to inform a
                    future reader. The first is addressed to a model and the
                    second to a person, and those two audiences make very
                    different demands of the same words.
                </P>
                ,
                <P>
                    Treating the two as substitutes produces a predictable
                    disappointment. A team that relies on memory alone gets
                    answers phrased in house language and very little it can
                    hand to a client or an auditor. A team that keeps artifacts
                    gets a record of the work itself, and that record outlives
                    the session which produced it.
                </P>
                ,<H2>Why is artfct not an &ldquo;artifact store&rdquo;?</H2>,
                <P>
                    The phrase is common in platform conversations, and it is
                    not the description this category needs. To a platform lead,
                    an artifact store is Artifactory or Harness, not a record of
                    what a team's agents built. That category holds build
                    outputs, binaries, packages and signed manifests, and it
                    sits inside a continuous integration pipeline beside the
                    code it was compiled from.
                </P>
                ,
                <P>
                    That job is real, mature and well served by the tools built
                    for it, and it is a different job from the one this page
                    describes. A build artifact is consumed by a deployment
                    process and rarely read by a person. A work artifact is read
                    by a person, linked in a document and searched by whoever
                    needs the same answer again.
                </P>
                ,
                <P>
                    artfct is a record of what your agents built. Publishing a
                    page or a document produces a link, keeps the content at
                    that address, and makes the artifact findable by any
                    connected tool and by any colleague who has access. The
                    category is deliberately narrower than file storage and
                    deliberately broader than one vendor's chat history.
                </P>
                ,<H2>What makes an artifact durable?</H2>,
                <P>
                    Durability here means that a reader can open the same thing
                    long after it was published and reach the same content.
                    Three mechanisms carry that weight. The first is
                    content-addressed identity: publishing identical content
                    twice yields one artifact rather than two, so a team does
                    not accumulate near-copies that differ only in the hour they
                    were made.
                </P>
                ,
                <P>
                    The second is versioning under a stable address. A corrected
                    report is published as a new version of the same artifact,
                    and a link already pasted into a document keeps working.
                    Earlier versions stay listed and can be restored, which
                    turns a mistake into a reversible event rather than a lost
                    afternoon. The mechanism is set out in{' '}
                    <A
                        href={blogRoutes.show.url({
                            slug: 'one-link-that-keeps-its-history',
                        })}
                    >
                        one link that keeps its history
                    </A>
                    .
                </P>
                ,
                <P>
                    The third is the provenance record attached to each
                    artifact. On the supported connection it holds the model
                    when the caller supplies one, the session identifier, the
                    publishing tool, and the client name and version. It does
                    not hold the repository, the branch or the commit on that
                    path, and the limits section below explains what that
                    omission means for search.
                </P>
                ,
                <P>
                    Size has a shape as well. A single self-contained page is
                    one file of up to 1 MB, while a multi-file artifact can
                    carry up to 500 files to a total of 8 MB. Plan limits are
                    lower still. Those bounds are generous for a report and
                    tight enough that a shared link stays a page rather than an
                    application.
                </P>
                ,<H2>How does a team find an artifact later?</H2>,
                <P>
                    Storage without retrieval produces a tidier version of the
                    original problem. The retrieval difficulty is specific and
                    worth naming: the right report and a plausible near-copy
                    often share most of their vocabulary, and the near-copy is
                    frequently the one nobody actually uses. Matching on text
                    alone therefore ranks the wrong answer first, with
                    confidence.
                </P>
                ,
                <P>
                    Search fuses two signals before it ranks anything. Semantic
                    similarity finds work about the same subject in different
                    words, and keyword matching finds the exact term when the
                    exact term is the whole point. The fused result is then
                    ordered by recency, by how often an artifact has been
                    opened, and by whether it sits in a pinned collection. A
                    canonical report therefore outranks a semantically closer
                    draft that nobody reads.
                </P>
                ,
                <P>
                    People and agents reach the same index. A colleague
                    describes what they need and receives matching artifacts
                    with their sources attached, and a connected tool asks the
                    same question in ordinary language and gets back summaries,
                    short excerpts and a link. The same search runs from a
                    console page or from a Slack command, where results return
                    privately to the person who asked rather than to the
                    channel. The <A href={docs().url}>documentation</A> lists
                    the filters and sorts each surface accepts.
                </P>
                ,<H2>Who can see an artifact, and who can change it?</H2>,
                <P>
                    Sharing has three levels: private, team and public. A
                    private artifact is visible to its owner, a team artifact to
                    the members of that team, and a public artifact to anyone
                    holding the link. Visibility and edit rights are separate
                    grants, so a colleague can be given the ability to publish
                    the next version of something they did not create.
                </P>
                ,
                <P>
                    One route serves every level. A signed-out visitor who opens
                    a team artifact is sent to sign in and returned to the page,
                    and the route never confirms whether a particular identifier
                    exists. Each artifact is served from its own hostname with a
                    cookieless token and a content security policy derived from
                    what that artifact declared, so the scripts inside one
                    artifact cannot read the scripts inside another. The access
                    model is covered in{' '}
                    <A
                        href={blogRoutes.show.url({
                            slug: 'who-sees-what-your-ai-makes',
                        })}
                    >
                        who sees what your AI makes
                    </A>
                    .
                </P>
                ,
                <P>
                    Administrators see the same objects through a governance
                    surface. An audit log records creation, viewing, sharing,
                    revocation and deletion, and it can be exported. Retention
                    policies and legal hold sit alongside it, so a record is
                    kept deliberately or released on a schedule rather than by
                    habit.
                </P>
                ,<H2>What does a team do first?</H2>,
                <P>
                    The concept is separable from any product, and a team can
                    test the concept in a single sitting. One person connects a
                    tool once, and every member of the team then publishes and
                    searches through the tools they already use, with nothing
                    further to install on their own machines.
                </P>
                ,
                <P>
                    The connection is a hosted OAuth server reached over HTTP. A
                    single command registers it, and the same setup text appears
                    in the <A href={docs().url}>docs</A> and in the
                    machine-readable reference at{' '}
                    <A href={llmsRoutes.full.url()}>llms-full.txt</A>, because
                    both render from one source and cannot drift apart.
                </P>
                ,
                <P>
                    Readiness varies by tool, and the distinction is worth
                    keeping straight. Claude Code, Codex, OpenCode and
                    Antigravity have been verified against staging. ChatGPT,
                    Cursor, VS Code, Windsurf and Zed have documented paths
                    rather than tested ones, and any other client that can reach
                    a remote server can connect. A publishing skill for agents
                    exists as well, installable with a single command.
                </P>
                ,
                <P>
                    For the sharing half alone, one page can be{' '}
                    <A href={free().url}>published as a link</A> without an
                    account, which is the shortest route to seeing what an
                    artifact is. The same link then opens for a colleague, a
                    client or a connected agent.
                </P>
                ,<H2>What this does not do</H2>,
                <ul className="mb-5 list-disc space-y-2 pl-6 leading-[1.75] text-muted-foreground">
                    <li>
                        It is not a memory layer for a model. It moves finished
                        artifacts rather than reasoning, and it does not change
                        what a model knows or how it writes.
                    </li>
                    <li>
                        It is not a wiki. There is no authoring surface and no
                        editing surface; a record of finished work is kept and
                        found, not written.
                    </li>
                    <li>
                        Notebooks, PDFs and video are not in scope, and neither
                        are arbitrary office files or archives kept for their
                        own sake. An artifact is something an AI made that
                        renders as a page.
                    </li>
                    <li>
                        It is not static site hosting and it is not a content
                        delivery network, and it is not offered as self-hosted
                        or on-prem software. Pages are shared with tens of
                        people rather than millions.
                    </li>
                    <li>
                        There is no per-person sharing. Access is private, team
                        or public, plus the owner and the team's administrators.
                    </li>
                    <li>
                        On the hosted connection the provenance record does not
                        include the repository, the branch or the commit, so a
                        repository filter in search matches only the artifacts
                        that carry one.
                    </li>
                    <li>
                        Indexing is not instantaneous. Re-indexing is limited to
                        once per ten minutes for a given artifact, and an
                        administrator can switch indexing off for a deployment.
                    </li>
                    <li>
                        Turning a pasted link into a preview card inside Slack
                        is not shipped. The search command returns results
                        privately to the person who asked; the unfurl does not
                        follow.
                    </li>
                </ul>
                ,<H2>Frequently asked questions</H2>,
                <FaqItem question="Is a chat transcript an artifact?">
                    No. A transcript records the reasoning that produced an
                    answer, and it stays inside the tool that held the session.
                    An artifact is the finished output, published at an address
                    that another person or another tool can open.
                </FaqItem>
                ,
                <FaqItem question="Can a single table or document be an artifact?">
                    Yes. The test is that it renders as a page and stands on its
                    own, so a table of results or a short report qualifies. A
                    raw spreadsheet file or an archive does not, because nothing
                    renders it and a reader cannot use it in place.
                </FaqItem>
                ,
                <FaqItem question="Does an artifact record which repository it came from?">
                    On the hosted connection, no. The provenance record holds
                    the model when the caller supplies it, the session, the
                    publishing tool and the client, and it leaves the
                    repository, branch and commit empty. A repository filter in
                    search therefore matches only artifacts that carry those
                    fields.
                </FaqItem>
                ,
                <FaqItem question="Can one artifact be shared with a single named person?">
                    Not today. Access is private, team or public, together with
                    the owner and the team's administrators. Edit rights are a
                    separate grant on the same artifact, so a colleague can be
                    allowed to publish the next version without widening who can
                    see it.
                </FaqItem>
                ,
                <FaqItem question="What happens when the same content is published twice?">
                    It resolves to one artifact rather than two, because
                    identity is derived from the content itself. That keeps a
                    team from accumulating near-identical copies that differ
                    only in when they were made.
                </FaqItem>
                ,
                <FaqItem question="Can a report be corrected without breaking a link already shared?">
                    Yes. The correction is published as a new version at the
                    same address, and the earlier versions stay listed, and any
                    of them can be opened or restored, so a document that
                    already cites the report keeps pointing at the current one.
                </FaqItem>
                ,
                <FaqItem question="Which AI tools can publish and search artifacts?">
                    Claude Code, Codex, OpenCode and Antigravity are verified
                    against staging. ChatGPT, Cursor, VS Code, Windsurf and Zed
                    have documented paths that have not been tested against
                    staging, and any other client that can reach a remote server
                    can connect.
                </FaqItem>
                ,
            </>
        ),
    },
    {
        slug: 'finding-what-your-ai-already-made',
        date: '2026-10-08',
        title: 'Finding the report your team already wrote',
        tag: 'search',
        description:
            'Each AI tool keeps its own history, so finished work goes missing. Artfct indexes the reports, tables and documents your agents produce, so the next question starts from what already exists.',
        body: (
            <>
                <P>
                    Teams rarely lack the analysis they need. They lack the
                    ability to find it, because each AI tool keeps its own
                    conversation history and nothing keeps the finished work.
                    Artfct indexes the reports, tables and documents a team's
                    agents produce, so the next question starts from what
                    already exists rather than from nothing.
                </P>
                ,<H2>Why does finished work go missing?</H2>,
                <P>
                    An agent produces a dashboard in an afternoon. That
                    dashboard answers a question the team will ask again in
                    three weeks, from a different tool, by a different person.
                    Nothing about the workflow is broken, and nothing is lost in
                    any obvious sense. The work is simply unavailable to anyone
                    who did not watch it being made.
                </P>
                ,
                <P>
                    The cause is structural rather than accidental. An AI tool
                    indexes what it can see, which is its own conversations and
                    its own files. A vendor has no commercial reason to index a
                    competitor's output, so a report produced in Cursor stays
                    invisible to an assistant running in Claude Code. The unit
                    of memory is the tool, while the unit of work is the team.
                </P>
                ,
                <P>
                    The usual workaround is to copy the output somewhere shared.
                    That works once. It depends on a person remembering to do
                    it, and it produces a file with no record of how it was made
                    or whether the numbers have since changed. Artfct records
                    how each artifact was published and keeps a version history
                    under one link, which is the subject of{' '}
                    <A
                        href={blogRoutes.show.url({
                            slug: 'one-link-that-keeps-its-history',
                        })}
                    >
                        one link that keeps its history
                    </A>
                    .
                </P>
                ,<H2>What does a search actually match on?</H2>,
                <P>
                    Artfct indexes the artifact itself: the finished page,
                    report, table or document, together with the record of how
                    it was published. A search therefore matches on what the
                    work says, rather than on its filename or the folder it
                    landed in.
                </P>
                ,
                <P>
                    Extraction is not always a matter of reading markup. Many
                    artifacts an agent produces are interactive, and their
                    substance appears only once the page runs. Artfct detects
                    that case, renders the artifact, and indexes the text a
                    reader would actually see. Rendering depends on a renderer
                    being configured and on indexing being switched on for the
                    deployment. The pipeline is described in the{' '}
                    <A href={docs().url}>documentation</A>.
                </P>
                ,
                <P>
                    Nothing becomes searchable until it is shared. A private
                    artifact stays out of another member's results, and an
                    artifact that has been revoked leaves the index rather than
                    lingering in it. Who may open an artifact, and who may
                    change that, is covered in{' '}
                    <A
                        href={blogRoutes.show.url({
                            slug: 'who-sees-what-your-ai-makes',
                        })}
                    >
                        who sees what your AI makes
                    </A>
                    .
                </P>
                ,<H2>How does an agent search before it starts?</H2>,
                <P>
                    A connected tool reaches the store through one tool call. It
                    asks a question in ordinary language, and receives
                    summaries, short excerpts and a link for each match. The
                    agent can then say which artifacts it used, so a reader can
                    follow the reasoning back to its source.
                </P>
                ,
                <P>
                    This is the mechanism the rest of the product rests on. An
                    agent that searches first has the option of not rebuilding a
                    report that already exists, rather than producing a second,
                    slightly different version of a number the team had settled.
                    Whether a team's tools take that option is the design's
                    intent rather than a promise this page can make.
                </P>
                ,
                <P>
                    Teams commonly put that expectation in the project
                    instructions an AI tool reads at the start of a session. A
                    single line — search the store before starting research, and
                    name what was used — is enough to make the behaviour
                    consistent across tools.
                </P>
                ,<H2>How is one result ranked above another?</H2>,
                <P>
                    Matching alone produces poor results, because the artifact
                    that shares the most vocabulary is frequently not the one a
                    team relies on. Artfct fuses two signals first: semantic
                    similarity, which finds work about the same subject in
                    different words, and keyword matching, which finds the exact
                    term when the exact term matters.
                </P>
                ,
                <P>
                    The fused result is then ranked by evidence of use. Recency
                    counts, and so does the number of distinct people who have
                    opened an artifact, weighted so that one reader returning
                    five times does not outrank five readers. That signal decays
                    over time, and an artifact superseded by a newer version
                    drops out of the ranking rather than coasting on its
                    history. An artifact pinned to a collection receives an
                    additional lift.
                </P>
                ,
                <P>
                    The practical consequence is that a canonical artifact
                    outranks a semantically closer one nobody has opened. A
                    team's own behaviour becomes part of the ranking, which is
                    what turns a pile of outputs into a set of answers the team
                    trusts.
                </P>
                ,<H2>What can a search be narrowed by?</H2>,
                <P>
                    An agent can restrict a search by collection and by date,
                    and the search tool accepts a repository filter for
                    artifacts that carry one. Those four cover most of the
                    questions a working session raises, particularly the common
                    one about what the team concluded recently in a particular
                    codebase.
                </P>
                ,
                <P>
                    The console serves people browsing rather than asking. Its
                    list can be filtered by title or description, by producing
                    agent, by repository, and by collection. The richer filter
                    set — owner, sharing level, file kind, and a window on when
                    an artifact was created or updated, with sorting by updated,
                    created, most viewed or title — belongs to the list tool a
                    connected agent calls.
                </P>
                ,<H2>Can a person search the same store?</H2>,
                <P>
                    Yes, and the answer arrives through the same index. Someone
                    describes what they are looking for without knowing a
                    filename, and receives the matching artifacts with their
                    sources attached.
                </P>
                ,
                <P>
                    The store also answers inside Slack. A team member runs a
                    search command and the results are returned privately to
                    that person rather than posted to the channel, which keeps a
                    half-formed question from becoming a public one. Setup for
                    every supported tool is listed in the{' '}
                    <A href={llmsRoutes.full.url()}>full reference</A>.
                </P>
                ,
                <P>
                    A team that wants to try the sharing half without connecting
                    a tool can{' '}
                    <A href={free().url}>publish a single page as a link</A>,
                    which needs no account to open.
                </P>
                ,<H2>What this does not do</H2>,
                <ul className="mb-5 list-disc space-y-2 pl-6 leading-[1.75] text-muted-foreground">
                    <li>
                        Search returns summaries, short excerpts and a link. It
                        does not return the whole document, so a reader still
                        opens the source for detail.
                    </li>
                    <li>
                        Nothing is searchable until it has been shared. A
                        private artifact is invisible to every other member and
                        to every agent.
                    </li>
                    <li>
                        Indexing is not instant. A newly published artifact is
                        queued, and an administrator can re-run indexing if a
                        page changes shape.
                    </li>
                    <li>
                        A tool that can only start programs on your own computer
                        cannot connect, because Artfct runs as a hosted server.
                    </li>
                </ul>
                ,<H2>Frequently asked questions</H2>,
                <FaqItem question="Does search read inside a report, or only its title?">
                    Inside it. The artifact is indexed by its content, including
                    pages whose substance appears only after the page renders.
                    Titles and publication details are recorded alongside the
                    text and used for filtering.
                </FaqItem>
                ,
                <FaqItem question="Does an agent see artifacts that were never shared?">
                    No. A search is scoped to the team and excludes anything
                    revoked or inaccessible, so a private artifact is invisible
                    to everyone but its owner and an administrator.
                </FaqItem>
                ,
                <FaqItem question="What makes one result outrank another?">
                    Semantic and keyword matching are fused first, then ranked
                    by recency, how often an artifact has been opened, and
                    whether it sits in a pinned collection.
                </FaqItem>
                ,
                <FaqItem question="Does the team have to tag or file things for search to work?">
                    No. Content is indexed when an artifact is published, and
                    collections only lift results. A collection is how a team
                    marks what mattered, not a precondition for finding it.
                </FaqItem>
                ,
                <FaqItem question="Can a team member use the same search without an AI tool?">
                    Yes. The console has a search page, and a Slack command
                    returns the same results privately to the person who asked.
                </FaqItem>
                ,
            </>
        ),
    },
    {
        slug: 'who-sees-what-your-ai-makes',
        date: '2026-10-07',
        title: 'Who sees what your AI makes',
        tag: 'governance',
        description:
            'An artifact is shared with the team unless someone says otherwise, and the level can change at any time. How sharing, isolation and revocation work in Artfct.',
        body: (
            <>
                <P>
                    An artifact arrives visible to the whole team unless someone
                    says otherwise, and the level can be changed at any time
                    afterwards. Artfct records the choice, records who changed
                    it, and serves each permanent artifact from its own origin
                    so one page cannot read another.
                </P>
                ,<H2>What happens when an artifact is published?</H2>,
                <P>
                    An artifact arrives with a sharing level already set. Three
                    levels exist, and a publisher can name one or leave the
                    default in place.
                </P>
                ,
                <P>
                    Team is the default. Every member of the workspace can open
                    the artifact, and where indexing is enabled, every connected
                    AI tool can find it through search. Private restricts it to
                    the owner and to team administrators. Public means anyone
                    holding the link can open it, with no account required.
                </P>
                ,
                <P>
                    A team can also turn public links off entirely. An
                    organization with a standing rule against open links sets
                    that once, and a publish that asks for public is refused
                    rather than quietly downgraded to something else. The{' '}
                    <A href={docs().url}>documentation</A> lists what a publish
                    can ask for.
                </P>
                ,<H2>Who can change who sees it?</H2>,
                <P>
                    The owner of an artifact, or a team administrator, can
                    change its sharing level after publication. Nobody else can.
                    That keeps the person who made the work in control of it,
                    while giving an administrator an override for the cases that
                    need one.
                </P>
                ,
                <P>
                    The change applies to links that already exist. A link
                    minted while an artifact was visible to the team stops
                    opening it as soon as the owner makes the artifact private.
                    Access is re-checked on every request rather than trusted
                    because a link was issued once, and the credential behind it
                    is scoped to a single artifact.
                </P>
                ,
                <P>
                    Sharing can be changed as many times as a team needs, and
                    every change is recorded.
                </P>
                ,<H2>Can a teammate publish the next version?</H2>,
                <P>
                    Sharing and editing are separate decisions. An artifact can
                    be visible to the whole team while only its owner may
                    publish a new version, or visible to the team with editing
                    open so that a colleague can update it without the link
                    changing.
                </P>
                ,
                <P>
                    The distinction matters for the artifacts a team maintains
                    rather than reads once. A dashboard with a standing link is
                    a small commitment, and someone other than its author
                    eventually needs to refresh it.
                </P>
                ,
                <P>
                    Updates and the history behind them are described in{' '}
                    <A
                        href={blogRoutes.show.url({
                            slug: 'one-link-that-keeps-its-history',
                        })}
                    >
                        one link that keeps its history
                    </A>
                    .
                </P>
                ,<H2>What can an administrator see, and undo?</H2>,
                <P>
                    The console lists every artifact a team's tools have
                    produced, and can be filtered by repository, by AI tool, and
                    by title or description. An administrator can open any
                    artifact or revoke it. Deleting is a separate action,
                    available to a connection that holds the delete permission.
                    Finding one specific artifact in that list is the same
                    problem described in{' '}
                    <A
                        href={blogRoutes.show.url({
                            slug: 'finding-what-your-ai-already-made',
                        })}
                    >
                        finding the report your team already wrote
                    </A>
                    .
                </P>
                ,
                <P>
                    Each of those actions is recorded. Creating, viewing,
                    sharing and revoking all write to an audit log, and the log
                    can be exported. A team can also set a retention policy, and
                    place a legal hold on an artifact so that deletion is
                    refused for as long as the hold stands. Retention and export
                    are covered in the{' '}
                    <A href={docs().url}>console documentation</A>.
                </P>
                ,
                <P>
                    An administrator can also take the whole record out of the
                    product. Exporting a team's artifacts produces an archive
                    containing the metadata and the files themselves, byte for
                    byte, with every file checked against its recorded hash
                    before it is written.
                </P>
                ,<H2>What stops one artifact from reading another?</H2>,
                <P>
                    An artifact may contain JavaScript, and a page an agent
                    produced is not something a team should have to take on
                    trust. Artfct serves each permanent artifact from its own
                    hostname rather than a shared one, and requests to that
                    hostname carry no session cookie at all.
                </P>
                ,
                <P>
                    For a team or private artifact, access instead requires a
                    short-lived signed token scoped to that one artifact. A
                    token minted for one artifact does not verify for another,
                    and an expired token is refused. The content security policy
                    served alongside an artifact is derived from what that
                    artifact declared, so a page naming no external origin is
                    served with a policy that permits none.
                </P>
                ,
                <P>
                    The practical effect is that one artifact cannot read
                    another, even when both belong to the same team.
                </P>
                ,<H2>How does a free link differ from a permanent one?</H2>,
                <P>
                    A page shared as a free link is encrypted, protected by a
                    passcode, and expires after five days of inactivity. It
                    needs no account to open, which makes it useful for sending
                    a single page to someone outside the team.
                </P>
                ,
                <P>
                    Permanent artifacts work differently. They are held under
                    the organization's keys, they do not expire, and they carry
                    a record of how the artifact was published: the model when
                    the caller supplied one, the tool that published it, and the
                    session it came from.
                </P>
                ,
                <P>
                    The <A href={free().url}>free tier</A> is a way to try the
                    sharing half of the product without connecting a tool. The
                    permanent tier is what a team keeps. Setup for every
                    supported tool is listed in the{' '}
                    <A href={llmsRoutes.full.url()}>full reference</A>.
                </P>
                ,<H2>What this does not do</H2>,
                <ul className="mb-5 list-disc space-y-2 pl-6 leading-[1.75] text-muted-foreground">
                    <li>
                        Sharing is one of three levels. It is not a per-person
                        permission list on a single artifact: a private artifact
                        is visible to its owner and to team administrators, and
                        to nobody else.
                    </li>
                    <li>
                        Public links can be turned off for an entire team. Where
                        that setting is on, no artifact in the workspace can be
                        published publicly.
                    </li>
                    <li>
                        Deletion is permanent. A legal hold prevents an artifact
                        from being deleted rather than delaying the deletion.
                    </li>
                    <li>
                        The free tier expires after five days of inactivity and
                        holds one file of up to one megabyte.
                    </li>
                </ul>
                ,<H2>Frequently asked questions</H2>,
                <FaqItem question="Is an artifact visible to the team as soon as it is published?">
                    Yes, unless the publisher names another level. Team is the
                    default; private and public can be chosen instead, and the
                    choice can be changed later.
                </FaqItem>
                ,
                <FaqItem question="Can someone outside the team open an artifact?">
                    Only if it has been published as public, or sent as a free
                    link. Neither requires an account to open, and both can be
                    withdrawn.
                </FaqItem>
                ,
                <FaqItem question="What happens to a shared link when an artifact is made private?">
                    It stops opening the artifact. Access is re-checked on every
                    request, and the credential is scoped to that one artifact.
                </FaqItem>
                ,
                <FaqItem question="Can an administrator remove something a colleague published?">
                    An administrator can revoke any artifact in the team, and
                    the revocation is written to the audit log. Deleting is a
                    separate action, available to a connection that holds the
                    delete permission, and it is refused while a legal hold is
                    in place.
                </FaqItem>
                ,
                <FaqItem question="Does an AI tool see artifacts that are private?">
                    Only the owner's own, and an administrator's. A search is
                    scoped to the caller, so another member's agent sees only
                    what has been shared with the team or published publicly.
                </FaqItem>
                ,
            </>
        ),
    },
    {
        slug: 'one-link-that-keeps-its-history',
        date: '2026-10-06',
        title: 'One link that keeps its history',
        tag: 'workflows',
        description:
            'Publishing the same content twice resolves to one artifact, and publishing a change creates a new version under the same link. How versions, identity and provenance work in Artfct.',
        body: (
            <>
                <P>
                    A link to an artifact is only useful if it keeps working.
                    Artfct gives an artifact one address for its whole life:
                    publishing the same content again resolves to the artifact
                    that already exists, and publishing a change creates a new
                    version under the same link rather than a second page nobody
                    can find.
                </P>
                ,<H2>What happens when the same thing is published twice?</H2>,
                <P>
                    An artifact is identified by its content rather than by the
                    moment it was published. Publishing something identical to
                    an artifact that already exists returns that artifact
                    instead of creating a second one.
                </P>
                ,
                <P>
                    The identity is derived from the artifact as a whole: the
                    files, their sizes, their content types, their hashes, and
                    which file opens first. A change to any of those produces a
                    different artifact, which is what makes the next case
                    possible.
                </P>
                ,
                <P>
                    The consequence is that a team does not accumulate three
                    copies of a report because three people asked for it. Two
                    links describing the same work resolve to the same page.
                </P>
                ,
                <P>
                    Nearly identical is not identical. A report regenerated with
                    a new date in its header is a different artifact, because
                    the bytes differ. The rule applies to the content as
                    published rather than to the question that produced it.
                </P>
                ,<H2>How does an update keep the same link?</H2>,
                <P>
                    A published artifact can be updated by naming it when the
                    new version is published. The result is a new version of the
                    same artifact rather than a new artifact, and the link
                    people already hold keeps working.
                </P>
                ,
                <P>
                    This is the difference between a link a team can put in a
                    document and a link that has to be replaced whenever the
                    numbers change. A dashboard referenced in a project brief
                    stays referenced.
                </P>
                ,
                <P>
                    Updating is deliberate rather than automatic. Nothing
                    rewrites an artifact because a similar one arrived, and the
                    publisher decides whether a change is a new version of the
                    same work or a different piece of work entirely.
                </P>
                ,<H2>What does an earlier version show?</H2>,
                <P>
                    The console keeps the version history of each permanent
                    artifact, newest first. Every completed version can be
                    opened on its own, and a past version can be restored, which
                    publishes it as the current one again while leaving the
                    record of what happened intact.
                </P>
                ,
                <P>
                    Each entry names the version number, when it was published,
                    the member who published it while they are still on the
                    team, and the title it carried. An entry that was itself a
                    restore says which version it came from.
                </P>
                ,
                <P>
                    The history is a record of decisions rather than a list of
                    files. A reader can see that a version was published, when,
                    and by whom, and can open any two versions side by side to
                    compare them.
                </P>
                ,<H2>What is recorded about how something was made?</H2>,
                <P>
                    Every permanent artifact carries a provenance record
                    alongside its content, and the record is built when the
                    artifact is published rather than reconstructed afterwards.
                    Each field also carries its own source, so a value the
                    connection could not observe is recorded as absent rather
                    than left blank or guessed.
                </P>
                ,
                <P>
                    On the supported connection, the record holds the model when
                    the caller supplied one, the session the publish came from,
                    the tool that published the artifact, and the name and
                    version of the client. Fields the connection could not
                    observe are recorded as absent rather than guessed, and the
                    record says which of the two each field is. The{' '}
                    <A href={docs().url}>documentation</A> covers the publish
                    step that builds it.
                </P>
                ,
                <P>
                    Provenance covers what the connection can observe.
                    Repository, branch and commit are recorded as absent on this
                    path; those fields were populated by a command-line client
                    that has since been retired, and the hosted server does not
                    attempt to reconstruct them. A team that needs the commit a
                    page came from cannot currently read it from the artifact.
                    The gap is recorded rather than hidden, which is the purpose
                    of tracking a source for every field.
                </P>
                ,<H2>Does a new version change who can see it?</H2>,
                <P>
                    Sharing belongs to the artifact rather than to a version.
                    Publishing a new version with the artifact's identifier does
                    not change who can open it, and does not change whether the
                    people it is shared with may publish a version themselves.
                </P>
                ,
                <P>
                    The separation is deliberate. A refresh to a dashboard
                    should not be able to widen its audience, and a team that
                    has restricted an artifact should not have to check the
                    setting again after every update.
                </P>
                ,
                <P>
                    Who may open an artifact, and who may change that, is
                    covered in{' '}
                    <A
                        href={blogRoutes.show.url({
                            slug: 'who-sees-what-your-ai-makes',
                        })}
                    >
                        who sees what your AI makes
                    </A>
                    . Finding an artifact without its link is covered in{' '}
                    <A
                        href={blogRoutes.show.url({
                            slug: 'finding-what-your-ai-already-made',
                        })}
                    >
                        finding the report your team already wrote
                    </A>
                    .
                </P>
                ,<H2>What this does not do</H2>,
                <ul className="mb-5 list-disc space-y-2 pl-6 leading-[1.75] text-muted-foreground">
                    <li>
                        The version history shows what changed hands. It does
                        not show a line-by-line difference between two versions.
                    </li>
                    <li>
                        Restoring publishes an earlier version as the current
                        one. It does not delete the versions published in
                        between.
                    </li>
                    <li>
                        Repository, branch and commit are recorded as absent on
                        the supported connection, and provenance is not
                        reconstructed later from the content.
                    </li>
                    <li>
                        Publishing different content without naming the artifact
                        produces a separate artifact rather than a new version.
                    </li>
                </ul>
                ,<H2>Frequently asked questions</H2>,
                <FaqItem question="Does publishing the same report twice create two artifacts?">
                    No. Content that is identical resolves to the artifact that
                    already exists, so a second copy is not stored and both
                    links open the same page.
                </FaqItem>
                ,
                <FaqItem question="Does updating an artifact change its link?">
                    No. The link belongs to the artifact and the version changes
                    underneath it, which is what makes the link safe to put in a
                    document.
                </FaqItem>
                ,
                <FaqItem question="Can a past version be brought back?">
                    Yes. A version can be restored, which publishes it as the
                    current one again. The record still shows every version that
                    came before it, including the one that was restored.
                </FaqItem>
                ,
                <FaqItem question="What is recorded when an artifact was not made inside a repository?">
                    The record shows what the connection could observe. Fields
                    it could not observe, including the repository and the
                    commit, are recorded as absent rather than inferred.
                </FaqItem>
                ,
                <FaqItem question="Does a new version change who can open the artifact?">
                    No. Sharing is a property of the artifact, so publishing a
                    version never widens or narrows the audience on its own.
                </FaqItem>
                ,
            </>
        ),
    },
    {
        slug: 'stop-ai-tools-contradicting-each-other',
        date: '2026-10-05',
        title: 'Stopping Claude and Cursor from contradicting each other',
        tag: 'workflows',
        description:
            'You drop a phrase in Claude. Cursor brings it back hours later. Here is how a shared library keeps AI tools from repeating decisions you already killed.',
        body: (
            <>
                <P>
                    You use Claude for research and Cursor for code. You spend a
                    morning killing the phrase &ldquo;shared AI brain&rdquo;
                    because customers keep calling it unverifiable hype. You
                    agree on plainer positioning: searchable reports, verified
                    sources, workspace access rules. You move on.
                </P>
                <P>That afternoon someone asks Cursor:</P>
                <Quote>
                    Build a three-column comparison of artfct against a static
                    wiki and a folder of markdown files. Lead with team
                    collaboration.
                </Quote>
                <P>
                    Cursor writes clean TypeScript, matching styles, and a hero
                    badge: &ldquo;The shared AI brain for modern teams.&rdquo;
                </P>
                <P>The team is aligned. The tools are not.</P>
                <BreakFigure />
                <H2>Why Claude and Cursor contradict each other</H2>
                <P>
                    Every AI tool works from what it can see right now. The
                    active conversation, recently opened files, maybe a local
                    rules file. Cursor cannot query a closed Claude Desktop
                    thread. Claude cannot inspect your IDE state. Close the tab
                    and the decision is gone. Start a new agent run and it
                    starts from zero.
                </P>
                <P>
                    So positioning gets refined at 10am in one tool and shipped
                    at 2pm in another, and the second tool has no idea the first
                    one changed anything.
                </P>
                <H2>Why .cursorrules and CLAUDE.md don&apos;t scale</H2>
                <P>
                    The standard fix is stuffing guidelines into a rules file.
                    It breaks down quickly:
                </P>
                <ul className="mb-5 list-disc space-y-2 pl-6 leading-[1.75] text-muted-foreground">
                    <li>
                        Every request pays the token cost even when it does not
                        need messaging rules.
                    </li>
                    <li>
                        Nobody files a PR to update it after a quick research
                        call, so it goes stale.
                    </li>
                    <li>
                        It only helps people inside the repo. Your PM drafting
                        in Claude Desktop never sees it.
                    </li>
                </ul>
                <H2>How we share context between Claude and Cursor</H2>
                <P>
                    We connect both tools to the same artfct team library over
                    MCP, the Model Context Protocol, an open standard that lets
                    AI tools reach external data instead of relying only on
                    whatever sits in the prompt. Both tools can search the same
                    library on demand.
                </P>
                <P>
                    <strong className="text-foreground">
                        Publish the decision where it gets made.
                    </strong>{' '}
                    When a positioning session wraps up, we tell Claude: share
                    this brief to our artfct library. Title it clearly. Include
                    approved phrases, banned terms, interview quotes. File it in
                    the Messaging collection.
                </P>
                <P>
                    <strong className="text-foreground">
                        One lookup rule replaces the rules file.
                    </strong>{' '}
                    Our <Mono>.cursorrules</Mono> is now a single line:
                </P>
                <Quote>
                    Before writing public copy, UI components, or documentation,
                    search the artfct library for recent guidance in the
                    Messaging collection. Cite the source link.
                </Quote>
                <P>
                    <strong className="text-foreground">
                        Cursor searches before it writes.
                    </strong>{' '}
                    When it scaffolds the comparison section, it calls{' '}
                    <Mono>search_artifacts</Mono> for &ldquo;feature comparison
                    messaging.&rdquo; It gets back the approved wording, the
                    banned-phrase warning, and a link to the original brief. It
                    uses the approved copy and drops the source link in the file
                    header.
                </P>
                <P>
                    The next time someone asked Cursor to draft comparison copy,
                    it pulled the brief, used the approved language, and cited
                    the link. The phrase did not come back.
                </P>
                <P>
                    Reviewers click once and see exactly where the copy came
                    from.
                </P>
                <LoopFigure />
                <H2>What this does not fix</H2>
                <P>
                    Someone still has to publish the decision. The library only
                    helps after the brief is in it.
                </P>
                <P>
                    Search returns targeted excerpts and links, not entire
                    documents. For edge cases you still open the source.
                </P>
                <P>
                    This covers Claude and Cursor today. The same setup works
                    for other MCP-compatible tools, but we have not tested every
                    combination yet.
                </P>
                <P>
                    <A href={`${docs().url}#mcp`}>
                        Connect your AI tools to artfct via MCP
                    </A>
                </P>
                <H2>Frequently asked questions</H2>
                <FaqItem question="Why do Claude and Cursor contradict each other?">
                    Each tool operates in its own context window. A decision
                    made in Claude Desktop is invisible to Cursor unless it
                    lives somewhere both can search.
                </FaqItem>
                <FaqItem question="Can Claude and Cursor share the same context?">
                    Yes. Connect both to a shared library over MCP. Claude
                    publishes decisions, Cursor searches them before generating
                    code.
                </FaqItem>
                <FaqItem question="Why not just use .cursorrules or CLAUDE.md?">
                    Static rules files burn tokens on every request, go stale
                    fast, and are invisible to anyone outside the repository.
                </FaqItem>
                <FaqItem question="How does MCP share context between tools?">
                    MCP is an open protocol that lets AI tools call external
                    functions like <Mono>search_artifacts</Mono>. Instead of
                    cramming everything into the system prompt, each tool pulls
                    only the context it needs, when it needs it.
                </FaqItem>
            </>
        ),
    },
];

/**
 * Newest first. Sorting here rather than trusting the literal's order means
 * adding a post cannot silently land in the wrong place on the index.
 */
export const POSTS: Post[] = [...POSTS_UNSORTED].sort((a, b) =>
    b.date.localeCompare(a.date),
);

export function getPostBySlug(slug: string): Post | undefined {
    return POSTS.find((post) => post.slug === slug);
}
