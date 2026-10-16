---
slug: what-is-a-shared-artifact-library
title: What is a shared artifact library for?
description: A shared artifact library is one place inside a team workspace where the pages an AI tool made are kept, so any member can open, cite and search them.
date: 2026-09-26
tag: explainers
published: false
surface: explainer
register: glean-formal
target: shared artifact library
---

A shared artifact library is one place inside a team workspace where the finished pages an AI tool produced are kept, so that any member of the team can open them, cite them weeks later, and search them once indexing is enabled. It is a record of output rather than of conversation, and that single choice is what separates it from a chat history.

## What is a shared artifact library?

The phrase makes two claims, and both have to hold before it is accurate. The first is duration: what goes in stays, keeps its name, and can be found again without the person who made it. The second is audience: more than one person reads from it, and the reading does not depend on a conversation that has closed.

An [artifact](https://artfct.dev/blog/what-is-an-ai-artifact) is the unit that fills such a library. It is a thing an AI tool made that a person can open — a report, a dashboard, a table, or an interactive page — rendered at a link, or downloaded as a file where the entrypoint is not HTML. The library is therefore a shelf of finished work rather than a folder of raw model output.

The shared half of the term changes the behavior. Most AI output is addressed to the person who prompted it, inside a session that ends when the window closes. A library inverts that address: the reader is a different person, arrives later, and holds no memory of how the thing was made.

The library half implies boundaries as well. A collection that accepts everything a team touches becomes an accumulation nobody trusts, because a reader cannot tell considered work from incidental work. A library is defined as much by its shelf list as by its contents.

## What counts as an artifact, and what does not belong?

The test for inclusion is narrow, and it is worth stating plainly. Something belongs if an AI tool produced it as finished work a person can open and read. A churn analysis, a comparison, a dashboard, a plan, a short interactive page — each of these qualifies when it renders on its own and carries a conclusion someone else can use.

Every artifact also carries a type — report, table, document, mockup, slide deck, dashboard or other — and both the search call and the list call can be filtered by it, so an agent can ask for the decks alone rather than paging through everything a workspace holds. The type is a label, not a promise that the thing opens as a page. It records what kind of work the artifact is; an entrypoint that is not HTML is served as a download rather than rendered.

What falls outside is equally clear. Office files, loose images and archives kept for their own sake are not artifacts, because nothing about them requires an AI to have made them. A notebook, a PDF or a recording is not an artifact in this sense either, since each is a working surface or a container rather than a finished page.

One boundary is easy to miss. A library stores finished work; it does not author or edit the content it holds. Nothing is drafted inside it, and a teammate with permission to publish a later version is acting on an existing page rather than editing it in place. What a reader can add is a comment.

A comment is discussion rather than content. Only a signed-in member who can see the page can leave one — plain text up to 4,000 characters, threaded under a parent comment, and resolvable or reopened at any time, including before anyone replies. A visitor holding only a public link cannot comment, because the public view renders no thread at all. A connected agent can post one through a tool connection holding the `comments:write` scope, so a question an agent raises stays on the work rather than in a session that closes. The artifact's own content still arrives finished and stays that way: a question asked on a page stays with the page, and commenting is a contribution to the discussion rather than a change to what was published.

A practical rule follows. If a colleague would open the thing to read a conclusion, and an AI produced it as a page, it belongs. If its value is a record of an exchange, or a file only its producer can interpret, it does not.

## Who can read what the library holds?

A library with a single audience is not genuinely shared, and a library with no audience is not genuinely a library. The useful arrangement is a default audience with deliberate narrowing, so publishing is an ordinary act and restricting something is a decision a person made.

Three audiences cover the cases a team meets. Work is visible to the whole team by default. It can be held to its owner alone, for something not yet ready. It can be opened to anyone holding the link — unless the team has switched public links off — for work that has to leave the workspace without an account on the other end. All three reach the same [viewer address](https://artfct.dev/blog/who-sees-what-your-ai-makes), so a link that worked yesterday keeps its shape today.

The default is the load-bearing part. If publishing dropped work into a private space, a library would fill with things nobody could find, and its value would depend on each person remembering to open it. A team default means the record grows as a side effect of publishing rather than as an extra step.

Administration sits beside access rather than inside it. A workspace carries roles — an administrator, an ordinary member, and a viewer who reads rather than publishes — and an invitation carries one. An AI tool reaches the library as the person who connected it rather than as the workspace — [a client acting on behalf of the resource owner](https://www.rfc-editor.org/rfc/rfc6749) — so one person's approval does not extend to anyone else.

Isolation is the last property, and it stays invisible until it is tested. Each artifact is served from its own address, so the scripts inside one page cannot reach the scripts inside another, and two artifacts in one library do not share a runtime.

## How does the library find something months later?

Retrieval decides whether a library is used or quietly abandoned. The part worth describing is how something is found when nobody remembers its name, its author, or the tool that produced it.

Search does not rely on the words a person guesses. Two signals are fused before ranking begins: semantic similarity, which finds work about the same subject expressed in different terms, and keyword matching, which finds the exact term when the exact term matters. Results come back with a short summary, a brief excerpt and a link, enough for a reader to judge whether the whole page is worth opening.

Ranking then decides which of several plausible answers arrives first. Recency is one term, and how often an artifact has been opened is another, which lets a working document outrank a near-copy nobody has read. A third is whether an artifact sits in a pinned collection, the cheapest way for a team to mark work as canonical.

Artfct keeps such a library, and the same record answers an agent and a person. An agent calls search with a question in plain language, receives summaries with links, and reports which artifact it used. A person reaches the same collection through a console, whose own controls are narrower: a repository filter, an AI tool filter, and a match on title or description rather than a fused one. The console carries no sort control. A list call accepts more than the console does — owner, sharing level, file kind and date windows — and sorts on when a thing was updated or created, how often it has been viewed, or its title. A Slack command runs the same query and returns results only to the person who asked.

Collections are the human half of organization: one can be created, renamed, filled and emptied, and pinning an artifact into it lifts that artifact in later results. It is how a team's judgment enters a system that would otherwise rank by use alone.

One mechanical detail decides whether any of this works. Search reads an index, and an artifact whose substance appears only once the page runs has to be rendered first. Artfct detects that case, renders the page, and indexes the text a reader would see. Indexing is a deployment setting rather than a default.

## What happens when the same thing is made twice?

Duplication is the default in a workspace without a library. Two people ask a similar question a week apart, in two different tools, and each receives a slightly different answer. Two versions of one figure are worse than one, because nobody can tell which is current.

A library changes that arithmetic at the moment of publishing. Identical content published a second time resolves to the artifact that already exists rather than to a duplicate, so the same dashboard produced twice lands in the collection once.

Correction is the harder case, and it has its own answer. When a figure turns out to be wrong, a corrected version is published to the same address by passing the existing artifact identifier, which adds a version to [the original link](https://artfct.dev/blog/one-link-that-keeps-its-history) rather than creating a second page. Earlier versions stay listed and can be restored. Any document that cited the link still resolves, and nobody has to guess which of several copies is current.

Two consequences follow for the library as a whole. It accumulates fewer duplicates — identical content only — so search results stay distinguishable. And a reader who follows a citation lands on the history of one artifact rather than on a snapshot that may already be stale.

## What does a shared artifact library look like in practice?

An analyst asks Claude Code for a churn breakdown and receives a dashboard worth keeping. The analyst publishes the page, gets a link back, and drops it into the planning document the team already reads. No filename is chosen and nothing is filed by hand.

Three weeks later an engineer working in a different tool asks why a particular set of accounts churned in the same quarter. The agent [searches the library](https://artfct.dev/blog/share-artifacts-between-agents) with a short query, receives a summary of the churn dashboard with its link, opens the page, and answers with the source attached. The engineer follows one link and reads the number the analyst produced rather than a fresh reconstruction of it.

What did not have to happen is the interesting part. The two people did not use the same tool, the engineer did not know the dashboard existed or in which week it was made, and nobody remembered a filename. The library answered a question nobody anticipated when the work was published, which is the case a folder cannot serve.

Correction keeps the practice alive. A month later the analyst finds an error and publishes a corrected version to the same address. Documents that cite the link still resolve, and earlier versions can be restored, so the record shows both what was claimed and what replaced it.

Repetition then loses its worst property. When the same analysis is needed a second time — a different quarter, a narrower segment — the second attempt starts from a page that already exists, and the same search reports what else the team has produced.

## What is outside a shared artifact library?

The boundary is easier to hold in the negative, because the neighboring categories are familiar and the differences structural. A shared artifact library is not a wiki: no artifact's own content is authored inside it, no page is edited in place, and the collection is filled by publishing rather than by drafting. A comment is the one thing a reader adds, and it is discussion attached to a page rather than a part of it.

It is not general file storage either. A drive accepts whatever a person uploads, in any format, and that breadth is its value. A library accepts what an AI made that a person can open — rendered at a link or downloaded as a file — a much smaller set chosen for a specific reason: the next reader has to open it without the tool that produced it.

A notebook, a PDF or a video recording is not an artifact here, for the same structural reason: each is a working surface or a fixed container rather than a page addressed to a reader who arrives later. The library is also not a memory or context layer. It moves finished artifacts between people and tools, and it does not change what a model knows or how it reasons.

It is not site hosting or a content delivery network, either. An artifact is shared with a team, or with anyone holding a link where the team allows it, and the point is a searchable record rather than public hosting.

## What does administering one involve?

Governance separates a library a team can rely on from a folder it hopes is accurate. An administrator sees everything the workspace owns through a console and can list it, filter it, search it, and revoke access to any single artifact. The console also exports everything the workspace owns as a zip of metadata plus byte-identical files, each verified against its hash.

An audit log records creation, viewing, sharing and revocation, and its export is available on the Enterprise plan.

Membership carries its own controls. People join through invitations and hold a role, ownership can be transferred, and someone can leave or switch between workspaces. Directory synchronization provisions and de-provisions access from the identity system a company already runs, so removing a person from the directory removes their reach.

Two smaller readouts matter more than they look. Usage and quota show what a workspace is consuming, and a list of [connected AI tools](https://artfct.dev/docs) lets an administrator revoke a connection. Organization tokens used by automation can be revoked without disturbing anyone's own connections.

## What this does not do

- It does not author or edit an artifact's own content. Work arrives finished, and a later version is published rather than edited in place; a comment is discussion and does not change the page.
- It does not accept arbitrary files. An artifact is something an AI made that a person can open — rendered at its link when it is a page, downloaded when it is not; office files, loose images and archives kept for their own sake are outside it, and notebooks, PDFs and video are not artifacts either.
- Sharing is exactly private, team or public, together with the owner and a team's administrators. There is no per-person sharing.
- Public links are a team setting. Where a team has switched them off, a publish that asks for public is refused, and the viewer refuses the same way.
- The provenance record leaves the repository, the branch and the commit empty on the supported path, reports the model the caller supplied rather than an observed one, and does not distinguish one connected tool from another.
- Search depends on indexing, which is a deployment setting and off until an operator enables it. Re-indexing one artifact is limited to once every ten minutes.
- A single page is capped at 1 MB, and a multi-file artifact at 500 files and 8 MB in total, with plan ceilings binding lower.
- An audit log records creation, viewing, sharing and revocation. Deletion performed through the tool is not one of its entries.
- A tool that can only start programs on the machine it runs on cannot reach a hosted library.
- Whether an indexed record measurably changes what an agent does is the thesis under test, not a result anyone can quote.

## Questions

### Why is a shared artifact library not a wiki?

Because nothing is drafted in it. A wiki is a surface for writing and editing pages by hand; a library is not that, because it receives finished pages an AI already produced and keeps them findable. A reader can post a comment, but that is discussion rather than a draft of the work.

### Does a shared artifact library replace the tools that make the work?

No. It stores and finds what those tools produce. The tools remain where the work happens, and the library is the shared record that outlives any one session.

### How long does an artifact stay in the library?

A free artifact is encrypted, passcode-protected, and expires after five days of disuse, with no account required. A permanent artifact is held under organization keys, with durable storage. Retention has a default period of 90 days, and setting a custom period is an Enterprise-tier setting.

### Can someone outside the team open an artifact?

Yes, at the public level, where anyone holding the link can read it, unless the team has turned public links off. A signed-out visitor to a team artifact is sent to sign in and back, and the route does not confirm whether an identifier exists.

### What happens when a correction is needed?

A corrected version is published to the same address, so existing citations keep working, and earlier versions stay listed and can be restored. Two versions can be opened one after another, and there is no side-by-side comparison.

### Does an agent need to be told about the library?

A connected tool can search and list what the workspace holds, and publishing is one call. Both need the tool to reach a remote server. Search also needs indexing switched on at the deployment level; listing and publishing work with indexing off.

### Can the library tell which AI tool made an artifact?

Only in part. The record carries the model the caller reported, the session, and the client name and version, and it does not distinguish one connected tool from another, so the console shows an unknown tool for those artifacts.
