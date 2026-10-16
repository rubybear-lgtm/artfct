---
slug: where-do-ai-generated-documents-go
title: Where do AI-generated documents end up?
description: AI-generated documents land in the tool that made them, inside a conversation history, and most stay there. Why that is structural, and what changes.
date: 2026-09-24
tag: explainers
published: false
surface: explainer
register: glean-formal
target: AI-generated documents
---

AI-generated documents land in the tool that produced them, inside a conversation history or a folder the tool created for the session, and most of them stay there. Nobody files them and nobody names them, so the next person who needs the same analysis starts from nothing. The reason is structural rather than careless.

## Where does an AI-generated document actually go?

Almost every answer names one of three destinations, and all three are real. The first is the conversation itself. The document sits in the thread that produced it, in the order the conversation arrived, above and below the corrections that shaped it.

The second is a folder or workspace that the tool offers to create for the session. The folder appears automatically, it is usually named after the conversation rather than the work, and no colleague is asked what belongs in it. Documents accumulate there because leaving them costs less effort than moving them.

The third is the shared drive, and it is the one that looks like success. Somebody copies the output into a document or downloads it, so the file is filed and the team can open it. The copy also loses its origin, because nothing on the file says which tool made it, what it was asked for, or whether anyone has changed it since.

The honest answer to the question is that a document goes wherever its tool puts it and stays until a person moves it. Nothing in that arrangement is designed for anyone else to search, which is why the document is still there months later and nobody can find it.

## Why is this structural rather than careless?

It is tempting to read the mess as a discipline problem. A tidier team would name its files and put them in the right place, and that team already does so for the documents it writes by hand. What makes AI output escape the habit is that there is nowhere shared for it to go.

Each AI tool keeps its own record. The history, the workspace and the index belong to one product, one account and usually one session, and they are built to serve the person sitting in front of them. A vendor indexes the output its own model produced, and no vendor maintains an index of a rival's output. That is a commercial boundary rather than an oversight, and it survives every improvement each vendor ships.

Three consequences follow. A second tool cannot see the page a first tool made, which makes cross-tool blindness a product boundary rather than a bug, and it is worked through in [why your AI tools cannot see each other's work](https://artfct.dev/blog/why-your-ai-tools-cannot-see-each-others-work). A colleague who opens the same tool sees a personal history rather than the team's. And the folder that looks organized is scoped to one conversation, so its contents answer a question nobody is asking any more.

None of this is a defect in a particular product. The vendors that ship these tools also ship sharing controls and administrative settings inside their own products — [Claude's project-sharing settings are set by an organization's administrators](https://support.claude.com/en/articles/9927533-control-project-sharing-for-your-organization) — and those controls govern what they were built to govern. The limit is the boundary of the record, not the quality of the controls inside it.

## What does a lost document look like in practice?

Consider a team that needs a churn figure early in a quarter. One analyst asks an AI tool for the number, receives a table, checks it against a written report, and uses it in a review. The table is good, the meeting goes well, and the table stays in the session that produced it.

Months later a different analyst needs the same figure for a planning meeting. A fresh session is opened, the question is asked in slightly different words, and a table comes back that answers it. Somewhere in another person's history, or in a folder created for a conversation that ended long ago, the first table still exists.

Nobody has done anything wrong. The first analyst had no reason to file a table that had already served its purpose, and there was no shared shelf to file it on. The cost appears when the two tables disagree at the edges. One counts a customer as active on the day the invoice was raised and the other on the day the product was last used, and that definition was settled in a conversation nobody can now read.

What follows is a meeting about which number is right rather than about what to do next. That is the practical shape of the problem, and it is not data loss, because nothing was lost. It is the absence of a place where finished work accumulates and can be compared, which is the subject of [what an AI artifact is](https://artfct.dev/blog/what-is-an-ai-artifact).

## What changes when the document lands in a shared record?

Two things change together and they are worth separating. The first is that publishing produces an address rather than a file. An artifact is stored once for identical content, at a link that can be pasted into a decision document, a ticket or a message, and it opens for anyone whose access allows it, including somebody who was not in the room when it was made.

The second is that the record belongs to the team rather than to one account. Artifacts published through the supported publish tool land in the same library, and that library answers two kinds of question once indexing is switched on at the deployment level. A person describes what is needed and receives matching pages. A connected tool asks the same library before it starts work, so the next question can begin from the report that already exists rather than rebuilding it. The retrieval side is set out in [finding what your AI already made](https://artfct.dev/blog/finding-what-your-ai-already-made), and the mechanics of connecting a tool are in the [documentation](https://artfct.dev/docs).

Search is not a text box over a list of filenames. Semantic similarity and keyword matching are fused before anything is ranked, so a page about the same subject in different words still surfaces, and a search for an exact term still works. The fused result is then ordered by recency, by how often a page has been opened, and by whether it sits in a pinned collection, which means a page the team trusts outranks a newer one that nobody reads.

A retrieval answer does not stop at a snippet. Search returns a summary and a short evidence excerpt beside each link, which is enough to recognize a page and not always enough to work from. An agent can read further, because one call returns the extracted text of a single artifact, one bounded page at a time, and hands back the offset at which the next page begins. That text is served from the index rather than from the stored bundle, which carries two conditions worth stating plainly. Reading depends on indexing exactly as search does, and it returns the version that was indexed most recently, so a page re-published a minute ago can still answer with the text of its previous version.

One caution belongs here rather than at the end. That a tool can search before it builds is not the same as proof that searching changes how that tool plans its work. The behavior is what the product exists to make possible, and the evidence is still being gathered. This page does not claim it as a result.

## Who can open the page, and who can change it?

A drive folder inherits whatever the drive grants and nobody decides its contents one item at a time. A page in this record carries its own level instead. Every artifact is private, visible to the team as the default, or public to anyone holding the link — a team can switch public links off — and the level can be changed after publishing.

Visibility and edit rights are separate grants. A teammate can be given the ability to publish the next version of a page they did not create, without the ability to see everything else the team owns. One route serves every level, so a signed-out visitor to a team page is sent to sign in and returned to what they were opening, and the route never confirms whether a particular identifier exists.

Administrators see the same objects through a console rather than a filesystem. They can filter and search everything the organization owns, revoke a page, and export the record as metadata alongside the files themselves. An audit log records creation, viewing, sharing and revocation, and its export is available on Enterprise. Retention policy and legal hold sit beside it, both Enterprise-tier settings, so a page is kept for a stated period or frozen deliberately rather than by habit. The access model is covered in more detail in [who sees what your AI makes](https://artfct.dev/blog/who-sees-what-your-ai-makes).

Isolation earns one sentence of its own. Each permanent page is served from its own hostname with a cookieless, short-lived token and a content security policy derived from what that page declared, so the scripts inside one page cannot read the scripts inside another.

## What counts as a document in this record?

An artifact is something an AI made and published at a link other people can open, and every artifact carries exactly one type: report, table, document, mockup, slide deck, dashboard or other. The type is a label rather than a promise about how the artifact opens. An artifact stored as HTML renders as a page at its link, and an entrypoint that is not HTML is served as a download instead, so a table carries that label without any claim that a reader opens it as a page.

The label does not widen the record. An arbitrary office file, a PDF, an image kept for its own sake, an archive, a notebook or a video is still not an artifact, because nobody published it to a link as a finished piece of AI work. Keeping those is a different job with mature tools already built for it, and adding them here would turn the record into a worse version of a drive. The type is a filter as well as a description, so a search or a listing can ask for the dashboards alone.

Size has a shape worth knowing. A single self-contained page is one file of up to 1 MB. A multi-file artifact can carry up to 500 files, and the total size cap is the lower of 8 MB and the ceiling of the team's plan, which is 5 MB on the free plan. That is generous for a report and deliberately short of an application.

## Does this replace the files the team already keeps?

No, and it is not meant to. A shared drive stores the files a person will open in the formats the team already uses: a spreadsheet to edit, a presentation to deliver, a signed agreement to keep. This record holds the artifacts an AI tool made, at addresses a colleague or a connected tool can open without a file transfer.

The boundary runs in both directions, and it is worth stating plainly. If a deliverable is a spreadsheet three people edit by hand, the drive is the right home for it and nothing here changes that. Where a page and a copy of it both exist, the two can drift apart, and the page is the one carrying a stable address and a version history, so it is the one worth citing. The difference is worked through in [Artfct against a shared drive folder](https://artfct.dev/blog/artfct-vs-a-shared-drive-folder).

## What this does not do

- It is not general file storage, and the office files, spreadsheets, PDFs and images a team already keeps stay wherever they are kept today.
- It is not a wiki and not a document editor. Nothing inside an artifact is drafted or edited here, and there is no authoring surface for its own content, so a page arrives finished. A reader can add one thing to it: a comment. Comments are plain text of up to 4,000 characters, a reply joins the thread through a parent comment, and a signed-in member who can see the page can post one and resolve or reopen the thread at any time, as can a connected tool whose connection carries the comment permission. A visitor holding only a public link cannot comment, and the public view shows no thread at all.
- It is not static site hosting, not a content delivery network, and not software the team runs on its own machines. Pages are shared with tens of people rather than millions.
- It is not a memory layer. Finished pages are kept rather than reasoning, and nothing here changes what a model knows or how it writes.
- Access is exactly private, team or public, together with the owner and the team's administrators. There is no per-person sharing, so a page cannot be opened for one named individual alone.
- Public links are a team setting and can be switched off for the whole team. With them off, a publish that asks for public is refused, and private and team remain available.
- On the hosted connection the provenance record does not include the repository, the branch or the commit, so a repository filter in search matches only what carries those fields. The model is recorded as the caller reports it rather than as something observed.
- Search is off by default and is switched on at the deployment level rather than per workspace. With it off, a search reports that it is not enabled yet, reading a page's text reports the same, and re-indexing a page is limited to once every ten minutes.
- There is no version comparison. Earlier versions of a page can be listed, opened and restored, but two versions cannot be placed side by side.
- A page shared as a free link is passcode-protected and carries a five-day sliding expiry. Permanent pages are durable and carry no expiry.
- Turning a pasted page link into a preview card in Slack is not shipped. The search command returns results privately to the person who asked, and the unfurl does not follow.

## Questions

### Where do AI-generated documents go if nobody saves them?

They stay where the tool put them: in the conversation, in a folder created for that session, or in a download that nobody filed. The page still exists, and it is invisible to everyone who was not in that session.

### Is a folder created automatically the same as a filing system?

No. A filing system has a person who decides what belongs in each place, and a name a colleague can predict. An automatic folder is named for the conversation and owned by nobody, so it accumulates work without ever becoming a place anyone searches.

### Can one AI tool open what another tool made?

Not through the tools themselves, because each keeps its own record and the record stops at that product. A shared library is what makes a page reachable from a second tool, since the address is neutral and any connected tool can look it up once indexing is switched on for the deployment.

### Does this record hold PDFs, spreadsheets or images?

It holds the artifacts an AI made, each carrying one type: report, table, document, mockup, slide deck, dashboard or other. Office files, PDFs, archives, notebooks and video are not what this holds, and they stay in the drives and tools built for them.

### Can a page be shared with one named person?

Not today. Access is private, team or public, together with the owner and the team's administrators. Edit rights can be granted narrowly, so a colleague can publish the next version of a page that only the team can open.

### Does a free link expire?

Yes. A free link is encrypted, passcode-protected and carries a five-day sliding expiry, so it closes five days after the last visit. A permanent page is durable and carries no expiry.

### Does a link pasted into Slack become a preview card?

Not yet. Turning a pasted page link into a preview card in Slack is not shipped. The slash command returns search results privately to the person who asked, and never to the channel. The link itself opens for anyone whose access allows it.

### Is search available by default, and can two versions be compared?

No on both counts. Search depends on indexing, which is off by default and switched on at the deployment level, so with it off a search reports that it is not enabled yet. Earlier versions of a page can be listed, opened and restored, but there is no version comparison, and two versions cannot be placed side by side.
