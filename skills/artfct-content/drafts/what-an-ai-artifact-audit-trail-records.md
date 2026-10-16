---
slug: what-an-ai-artifact-audit-trail-records
title: What should an AI artifact audit trail record?
description: An audit trail records who made an artifact, who opened it, when its sharing changed and access was withdrawn. What Artfct's log holds, and what it does not.
date: 2026-09-21
tag: governance
published: false
surface: explainer
register: glean-formal
target: audit trail
---

An AI audit trail is a record of four events: who produced an artifact, who opened it, when its sharing changed, and when access was withdrawn. Those four are the entries a reviewer asks for first, because each answers a question a different function owns. A trail is only as strong as its weakest entry, and only as useful as it is exportable.

## What is an AI audit trail for?

An organization running AI tools now produces work that nobody watched being made. A report appears, a table is cited in a planning meeting, and the person who asked for it moved on weeks ago. An audit trail exists for the reviewer who arrives after all of that.

The instinct is to treat logging as a checkbox, and that instinct is what makes a trail useless. A record that says something happened, without naming the object or the person or the time, answers none of the questions an investigation actually asks. The test is whether a reader with no context can reconstruct an event from the entries alone: an actor, an artifact, an action and a time.

That test is why export matters as much as capture. A record that can only be read inside the product is a report about the product. A record that leaves as a file is evidence a compliance, security or legal function can work with, which is the form an AI governance review tends to ask for.

## Who produced the artifact, and does the entry say how?

Creation is the first entry, and the actor it names is the signed-in account whose connection published the artifact. Connections are per person: one member's connection does not authorize another, and each connected tool asks that person to sign in. The creating account is therefore the person who signed in, which is the answer a reviewer wants and the one the record can support with evidence.

What the entry does not establish is how the artifact was made. On the supported connection the model is recorded as the caller reports it rather than as something observed, and the repository, branch, commit and working-tree state are recorded as absent. The console's tool column reads an unknown tool for artifacts published that way, because the hosted connection reports the same client name for every caller.

A separate per-artifact provenance record attributes each field to its source, and it inherits the same boundary. An IT or security reader should treat which AI tool produced a page as an open question rather than a settled one.

## Who opened it, and when?

Viewing is the entry read most often and audited least carefully. The log records the open, and the count of opens does a second job: how often an artifact has been opened is one of the signals that ranks search results, as [finding what your AI already made](https://artfct.dev/blog/finding-what-your-ai-already-made) sets out, so a page the team keeps returning to outranks a newer one nobody reads. That ordering holds only where indexing is switched on, which is a deployment setting and off by default. The record of attention and the ordering of results share one source.

Time is what makes a view entry meaningful. A single open says that somebody looked. Opens spread across a quarter, from different accounts, say that the artifact became part of how a decision was made, which is a different claim and the more useful one to put in front of a reviewer.

One boundary belongs here. Where access does not require an account, the entry records an open attributed to a daily-salted pseudonymous key rather than to a person, because there is no account to attribute it to. A page reachable by anyone holding the link is that case, so a report of who read a page is complete for team access and partial for public access.

## When did sharing change, and who changed it?

Sharing is the entry that usually decides whether an artifact mattered, because it marks the moment a page became reachable to more people than its author. Artfct records the change. An artifact is published visible to the team or private to one account, and it can be moved to public — for anyone holding the link — unless the team has turned public links off; each move is an event with an actor and a time.

Edit access is a separate axis from visibility, so a teammate can be given the ability to publish the next version of a page without a wider grant. A reviewer can therefore read a sharing entry as a change of reach rather than a change of authorship, and the two are worth keeping apart in a report. The same distinction runs through [Artfct against your AI tool's own sharing](https://artfct.dev/blog/artfct-vs-your-ai-tools-own-sharing).

What the entry cannot do is name a set of people. Access is a level — private, team or public — together with the owner and the team's administrators. There is no per-person grant, so the log answers when a page became team-visible, and answers which colleague gained access only by implication. The access model is set out in [who sees what your AI makes](https://artfct.dev/blog/who-sees-what-your-ai-makes).

## When was access withdrawn?

Withdrawal is the entry a security function asks about first, and it arrives through more than one door. An administrator can revoke a single artifact from the console, revoke a token that an integration uses to reach the workspace, or revoke a connected AI tool from the list the console shows. Each of those is an access decision about a different object.

People are the fourth door. Directory provisioning and de-provisioning arrive as signed webhooks, so removing somebody from the directory removes their access rather than only their convenience. Invitations, roles and ownership transfer sit alongside that. For an organization on the enterprise tier, single sign-on is the gate: the domain is verified by a DNS TXT record before it is enforced, and the configuration carries a tested path back down.

Revocation ends reachability through the workspace, not possession of a copy. The console export produces files byte-identical to what was published, and a file that has left the workspace is governed by whatever policy applies where it went.

## What does the trail look like for one artifact?

Take one quarterly planning report and follow it through the four entries. The analyst publishes it from a signed-in connection, and the creation entry names that account, the artifact and the time. The model field carries whatever that connection reported, and the repository, branch and commit fields arrive empty.

The viewing entries follow. A colleague who opens the page inside the team is named by account, and the count of opens feeds the ranking described above, where indexing is switched on. An open through a link that needs no account is the exception, and it carries a daily-salted pseudonymous key rather than a name.

The sharing entry marks the change of reach. When the analyst moves the page from private to team, the entry records the actor, the new level and the time. A later move to public depends on the team's own switch, and the revocation entry closes the sequence — whether it comes from an administrator in the console or from the directory when the analyst leaves the organization.

Four entries, one object, in order. Read together they answer what was made, who saw it, when reach changed and when it ended.

## What can an administrator export, and in what form?

Two exports exist and they answer different questions. The audit log leaves as its own export on Enterprise, which is what a reviewer reads for events. The console can also list, filter and search everything the organization owns and export it as a single archive: metadata alongside the files themselves, byte-identical to what was published, each verified against its hash.

The hash is the part that makes the archive usable as evidence rather than as a backup. A reviewer can show that the file in hand is the file that was published, without trusting a copy step in between. That is a stronger statement than a screenshot and a simpler one to defend. Detecting a changed file from its digest is what the [Secure Hash Standard](https://csrc.nist.gov/pubs/fips/180-4/upd1/final) specifies.

Retention policy, legal hold and release of hold sit in the same console on Enterprise; when a team leaves retention unset, the period is 90 days, and a hold is a decision somebody makes deliberately rather than one a team inherits by default. The export itself is a file an administrator runs and takes away; moving it into another system is a step the organization performs.

## Which entries are not in the log?

Deletion is the gap worth stating before a reviewer finds it. Removing an artifact through the connection records telemetry rather than an audit entry, and it is not one of the actions the log's export carries on Enterprise. The deletion entries that do exist come from governance flows such as a legal hold or an erasure, not from an ordinary removal. A question about when something was deleted cannot be answered from the trail alone.

Comments are the second gap, and they arrive from a surface that looks like exactly the kind of event a log should carry. A reader with access can comment on an artifact, and a connected agent can post one: plain text, up to 4,000 characters, threaded as a reply and resolvable. None of that produces an audit entry. There is no comment event type, and the tool that posts one records telemetry only, so the trail shows that a page was shared and says nothing about who weighed in on it.

Comparison is the third gap. Earlier versions of a page can be listed, opened and restored, and two versions cannot be placed side by side. Nothing in the record describes what changed between them, so a reviewer who needs the difference has to hold both pages and read them.

Each of these gaps is a property of the record rather than a failure of a workflow, and a review that assumes otherwise reaches a wrong conclusion at the worst moment. Stating them here is cheaper than discovering them under time pressure.

## What does a team still have to supply itself?

The record is raw material, and the review is the organization's own work. Four things stay outside it. The first is policy: how long each class of artifact is kept, which ones are frozen, who may publish, and who approves a change of reach. The log reports the events a policy governs, and it does not state the policy.

The second is classification. An entry names an actor, an artifact, an action and a time. It does not say whether the artifact held regulated data, so the sensitivity that decides how an incident is handled comes from wherever the organization keeps that judgement.

The third is adjudication. Whether an action was appropriate, approved or consistent with a control is a conclusion somebody draws, and the approvals, tickets and justifications that support it live in the systems that produced them. The fourth is identity: an entry names an account, and tying that account to a person, a role and a departure date comes from the directory the workspace is connected to.

A trail that names these four boundaries is more useful than one that implies they do not exist. An IT or security team building an AI governance program has usually met both kinds, and in most cases only the first survives a review. Where finished AI work lands in the first place is covered in [where AI-generated documents go](https://artfct.dev/blog/where-do-ai-generated-documents-go).

## What this does not do

- **Deletion is not an audit entry.** Removing an artifact through the connection records telemetry rather than an audit entry, and it is not among the actions the log's export carries on Enterprise. Deletion entries come from governance flows such as a legal hold or an erasure.
- **Comments are not audited.** A reader with access can comment on an artifact, and a connected agent can post one, which makes commenting a write against the work. It produces no audit entry: there is no comment event type, and the tool records telemetry only. The log reports that a page was shared rather than what colleagues wrote on it.
- **There is no version comparison.** Earlier versions can be listed, opened and restored, and two versions cannot be placed side by side, so the record shows that a change happened rather than what changed.
- **No repository, branch or commit.** On the supported connection those fields are recorded as absent, which also leaves the repository filter in search with nothing to match.
- **The AI tool is not identified with confidence.** The model is recorded as the caller reports it, and the console's tool column shows an unknown tool for artifacts published through the hosted connection.
- **No per-person sharing.** Access is private, team or public, together with the owner and the team's administrators, so a sharing entry records a level rather than a list of names.
- **Public access is not attributable.** A page reachable by anyone holding the link can be opened without an account, so an open of it is recorded against a daily-salted pseudonymous key rather than a named reader. An administrator can also switch public links off for the whole team, in which case a publish that asks for public is refused.
- **Retention and hold are Enterprise-tier settings an administrator sets.** A legal hold and its release are recorded as events; a change to the retention policy itself is not audited, so the log shows hold decisions rather than who set the period. Where a team leaves retention unset, the period is 90 days.
- **The free tier is not the durable record.** A page published there is passcode-protected, needs no account and carries a five-day sliding expiry, while a permanent artifact is durable and carries no expiry.
- **Search is switched on at the deployment level and sits off by default.** With it off, a search reports that it is not enabled yet, and the view counts described above order nothing.
- **Exports are files, not a feed.** The log, on Enterprise, and the archive leave as downloads an administrator runs, and loading either into a separate system is a step the organization takes.

## Questions

### What does an AI audit trail record?

Creation, viewing, sharing and revocation, and the log is exportable on Enterprise. Deletion is not among those entries: removing an artifact through the connection records telemetry, and the deletion entries that exist come from governance flows.

### Does the log name the person who published an artifact?

It names the signed-in account whose connection published it. Connections are per person, so that account is the person who signed in, and one member's connection does not authorize another.

### Is search on by default?

No. Indexing is off by default and switched on at the deployment level rather than per workspace. With it off, a search reports back that indexing is not enabled yet, so the open counts that order results rank nothing.

### Does the record say which AI tool made an artifact?

Not on the supported connection. The model is recorded as the caller reports it and the tool is not distinguished, so the console shows an unknown tool for artifacts published that way.

### How is access removed when somebody leaves?

Through the directory. Provisioning and de-provisioning arrive as signed webhooks, so removing somebody from the directory removes their access, and roles can be changed in the console as well.

### What happens to retention when an investigation starts?

Custom retention and legal hold are Enterprise-tier settings, so keeping a page and freezing it are deliberate decisions rather than defaults a team inherits. Where a team leaves retention unset, the period is 90 days, and releasing a hold returns the page to the policy in force.

### Is the audit log enough on its own for a review?

No. It reports events. The policy, the classification of what was stored, the approvals behind a decision and the mapping from account to person come from the organization's own systems.

### Can a page be shared with one named colleague?

No. Access is private, team or public, with the owner and the team's administrators. Edit rights can be granted narrowly, so a colleague may publish the next version of a page others can read.
