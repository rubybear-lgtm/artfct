---
slug: how-to-stop-rebuilding-the-same-report
title: How to stop your team rebuilding the same report
description: Two people build the same report a week apart when neither can find the first. Artfct keeps what your AI tools publish at one link, so duplicate work stops.
date: 2026-09-22
tag: workflows
published: false
surface: feature-showcase
register: glean-formal
target: duplicate work
---

Duplicate work is rarely a knowledge problem. It is a retrieval problem: two people ask the same question a week apart, in two different tools. Neither can see that the answer already exists. Artfct records what those tools published, so the next person can find it, and keeps that record at one address through every later correction.

## How does one analysis end up built twice?

The failure is quiet, which is why it repeats. A product manager asks Claude Code to break last quarter's churn down by plan, and gets a table worth keeping. A week later an analyst in Codex is asked the same question and rebuilds the whole thing. Nothing told either of them the other work existed.

Neither person did anything wrong. No lab will index a competitor's output, so the second analysis starts from nothing while the first sits in a different history.

Copying the output into a shared folder is the common workaround, and it depends on one person remembering to do it. A copy also arrives without a record of how it was produced, or of what has changed since.

## What makes the second attempt avoidable?

Two steps, and neither is elaborate. The person who finished the analysis publishes it instead of leaving it in a chat. A [connected tool](https://modelcontextprotocol.io/) does that in one call. Related files can go up as a bundle of up to 500 files, capped at the lower of 8 MB and the plan ceiling.

Where indexing is enabled, the next person then searches before starting. Artfct fuses semantic similarity with keyword matching, so a question asked in different words still reaches the analysis that answers it. Each match comes back as a summary, a short excerpt and a link. An [artifact](https://artfct.dev/blog/what-is-an-ai-artifact) is the finished thing — a page, a report, or a table — rather than the conversation around it.

That order matters. Recording without searching produces an archive nobody consults, and searching without recording produces a good question and no answer. The pair is what turns finished work into a starting point.

## Where does the check belong in the workflow?

Retrieval has to sit where the work happens, or it will not happen. With indexing enabled, a connected tool searches the record during a session. That gives an agent the chance to find an earlier analysis before it begins a new one rather than after the work finishes. The [documentation](https://artfct.dev/docs) lists what a connection exposes.

People need the same reach, once indexing is enabled. The console carries a search page for browsing the record, and a Slack command returns matches privately to the person who asked. A half-formed question therefore stays out of the channel.

Teams often make the check explicit by putting one line in the instructions an AI tool reads at the start of a session. The line reads: “Search what the team has already recorded, and name what was used”. Whether a particular tool follows it is a property of the tool rather than of the record.

## Why does a citation need an address that does not move?

A finding is only useful while it stays citable. When someone corrects an analysis, they publish a new version under the same link rather than a new document at a new address. Every note, ticket and thread that pointed at the original keeps resolving to the work as it now stands.

Identity helps before the question of correction arrives. Publishing identical content twice resolves to the same artifact rather than to a duplicate. A team that re-publishes the same report therefore does not split its record across two links. The mechanics are set out in [one link that keeps its history](https://artfct.dev/blog/one-link-that-keeps-its-history).

## How does a team keep the record worth searching?

A record is only useful while the version people trust stays near the top. Where indexing is enabled, ranking draws on recency, how often an artifact has been opened, and whether it sits in a pinned collection. The report the team keeps returning to therefore outranks a near-copy that matches the same words.

Pinning is the deliberate half of that. A collection is how a team marks the analyses that settled a question; where search is on, pinning one lifts it in later results. The rest is use: an artifact nobody opens ranks below the artifact the team keeps reading.

None of this asks anyone to file carefully. Publishing is the step that makes work findable; once indexing is on, a collection changes where a result lands, not whether it can be found. [Finding what your AI already made](https://artfct.dev/blog/finding-what-your-ai-already-made) covers the search itself.

## What happens when an earlier version was wrong?

The practice dies when people stop trusting the record, so the correction path matters more than it looks. A wrong analysis is fixed by publishing the corrected version under the existing link. Publishing a correction deletes nothing, and starts no parallel document beside the original. A reader who spots a problem can comment on the artifact, so the discussion stays with the record rather than in a chat tool; the thread is resolvable once answered.

Earlier versions stay listed, so a reader can open the version a decision was made against. A team can also restore one if a correction is later reversed. There is no side-by-side comparison: the history is a list of versions a person opens.

Publishing a correction takes edit access, a separate axis from who can open an artifact, covered in [who sees what your AI makes](https://artfct.dev/blog/who-sees-what-your-ai-makes).

## What this does not do

- Where indexing is enabled, search returns summaries, short excerpts and a link rather than the whole artifact, so a reader still opens the source for detail.
- Indexing is switched on at the deployment level and is off by default. Where it is not enabled, a search reports that search is not enabled for the workspace yet, and re-indexing one artifact is rate-limited.
- An artifact does not record the repository, branch or commit it came from on the supported connection path. Those fields are absent, so a repository filter has nothing to match for work published through the hosted server. The model on the record is the one the caller reported rather than one Artfct observed.
- Artfct stores and finds what an AI already made. It does not write or edit an artifact's content — a member who can see the page can comment on it, and commenting is a write — and it does not accept arbitrary office files.
- Nothing obliges a tool to search before it builds. A connected agent has the option of finding the earlier analysis, and taking that option is a property of the instructions the team gives it.
- A tool that can only start programs on your own computer cannot connect, because Artfct runs as a hosted server.

## Questions

### Is search switched on by default, and does every teammate need a connected tool to use it?

No to both. Indexing is a deployment-level setting and is off by default; where it is off, a search reports that search is not enabled for this workspace yet. Where it is on, a teammate reaches the record through the console search page or the Slack command, and a connection extends the same record to an agent working inside a tool. Re-indexing a single artifact is rate-limited to once every ten minutes.

### What happens when two people publish the same analysis, and does anyone have to file it first?

Identical content resolves to one artifact rather than two. Where the content differs, both records exist, and where indexing is enabled ranking decides which appears first — recency, opens and pinned collections do that work. Nothing has to be filed first: publishing is what makes work findable, and once indexing is on, a collection changes where a record lands rather than whether it can be found.

### Does a saved link break when an analysis is corrected?

No. A correction is published as a new version under the same link, earlier versions stay listed, and any of them can be restored.

### Does the record say which repository or commit an artifact came from?

Not on the supported connection path. The record holds the model the caller reported, the tool name, the client name and version, and the session id. Repository, branch and commit are absent.

### Does Artfct write or edit the analysis, and will a connected tool always search before it builds?

No on both counts. The product stores and finds what an AI already made; writing, editing and rewriting belong to the tool that produced the analysis, and arbitrary office files are out of scope. Nothing obliges a tool to search before it builds, so the check depends on the instructions the team gives it. A tool that can only start programs on your own computer cannot connect at all, because Artfct runs as a hosted server.
