---
slug: share-artifacts-between-agents
title: How to share artifacts between agents
description: Different AI tools cannot read each other's work, so an agent rebuilds what a peer made. To share artifacts between agents, publish to one hosted address.
date: 2026-09-30
tag: workflows
published: false
surface: feature-showcase
register: glean-formal
target: share artifacts between agents
---

To share artifacts between agents from different products, something has to hold the work itself, since one cannot read the other's output. An artifact closes that handoff: it is finished work that stays readable to the next agent, and a connected tool finds it by searching one hosted address, once indexing is switched on at the deployment level — without either tool knowing the other exists.

## Why does the second agent start from nothing?

An agent answers from the conversation in front of it and the history of the product it runs inside. Nothing outside that boundary reaches it, so a second agent is blind to work a first agent finished.

A support lead asks an agent to turn a quarter of closed tickets into a summary of what keeps recurring. The agent produces a page, and someone drops the link into a handover note. Five weeks later a product manager asks a different agent, in a different product, the same question. That agent cannot see the first page, so it works through the tickets again and returns a slightly different list.

The cost is not the duplicated effort alone. Two lists of recurring issues disagree at the edges, and nobody can say which one the team already acted on.

## What does one address change for two different tools?

The record of that work has to belong to the team rather than to a single tool. Artfct gives the team one hosted address to connect to and search, and each connected tool points there instead of at its own history.

That indirection is what makes agents from different vendors usable together. The first agent publishes an artifact to the address. The second agent, running inside a different product, searches the same address and finds it. Neither vendor cooperated with the other, and neither tool needed to know the other existed.

What travels is a finished artifact rather than a transcript. An HTML artifact opens in a browser at its link, and the next agent reads the same text a person sees.

## How does an artifact get from one agent to the next?

Publishing is one call. An agent invokes deploy_artifact with a self-contained HTML page or a bundle of files, and the call returns a link. The artifact lives at that link, outside the tool that made it.

Finding it is one search. The next agent invokes search_artifacts, asks in ordinary language, and receives a ranked list of summaries, short excerpts and a link. It can cite that artifact rather than produce a second version, and narrow the search to a collection or a date window.

When an excerpt is not enough, the next agent invokes read_artifact, which returns the extracted text of a single artifact, one bounded page at a time, and never the HTML bundle. Like search, it is gated on indexing, and only the last-indexed version can be read.

No step asks the two tools to agree on anything. A tool that can reach the address can publish to it, and one that can reach it can search it, which is what makes a mixed set of agents workable.

## What has to be true before the next agent can find it?

Sharing bounds the handoff, and there are three levels: private, team, or public to anyone with the link — though a team can turn public links off, and a publish asking for public is refused. Team is the default, so a colleague's agent reaches an artifact without the publisher naming it. A private artifact stays out of other members' results, apart from its owner and team admins. The sharing model is covered in [who sees what your AI makes](https://artfct.dev/blog/who-sees-what-your-ai-makes).

Search is a setting of the deployment rather than a property of an artifact. Indexing is off by default, and until it is switched on, a search answers that search is not enabled rather than returning an empty list. Once it runs, an artifact becomes searchable as it is published, and re-indexing one artifact is limited to once every ten minutes.

Readiness also depends on the reader. A connection is what lets an agent search at all, so an agent whose owner has not connected a tool reads nothing.

## What does connecting a second tool involve?

One address and one approval, per person and per tool. A member adds the hosted address to the tool they use, the tool opens a browser page, and that member [approves the sign-in](https://www.rfc-editor.org/rfc/rfc6749). No Artfct server and no local component is installed or kept running on the machine, and no credential is copied out of a settings page. The [setup guide](https://artfct.dev/docs#mcp) lists the steps for each tool.

The connection belongs to the person who approved it, so every tool asks its own user to sign in, and a workspace cannot pool a single approval across the team.

Readiness differs by tool, and the difference is worth keeping straight. Claude Code, Codex, OpenCode and Antigravity are verified against staging, which means someone completed the browser sign-in and a tool call inside each one. The Claude app, ChatGPT, Cursor, VS Code, Windsurf and Zed have documented paths that artfct has not tested. A tool that can only start programs on the machine it runs on cannot reach a hosted server at all. The [machine-readable reference](https://artfct.dev/llms-full.txt) carries the current list.

## How does a handoff survive a change of tools?

A team that adds or drops a tool keeps the record, because the artifacts live at their links rather than inside a product. A new tool arrives, its user connects once, and the work already published to the workspace is there to search, provided it is shared to a level that reaches them and indexing is on.

An administrator can revoke a connection when a tool or a person leaves. Revocation removes the tool's access to the address and leaves the published artifacts in place.

That is the practical difference between a record and a chat history. The record outlives the agent that wrote to it, which is what lets a handoff cross a change of tools and a change of vendor.

## What this does not do

- A connection belongs to the person who approved it. It does not extend to a colleague's tool, so each member connects each tool they use, and every tool asks its own user to sign in.
- Indexing is switched on at the deployment level and is off by default. Where it is not enabled, a search reports that search is not enabled for the workspace yet.
- The record does not say which connected tool published an artifact, and the console shows an unknown tool. Repository, branch and commit are absent for the same reason, so the repository and agent filters in search have nothing to match on.
- A single HTML page is capped at 1 MB. A bundle is capped at 500 files, and its total is the lower of 8 MB and the plan ceiling: 5 MB on free, 10 MB on team, 25 MB on enterprise.
- It is not a memory layer. The record moves finished artifacts between agents, and it does not add to what a model knows or change how it reasons.
- Reading one artifact does not open another. Each artifact is served from its own hostname, and one artifact's JavaScript cannot read another's.
- It does not accept arbitrary office files. An artifact is something an AI made that a person can open at a link or download as a file.

## Questions

### Do both agents have to be the same product?

No. Any tool that can reach a remote server can publish to the address, and can search it once indexing is enabled, so the two ends of a handoff can come from different vendors. The setup guide marks which connections are verified and which are documented only.

### Does a colleague's agent see an artifact as soon as it is published?

It sees it once the artifact is shared and indexing is on. Team sharing is the default, and a private artifact stays out of everyone else's results.

### Does every member connect the tools they use?

Yes, and each member connects each tool separately. The connection belongs to the person who approved it, so a single approval cannot be pooled, and every tool asks its own user to sign in.

### Which artifact does the next agent get when several match?

The search fuses meaning with keyword matching, then ranks results by recency, how often an artifact has been opened, and whether it sits in a pinned collection. Summaries and short excerpts come back with the link, so an agent can choose without opening every match.

### Can a person read the same artifact?

Yes. An HTML artifact's link opens in a browser at the same address a colleague would use, and what an agent reads is the page a person sees.
