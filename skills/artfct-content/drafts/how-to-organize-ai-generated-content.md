---
slug: how-to-organize-ai-generated-content
title: How to organize AI-generated content
description: Organizing AI-generated content means deciding what people look for twice. Collections hold a topic, pinning marks what is canonical, and search does the rest.
date: 2026-09-23
tag: workflows
published: false
surface: feature-showcase
register: glean-formal
target: AI-generated content
---

Organizing AI-generated content is mostly a matter of deciding what deserves a place a person will look at again. The workable unit is not a folder but an artifact your team can pin, and search once indexing is enabled: collections hold a topic, pinning marks what is canonical, and search carries everything else. Most of what your agents produce needs neither.

## What actually needs organizing in a library of AI output?

The instinct is to file an artifact at the moment it is made, and that instinct is where the trouble starts. An agent publishes a report in the middle of a task, names it after the request, and leaves it wherever the tool put it. Nobody is present to make a classification decision, and nobody will be present later either.

What a team can organize after the fact is smaller than it first appears, and the useful unit is the finished artifact rather than the request that produced it. An artifact is a page or report that renders on its own and can be found again by describing it. That is the unit worth curating, because it is the unit a colleague and a connected tool can both retrieve.

The test is not where a thing belongs, but whether anyone will look for it a second time. Most output fails that test, and leaving it uncollected costs the team nothing. Organizing everything is the failure mode; organizing the small set people return to is the work.

## What is a collection for?

A collection is a list of artifacts that a team assembles after the fact. Someone creates one, renames it, adds artifacts and removes them, and a team admin pins the ones that matter. A collection carries a name and a description, and that is the whole of it.

The pattern that works is one collection per question rather than one per team or per quarter. A collection named after a question tells whoever maintains it what belongs, and it tells a newcomer where to start reading. A structure built from teams and dates asks for a decision every time something arrives, and that is exactly the decision that never gets made.

Adding an artifact to a collection does not change the artifact itself. A collection accumulates the artifacts a team wants to point at, and an artifact that belongs to no collection is still found by describing it. Nothing is lost by being left out.

## What should be pinned, and what should be left alone?

Pinning is the one editorial decision that changes what a reader sees first. Search ranking reads whether an artifact is pinned in a collection. Where indexing is enabled, that lifts a settled version above a newer draft matching the same words.

The artifacts worth pinning are the ones where two answers would be worse than one. The current figure, the approved page, the reference a newcomer reads before asking — those are the ones to mark. A pinned artifact outranks a semantically closer one nobody has opened. That is how a team's judgment survives a search box.

Everything else can stay unpinned. Pinning is not a reward for good work and does not need to be applied evenly; it is a signal about which result a reader should see first, and it is worth reserving for the cases where it helps.

## How does search carry what filing used to do?

Search is hybrid where indexing is enabled. It fuses semantic similarity with keyword matching, then ranks by recency, how often an artifact has been opened, and whether it sits in a pinned collection.

The [tool surface](https://modelcontextprotocol.io/specification/2025-06-18/server/tools) has two entry points. `search_artifacts` narrows by repository, agent, collection, date and type. `list_artifacts` filters by owner, sharing level, collection, agent, date window, title text, file kind and type, and sorts by updated, created, most viewed or title. Every artifact carries one type, exposed as `artifact_type` — report, table, document, mockup, slide deck, dashboard or other — and a type is a label, not a rendering promise. The console carries repository, producing agent and collection only, and its text box searches a title or a description; there is no sort control.

This is why the filing stays small: collections answer the repeated questions, and search answers the rest. Retrieval has its own page in [finding the report your team already wrote](https://artfct.dev/blog/finding-what-your-ai-already-made).

## Why does the same content published twice not create a duplicate?

Cleaning up is the task that makes most organizing systems fail, and here it largely does not arise. Artifacts are content-addressed, so publishing the same page twice resolves to the artifact that already exists rather than creating a second copy.

The practical effect on a collection is that a page cannot accumulate. When the content is identical, two agents publishing it end up pointing at one artifact, and the collection holds a subject rather than every rendering of it. Identical content never becomes a second artifact. A report regenerated with one figure changed is different content, and becomes its own artifact.

A revision behaves differently from a duplicate. A change becomes a new version under the original link, so the link a colleague saved keeps working and an earlier version stays available. That mechanism has its own page in [one link that keeps its history](https://artfct.dev/blog/one-link-that-keeps-its-history).

## How does the structure stay current?

A collection is expected to change, and editing it is an ordinary operation rather than an event. Renaming it, adding artifacts and removing the ones that no longer answer the question are routine. A collection that has outgrown its name should be renamed, not split into a hierarchy, because the hierarchy is the part nobody maintains.

Changed content is re-indexed when a new version is published. The console carries a reindex action a team admin can run, which retries an artifact whose indexing failed or was never indexed. Re-indexing is rate-limited to once per ten minutes per artifact.

An artifact whose substance appears only after the page runs is rendered before it is indexed, where indexing is enabled and a renderer is configured. That makes an interactive page searchable by the text a reader sees rather than by its markup. The structure stays current because it describes what people look for, which changes more slowly than the output does.

## What this does not do

- A collection is a name, a description and a list of artifacts rather than a taxonomy.
- Two filters on the console search page, repository and producing agent, have nothing to match on for artifacts published through the hosted connection, because that path records neither.
- Search is enabled at the deployment level and is off by default. Until it is switched on, a search reports that search is not enabled for the workspace.
- Re-indexing an artifact is allowed once every ten minutes.
- A bundle publish caps at 500 files, and at the lower of 8 MB and the plan ceiling. That is 5 MB free, 10 MB team, 25 MB enterprise. A single-page publish caps at 1 MB.
- A team admin can switch public links off, after which a publish asking for public is refused.
- Deduplication covers identical content: the id comes from a content hash, so one changed figure makes a new artifact.
- It does not accept arbitrary office files, images or archives. An artifact is something an AI made that a person can open.
- Organizing does not change what a model can do. Artfct stores and finds what an AI already made; it is not a place to author or edit documents.

## Questions

### Does every artifact need a collection?

No. A collection earns its place when people keep asking the same question. Artifacts nobody returns to can stay searchable and uncollected.

### What happens when two tools publish the same page?

When the content is identical, the second publish resolves to the artifact that already exists, so the collection does not gain a second copy.

### Do the console filters match the ones an agent gets?

Partly. Both surfaces carry the repository, the agent and the collection. The console text box searches a title or a description, while the tool surface adds owner, sharing level, file kind, artifact type, date windows and sorts.

### Why would a search return nothing?

Indexing is off by default and switched on at the deployment level. Until then, a search answers that search is not enabled for the workspace yet.

### Does a collection change the artifacts it holds?

No. Visibility stays with the artifact's own sharing level, which is private, team or public, and a collection does not widen it. Renaming a collection, or moving artifacts in and out of it, changes nothing about them. [Sharing](https://artfct.dev/blog/who-sees-what-your-ai-makes) has its own page.
