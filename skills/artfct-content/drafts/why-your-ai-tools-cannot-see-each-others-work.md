---
slug: why-your-ai-tools-cannot-see-each-others-work
title: Why your AI tools cannot see each other's work
description: Every AI tool keeps its own record of what it made, so the next agent rebuilds finished work. Why AI tools cannot see each other's work, and what changes.
date: 2026-10-02
tag: explainers
published: true
surface: explainer
register: glean-formal
target: AI tools cannot see each other
---

Cross-tool blindness is what happens when each AI tool remembers only its own output. It is a product boundary rather than an oversight, and it is why AI tools cannot see each other's work. A vendor indexes the work its own model produced and has no commercial reason to index a rival's, so the unit of memory is the tool while the unit of work is the team.

## What does it mean for one AI tool to see another tool's work?

An AI tool sees its own work when the product around the model keeps a searchable record of what that model produced. The record holds the finished item rather than the conversation that led to it. An artifact is something an AI makes that stands on its own as a page: a dashboard, a report, a table, a diagram or a short document.

The record is an index, and the index is what makes it useful. An index is a structure built for retrieval, so a question asked in plain language returns the items that match rather than a list of files to open one by one. A tool searches its own index every time it answers from history.

Seeing another tool's work means something stronger again. The second tool must be able to find the first tool's artifact, open it, and reuse it without a person copying anything across by hand. The structural reason is that no vendor indexes a competitor's output, so the gap is reproduced by every product boundary rather than being a feature anyone is late to ship. It exists inside a single product line, where one vendor owns both the output and the index of it.

## Why does each AI tool keep its own memory?

Every AI tool ships with a product around its model, and that product keeps the record of what the model made inside it. The record serves the vendor's own customers, inside the vendor's own workspace. There is no commercial reason for one vendor to index a competitor's output, and there is a strong reason to keep the record where the vendor can govern it.

The consequence is structural rather than accidental. The unit of memory is the tool, while the unit of work is the team. A person moves between several tools in a single week, and each tool sees a fragment of that movement.

Teams rarely standardize on one tool. Different roles reach for different assistants, and the tools rarely agree on where finished work should live. The natural fault line therefore runs between the products, not inside them.

Closing the gap requires a record that no single tool owns. That is a different product from any of the tools it connects, because its whole value lies in spanning them.

## What actually breaks when the tools cannot see each other?

The first cost is repeated work. When the next agent cannot see what the last one produced, it produces the work again, often differently. Two dashboards with the same title and slightly different numbers are worse than one, because nobody can tell which one to trust.

The second cost is accumulation that never happens. A good report format, a useful dashboard pattern, a runbook that worked, all stay one-off outputs instead of becoming the way a team works.

The third cost falls on people who are not engineers. A product manager who generates a report in one tool has nowhere to put it that the rest of the team will look. Multi-tool work is the norm: [most engineers juggle two to four AI tools at once](https://newsletter.pragmaticengineer.com/p/ai-tooling-2026), [90 percent of professional developers use an AI coding agent at work each week](https://blog.jetbrains.com/research/2026/08/ai-coding-agent-adoption-2026/), and 68 percent use one daily.

Those figures describe tools in use. They do not describe work shared between tools, and nothing in them suggests that outputs travel. The gap is not measured by adoption, because adoption is precisely what creates it.

## What does the gap look like in practice?

Consider a twelve-person product team that runs three tools: one the analysts use, one the engineers use, and one that marketing keeps for campaign work. In January, an analyst asks the analytics tool for a monthly churn review. The tool produces an interactive dashboard with a written summary, the analyst shares the link, and the dashboard becomes the version people quote in planning meetings.

In April, an engineer is asked for the same breakdown. The engineer works in a different tool, which has no knowledge of the January dashboard. The tool answers the question well, because it can, and produces a second churn dashboard. Its definition of an active account differs by a single filter from the first one. Both documents are correct. The numbers disagree.

The analyst and the engineer then spend a meeting reconciling two artifacts that describe the same business. Neither tool did anything wrong. Each answered inside the history it keeps. The team paid for the same analysis twice, and the second version is now the one in circulation.

The fix is not a better prompt or a longer context window. A shared record changes the second half of that story. The engineer's tool could ask whether the team already has a churn review, find the January dashboard, and read it before building anything. The engineer would still decide whether to reuse it or start again. The difference is that the decision is made with the earlier work in view.

## Do the labs' own sharing controls close the gap?

This is the fair objection, and the honest answer begins with a concession. During 2026 the major labs shipped governance inside their own products. [Claude Code Artifacts](https://docs.claude.com/en/docs/claude-code/artifacts) require an administrator to enable them for an organization, and the owner then grants permission before a page is shared publicly. OpenAI Canvas Sites arrived for business and enterprise workspaces with administrator-managed role-based access and per-site access modes that can be self-only, whole-workspace, or limited to named user groups.

Those controls are real, and a platform lead who has configured them will not be persuaded by a pitch that denies they exist. What they govern is one vendor's output, inside one vendor's workspace. The controls stop at the edge of the product that shipped them.

The structural problem is subtler than an absence of controls. No lab will index a competitor's output, because the index is the vendor's own product. Anthropic indexing Cursor's artifacts is not a feature that is running late; it is competitively incoherent. The gap lives between products, and every product boundary reproduces it.

## What would make cross-tool visibility possible?

Three conditions have to hold at once. Finished work has to be published somewhere outside any single tool. Every tool has to be able to reach that place, which means one shared connection rather than a separate integration for each pair of tools. And the record has to be searchable by the people who need it, not only by the tools.

Pairwise integration does not scale. Three tools need three connections, and five tools need ten, which is why a single shared endpoint matters more than any individual integration. The open primitives for that endpoint now exist: tools can speak a shared protocol, load shared skills, and use shared plugins, as the growing [cross-vendor agent tooling](https://github.com/ARUNAGIRINATHAN-K/awesome-ai-agents-2026) around them shows.

Artfct is one implementation of that shape. Its connection is a hosted, authenticated server that every supported tool reaches in the same way. One person on the team adds it and signs in once, and members install nothing. Claude Code, Codex, OpenCode and Antigravity are verified against staging, while several other tools, including Claude, ChatGPT, Cursor, VS Code, Windsurf and Zed, have setup paths that are documented rather than tested.

Two audiences then share one record instead of two copies of it. A teammate opens an artifact from a link or from Slack, and a connected agent finds the same artifact through retrieval. The [documentation](https://artfct.dev/docs) lists what each connection exposes.

## How does a team keep the version people trust on top?

Publishing is one half of the problem, and retrieval is the other. A store that returns ten plausible artifacts is not obviously more useful than no store at all. Ranking is what turns a pile of outputs into a record that people rely on.

Artfct ranks results by how recently an artifact was updated, how often it has been opened, and whether it sits in a pinned collection. Usage therefore decides what surfaces. A dashboard the team keeps opening outranks one that is semantically close but has never been read.

This is the mechanism behind a canonical version. Teams do not need ten churn reviews; they need the one that is current, and they need to know that it is. A record that blends recency with real use keeps the working version near the top without an administrator curating anything by hand.

Search is available to people as well as to agents. The console has a search page, and a Slack command returns results to the person who asked without posting them to the channel. The retrieval tool returns summaries, short excerpts and a link rather than raw files, so an agent can judge relevance before it reads. The [full reference](https://artfct.dev/llms-full.txt) lists the retrieval tools and what each returns.

## Where does a shared record cross into governance?

A store that tools can read is also a store that needs rules. Artfct publishes every artifact at one of three levels: private to the owner and administrators, visible to the team, or public to anyone holding the link. Editing is a separate decision from visibility, so a colleague can publish the next version of an artifact while the link stays the same.

The record is accountable. Creating, viewing, sharing and revoking all write to an audit log, and its export is available on Enterprise. A retention policy and a legal hold are Enterprise-tier features, and a hold prevents an artifact from being deleted while it stands. Each artifact also carries a provenance record: the tool that published it, the session it came from, and the model, when the caller supplied one.

Two boundary cases are worth stating plainly. Sharing is exactly three levels rather than a per-person list, so an artifact is visible to the whole team or to its owner and the team's administrators. And provenance records the publishing tool and client rather than the repository or commit, because those fields are not captured on the supported connection path.

Those rules are what make a shared record safe to adopt rather than a second place to lose track of work. Access is re-checked whenever an artifact is opened, and the three levels described here are the ones a team can change at any time. The [sharing model](https://artfct.dev/blog/who-sees-what-your-ai-makes) is covered in more detail elsewhere.

## What this does not do

- It does not make a model smarter. It moves finished work between tools rather than reasoning, and it does not change what a model knows.
- It does not accept arbitrary office files, images or archives. An artifact is something an AI made that a person can open — rendered at its link when it is a page, downloaded when it is not.
- Sharing is three levels, not a per-person permission list. An artifact is visible to the whole team, or to its owner and the team's administrators.
- Provenance records the publishing tool, the client and the model when the caller supplies one. It does not record the repository, the branch or the commit on the supported connection path.
- A tool that can only start programs on a computer cannot connect, because Artfct runs as a hosted server.
- The free tier is encrypted and passcode-protected, expires after five days of inactivity, and holds one file of up to one megabyte.
- Indexing can be switched off for a deployment, and a re-index runs at most once every ten minutes for each artifact.

## Questions

### Why cannot one AI tool read another tool's output?

Because the record of that output lives inside the product that made it. A tool searches its own history, and a different vendor's product does not expose that history to it. The two tools may run the same class of model and still share nothing.

### Does asking both tools the same question fix the problem?

Partly. The second tool will answer, but it answers from its own history rather than from the first tool's work, so the team still ends up with two artifacts and two versions of the same number. A shared record lets the second tool read the first answer before producing a second one.

### Do the AI vendors not provide sharing and access control already?

They do, inside their own products, and those controls are real. What they cannot provide is a record that spans two vendors, because the index of a competitor's output is not a product any vendor is placed to sell.

### Does a shared record make a model smarter?

No. It moves finished artifacts between tools. It does not improve what a model knows or how it reasons, and it is not a context layer for the model.

### How does a teammate find something an agent published?

The same way they would search anything else. They describe what they are looking for, and the record returns matching artifacts with a link, ranked by recency and by what the team has actually opened.

### What happens when two tools produce the same thing?

Identical content resolves to a single artifact rather than a duplicate, and a later version of the same artifact keeps the original link. A teammate who saved the first link still reaches the current version.

### Can an administrator remove something a colleague published?

Yes. An administrator can revoke any artifact in the team, and the revocation is written to the audit log. Retention and legal hold rules, both Enterprise-tier settings, apply to that removal as well.
