---
slug: ai-artifacts-vs-agent-memory
title: How AI artifacts differ from agent memory
description: Agent memory is what an assistant carries while it works, scoped to a session and written for the model. An AI artifact is finished work a person can open.
date: 2026-09-27
tag: comparisons
published: false
surface: comparison
register: glean-formal
target: agent memory
---

Agent memory is what an AI assistant carries while it works: notes, preferences and decisions scoped to a session or a project, and written for the model rather than for a person. An AI artifact is the finished thing that comes out of that work, built to be opened by a colleague and found by the next agent. The two solve different problems.

## What does an agent carry while it works?

Agent memory is the working context an assistant carries: the preferences it has been told, the terms of a project, and the decisions already made, held so a later answer fits the earlier ones. Its scope is usually the session or workspace the tool defined for it — [Claude's chat search is bounded to the account and its projects](https://support.claude.com/en/articles/11817273-use-claude-s-chat-search-and-memory-to-build-on-previous-context) — and its reader is the model rather than a person. A memory store is written to be retrieved by a machine, which is why its contents often make little sense when read directly.

The advantage of that arrangement is that it asks nothing of anyone. Memory accumulates as a by-product of the work, and it takes effect without a colleague filing, naming, or sharing a single item. For continuity inside one assistant and one project, that is enough, and it is a real strength.

## What is an AI artifact, and who is it for?

An AI artifact is a finished work product that an AI tool made and that a person can open, rendered at a link or downloaded as a file. A report, a dashboard or an interactive page all qualify, provided the work is finished and legible to a reader who did not run the session. A table or a document is an artifact too; its type records what kind of work it is, not how it opens, because an entrypoint that is not HTML is served as a download rather than rendered.

An artifact is published in one call. The publish carries either a single self-contained HTML document, capped at 1 MB, or a bundle of up to 500 files, capped at 8 MB or the plan ceiling, whichever is lower. What comes back is a link, and the link is the point: a person opens it in a browser, and a connected agent reaches the same page through search. Indexing is off by default and is switched on at the deployment level, so search is available only once that setting is enabled. The [full reference](https://artfct.dev/llms-full.txt) lists the setup for each tool.

The audience is therefore double in a way that memory is not. An artifact is addressed both to a colleague who never saw the conversation and to whatever agent asks a similar question later. How a page keeps its address while its contents change is covered in [one link that keeps its history](https://artfct.dev/blog/one-link-that-keeps-its-history).

## Where do the two differ?

Three properties decide which object a team needs.

**Audience:** memory is addressed to the model that reads it next, and an artifact is addressed to a person who opens it. A memory entry can be terse, while an artifact has to make sense on its own.

**Lifetime:** memory is scoped to a session, a project or a workspace, and it ends with that scope. An artifact has an address, and the address outlives the session that produced it.

**Reach:** a memory store serves the tool and the account that own it, with no sharing model attached. An artifact is published to a team, held private, or offered as a public link where the team allows one, and edit access is a separate permission from visibility.

| Question | Agent memory | An AI artifact |
| --- | --- | --- |
| What it holds | Working context: preferences, project terms, decisions already made | A finished work product, rendered at a link or downloaded as a file |
| Who it is for | The model that reads it next | A person who opens the link, and any agent a team member has connected |
| How long it lasts | The session or project it was scoped to | A stable address that outlives the session |
| How it is reached | The tool that owns it, in the context it was written for | Search, where indexing is switched on, or the link itself |
| Who can see it | The account and the tool that own it | Team, private, or public where public sharing is enabled, with view or edit as a separate axis |
| How it changes | Revised or discarded as the context moves on | A new version on the same link, with earlier versions listed and restorable |

The table settles the categories rather than ranking them, because the two columns hold different objects for different readers.

## What happens to the work when the session ends?

When a session closes, the memory that supported it does not travel with the work. The next session starts from whatever context it was given, and the finished page has no address unless something published it.

Publishing is that transfer, and it is one action. The tool that made the page calls a publish and receives a link. Identical content published twice resolves to one artifact instead of a duplicate, because the identifier is derived from the content itself. A later revision creates a new version on the existing address rather than a second page, and earlier versions can be listed and restored.

The consequence is narrow and useful. A link a colleague saved last month still opens the current page, and the version they read is still recoverable. There is no difference view between two versions: a history can be listed, opened and restored, and that is all it offers. What each connection may ask for is in the [documentation](https://artfct.dev/docs).

## Can one agent retrieve what another one remembered?

A memory store serves the tool and the account that own it, and no memory crosses vendors, so nothing one assistant was told is available to another. That boundary is not a fault in either object.

An artifact is retrievable because it was published to a shared library rather than left inside the tool that made it. A connected agent calls a search that fuses semantic similarity with keyword matching, then ranks the results by recency, by how often an artifact has been opened, and by whether it sits in a pinned collection. The ordering is deliberate: a canonical artifact outranks a semantically closer one nobody has opened. Results come back as summaries, short excerpts and a link.

A narrower list is available as well. An agent can list artifacts filtered by owner, sharing level, collection, agent, a date window, title text or file kind, sorted by recently updated, created, most viewed or title. People search the same library from a console, where the filters are narrower: title or description, producing agent, repository and collection. How a search ranks one result above another in practice is covered in [finding what your AI already made](https://artfct.dev/blog/finding-what-your-ai-already-made).

## When is agent memory the better tool?

Memory is the better choice in the cases it was built for, and those cases are common. A task that finishes inside one session and one tool needs no address, because nothing outside it will ask for the result. A preference that should shape every future answer belongs in memory rather than in a published page, because it is an instruction to a model rather than a deliverable for a reader.

The second case is a team that has standardized on one assistant. The memory inside that tool already gives the assistant continuity, and outputs that stay inside the same product are served by what the tool provides. No second system is required.

The third is cost. Memory arrives with the assistant and needs no access decision, no filing and no explanation. A team whose AI output is conversational rather than documentary has little reason to publish it.

## When does an artifact earn a place beside memory?

An artifact earns its place when work has to leave the session that produced it. The signal is a record that exists and cannot be reached: the report was made, the session closed, and the next agent rebuilds it because nothing published it.

Artfct is the second object in this comparison, not a second memory. It is not a memory product, and it does not sit between a model and its prompt. It receives a finished page, keeps that page at a stable address, and answers the search that follows. A connected agent can search and list what the workspace holds, and a person can open the same link.

The second signal is a reader who is not the author. A colleague, a client, or an auditor opens a link rather than a session. A team artifact is open to the workspace, a private one is limited to the owner and the team administrators, and a public link opens for anyone holding it, without an account, provided the team has left public sharing on. Edit access sits on a separate axis, so a teammate can publish the next version. The boundary between the two objects is set out in more detail in [what is an AI artifact](https://artfct.dev/blog/what-is-an-ai-artifact).

Both objects serve the same team, and they are not substitutes. Memory shapes what the assistant does next, and an artifact records what it finished. Which one is missing shows up as a repeated answer or a rebuilt report.

## What this does not do

- It is not a memory product. It does not store preferences, project terms, or reasoning for a model to read, and it does not change what a model knows.
- It does not compare two versions. A history can be listed, opened and restored, and a difference view does not exist.
- It does not record the repository, branch or commit an artifact came from. Those fields are absent on the supported connection path, so the repository and producing-agent filters have little to match.
- Sharing is exactly private, team or public, plus the owner and the team administrators. A team administrator can switch public links off for the whole team, and a publish that asks for public is then refused. There is no per-person share.
- Search depends on indexing, which is switched on at the deployment level and is off by default, and a re-index is rate-limited to once per ten minutes per artifact.
- A single page is limited to 1 MB, and a bundle to 500 files, capped at 8 MB or the plan ceiling, whichever is lower: 5 MB on free, 10 MB on team, 25 MB on enterprise. A link published without an account carries a five-day sliding expiry and a 1 MB limit for a single file.
- It does not author or edit an artifact's own content. It stores and finds what an AI already made, and a page is published rather than drafted inside it; a signed-in member who can see the page can comment on it, and commenting is a write.

## Questions

### Is artfct a memory product for an agent?

No. Artfct holds finished artifacts rather than the context a model reads while it works. It does not store preferences or terminology for a model, and it does not change what a model knows.

### Does agent memory remove the need for artifacts?

No. A memory store serves the tool and the account that own it, and no memory crosses vendors. An artifact has an address and a sharing level, so it reaches further.

### Does search work in every deployment?

No. Indexing is off by default and is switched on at the deployment level, not per workspace or per person. Until it is enabled, a search returns "Search is not enabled for this workspace yet", and the artifact remains reachable through its link.

### Does an artifact record the repository or commit it came from?

Not on the supported connection path. The repository, branch and commit fields are absent there, so the record holds the model when the caller supplies it, the MCP session id, the tool name, and the client name and version.

### Does a new version break a link someone already saved?

No. A revision is published against the same artifact, so the address does not change, and earlier versions can be listed and restored. A difference view is not available.

### What does it cost to try?

A single page can be published as a link with no account, encrypted and passcode-protected, and it expires five days after the last visit. A durable artifact is held under the organization's own keys, with no expiry.
