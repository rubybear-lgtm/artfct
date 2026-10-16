---
slug: why-agentic-ai-needs-a-shared-store
title: Why agentic AI needs a shared record
description: A larger context window helps one agent hold more of one task. It cannot hand a finished report to a second one. Why agentic AI needs a shared record instead.
date: 2026-09-28
tag: comparisons
published: false
surface: comparison
register: glean-formal
target: agentic AI
---

The case for a shared record in agentic AI does not rest on a bigger window. A larger context window lets one agent hold more of one task, and that is a genuine gain. What it cannot do is show a second agent what a first one produced, because that boundary sits between tools rather than inside a window. A shared record of what your agents built is what closes the handoff.

## What does a larger context window actually buy?

A context window is the working memory a model can read at once, as [the definition a model vendor publishes](https://docs.anthropic.com/en/docs/build-with-claude/context-windows) sets out. It holds the current exchange, the documents in play, and the result of each step the agent has taken. A larger window means more of a single task fits inside it without being trimmed.

That is a genuine gain, and it is why the number keeps growing. A long document can be read in full rather than summarized in pieces. More of a codebase stays in view while one agent works. A run of steps holds together, so the agent does not lose the thread halfway through a task.

Windows fill up in ordinary use. Tool results arrive by the page, and one broad retrieval step can consume a large share of the space available. Past a point, the agent keeps less of the task in view and more of it in compressed form.

For one agent working through one task, a larger window removes a real constraint. It delays the point at which the task has to be split, and it keeps the facts a single step needs in the same place. It is the right lever for a long task handled by one agent.

## What is a shared record for, in a team of agents?

A shared record is for the finished work that a context window is not built to keep. Artfct takes the page an agent produced in HTML — a report, a dashboard — and publishes it to an address. The [artifact](https://artfct.dev/blog/what-is-an-ai-artifact) outlives the session that made it.

The record is not a larger working memory, and it is not somewhere a model thinks. It is what the team's agents have already built, kept in a place that a person can open and that any connected tool can search once indexing is enabled for the deployment.

Its scope is narrow on purpose. It holds what an AI tool produced and a person can open — rendered at a link or downloaded as a file. The team keeps the rest of its files where it keeps them.

That difference matters in a multi-agent setup, because the second agent is rarely short of its own context. What it lacks is not room, but any sign that the work already exists.

## Where does the boundary sit when two agents work together?

In an agentic system that spans more than one tool, two agents have no shared window to begin with. An agent in one tool and an agent in another each hold a private context, and neither is aware of the other's session. The gap between them is the tool boundary, not the edge of a window.

Where both agents run inside one product, that product may keep a shared thread, and the window can then carry work between them. That is a real advantage of staying inside one vendor, and it is why the boundary is easy to miss. It applies only to agents that share a vendor.

Across tools, no lab will index a competitor's output. The [boundary between tools](https://artfct.dev/blog/why-your-ai-tools-cannot-see-each-others-work) is not a matter of window size; it is a matter of whether the work was ever written down somewhere the second agent can reach.

A monthly review makes the seam visible. An analyst's agent produces the summary in one tool, and a week later an engineer's agent is asked for the same numbers in another. The second agent has a large window and no route to the analyst's page, so the summary is produced a second time.

## Why does a bigger window not carry a peer's finished work?

A context window is private and short-lived. Another agent cannot query it, and it is gone once the session ends. There is no address for a peer to open, so a second agent cannot reach the first agent's report even when it knows the report exists.

Holding more is not the same as reaching further. A model can work over what is in front of it; it cannot ask a process it has no route to for a page it has never seen. A larger window changes how much the second agent can hold, not what it is able to reach.

A person can close the gap by hand. Copying one agent's result into the next agent's prompt works for a single handoff, and it is often good enough for a single handoff. It leaves nothing behind, so the third agent and the same team next month both start from nothing.

Feeding the same text forward has a second cost. The pasted copy belongs to no one, sits at no address, and is not updated when the numbers change, so the two agents can disagree about a figure when each was accurate at the moment it wrote.

## What would a larger window have to do to replace a shared record?

In an agentic system, three things would have to be true for a window to do this job. The second agent would need a route into the first agent's session. The result would have to be kept somewhere that outlives both sessions. And the second agent would need a way to search that place without knowing what is in it.

The first condition is the one a window cannot meet, because it is a boundary between systems rather than a limit inside one. A window is scoped to the process that holds it, and no amount of space creates a route into another process. The other two conditions describe a shared record.

That is why the two are normally needed together rather than weighed against each other. The window carries the task; the record carries the output past the end of the task.

## How does the first agent's output become findable?

Finished work has to be published before it can be found. Publishing is one call, and the result is a page at an address that a connected tool can search once indexing is enabled. The [docs](https://artfct.dev/docs) describe the tools a connection exposes.

Where indexing is enabled, search is hybrid: a semantic request is fused with keyword matching, so a description of what the work contains can find a page whose title does not match. Results are then ranked by recency and by how often an artifact has been opened. A canonical report the team keeps returning to can therefore outrank a page that is merely closer in meaning.

Identity is what keeps the record clean. Because an artifact is content-addressed, publishing identical content again resolves to the artifact that already exists rather than to a duplicate. A correction publishes a [new version](https://artfct.dev/blog/one-link-that-keeps-its-history) at the same address, and an earlier version can be listed and restored. A collection is how a team marks what mattered, and a pinned artifact ranks above one that was never pinned.

None of this requires the second agent to know which tool produced the first page, or that a first page exists. Search, once indexing is enabled, works against the shared record, so an agent that knows the subject can find the work without knowing who made it.

Retrieval still depends on the agent. Whether a given tool searches before it starts is a property of that tool rather than of the record.

## What can the next agent do once it has found the work?

A search returns a summary, an evidence excerpt and a link, and the excerpt a connected tool receives is longer than the one the console shows. That is often enough to establish that a page is the right one. It is not always enough to build on, because an excerpt is a sample of the work rather than the work itself.

Where indexing is enabled, the next agent is not limited to that sample. `read_artifact` returns the extracted text of one artifact, one bounded page at a time, taking an offset and a limit and reporting a next offset to continue from. It returns text and never the HTML bundle, and it is version-honest: only the last-indexed version can be read, so a reader cannot pull the wording of an older revision.

The second question is what has changed since the peer last looked. `search_artifacts` accepts `changed_since`, so an agent that already holds a time can ask for the work updated after it, rather than repeating the whole search from the start. A nightly agent picks up the report it read last week and asks only for what has moved since.

Both paths depend on indexing, and neither is a property of the window on either side. With indexing off, search answers that it is not enabled for the workspace yet, and a read answers that reading artifact text is not enabled for the workspace yet. Where it is on, a peer can continue from recorded work rather than reconstruct it.

## Which one does a team need?

The two are not substitutes, and neither replaces the other. A window is where an agent holds the task it is working on. A shared record is where the work goes once the task is finished.

|  | A larger context window | A shared record |
| --- | --- | --- |
| Scope | The one agent holding it | Any tool an individual member has connected |
| Lifetime | Lasts for the session | Lasts beyond the session, at an address |
| Between two different tools | Nothing crosses unless one vendor owns both | A published artifact is searchable from either side, once indexing is on |
| Holding a long task together | Its main job | Not its job; it keeps finished output, not work in progress |
| Finding earlier work | Not possible; the next session starts over | With indexing on, a read returns the last-indexed text, marked stale |

### Choose a larger context window if

The work is one long task handled by one agent. A single agent that has to read a long document, hold a large codebase in view, or work through many steps without losing the thread is served by a larger window, and there is nothing to add to it.

### Choose a shared record if

More than one tool, or more than one person, will need the output. When a report one agent produced has to reach a different agent, a colleague, or the same team next month, the window is the wrong place to keep it. The [full reference](https://artfct.dev/llms-full.txt) lists what a connected tool can do.

## What this does not do

- It moves finished artifacts, not reasoning. What reaches the second agent is the report, not the argument or the steps that produced it.
- It does not change what a model knows or how well it reasons. A record of finished work is something a model is given, not an improvement to the model.
- Whether an indexed artifact changes how an agent behaves is not something we can show a measurement for yet. It is the question the product exists to test.
- Search and artifact reading are off until a deployment operator enables indexing for the deployment, so retrieval is not present in every workspace by default.
- Public links can be switched off for a team, so whether a link opens without an account depends on an administrator's setting.
- A tool that can only start programs on your computer cannot connect, because artfct runs as a hosted server.

## Questions

### Is artfct a memory layer or a context layer?

No. It is not a memory layer and it is not a context layer, and it does not carry reasoning between agents. It holds the finished pages an AI tool produced and makes them searchable once indexing is enabled.

### Is search available in every workspace?

No. Indexing is off by default, and it is switched on for the deployment rather than for one workspace. Until it is on, a search returns "Search is not enabled for this workspace yet."

### Does indexing change how an agent behaves?

That is the question the product exists to test, and there is no measurement to show for it yet. What indexing does is make finished work reachable. Whether a tool searches before it starts remains a property of that tool.

### Does a shared link open without an account?

Where the team has public links enabled, a public link opens for anyone who holds it, with no account. A team can switch public links off, and then a link is limited to the team. A link published on the free tier is passcode-protected.

### Can every AI tool connect?

No. The connection is hosted, so a tool that can reach it over the network can connect. A tool that can only start programs on your computer cannot connect, because artfct runs as a hosted server.

### Why not paste the first agent's output into the second agent's window instead?

That works for one handoff and costs nothing to try. It leaves no record, so a third agent and the same team a month later both start from nothing, and the next handoff falls on a person again.
