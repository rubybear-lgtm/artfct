---
slug: one-link-that-keeps-its-history
title: One link that keeps a version history
description: Publishing the same content twice resolves to one artifact, and a change creates a new version under the same link. How the version history and identity work.
date: 2026-10-06
tag: workflows
published: true
surface: feature-showcase
register: glean-formal
target: version history
---

A link to an artifact is only useful if it keeps working. Artfct gives an artifact one address for its whole life: publishing the same content again resolves to the artifact that already exists, and publishing a change extends the version history under the same link rather than a second page nobody can find.

## What happens when the same thing is published twice?

An artifact is identified by its content rather than by the moment it was published. Publishing something identical to an artifact that already exists returns that artifact instead of creating a second one.

The identity is derived from the artifact as a whole: the files, their sizes, their content types, their hashes, and which file opens first. A change to any of those produces a different artifact, which is what makes the next case possible.

The consequence is that a team does not accumulate three copies of a report because three people asked for it. Two links describing the same work resolve to the same page.

Nearly identical is not identical. A report regenerated with a new date in its header is a different artifact, because the bytes differ. The rule applies to the content as published rather than to the question that produced it.

## How does an update keep the same link?

A published artifact can be updated by naming it when the new version is published. The result is a new version of the same artifact rather than a new artifact, and the link people already hold keeps working.

This is the difference between a link a team can put in a document and a link that has to be replaced whenever the numbers change. A dashboard referenced in a project brief stays referenced.

Updating is deliberate rather than automatic. Nothing rewrites an artifact because a similar one arrived, and the publisher decides whether a change is a new version of the same work or a different piece of work entirely.

## What does an earlier version show?

The console keeps the version history of each permanent artifact, newest first. Every completed version can be opened on its own, and a past version can be restored, which publishes it as the current one again while leaving the record of what happened intact.

Each entry names the version number, when it was published, the member who published it while they are still on the team, and the title it carried. An entry that was itself a restore says which version it came from.

The history is a record of decisions rather than a list of files. A reader can see that a version was published, when, and by whom, and can open any earlier version on its own.

## What is recorded about how something was made?

Every permanent artifact carries a provenance record alongside its content, and the record is built when the artifact is published rather than reconstructed afterwards. Each field also carries its own source, so a value the connection could not observe is recorded as absent rather than left blank or guessed.

On the supported connection, the record holds the model when the caller supplied one, the session the publish came from, the tool that published the artifact, and the name and version of the client. A client reports its name and version at initialization, per the [Model Context Protocol](https://modelcontextprotocol.io/specification/2025-06-18/basic/lifecycle). Fields the connection could not observe are recorded as absent rather than guessed, and the record says which of the two each field is. The [documentation](https://artfct.dev/docs) covers the publish step that builds it.

Provenance covers what the connection can observe. Repository, branch and commit are recorded as absent on this path; those fields were populated by a command-line client that has since been retired, and the hosted server does not attempt to reconstruct them. A team that needs the commit a page came from cannot currently read it from the artifact. The gap is recorded rather than hidden, which is the purpose of tracking a source for every field.

## Does a new version change who can see it?

Sharing belongs to the artifact rather than to a version. Publishing a new version with the artifact's identifier does not change who can open it, and does not change whether the people it is shared with may publish a version themselves.

The separation is deliberate. A refresh to a dashboard should not be able to widen its audience, and a team that has restricted an artifact should not have to check the setting again after every update.

Who may open an artifact, and who may change that, is covered in [who sees what your AI makes](https://artfct.dev/blog/who-sees-what-your-ai-makes). Finding an artifact without its link is covered in [finding the report your team already wrote](https://artfct.dev/blog/finding-what-your-ai-already-made).

## What this does not do

- The version history shows what changed hands. It does not show a line-by-line difference between two versions.
- Restoring publishes an earlier version as the current one. It does not delete the versions published in between.
- Repository, branch and commit are recorded as absent on the supported connection, and provenance is not reconstructed later from the content.
- Publishing different content without naming the artifact produces a separate artifact rather than a new version.

## Questions

### Does publishing the same report twice create two artifacts?

No. Content that is identical resolves to the artifact that already exists, so a second copy is not stored and both links open the same page.

### Does updating an artifact change its link?

No. The link belongs to the artifact and the version changes underneath it, which is what makes the link safe to put in a document.

### Can a past version be brought back?

Yes. A version can be restored, which publishes it as the current one again. The record still shows every version that came before it, including the one that was restored.

### What is recorded when an artifact was not made inside a repository?

The record shows what the connection could observe. Fields it could not observe, including the repository and the commit, are recorded as absent rather than inferred.

### Does a new version change who can open the artifact?

No. Sharing is a property of the artifact, so publishing a version never widens or narrows the audience on its own.
