---
slug: where-pstack-artifacts-live
title: pstack verification skills: where the proof lives
description: pstack writes decision logs, verification skills and evidence to local paths, mostly outside git. Where each lives, and how a teammate's agent finds it.
date: 2026-11-27
tag: workflows
published: false
surface: explainer
register: glean-formal
target: pstack verification skill
---

A pstack verification skill is a project skill that tells any agent how to launch, drive and check an application, together with a feature map of what the application does. It is one of three things a pstack run leaves behind, alongside a decision log and a folder of evidence, and most of that trail is written to local paths that a teammate on another agent will never see unless someone shares it on purpose.

This guide maps where each piece lives in Cursor and in Claude Code, what the official docs say about committing it, and how to get the trail in front of the rest of a team.

## Why does pstack produce so much proof?

Because the proof is the point. In [All Code Is Slop (Except Mine)](https://www.youtube.com/watch?v=O_zcc6Xl12g), Lauren Tan argues that once agents write most of the code, human review moves away from reading every line and toward looking at what the agent can show: screenshots, recordings, diagrams of state, and in some cases formal proofs. [pstack](https://github.com/cursor/plugins/tree/main/pstack) is the working version of that idea. Its playbooks reproduce before they fix, measure before they claim, and keep a written account of every decision.

The talk explains why those artifacts matter. It does not say where the files should live. The official pstack docs do, and that is what the rest of this page covers.

## What does a pstack run leave behind?

The official docs name three kinds of artifact:

- **The decision log.** `/show-me-your-work` keeps a running table of what was decided, why, and what evidence supports it.
- **The verification skill and its feature map.** `/create-verification-skill` writes a skill that tells any agent how to work with the application, plus a map of what the application does and what result proves each feature works.
- **The evidence.** Screenshots, recordings and traces, saved wherever the verification skill says they go.

Each has its own default location and its own answer to "should this be committed?" The sections below take them one at a time.

## Where does the decision log live?

`/show-me-your-work` writes a tab-separated file with one row per decision. Its columns are `ts`, `phase`, `decision`, `why`, `evidence` and `result`. The evidence cell is always a pointer, such as a commit, a pull request number, a `file:line` reference or a path to a screenshot or trace, never a paragraph.

The skill is specific about location. The log lives at `decisions.tsv` in the work directory, or at `.audit/<task-slug>.tsv` when several efforts run at once. By default it is a working file and stays out of git. The skill says to commit it only when the work is ambitious enough that a reviewer needs the trail to trust the result.

For a teammate, the consequence is simple. If the log was not committed, they do not have it, even when they work in the same repository.

## What is a pstack verification skill, and where does it go?

The official guide page *Verify and ship* and the `/create-verification-skill` skill describe the second artifact. The generator writes a folder named `verify-<app>` inside the project's skills directory. Inside it are instructions with Launch, Doctor, Drive, Evidence and Cleanup sections, a Helpers section for any script it ships, and a feature map under `features/` that lists what the application does and what result proves each feature works.

The official guide is clear that this one should be shared. It describes the verification skill as infrastructure, not a one-off, and says to commit it so every person and every agent on the team drives the application the same way.

The Cleanup section removes running instances and scratch state, but it never removes evidence. Proof has to survive the teardown, in the location the skill names.

Two related skills write artifacts of their own:

- **`/create-formal-verification-skill`** writes another project skill that proves a property tests rarely reach, and records which code each check covers.
- **`/statechart`** draws a diagram of what the user sees, generated from a formal model, so a reviewer can compare the intended states with a recording of the real interface.

## Where do screenshots, recordings and traces go?

Wherever the generated verification skill names. That location is chosen per project, which is both the strength and the risk. A team that points it at a folder in the repository, or at an agreed shared location, gets evidence anyone can open. A team that leaves it in a temporary folder gets evidence that exists on one laptop.

The decision log refers to evidence by path. When the path points somewhere only one machine can reach, the log still reads correctly, and the pointer leads nowhere for everyone else.

## Do the paths change in Claude Code?

Skill paths do. Official pstack also runs in Claude Code, and its [Claude Code adapter](https://github.com/cursor/plugins/blob/main/pstack/docs/harness/claude-code.md) maps Cursor's folders to Claude Code's. The full picture:

| Artifact | In Cursor | In Claude Code | Shared by default? |
| --- | --- | --- | --- |
| Decision log | `decisions.tsv` or `.audit/<task-slug>.tsv` in the work directory | Same | No, kept out of git |
| Verification skill and feature map | `.cursor/skills/verify-<app>/` | `.claude/skills/verify-<app>/` | Only if committed |
| Evidence | Where the verification skill names | Same | Only if that location is shared |
| Transcripts used by `/recall` | `~/.cursor/projects/<slug>/agent-transcripts/` | `~/.claude/projects/<slug>/` | No, local to one machine |

If the team is not on Cursor at all, [using pstack without Cursor](https://artfct.dev/blog/pstack-without-cursor) covers the official Claude Code path and the community ports for Codex, OpenCode, Copilot and OMP.

## Why can a teammate on another agent not see any of it?

None of this is hidden. It is spread across places that only some tools look at.

Read the table from a teammate's seat. A decision log is usually kept out of git. A verification skill in `.cursor/skills/` is invisible to a tool that only reads `.claude/skills/`, and the reverse is also true. Evidence is a path, sometimes to a file on one laptop. A teammate on Codex, OpenCode or Copilot will not read Cursor's transcript folders at all.

Here is how that plays out. One engineer runs a bug fix in Cursor. The agent reproduces the bug, verifies the fix with a recording, and logs its reasoning. The next day a second engineer asks Claude Code about a related bug. Their agent cannot read the first agent's log, does not look in `.cursor/skills/`, and has no way to reach the recording. It starts the investigation again from nothing, and may reach a slightly different conclusion. Nobody did anything wrong. The proof was simply in places the second agent does not look.

## How can a team share the trail with plain git?

The official docs already point most of the way:

1. **Commit the verification skill.** The official guide says so, and it is the artifact every agent needs to drive the application the same way. On a team that uses both Cursor and Claude Code, decide which skills folder is canonical, or keep both in step.
2. **Commit the decision log when a reviewer needs it.** GitHub renders a committed TSV file as a table, which makes the trail easy to read inside a pull request.
3. **Put evidence somewhere shareable.** Point the verification skill at a folder in the repository or an agreed team location, not a temporary folder.
4. **Do not assume another tool reads your folders.** A different agent will not look in `.cursor/skills/` or in Cursor's transcript folders unless it is told to.

That covers a lot. It still leaves the trail in a repository, readable by people who know where to look, and only after someone remembers to commit it.

## Is there a way to share it without remembering to commit?

That is the gap [artfct-pstack](https://artfct.dev/blog/artfct-pstack) fills. It is a plugin that carries pstack's skills and adds `/publish-evidence`, which collects the pieces this guide has mapped and publishes them as one bundle to the team's Artfct library:

- **It finds the trail.** Decision logs at `decisions.tsv` or `.audit/<task-slug>.tsv`, feature maps in `verify-*` folders under `.cursor/skills/`, `.claude/skills/` and `.omp/skills/`, and the evidence files named in the log's evidence column.
- **It strips secrets first.** A filename denylist and a scan for token patterns run before anything leaves the machine, and `AGENTS.md` and `CLAUDE.md` are never published.
- **It publishes once and updates in place.** The bundle is titled `[pstack] <task-slug> evidence`, shared with the team, never public unless someone asks, and re-runs add a new version at the same link.

Verification and show-me-your-work runs publish at the end on their own while Artfct is connected. A teammate in any connected tool can then search the library and open the bundle, instead of asking which laptop holds the screenshot. The add-on is independent, not official pstack, and it is not endorsed by Cursor or Lauren Tan. The launch post has the setup for each tool.

## Questions

### Where is the pstack decision log, and is it in git by default?

It is at `decisions.tsv` in the work directory, or `.audit/<task-slug>.tsv` when several efforts run at once. It is not in git by default. The skill says to commit it only when a reviewer needs the trail to trust the result.

### Where does /create-verification-skill write files, and should the skill be committed?

It writes `.cursor/skills/verify-<app>/` in Cursor or `.claude/skills/verify-<app>/` in Claude Code, with a feature map under `features/`. Yes, the official guide says to commit it so every person and every agent drives the application the same way.

### Where do screenshots, recordings and traces go?

To the location the generated verification skill names. Cleanup leaves them in place. That location needs to be shared if a teammate is going to see them.

### Can a teammate on Codex, OpenCode or Copilot see my pstack evidence automatically?

No. Not unless the files are shared on purpose, by committing them, putting them in a shared location or publishing them somewhere the teammate's tool can read.

### Did Lauren Tan's talk say where these files should be stored?

No. The talk explains why verification artifacts matter. The official pstack docs specify the paths.
