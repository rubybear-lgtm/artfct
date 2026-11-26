---
slug: artfct-pstack
title: pstack's proof shouldn't live on one laptop
description: artfct-pstack is a free plugin that runs pstack's workflows and publishes each verification run's decision log, feature maps and evidence to a shared library.
date: 2026-11-27
tag: launches
published: false
surface: feature-showcase
register: artfct-human
target: artfct-pstack
---

This week I added a pstack verification skill to Artfct's own repo: 48 lines that tell an agent how to launch the app, drive it and prove a feature works, plus three feature maps. It's good. It's also sitting in `.omp/skills/`, a folder only OMP reads.

That's the problem artfct-pstack exists for.

## pstack is great at proof

[pstack](https://github.com/cursor/plugins/tree/main/pstack) is Lauren Tan's skill pack for agents that have to show their work. (Lauren's AI Engineer talk, [All Code Is Slop (Except Mine)](https://www.youtube.com/watch?v=O_zcc6Xl12g), is the best introduction to why it works the way it does.) `/poteto-mode` picks a playbook, reproduces the bug before touching it, verifies the fix, and logs every decision with a pointer to the evidence.

What pstack doesn't do is decide where that proof goes. The decision log is a working file kept out of git unless a reviewer needs it. The verification skill lives in whichever skills folder your tool reads. Screenshots go wherever the skill says, which is often a folder on one machine.

That's a reasonable default for one person in one tool. It falls apart the moment a teammate opens the same repo in Codex, and their agent starts the investigation over because it can't see any of it.

## What artfct-pstack adds

It's pstack's skills and playbooks, adapted, plus one thing: when a verification run finishes, the proof gets published to your team's Artfct library.

Concretely, `/publish-evidence` collects:

- the decision log (`decisions.tsv`, or `.audit/<task-slug>.tsv` when several efforts run at once)
- feature maps from any `verify-*` folder under `.cursor/skills/`, `.claude/skills/` or `.omp/skills/`
- the evidence files the log points to

and publishes them as one bundle called `[pstack] <task-slug> evidence`. Verification and show-me-your-work runs do this on their own at the end, as long as Artfct is connected. Anything else publishes when you ask. Run the same task again and the bundle gets a new version at the same link instead of a second copy.

Then the next agent connected to your workspace, in whatever tool, searches the library and finds it. So does the next person, in a browser.

Bundles are shared with your team and never public unless you say so. Before anything leaves the machine, filenames on a denylist are skipped and text is scanned for token patterns. `AGENTS.md` and `CLAUDE.md` are never published. (Screenshots ship as they are once they pass the denylist, and a terminal screenshot can show a token. The skill tells you when it's including one. Look before you share it further.)

## Install

It's two plugins: Artfct's own, which handles the connection, and the add-on. In Claude Code:

```bash
/plugin marketplace add artfct-dev/artfct-plugin
/plugin install artfct@artfct
/plugin marketplace add artfct-dev/artfct-pstack
/plugin install artfct-pstack@artfct-pstack
```

Approve the Artfct sign-in page when it opens (no tokens to paste), then run `/setup-artfct` and `/setup-pstack-omp`. The second one works out which tool you're in and asks for a model pack and a reasoning budget. After that, `/poteto-mode` works the way it always has.

Codex uses the same two repositories with `codex plugin marketplace add` and `codex plugin add`. In the Claude app it's Customize > Plugins > Add marketplace. The [artfct-pstack README](https://github.com/artfct-dev/artfct-pstack) has the add-on's line for Cursor, GitHub Copilot, OMP, OpenClaw, Hermes and Antigravity, and the [artfct-plugin README](https://github.com/artfct-dev/artfct-plugin) covers the connection for each tool it supports. If you'd rather connect by hand, there are step-by-step guides for [Claude Code](https://artfct.dev/connect/connect-claude-code), [Codex](https://artfct.dev/connect/connect-codex), [Cursor](https://artfct.dev/connect/connect-cursor) and [OMP](https://artfct.dev/connect/connect-omp).

## What's tested and what isn't

The [client matrix](https://github.com/artfct-dev/artfct-pstack/blob/main/skills/setup-artfct/references/supported-clients.md) covers 15 tools, and I'd rather you read it than trust a logo wall. The short version:

- **Skills install and load, checked live:** Claude Code, Codex, GitHub Copilot, OMP, OpenClaw, Hermes, Antigravity and Cursor.
- **Caveats on those:** in Codex the add-on is skills only, so Artfct's connection comes from the other plugin. Hermes has everything wired, but its sign-in test hasn't passed yet. Copilot loads the add-on's skills, but artfct-plugin has no Copilot install line, so I'm not claiming publishing works there end to end.
- **Cursor, separately:** the add-on's skills load in Cursor. Artfct's own connection in Cursor is documented, and I haven't verified it end to end yet.
- **Should work, not confirmed:** ChatGPT, VS Code, Devin, Kiro, NanoClaw and OpenHands. Mostly blocked on needing an account or a desktop app to test.
- **Doesn't fit:** Perplexity caps a plugin at 32 skills. This one has 62.
- **Not in the matrix yet:** OpenCode.

## What doesn't work yet

A few pstack pieces don't travel. benny, the issue-triage automation, is included but needs Cursor Automations to actually run. Pinned mode is an OMP feature; elsewhere it skips with a one-line note. And with no Artfct connection, nothing publishes. Your runs work exactly like plain pstack and the files stay local.

## Credit

pstack is Lauren Tan's work ([poteto](https://github.com/poteto)), MIT licensed. artfct-pstack adapts the skills and playbooks under the same license and adds the setup and publishing parts. It's not official pstack, and neither Cursor nor Lauren has endorsed it.

## Try it

Both plugins are free. Publishing needs an Artfct workspace: new teams get a 90-day Team trial, and after that the workspace drops back to Free unless you upgrade. [Start one here](https://artfct.dev/login?screen_hint=sign-up). If you're not on Cursor, I wrote up [how to run pstack elsewhere](https://artfct.dev/blog/pstack-without-cursor), and [where pstack keeps its proof](https://artfct.dev/blog/where-pstack-artifacts-live) maps every file this plugin goes looking for.

My verification skill is still in `.omp/skills/`. The proof it produces doesn't have to stay there.

*Checked against the artfct-plugin and artfct-pstack READMEs and the supported-clients matrix on 26 November 2026.*
