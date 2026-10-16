---
slug: artfct-vs-your-ai-tools-own-sharing
title: Artfct vs your AI tool's own sharing
description: Claude Code Artifacts and OpenAI's Sites share work inside one vendor. Artfct keeps the same work findable across tools. Reach, controls and retrieval.
date: 2026-10-03
tag: comparisons
published: true
surface: comparison
register: glean-formal
target: Claude Code Artifacts
---

Every large AI vendor now lets a team share what its agent makes. Claude Code Artifacts and OpenAI's Sites are both governed by the vendor's own administration, with role-based access and per-site sharing modes. Those controls are real, and they work well inside one product. The difference is reach, because a vendor indexes its own output and no vendor indexes a competitor's output.

## What is built-in sharing for?

Built-in sharing is a feature of the product that already made the artifact, and it is designed around that fact. Claude Code Artifacts ship inside Anthropic's own product, where an administrator governs them through organization settings and public sharing is a setting only that administrator controls. The [Claude Code artifacts documentation](https://docs.claude.com/en/docs/claude-code/artifacts#manage-artifacts-for-your-organization) covers those organization controls. OpenAI's Sites carry administrator-managed role-based access, and each site's audience is drawn from the vendor's own list of people and groups.

That design fits a team that lives in one tool. The artifact appears where the conversation happened, the permissions come from a directory the organization already maintains, and nobody adopts a second vendor in order to share a dashboard. For a working session between colleagues who all use the same assistant, this is the shorter path, and it deserves to be said plainly.

The boundary is the vendor. A product indexes its own conversations, its own files and its own artifacts, and it has no commercial reason to read another vendor's. A report published inside one tool therefore does not exist for a person or an agent working in a different tool. That is a structural limit rather than a missing feature, and it is the whole of the difference this page is about.

## What is Artfct for?

Artfct is a record of what a team's agents built. An artifact is a page, a report, a table or a document that an AI already made; Artfct stores it, shares it, and lets the next agent find it. It does not write the work, and it does not edit it.

There is one way in: the hosted server, added to a tool once and signed into once. Claude Code, Codex, OpenCode and Antigravity are verified against staging. ChatGPT, Cursor, VS Code, Windsurf and Zed have documented setup that has not been tested against staging, and any other client that can reach a remote server can follow the same path. A tool that can only run programs on the same machine cannot connect, because the server is hosted rather than installed.

A team that only wants to hand someone a single page can [publish it as a link](https://artfct.dev/free) without an account, and the recipient opens it at the same address. For a team that runs several tools, the point is cumulative: every connected tool can read what every other one published.

## Why does a single-vendor index leave gaps?

A vendor indexes what it can observe, which is its own product. No laboratory will index a competitor's output, because the artifact is the reason a customer stays. The practical result is that a dashboard made in one tool is invisible in another, and the second tool rebuilds it rather than finding it.

The behavior this meets is ordinary rather than exotic. [Survey work published by the Pragmatic Engineer in 2026](https://newsletter.pragmaticengineer.com/p/ai-tooling-2026) found that most engineers juggle two to four AI tools at once, and [JetBrains reported in August 2026](https://blog.jetbrains.com/research/2026/08/ai-coding-agent-adoption-2026/) that 90% of professional developers use an AI coding agent weekly, with 68% using one daily. Adoption of that shape produces two histories per team, and neither history answers a question asked in the other product.

Memory accumulates per product, while the work belongs to the team. That mismatch is what an artifact record is designed to close, and it is the axis on which the rest of this comparison turns.

## Who can open an artifact, and who administers the controls?

Both options carry real controls, and a comparison that pretends otherwise is not worth reading. Built-in sharing takes its permissions from the vendor's own administration. An administrator enables the feature, access runs through role-based controls, and public sharing is a separate grant that the owner must make.

Artfct organizes access around the artifact itself. A published page is private, shared with the team, or public to anyone holding the link, and edit access is a separate permission so that a colleague can publish the next version. Every sharing level uses one viewer route; a signed-out visitor to a team artifact is sent to sign in and back, and the route never confirms whether an identifier exists. Each artifact also receives its own hostname with a short-lived signed token and a content security policy derived from what that artifact declared, so one artifact's scripts cannot read another's.

Around that sit the controls an administrator would ask about. Roles cover administrators, members and viewers. Single sign-on runs through SAML or OIDC once a domain is verified by a DNS record. Directory sync provisions and de-provisions people from the identity provider. An audit log records create, view, share and revoke, and its export is available on Enterprise. Retention policy and legal hold are Enterprise-tier features, and an administrator can export everything the organization holds as a set of files verified against their hashes. A list of connected AI tools can be revoked from the same console.

Sharing is exactly private, team or public, plus the owner and the team administrators. There is no sharing with a hand-picked individual, and a page that claims otherwise would be describing a feature that does not exist.

## What happens when the tool that made the artifact changes?

A link produced inside a vendor's product is tied to that product. Where an artifact is served from, and for how long, is the vendor's decision rather than the team's. Nothing here is a criticism of that arrangement; it follows from the artifact living inside the tool.

Artfct separates the artifact from the tool that produced it. Publishing returns a link that does not change when the content does, because a later version is published on the same address, so a link pasted into a ticket still resolves after the numbers are revised. Earlier versions can be listed, and one can be restored.

Identical content resolves to one artifact rather than two, because the identifier is derived from the content itself. Two agents that produce the same report produce one page rather than a duplicate with a second address. The record kept about the work is bounded and honest: it carries the publishing tool, the client and its version, the session identifier, and the model when the caller supplies it. Repository, branch and commit details are not captured on the hosted connection path, so the record describes how the artifact arrived rather than where it came from. How a replacement version is published is described in the [documentation](https://artfct.dev/docs).

## How is the work found again later?

A published page answers people and agents through the same index. A teammate describes what they are looking for without knowing a filename, and an agent asks before it starts work, so the next session begins from what the team already concluded. A record of how this behaves in practice is kept in [finding what your AI already made](https://artfct.dev/blog/finding-what-your-ai-already-made).

Ranking fuses two signals. Semantic similarity finds work about the same subject in different words, and keyword matching finds the exact term when the exact term matters. The fused result is then ordered by recency, by how often an artifact has been opened, and by whether it sits in a pinned collection. The consequence is deliberate: a canonical artifact outranks a semantically closer one that nobody has opened.

A search can be narrowed by collection and by date, and by repository or producing agent for artifacts that carry those values, which on the hosted path most do not. The list tool a connected agent calls accepts a wider set — owner, sharing level, collection, producing agent, a date window, title text and file kind, sorted by most recently updated, most opened, or title. The console's own list filters by repository, by AI tool, and by title or description. A Slack command returns the same results privately to the person who asked, never to the channel. Setup for each tool is listed in the [full reference](https://artfct.dev/llms-full.txt).

## How do the two compare side by side?

The dimensions below decide most of the question. Feature counts do not, because both options share a page, both render it, and both protect it.

| Dimension | Built-in sharing in an AI tool | Artfct |
| --- | --- | --- |
| Reach | Visible to people and agents inside that vendor's product | Visible to any connected tool, whichever vendor published the artifact |
| Who can open it | Governed by the vendor's own administration, with role-based access and a public-link setting the administrator controls | Private, team, or a link anyone can open; edit access is a separate axis; a signed-out visitor is sent to sign in |
| The link | Tied to the workspace that holds the artifact | Unchanged when a new version is published; identical content resolves to one artifact |
| What is recorded | Kept with the vendor's workspace record | Publishing tool, client version, session identifier and the model when supplied; repository and commit details are not captured |
| Administration | Administrator enablement, share permissions, role-based access | Roles, single sign-on, directory sync, an audit log, and retention policy and legal hold on Enterprise |
| Getting started | Included in the plan the team already holds | A link tier that needs no account to open, and a permanent organizational tier |

## Which should a team choose?

Choose built-in sharing when the team works in one AI tool and the artifact is part of that working session. A dashboard produced and read inside the same product, shared with colleagues who already have accounts there, is served well by the controls the vendor already maintains. Administration stays in one place, the directory does the work, and no second vendor enters the procurement conversation. A team that has deliberately consolidated on one assistant should use what that assistant ships.

Choose Artfct when more than one tool is in use and the artifact has to outlive the session that produced it. A report made in one product and read by an agent in another is the case the vendor cannot serve, and it is the case that produces silent duplication. Two tools, two histories, and a second version of a number the team had settled is worse than one version nobody can find.

The two are not rivals in most organizations. Built-in sharing is where the work happens; an artifact record is where it stays. Which pages see which audience is covered in [who sees what your AI makes](https://artfct.dev/blog/who-sees-what-your-ai-makes), and how a link keeps its history is covered in [one link that keeps its history](https://artfct.dev/blog/one-link-that-keeps-its-history).

## What this does not do

- It does not author or edit documents. Artfct stores and finds what an AI already made, and it is not a place to write.
- It does not accept arbitrary office files, images or archives. An artifact is something an AI made that a person can open — rendered at its link when it is a page, downloaded when it is not.
- It does not record repository, branch or commit provenance on the hosted connection path, so an artifact does not name the codebase it came from.
- Sharing is exactly private, team or public, plus the owner and the team administrators. There is no per-person share.
- Indexing can be switched off for a deployment, and a re-index is rate-limited to once per ten minutes per artifact.
- A link published without an account carries a five-day sliding expiry and a 1 MB limit for a single file. The permanent tier is what removes the expiry.

## Questions

### Do the AI vendors lack governance controls?

No, and the opposite is closer to the truth. They have real ones. Claude Code Artifacts and OpenAI's Sites are governed by the vendor's own administration, with role-based access and per-site sharing modes. The structural limit is cross-vendor: a report made in one product is invisible to an agent running in another, because no vendor indexes a competitor's output.

### Is Artfct a replacement for the AI tools a team already uses?

No. It does not generate or edit anything. It receives a finished artifact from whichever tool made it, stores it under a stable link, and answers searches from every connected tool. The tools keep doing the producing.

### Can two colleagues share a page with each other alone?

No. Sharing is private, team or public, plus the owner and the team administrators. A narrower grant is not available, so a page that needs to reach one person is published to the team or by link instead.

### What keeps one published page from reading another?

Each artifact receives its own hostname, a short-lived signed token, and a content security policy derived from what that artifact declared. Pages are cookieless, and one artifact's scripts cannot reach another's data.

### Can an artifact be taken back?

Yes. An administrator can revoke it from the console, the artifact leaves every list and every search, and the revocation is written to an audit log, whose export is available on Enterprise.

### What does it cost to try?

A single page can be published as a link with no account, encrypted and passcode-protected, and it expires five days after the last visit. A durable artifact is held under the organization's own keys, with no expiry.
