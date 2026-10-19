---
slug: what-is-mcp
title: What is the Model Context Protocol (MCP)?
description: The Model Context Protocol is an open standard that lets an AI tool reach data outside its own prompt. What a server exposes, and where a hosted one fits.
date: 2026-09-30
tag: explainers
published: false
surface: explainer
register: glean-formal
target: Model Context Protocol
---

The Model Context Protocol is an open standard that lets an AI tool reach data and actions outside its own prompt. A server publishes the operations it offers, a connected tool calls them by name, and the person behind that tool approves the connection once. Before the standard existed, every pairing of a tool and a data source needed its own integration.

## What is the Model Context Protocol?

The Model Context Protocol is an open standard for connecting an AI tool to something it cannot see on its own: a store of documents, a set of records, or an action such as publishing a page. The [specification](https://modelcontextprotocol.io/) fixes the shape of that exchange, so a tool and a server built by different teams can work together, without either side knowing what the other one built.

The name rewards a slow reading. A model answers from what it has been given, and context is everything it holds while it answers: the conversation so far and the results of any lookup it has already made. The protocol is the agreed shape of those lookups, in the way that ordinary web traffic depends on an agreed shape for a request and a response.

Because that shape is public, both ends can be built independently. A tool vendor writes a client once, and every server that follows the specification becomes reachable from that tool. The owner of a data source runs one server, and every client that follows the same specification can call it. The pile of bespoke integrations stops growing in both directions at once.

What the standard leaves open matters just as much as what it fixes. It does not decide what a server should expose, who is allowed to call it, or what the calling tool does with the answer. Those are product decisions on each side, which is why two servers can both comply with the specification and still differ sharply in what they are worth to a reader.

## Why does an AI tool need a protocol to reach outside its prompt?

A model answers from the material that travels with the question. That material is the prompt, and it has a boundary: the tool assembling the request decides what goes inside, and nothing beyond the boundary can be consulted unless something goes and fetches it.

A team's useful material is much larger than any single prompt can carry. Placing all of it in the request is not an option, because a request that long dilutes the passages that matter and takes time to assemble.

Two workarounds carried most teams for a while. One was a fixed set of connectors, chosen by the tool vendor and compiled into the client, which covered the common sources and little else. The other was a hand-built integration for each source a team needed, written against one vendor's documentation and maintained by whoever had the time. Both put the decision about what to fetch in the hands of a developer, made in advance and frozen into a release.

A protocol moves that decision to the moment of the answer. The material is reached on demand, mid-sentence, and only the part that is needed is fetched. Think of it as a lookup performed during an answer rather than a briefing handed over before the work begins, and the difference is not merely architectural, because it decides whether an agent can respond to a question nobody anticipated.

The consequence is worth stating plainly, because it is the part readers overestimate. A connection adds reach, not knowledge. What comes back is exactly what the server chose to publish, so a well-built connection to a thin source is still a thin source.

## Who is on each end of an MCP connection?

Every MCP connection has two roles. The client lives inside the AI tool and asks the questions. The server answers them, exposing the operations and the material it is willing to offer. The same standard governs both roles, and either side can be replaced without the other needing to change.

The standard does not say where the server runs, and that silence is deliberate. A server may sit on the same machine as the tool, or it may be a hosted address reached across the network. The choice is a product decision, and it changes who can reach the connection, what a team has to maintain, and how many people can use it at once.

A hosted server has one address, which is the property that matters most to a team. A person adds that address to each tool, signs in once through a browser, and nothing is installed on the machine or updated on it later. A client that runs in a browser reaches the same server as one that runs on a laptop, and one address serves every member of the workspace.

Authorization follows the pattern the web already uses rather than inventing one. A hosted server asks the person to approve the connection in a browser, and the tool then holds a credential on that person's behalf. Each connection belongs to the individual who approved it, so one member's sign-in does not extend to a colleague, and each tool asks its own user separately.

## What does an MCP server expose to a model?

A server offers a catalogue of named operations. Each one carries a description and a list of arguments, and the client presents that catalogue to the model along with everything else in the request. The model then works as it works with any other instruction: it reads the descriptions and decides whether a call is worth making.

That makes the description a load-bearing piece of text rather than a footnote. An operation described vaguely gets called at the wrong moment, or never called at all, and no amount of server-side correctness repairs the mistake. Writing a description is closer to writing a form label than to writing a manual, because the reader is deciding whether to act, not studying a subject.

The catalogue also draws a boundary that readers tend to miss. A model can call only what the catalogue lists, so the reach of a connection is a fixed and reviewable list rather than an open door into a system. A team can read that list in a few minutes and know exactly what an agent is able to ask for.

The operations themselves divide into two families, and the split is worth keeping straight. One family reads: search, list, fetch, summarize. The other writes: create, update, delete. A server that mixes the two without saying so gives an agent more authority than the person who approved the connection understood they were granting.

## How is MCP different from writing an integration for every tool?

An API is a contract between one service and the programs written against it. When five AI tools need to reach the same source, and that source offers only an API, each pairing needs its own work: five clients, five sets of credentials, five places where the shape of the data is interpreted. The total grows with the product of the two sides, and every vendor's documentation is read separately.

A shared standard divides that work once instead of repeatedly. The source implements a server, each tool implements a client, and the two sides never have to meet. The standard also gives the model a uniform way to ask, and the caller here is the model, mid-answer, choosing for itself.

This is not a replacement story. An API remains the right interface for scheduled jobs, scripts and pipelines, and artfct keeps a REST API beside its server for exactly those callers. Both exist because the consumers differ: one is a program written by a developer, and the other is a model deciding what to look at while it writes an answer.

A standard reduces integration work, and it removes none of the judgment. A server owner still decides what is safe to expose, still keeps the underlying material current, and still answers for what a wrong answer costs.

## Where does a hosted MCP server fit?

artfct runs one hosted MCP server, at a single address, and it is the one way to connect a tool to a workspace. The server does one narrow job for the team: it exposes finished work, so a connected agent can find what an earlier agent already made instead of building it a second time.

The connection is an address and a sign-in. One command registers the server with each tool it finds on a machine, and each tool then asks that person to approve the connection in a browser.

```
npx add-mcp@latest https://artfct.dev/mcp --name artfct --global
```

Members install nothing, and an AI tool that runs in a browser reaches the same server as one that runs in a terminal. The [setup guide](https://artfct.dev/docs#mcp) carries the steps for each tool, alongside the [machine-readable reference](https://artfct.dev/llms-full.txt) written for an agent rather than for a person.

What the server offers is a set of operations over the [artifacts](https://artfct.dev/blog/what-is-an-ai-artifact) a workspace holds. An agent can publish a page or a document, update what it published without changing the link, list what exists, and search it. Publishing carries real limits: a single self-contained page is one file of up to 1 MB, while a multi-file artifact can hold up to 500 files and 8 MB in total.

Search is the operation that turns a connection into recall, so it is worth describing in full. It returns summaries, short excerpts and a link to the original page, which is enough for an agent to judge whether the whole artifact is worth opening. Results are not a bare text match: semantic similarity is fused with keyword matching and then ordered by recency, by how often an artifact has been opened, and by whether it sits in a pinned collection. A canonical report therefore outranks a near-copy that merely reads as similar.

Two operational details matter before a team leans on this. The server limits how often it is called and reports a retry-after value when a limit is reached. Indexing, which is what makes search possible at all, is a deployment setting rather than a default, and an operator switches it on for the workspace.

## What does a shared server look like in practice?

An analyst asks Claude Code for a churn breakdown and gets a dashboard worth keeping. The analyst asks for it to be published, and the tool calls `deploy_artifact` with the page. A link comes back, and the analyst drops that link into the planning document the team already reads.

Three weeks later an engineer working in Codex asks why a particular set of accounts churned in the same quarter. The agent calls `search_artifacts` with a short query, receives a summary of the churn dashboard with its link, opens the page, and answers with the source attached. The engineer follows one link and reads the number the analyst produced, rather than a fresh reconstruction of it.

The correction path is what keeps a practice like that alive. When a figure turns out to be wrong, the analyst publishes a corrected version to the same address. Any document citing the link still resolves, earlier versions stay listed and can be restored, and nobody has to guess which of several copies is current. The same search runs from a console page and from a Slack command, where results return to the person who asked and to nobody else in the channel.

Two further properties do quiet work in the background. Identical content published twice resolves to one artifact rather than two, so a team does not accumulate near-copies that differ only in the hour they were made. Each artifact is served from its own hostname behind a short-lived signed token, so the scripts inside one page cannot reach the scripts inside another.

Nothing in that story required both people to be in the same tool, or even to work in the same week. The record belongs to the team, and the client reading it belongs to whoever asks.

## Who decides what a connected agent can reach?

Permission sits in two places, and conflating them produces most of the anxiety around agent connections. The server decides what exists to be called. The person decides whether their tool may call it at all.

On artfct the person's decision arrives as a browser sign-in. The approval screen names the client, the workspace and the person asking, and nothing at all is granted until it is accepted. A connection belongs to one person, so a colleague's approval does not carry over to anyone else, and every tool asks its own user in turn.

An administrator holds the list. The console shows the AI tools connected to a workspace, and any connection on that list can be revoked by an admin. Sharing levels still govern the material underneath: a private artifact stays with its owner, a team artifact is visible to the team, and a public artifact to anyone holding the link.

Governance keeps its own record. An audit log captures creation, viewing, sharing and revocation, and its export is available on Enterprise. An organization can also revoke the tokens its automation uses through the REST API, without disturbing the connections that individual people set up for their own tools.

## Which AI tools can connect today?

Readiness varies by tool, and the difference between the two groups is worth keeping straight. Claude Code, Codex, OpenCode and Antigravity are verified against staging, which means someone completed the browser sign-in and a tool call inside each one. The Claude app, ChatGPT, Cursor, VS Code, Windsurf and Zed have documented paths that artfct has not tested against staging, so those connections are described rather than proven.

The remaining condition is mechanical rather than commercial. A hosted server is reached over the network, so a client that can only start programs on the machine it runs on cannot reach one. Any other client that supports a remote server is a candidate, and the [docs](https://artfct.dev/docs#mcp) keep the current list.

A setup step cannot drift between the page a person reads and the text an agent reads, because the docs page and the machine-readable reference render from the same source. If a step changes, every surface changes with it, which is a small thing until the first time a client is configured from stale instructions.

## What this does not do

- A connection is a channel rather than a memory. It moves finished artifacts between tools, and it does not change what a model knows or how it reasons.
- There is one way in, and it is the hosted server. Nothing is installed on a member's machine, and no local component is offered.
- A tool that can only start programs on the machine it runs on cannot connect to a hosted server, and no workaround exists for that shape of client.
- The provenance record on the hosted connection leaves the repository, the branch and the commit empty, and it does not distinguish one connected tool from another. The model is reported by the caller rather than observed.
- Sharing is exactly private, team or public, together with the owner and a team's administrators. There is no per-person sharing.
- Search depends on indexing, which is switched on at the deployment level and off until an operator enables it. Re-indexing a single artifact is limited to once every ten minutes.
- A single page is capped at 1 MB, and a multi-file artifact at 500 files and 8 MB, with plan ceilings binding lower still.
- A version history can be listed, opened and restored, and there is no comparison between two versions.
- An audit log records creation, viewing, sharing and revocation. Deletion is not one of its entries.
- Whether an indexed record measurably changes what an agent does is the question the product exists to test. It is a working thesis rather than a result anyone can quote.
- Data residency by region and tenant-branded domains are in development. Neither is available today.

## Questions

### Is the Model Context Protocol a product or a standard?

It is a standard: a published specification that defines how a tool and a server exchange a request and an answer. artfct implements the server side of it, and the same client works against any server that follows that specification.

### Does MCP give a model access to everything an organization stores?

No. A server exposes only the operations its owner chose to publish, and the person using the tool approves the connection before any of them can be called. A model can reach what a server publishes and a person has granted, and nothing further.

### Does an MCP server replace an API?

No, and the two serve different callers. An API is written against by developers for programs, scripts and scheduled pipelines. MCP serves a model that is choosing what to look at while it answers. artfct keeps a REST API alongside its server for the first kind of caller.

### Why does a hosted server matter more than one running on a laptop?

Reach. A hosted server has one address, so nothing is installed on any machine, a browser-based client reaches it as easily as a desktop one, and every member of a team is talking to the same set of artifacts rather than to their own copy of it.

### Which tools can connect to artfct today?

Claude Code, Codex, OpenCode and Antigravity are verified against staging. The Claude app, ChatGPT, Cursor, VS Code, Windsurf and Zed have documented paths that have not been tested by artfct, and any other client that supports a remote server can connect.

### How does search find the right report when two of them read alike?

Two signals are fused before ranking begins: semantic similarity, which finds work about the same subject in different words, and keyword matching, which finds the exact term when the exact term is the point. The fused result is then ordered by recency, by opens, and by whether an artifact is pinned to a collection, so the version a team actually uses tends to arrive first.

### What happens to the content once a tool is connected?

Content published to artfct is not used to train AI. A connection grants the tool the ability to read and to publish within the workspace that person belongs to, and the connection can be revoked from the console when it is no longer wanted.
