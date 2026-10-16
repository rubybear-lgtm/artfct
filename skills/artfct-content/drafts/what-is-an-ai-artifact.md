---
slug: what-is-an-ai-artifact
title: What is an AI artifact, and what is not?
description: An AI artifact is finished work an AI tool made that a person can open: a report, dashboard, table or document. How it differs from chat scrollback.
date: 2026-10-01
tag: explainers
published: true
surface: explainer
register: glean-formal
target: AI artifact
---

An AI artifact is a finished work product that an AI tool produced and that a person can open: a report, a dashboard, a table, a document, or an interactive page. It is the output, not the conversation that produced it. That distinction matters, because most AI work today disappears into a chat window when the session ends.

## What counts as an artifact?

This page holds one definition and applies it throughout. An artifact is a finished work product that an AI tool made and that a reader can open unaided — rendered at its link when the entrypoint is a page, downloaded when it is a file such as a table or a document. A revenue report, a dashboard of pipeline numbers, a comparison table, a short written document and an interactive page all qualify, and the form varies while the test does not.

The test is whether a colleague can use the thing without the conversation that produced it. A finished report passes, because its numbers and its reasoning arrive together and stand on their own. A half-finished outline fails, because the reader cannot separate what was decided from what was merely considered. An artifact sits at the finished end of that spectrum, which is why the word carries the weight it does.

The same word appears elsewhere in software, and those other senses cause much of the confusion this explainer addresses. In a build pipeline an artifact is a compiled file, a package or a signed manifest. In a design tool it is a mock-up handed between stages. Both senses are legitimate, and neither is the one used here.

## How is an artifact different from a chat transcript?

A conversation with an AI tool is reasoning made visible. The transcript holds the question, the false starts, the corrections and the final answer, and it is genuinely valuable to the person who was present for it. It is much less valuable to anyone else, because a reader has to reconstruct which turn ended up mattering.

Scrollback is also bound to the tool that produced it. It lives inside one account, it belongs to one session, and it was never designed for a second person to open later. The unit of memory is the tool, while the unit of work is the team, and that mismatch is the reason finished work goes missing. The problem is described in more detail in [finding the report a team already wrote](https://artfct.dev/blog/finding-what-your-ai-already-made).

A second difference matters more than tidiness. A transcript cannot be cited. A decision document can link to a report at a stable address, and a reader follows that link to the same table the decision rested on. Nobody links to a middle turn of a session, and a record that cannot be cited does not survive a review.

## How is an artifact different from a memory note?

A memory note is an input. Assistants increasingly keep a small store of facts, preferences and terminology, and they read that store at the beginning of a session so that later answers fit the organization. The note is never the deliverable itself; it shapes the deliverable, quietly and continuously.

An artifact is an output. It is the thing that was asked for, finished, and handed to a reader who may never know which tool made it. The distinction is not cosmetic, because the two stores change different behavior in different places. Editing a memory note changes what a model generates next, while keeping an artifact changes what a team can find long afterwards.

A useful way to hold the difference is to look at the intended audience. A memory note is written to influence a future model call, while an artifact is written to inform a future reader. The first is addressed to a model and the second to a person, and those two audiences make very different demands of the same words.

Treating the two as substitutes produces a predictable disappointment. A team that relies on memory alone gets answers phrased in house language and very little it can hand to a client or an auditor. A team that keeps artifacts gets a record of the work itself, and that record outlives the session which produced it.

## Why is artfct not an “artifact store”?

The phrase is common in platform conversations, and it is not the description this category needs. To a platform lead, an artifact store is Artifactory or Harness, not a record of what a team's agents built. That category holds build outputs, binaries, packages and signed manifests, and it sits inside a continuous integration pipeline beside the code it was compiled from.

That job is real, mature and well served by the tools built for it, and it is a different job from the one this page describes. A build artifact is consumed by a deployment process and rarely read by a person. A work artifact is read by a person, linked in a document and searched by whoever needs the same answer again.

artfct is a record of what your agents built. Publishing a page or a document produces a link, keeps the content at that address, and makes the artifact findable by any connected tool and by any colleague who has access. The category is deliberately narrower than file storage and deliberately broader than one vendor's chat history.

## What makes an artifact durable?

Durability here means that a reader can open the same thing long after it was published and reach the same content. Three mechanisms carry that weight. The first is content-addressed identity: publishing identical content twice yields one artifact rather than two, so a team does not accumulate near-copies that differ only in the hour they were made.

The second is versioning under a stable address. A corrected report is published as a new version of the same artifact, and a link already pasted into a document keeps working. Earlier versions stay listed and can be restored, which turns a mistake into a reversible event rather than a lost afternoon. The mechanism is set out in [one link that keeps its history](https://artfct.dev/blog/one-link-that-keeps-its-history).

The third is the provenance record attached to each artifact. On the supported connection it holds the model when the caller supplies one, the session identifier, the publishing tool, and the client name and version. It does not hold the repository, the branch or the commit on that path, and the limits section below explains what that omission means for search.

Size has a shape as well. A single self-contained page is one file of up to 1 MB, while a multi-file artifact can carry up to 500 files to a total of 8 MB. Plan limits are lower still. Those bounds are generous for a report and tight enough that a shared link stays a page rather than an application.

## How does a team find an artifact later?

Storage without retrieval produces a tidier version of the original problem. The retrieval difficulty is specific and worth naming: the right report and a plausible near-copy often share most of their vocabulary, and the near-copy is frequently the one nobody actually uses. Matching on text alone therefore ranks the wrong answer first, with confidence.

Search fuses two signals before it ranks anything. Semantic similarity finds work about the same subject in different words, and keyword matching finds the exact term when the exact term is the whole point. The fused result is then ordered by recency, by how often an artifact has been opened, and by whether it sits in a pinned collection. A canonical report therefore outranks a semantically closer draft that nobody reads.

People and agents reach the same index. A colleague describes what they need and receives matching artifacts with their sources attached, and a connected tool asks the same question in ordinary language and gets back summaries, short excerpts and a link. The same search runs from a console page or from a Slack command, where results return privately to the person who asked rather than to the channel. The [documentation](https://artfct.dev/docs) lists the filters and sorts each surface accepts.

## Who can see an artifact, and who can change it?

Sharing has three levels: private, team and public. A private artifact is visible to its owner, a team artifact to the members of that team, and a public artifact to anyone holding the link. Visibility and edit rights are separate grants, so a colleague can be given the ability to publish the next version of something they did not create.

One route serves every level. A signed-out visitor who opens a team artifact is sent to sign in and returned to the page, and the route never confirms whether a particular identifier exists. Each artifact is served from its own hostname with a cookieless token and a content security policy derived from what that artifact declared, so the scripts inside one artifact cannot read the scripts inside another. The access model is covered in [who sees what your AI makes](https://artfct.dev/blog/who-sees-what-your-ai-makes).

Administrators see the same objects through a governance surface. An audit log records creation, viewing, sharing and revocation, and its export is available on Enterprise. Retention policy and legal hold are Enterprise-tier features, so a record is kept deliberately or released on a schedule rather than by habit.

## What does a team do first?

The concept is separable from any product, and a team can test the concept in a single sitting. One person connects a tool once, and every member of the team then publishes and searches through the tools they already use, with nothing further to install on their own machines.

The connection is a hosted OAuth server reached over HTTP ([MCP specification, authorization section](https://modelcontextprotocol.io/specification/2025-06-18/basic/authorization)). A single command registers it, and the same setup text appears in the [docs](https://artfct.dev/docs) and in the machine-readable reference at [llms-full.txt](https://artfct.dev/llms-full.txt), because both render from one source and cannot drift apart.

```
npx add-mcp@latest <mcp-url> --name artfct --global
```

Readiness varies by tool, and the distinction is worth keeping straight. Claude Code, Codex, OpenCode and Antigravity have been verified against staging. ChatGPT, Cursor, VS Code, Windsurf and Zed have documented paths rather than tested ones, and any other client that can reach a remote server can connect. A publishing skill for agents exists as well, installable with a single command.

For the sharing half alone, one page can be [published as a link](https://artfct.dev/free) without an account, which is the shortest route to seeing what an artifact is. The same link then opens for a colleague, a client or a connected agent.

## What this does not do

- It is not a memory layer for a model. It moves finished artifacts rather than reasoning, and it does not change what a model knows or how it writes.
- It is not a wiki. There is no authoring surface and no editing surface; a record of finished work is kept and found, not written.
- Notebooks, PDFs and video are not in scope, and neither are arbitrary office files or archives kept for their own sake. An artifact is something an AI made that a person can open — rendered at its link when it is a page, downloaded when it is not.
- It is not static site hosting and it is not a content delivery network, and it is not offered as self-hosted or on-prem software. Pages are shared with tens of people rather than millions.
- There is no per-person sharing. Access is private, team or public, plus the owner and the team's administrators.
- On the hosted connection the provenance record does not include the repository, the branch or the commit, so a repository filter in search matches only the artifacts that carry one.
- Indexing is not instantaneous. Re-indexing is limited to once per ten minutes for a given artifact, and an administrator can switch indexing off for a deployment.
- Turning a pasted link into a preview card inside Slack is not shipped. The search command returns results privately to the person who asked; the unfurl does not follow.

## Questions

### Is a chat transcript an artifact?

No. A transcript records the reasoning that produced an answer, and it stays inside the tool that held the session. An artifact is the finished output, published at an address that another person or another tool can open.

### Can a single table or document be an artifact?

Yes. A table or a document is an artifact, and it opens the way its format allows: served as a page when the entrypoint is HTML, downloaded when it is a comma- or tab-separated table or a JSON file. What does not qualify is a file with no artifact entrypoint — an office document, an image or an archive kept for its own sake.

### Does an artifact record which repository it came from?

On the hosted connection, no. The provenance record holds the model when the caller supplies it, the session, the publishing tool and the client, and it leaves the repository, branch and commit empty. A repository filter in search therefore matches only artifacts that carry those fields.

### Can one artifact be shared with a single named person?

Not today. Access is private, team or public, together with the owner and the team's administrators. Edit rights are a separate grant on the same artifact, so a colleague can be allowed to publish the next version without widening who can see it.

### What happens when the same content is published twice?

It resolves to one artifact rather than two, because identity is derived from the content itself. That keeps a team from accumulating near-identical copies that differ only in when they were made.

### Can a report be corrected without breaking a link already shared?

Yes. The correction is published as a new version at the same address, and the earlier versions stay listed, and any of them can be opened or restored, so a document that already cites the report keeps pointing at the current one.

### Which AI tools can publish and search artifacts?

Claude Code, Codex, OpenCode and Antigravity are verified against staging. ChatGPT, Cursor, VS Code, Windsurf and Zed have documented paths that have not been tested against staging, and any other client that can reach a remote server can connect.
