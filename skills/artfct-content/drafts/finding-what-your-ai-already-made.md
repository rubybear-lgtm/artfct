---
slug: finding-what-your-ai-already-made
title: Finding the report your team already wrote
description: Each AI tool keeps its own history, so finished work goes missing. Artfct indexes what your agents produce, so a team can find its AI work, not rebuild it.
date: 2026-10-08
tag: search
published: true
surface: feature-showcase
register: glean-formal
target: AI work
---

Teams rarely lack the analysis they need. They lack the ability to find it, because each AI tool keeps its own conversation history and nothing keeps the finished work. Artfct indexes the reports, tables and documents a team's agents produce, so a team can find its AI work rather than commissioning it again.

## Why does finished work go missing?

An agent produces a dashboard in an afternoon. That dashboard answers a question the team will ask again in three weeks, from a different tool, by a different person. Nothing about the workflow is broken, and nothing is lost in any obvious sense. The work is simply unavailable to anyone who did not watch it being made.

The cause is structural rather than accidental. An AI tool indexes what it can see, which is its own conversations and its own files. A vendor has no commercial reason to index a competitor's output, so a report produced in Cursor stays invisible to an assistant running in Claude Code. The unit of memory is the tool, while the unit of work is the team.

The usual workaround is to copy the output somewhere shared. That works once. It depends on a person remembering to do it, and it produces a file with no record of how it was made or whether the numbers have since changed. Artfct records how each artifact was published and keeps a version history under one link, which is the subject of [one link that keeps its history](https://artfct.dev/blog/one-link-that-keeps-its-history).

## What does a search actually match on?

Artfct indexes the artifact itself: the finished page, report, table or document, together with the record of how it was published. A search therefore matches on what the work says, rather than on its filename or the folder it landed in.

Extraction is not always a matter of reading markup. Many artifacts an agent produces are interactive, and their substance appears only once the page runs. Artfct detects that case, renders the artifact, and indexes the text a reader would actually see. Rendering depends on a renderer being configured and on indexing being switched on for the deployment. The pipeline is described in the [documentation](https://artfct.dev/docs).

Nothing becomes searchable until it is shared. A private artifact stays out of another member's results, and an artifact that has been revoked leaves the index rather than lingering in it. Who may open an artifact, and who may change that, is covered in [who sees what your AI makes](https://artfct.dev/blog/who-sees-what-your-ai-makes).

## How does an agent search before it starts?

A connected tool reaches the store through [one tool call](https://modelcontextprotocol.io/specification/2025-06-18/server/tools). It asks a question in ordinary language, and receives summaries, short excerpts and a link for each match. The agent can then say which artifacts it used, so a reader can follow the reasoning back to its source.

This is the mechanism the rest of the product rests on. An agent that searches first has the option of not rebuilding a report that already exists, rather than producing a second, slightly different version of a number the team had settled. Whether a team's tools take that option is the design's intent rather than a promise this page can make.

Teams commonly put that expectation in the project instructions an AI tool reads at the start of a session. A single line — search the store before starting research, and name what was used — is enough to make the behavior consistent across tools.

## How is one result ranked above another?

Matching alone produces poor results, because the artifact that shares the most vocabulary is frequently not the one a team relies on. Artfct fuses two signals first: semantic similarity, which finds work about the same subject in different words, and keyword matching, which finds the exact term when the exact term matters.

The fused result is then ranked by evidence of use. Recency counts, and so does the number of distinct people who have opened an artifact, weighted so that one reader returning five times does not outrank five readers. That signal decays over time, and an artifact superseded by a newer version drops out of the ranking rather than coasting on its history. An artifact pinned to a collection receives an additional lift.

The practical consequence is that a canonical artifact outranks a semantically closer one nobody has opened. A team's own behavior becomes part of the ranking, which is what turns a pile of outputs into a set of answers the team trusts.

## What can a search be narrowed by?

An agent can restrict a search by collection and by date, and the search tool accepts a repository filter for artifacts that carry one. Those four cover most of the questions a working session raises, particularly the common one about what the team concluded recently in a particular codebase.

The console serves people browsing rather than asking. Its list can be filtered by title or description, by producing agent, by repository, and by collection. The richer filter set — owner, sharing level, file kind, and a window on when an artifact was created or updated, with sorting by updated, created, most viewed or title — belongs to the list tool a connected agent calls.

## Can a person search the same store?

Yes, and the answer arrives through the same index. Someone describes what they are looking for without knowing a filename, and receives the matching artifacts with their sources attached.

The store also answers inside Slack. A team member runs a search command and the results are returned privately to that person rather than posted to the channel, which keeps a half-formed question from becoming a public one. Setup for every supported tool is listed in the [full reference](https://artfct.dev/llms-full.txt).

A team that wants to try the sharing half without connecting a tool can [publish a single page as a link](https://artfct.dev/free), which needs no account to open.

## What this does not do

- Search returns summaries, short excerpts and a link. It does not return the whole document, so a reader still opens the source for detail.
- Nothing is searchable until it has been shared. A private artifact is invisible to every other member and to every agent.
- Indexing is not instant. A newly published artifact is queued, and an administrator can re-run indexing if a page changes shape.
- A tool that can only start programs on your own computer cannot connect, because Artfct runs as a hosted server.

## Questions

### Does search read inside a report, or only its title?

Inside it. The artifact is indexed by its content, including pages whose substance appears only after the page renders. Titles and publication details are recorded alongside the text and used for filtering.

### Does an agent see artifacts that were never shared?

No. A search is scoped to the team and excludes anything revoked or inaccessible, so a private artifact is invisible to everyone but its owner and an administrator.

### What makes one result outrank another?

Semantic and keyword matching are fused first, then ranked by recency, how often an artifact has been opened, and whether it sits in a pinned collection.

### Does the team have to tag or file things for search to work?

No. Content is indexed when an artifact is published, and collections only lift results. A collection is how a team marks what mattered, not a precondition for finding it.

### Can a team member use the same search without an AI tool?

Yes. The console has a search page, and a Slack command returns the same results privately to the person who asked.
