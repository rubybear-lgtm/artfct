---
slug: who-sees-what-your-ai-makes
title: Who sees what your AI makes, and when
description: An artifact is shared with the team unless someone says otherwise, and the level can change at any time. Who sees what your AI makes, and how that changes.
date: 2026-10-07
tag: governance
published: true
surface: feature-showcase
register: glean-formal
target: who sees what your AI makes
---

Who sees what your AI makes is settled by a default rather than a decision: an artifact arrives visible to the whole team unless someone says otherwise, and the level can be changed at any time afterwards. Artfct records the choice, records who changed it, and serves each permanent artifact from its own origin so one page cannot read another.

## What happens when an artifact is published?

An artifact arrives with a sharing level already set. Three levels exist, and a publisher can name one or leave the default in place.

Team is the default. Every member of the workspace can open the artifact, and where indexing is enabled, every connected AI tool can find it through search. Private restricts it to the owner and to team administrators. Public means anyone holding the link can open it, with no account required.

A team can also turn public links off entirely. An organization with a standing rule against open links sets that once, and a publish that asks for public is refused rather than quietly downgraded to something else. The [documentation](https://artfct.dev/docs) lists what a publish can ask for.

## Who can change who sees it?

The owner of an artifact, or a team administrator, can change its sharing level after publication. Nobody else can. That keeps the person who made the work in control of it, while giving an administrator an override for the cases that need one.

The change applies to links that already exist. A link minted while an artifact was visible to the team stops opening it as soon as the owner makes the artifact private. Access is re-checked on every request rather than trusted because a link was issued once, and the credential behind it is scoped to a single artifact.

Sharing can be changed as many times as a team needs, and every change is recorded.

## Can a teammate publish the next version?

Sharing and editing are separate decisions. An artifact can be visible to the whole team while only its owner may publish a new version, or visible to the team with editing open so that a colleague can update it without the link changing.

The distinction matters for the artifacts a team maintains rather than reads once. A dashboard with a standing link is a small commitment, and someone other than its author eventually needs to refresh it.

Updates and the history behind them are described in [one link that keeps its history](https://artfct.dev/blog/one-link-that-keeps-its-history).

## What can an administrator see, and undo?

The console lists every artifact a team's tools have produced, and can be filtered by repository, by AI tool, and by title or description. An administrator can open any artifact or revoke it. Deleting is a separate action, available to a connection that holds the delete permission. Finding one specific artifact in that list is the same problem described in [finding the report your team already wrote](https://artfct.dev/blog/finding-what-your-ai-already-made).

Creating, viewing, sharing and revoking are all recorded in an audit log, and its export is available on Enterprise. A retention policy and a legal hold are Enterprise-tier features, and a hold refuses deletion for as long as it stands. Retention and export are covered in the [console documentation](https://artfct.dev/docs).

An administrator can also take the whole record out of the product. Exporting a team's artifacts produces an archive containing the metadata and the files themselves, byte for byte, with every file checked against its recorded hash before it is written.

## What stops one artifact from reading another?

An artifact may contain JavaScript, and a page an agent produced is not something a team should have to take on trust. Artfct serves each permanent artifact from its own hostname rather than a shared one, and requests to that hostname carry no session cookie at all.

For a team or private artifact, access instead requires a short-lived signed token scoped to that one artifact. A token minted for one artifact does not verify for another, and an expired token is refused. The content security policy served alongside an artifact is derived from what that artifact declared, so a page naming no external origin is served with a policy that permits none. The [Content Security Policy](https://www.w3.org/TR/CSP3/) specification defines how a document declares those origins.

The practical effect is that one artifact cannot read another, even when both belong to the same team.

## How does a free link differ from a permanent one?

A page shared as a free link is encrypted, protected by a passcode, and expires after five days of inactivity. It needs no account to open, which makes it useful for sending a single page to someone outside the team.

Permanent artifacts work differently. They are held under the organization's keys, they do not expire, and they carry a record of how the artifact was published: the model when the caller supplied one, the tool that published it, and the session it came from.

The [free tier](https://artfct.dev/free) is a way to try the sharing half of the product without connecting a tool. The permanent tier is what a team keeps. Setup for every supported tool is listed in the [full reference](https://artfct.dev/llms-full.txt).

## What this does not do

- Sharing is one of three levels. It is not a per-person permission list on a single artifact: a private artifact is visible to its owner and to team administrators, and to nobody else.
- Public links can be turned off for an entire team. Where that setting is on, no artifact in the workspace can be published publicly.
- Deletion is permanent. A legal hold prevents an artifact from being deleted rather than delaying the deletion, and it is an Enterprise-tier setting.
- The free tier expires after five days of inactivity and holds one file of up to one megabyte.

## Questions

### Is an artifact visible to the team as soon as it is published?

Yes, unless the publisher names another level. Team is the default; private and public can be chosen instead, and the choice can be changed later.

### Can someone outside the team open an artifact?

Only if it has been published as public, or sent as a free link. Neither requires an account to open, and both can be withdrawn.

### What happens to a shared link when an artifact is made private?

It stops opening the artifact. Access is re-checked on every request, and the credential is scoped to that one artifact.

### Can an administrator remove something a colleague published?

An administrator can revoke any artifact in the team, and the revocation is written to the audit log. Deleting is a separate action, available to a connection that holds the delete permission, and it is refused while a legal hold, an Enterprise-tier setting, is in place.

### Does an AI tool see artifacts that are private?

Only the owner's own, and an administrator's. A search is scoped to the caller, so another member's agent sees only what has been shared with the team or published publicly.
