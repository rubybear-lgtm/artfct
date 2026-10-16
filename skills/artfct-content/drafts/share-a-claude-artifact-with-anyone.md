---
slug: share-a-claude-artifact-with-anyone
title: How to share a Claude artifact with anyone
description: To share a Claude artifact with someone who does not use Claude, send a link that opens in any browser. It needs no account, and can be encrypted.
date: 2026-09-25
tag: workflows
published: false
surface: feature-showcase
register: glean-formal
target: share a Claude artifact
---

To share a Claude artifact with someone outside the tool that made it, one object decides whether it works: the link. Everything else stays behind — the conversation, the account it belonged to, the tool that rendered it. Artfct publishes the finished page to a URL that opens in any browser, so the person on the other end needs nothing but the link.

The work is finished inside one AI tool. The person who needs it uses a different one, or none at all. Nothing carries the finished page between them, which is what a link is for.

## What actually travels when the link is sent?

What travels is a self-contained HTML document. The artifact is published to artfct, given an id, and served at one address. The conversation, the model and the application that produced it stay where they were. Nothing about the recipient's own setup enters into it.

That is a page rather than a file to open somewhere else. [Anthropic's documentation](https://docs.claude.com/en/docs/claude-code/artifacts) describes an artifact as a live, interactive web page. The recipient sees what a reader would see. The layout and the tables the artifact already carried render on the screen in front of them. They do not have to download a document, install an application, or work out which version of it they were sent.

The distinction matters as soon as the work leaves the team, whether the recipient is a client, a vendor or a colleague in another department. They do not need the tool, an account in it, or the transcript around it. They need a browser and the link. The [docs](https://artfct.dev/docs) describe the publish step and what a published page carries.

## What does the recipient need before they can open it?

A free link asks for nothing. The content is encrypted, protected by a passcode that travels inside the link itself, and capped at one megabyte for a single HTML file. The person opening it needs no account, and nobody has to send them a passcode separately.

A team artifact behaves differently. A signed-out visitor is sent to sign in and then returned to the page. The link works for the team's members and stops there. A permanent artifact is held under the organization's keys, which is the tier for a page that should still open next month.

## How long does the link keep working?

A free link expires five days after it was last opened. The window slides with use instead of counting down from the day of publishing. A link that someone keeps returning to stays alive, and an idle one lapses. That matches what a free link is for: handing something over now, rather than parking a record.

A permanent artifact does not expire. It stays in the workspace under the organization's keys, and the same link resolves weeks later when the page is needed again.

## What does the same address show to different people?

Every artifact is opened through one viewer route, `/a/{id}`, whatever its sharing level. Sharing is private, team or public, and the address does not change with it. What changes is who gets through.

A public artifact opens for anyone holding the link, provided the team has public links switched on. A team artifact asks a signed-out visitor to sign in and back. A private artifact belongs to its owner and to the team's administrators. The route never confirms whether an id exists. A link forwarded to someone who is not signed in returns a sign-in prompt rather than a description of what they are missing.

Access belongs to the artifact rather than to the link. Moving an artifact between private, team and public changes who gets through at the same address, and the URL itself does not change. [Who sees what your AI makes](https://artfct.dev/blog/who-sees-what-your-ai-makes) covers those controls in more detail.

The viewer carries a comment thread alongside the page, and a signed-in member of the team can post a comment on it or resolve one. Comments are plain text of up to 4,000 characters, and a reply joins the thread rather than starting a new one.

## Does the recipient need the tool the artifact was made in?

No. The published page is HTML, and a browser is the whole requirement. The recipient does not need an account in the chat tool, the same model, or the conversation the work came out of. No lab will index a competitor's output, so the link carries it out to a different tool.

The reverse holds as well. Once the artifact is published, the link serves the stored copy rather than the live session that produced it. The handover does not depend on anyone leaving a window open.

It also grants nothing beyond the page. A link is addressed to one artifact, so the link carries nothing beyond that one page.

## What should be checked before the link goes out?

Three points cover most handovers. The first is where the recipient sits. An insider opens a team artifact after signing in. An outsider is better served by a free link or, where the team allows one, a public artifact. The second is whether the work needs to outlast the five-day window, which puts it on the permanent tier. The third is whether the recipient also needs to publish the next version, because edit access is set separately from visibility.

Nothing reaches the library until someone publishes it, and the link exists only after that. Publishing is also where a team decides what is worth keeping, which is why the default level is the team rather than the world.

The choice is easiest to make from the recipient's side. A reviewer who needs to see the work this afternoon is served by a free link that asks nothing of them. A client who will come back to the page after the project closes is better served by a permanent artifact. That link does not expire.

If the object being shared is new ground, [What is an AI artifact?](https://artfct.dev/blog/what-is-an-ai-artifact) covers what counts as one and what does not.

## What this does not do

- It does not move a conversation or a prompt. What travels is the finished artifact, not the reasoning that produced it.
- Encryption is tiered. A free link is encrypted and passcode-protected; a permanent artifact is held under the organization's keys.
- A free single-file link is capped at one megabyte and lapses five days after it was last opened.
- Sharing is private, team or public. There is no per-person sharing and no list of named recipients.
- Public links are a team setting, and an admin can switch them off. A publish that asks for public is then refused, with a message that the team has turned off public links.
- A signed-out visitor is sent to sign in, and the viewer route never confirms whether an id exists.
- It does not edit an artifact's own content. A signed-in member of the team can comment on the page; changing what the page says means someone publishes a new version, and the same link keeps working.

## Questions

### Does the person I send it to need an account?

Not for a free link. It opens with a passcode and no sign-in. A teammate opening a team artifact is asked to sign in, and a permanent artifact is reached under the organization's access.

### What happens when a free link expires?

It stops opening. The expiry runs five days from the last time the link was used, so the window slides rather than running out from the publish date. A permanent artifact carries no expiry.

### Is the free link encrypted?

Yes. The content is encrypted and protected by a passcode carried in the link, so nothing has to be sent alongside it.

### Can the link be forwarded, or sent to just one person?

A free link opens for anyone who holds the link, so forwarding works and the passcode travels with it. A public artifact opens the same way, provided the team has public links switched on. A team artifact asks the next person to sign in and opens only for a member of the team. Sharing is private, team or public, and there is no list of named recipients; the narrowest option is a private artifact, which its owner and the team's administrators can open.

### Can the recipient change the artifact?

Only with edit access, which is granted separately from visibility. Otherwise they read the published page, and a member of the team who is signed in can comment on it; a new version comes from someone who can publish.
