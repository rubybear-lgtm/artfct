---
slug: where-pstack-artifacts-live
title: pstack verification skills: where the proof lives
description: pstack writes decision logs, verification skills and evidence to local paths, mostly outside git. Where each lives, and how a teammate's agent finds it.
date: 2026-11-27
tag: workflows
published: false
surface: explainer
register: artfct-human
target: pstack verification skill
---

Run a pstack verification and you end up with three things: a decision log, a verification skill with a feature map, and a pile of evidence. All three are good. Most of them are on your machine, in folders a teammate's agent will never open unless somebody shares them on purpose.

This is a map of where each one goes in Cursor and Claude Code, what the official docs say about committing it, and how to get it in front of the rest of your team.

## Why pstack makes so much proof

Because proof is the whole idea. [pstack](https://github.com/cursor/plugins/tree/main/pstack) is Lauren Tan's skill pack for agents that have to show their work: its playbooks reproduce before they fix, measure before they claim, and write down every decision. (Lauren's AI Engineer talk, [All Code Is Slop (Except Mine)](https://www.youtube.com/watch?v=O_zcc6Xl12g), is the best explanation of why.)

What pstack doesn't try to answer is where all that proof should live for a team. The docs answer where it lands by default, which is what the rest of this post covers.

## The decision log

`/show-me-your-work` writes a tab-separated file, one row per decision, with the columns `ts`, `phase`, `decision`, `why`, `evidence` and `result`. The evidence cell is always a pointer: a commit, a pull request number, a `file:line`, or a path to a screenshot or trace. Never a paragraph.

It lives at `decisions.tsv` in the work directory, or `.audit/<task-slug>.tsv` when several efforts run at once. By default it's a working file and stays out of git. The skill says to commit it only when the work is big enough that a reviewer needs the trail to trust the result.

So if nobody committed it, your teammate doesn't have it. Same repo or not.

## The verification skill and its feature map

`/create-verification-skill` writes a folder called `verify-<app>` into your project's skills directory. Inside: instructions for any agent, with Launch, Doctor, Drive, Evidence and Cleanup sections, a Helpers section for any scripts it ships, and a feature map under `features/` listing what the app does and what result proves each feature works.

The official *Verify and ship* guide is clear that this one should be shared. It calls the verification skill infrastructure, not a one-off, and says to commit it so every person and every agent drives the app the same way. I agree. I added one to Artfct's repo this week, and it went straight into git.

Cleanup tears down running instances and scratch state but never touches evidence. The proof has to survive in the location the skill names.

Two related skills write their own artifacts. `/create-formal-verification-skill` writes another project skill that proves a property tests rarely reach and records which code each check covers. `/statechart` draws a diagram of what the user sees, generated from a formal model, so a reviewer can line the intended states up against a recording of the real interface.

## The evidence

Screenshots, recordings and traces go wherever the generated verification skill says. That's chosen per project, which is both the nice part and the trap. Point it at a folder in the repo, or an agreed shared location, and anyone can open the evidence. Leave it in a temp folder and it exists on one laptop.

The decision log refers to evidence by path. When the path only resolves on one machine, the log still reads fine, and every pointer in it leads nowhere for everyone else.

## Cursor vs Claude Code paths

Skill paths change; the rest doesn't. The [Claude Code adapter](https://github.com/cursor/plugins/blob/main/pstack/docs/harness/claude-code.md) maps Cursor's folders to Claude Code's:

| What | Cursor | Claude Code | Shared by default? |
| --- | --- | --- | --- |
| Decision log | `decisions.tsv` or `.audit/<task-slug>.tsv` in the work directory | Same | No, kept out of git |
| Verification skill and feature map | `.cursor/skills/verify-<app>/` | `.claude/skills/verify-<app>/` | Only if committed |
| Evidence | Wherever the verification skill says | Same | Only if that location is shared |
| Transcripts used by `/recall` | `~/.cursor/projects/<slug>/agent-transcripts/` | `~/.claude/projects/<slug>/` | No, local to one machine |

If you're not on Cursor at all, I covered the official Claude Code path and the community ports for Codex, OpenCode, Copilot and OMP in [using pstack without Cursor](https://artfct.dev/blog/pstack-without-cursor).

## Why your teammate can't see any of it

None of this is hidden. It's just spread across places only some tools look.

Read that table from a teammate's chair. The log is usually out of git. A verification skill in `.cursor/skills/` is invisible to a tool that only reads `.claude/skills/`, and the other way round. Evidence is a path, sometimes to a file on one laptop. And nobody on Codex, OpenCode or Copilot is reading Cursor's transcript folders.

Here's how it plays out. One engineer runs a bug fix in Cursor; the agent reproduces it, verifies the fix with a recording and logs its reasoning. Next day a second engineer asks Claude Code about a related bug. Their agent can't read the log, doesn't look in `.cursor/skills/`, can't reach the recording, and starts the investigation over. Maybe it lands on a slightly different answer. Nobody did anything wrong. The proof was just somewhere the second agent doesn't look.

## Sharing it with plain git

The official docs get you most of the way:

1. **Commit the verification skill.** The docs say so, and it's the piece every agent needs. If your team uses both Cursor and Claude Code, pick which skills folder is canonical, or keep both in step.
2. **Commit the decision log when a reviewer needs it.** GitHub renders a committed TSV as a table, so the trail reads fine inside a pull request.
3. **Point evidence somewhere shared.** A folder in the repo or an agreed team location, never a temp folder.
4. **Don't assume another tool reads your folders.** It won't look in `.cursor/skills/` or Cursor's transcripts unless you tell it to.

That works. It still leaves the trail in a repo, readable by people who know where to look, after someone remembers to commit it.

## Sharing it without remembering to commit

That gap is why we built [artfct-pstack](https://artfct.dev/blog/artfct-pstack). It's a plugin that carries pstack's skills and adds `/publish-evidence`, which collects everything above and publishes it as one bundle to your team's Artfct library:

- **It finds the trail:** decision logs at `decisions.tsv` or `.audit/<task-slug>.tsv`, feature maps in `verify-*` folders under `.cursor/skills/`, `.claude/skills/` and `.omp/skills/`, and the evidence files named in the log.
- **It strips secrets first:** a filename denylist and a scan for token patterns run before anything leaves the machine, and `AGENTS.md` and `CLAUDE.md` never go.
- **It updates in place:** one bundle per task, titled `[pstack] <task-slug> evidence`, shared with your team, never public unless you ask. Re-runs add a new version at the same link.

Verification and show-me-your-work runs publish on their own at the end while Artfct is connected. Then a teammate in any tool connected to the workspace can search the library and open the bundle, instead of asking whose laptop has the screenshot. Connecting is a plugin install, or by hand with the [Claude Code](https://artfct.dev/connect/connect-claude-code), [Codex](https://artfct.dev/connect/connect-codex), [Cursor](https://artfct.dev/connect/connect-cursor) and [OMP](https://artfct.dev/connect/connect-omp) guides. The launch post has the rest.

*pstack is MIT licensed, copyright 2026 Lauren Tan (poteto), and lives at [cursor/plugins](https://github.com/cursor/plugins/tree/main/pstack). artfct-pstack is an independent add-on, not official pstack, and not endorsed by Cursor or Lauren Tan. Last updated 25 November 2026: checked against the official pstack docs, the artfct-pstack README and its publish-evidence skill.*

## Questions

### Where is the pstack decision log, and is it in git?

`decisions.tsv` in the work directory, or `.audit/<task-slug>.tsv` when several efforts run at once. Not in git by default; commit it when a reviewer needs the trail.

### Where does /create-verification-skill write, and should I commit it?

`.cursor/skills/verify-<app>/` in Cursor or `.claude/skills/verify-<app>/` in Claude Code, with a feature map under `features/`. Yes, commit it. The official guide says so.

### Where do screenshots and recordings go?

Wherever the generated verification skill names. Cleanup leaves them alone. Make sure that location is shared if a teammate needs them.

### Can a teammate on Codex, OpenCode or Copilot see my pstack evidence automatically?

No, not unless it's been committed, put somewhere shared, or published somewhere their tool can read.
