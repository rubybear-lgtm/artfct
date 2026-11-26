---
slug: artfct-pstack
title: artfct-pstack: pstack proof your whole team can see
description: artfct-pstack runs pstack's workflows and publishes each run's decision trail, feature maps and evidence to your team's Artfct library. Install it as a plugin.
date: 2026-11-27
tag: launches
published: false
surface: feature-showcase
register: artfct-terse
target: artfct-pstack
---

pstack makes your agents prove their work. artfct-pstack is a free plugin that publishes that proof to a library your whole team and all its agents can read.

## The proof is good. Nobody else can see it.

If you watched Lauren Tan's talk [All Code Is Slop (Except Mine)](https://www.youtube.com/watch?v=O_zcc6Xl12g), you probably installed [pstack](https://github.com/cursor/plugins/tree/main/pstack) the same week. It changes how an agent works. You type `/poteto-mode`, the agent picks a playbook, reproduces the bug before touching it, verifies the fix with real evidence, and writes down every decision it made along the way.

Then the run ends, and the proof stays where it was made.

Picture a Tuesday. You ask Claude Code to fix a scroll drift. It reproduces the drift, records a before-and-after video, and logs twelve decisions to `decisions.tsv`. By default that log is a working file left out of git. The videos sit in a folder on your laptop. The next morning a teammate picks up a related bug in Codex. Their agent cannot see your trail, so it starts from nothing, and your teammate messages you to ask which laptop has the screenshot.

pstack did its job. The trail just had nowhere shared to go.

## What does artfct-pstack add?

One thing: a place for the proof to land.

artfct-pstack carries pstack's skills and playbooks, so `/poteto-mode` and `/poteto-help` work the way you already know. On top of that it adds a setup step that connects Artfct and one skill that publishes:

- **`/publish-evidence`** gathers the run's decision log, the feature maps from your verification skills and the evidence files the log points to, and publishes them as one bundle titled `[pstack] <task-slug> evidence`.
- **It runs on its own at the end of a verification run.** Verification and show-me-your-work runs close by publishing their proof while Artfct is connected. Any other run publishes when you call `/publish-evidence`.
- **Re-runs update the same bundle.** Run the task again and the bundle gets a new version at the same link, instead of a pile of near-duplicates.

The bundle is an Artfct artifact like any other, so the next agent can find it. Your teammate's Codex session searches the team library, finds the bundle, and reads the decisions and the evidence links before it starts. A person opens the same bundle in a browser.

## How do you install it?

Both pieces are plugins, so setup is two installs and two commands. In Claude Code:

```bash
/plugin marketplace add artfct-dev/artfct-plugin
/plugin install artfct@artfct
/plugin marketplace add artfct-dev/artfct-pstack
/plugin install artfct-pstack@artfct-pstack
```

Then approve the Artfct sign-in page when it opens. You never paste a password or a token. Finish with two commands in your agent:

```bash
/setup-artfct
/setup-pstack-omp
```

`/setup-artfct` checks that Artfct is connected so proof can publish. `/setup-pstack-omp` detects which tool you are in, lets you pick a model pack and a reasoning budget, and writes the routing. After that, use `/poteto-mode` as usual.

Other tools follow the same pattern from the same two repositories:

- **Codex.** `codex plugin marketplace add artfct-dev/artfct-plugin`, then `codex plugin add artfct@artfct`. Repeat with `artfct-dev/artfct-pstack` and `artfct-pstack@artfct-pstack`.
- **Claude app and Cowork.** Open **Customize > Plugins > Add > Add marketplace** and add `artfct-dev/artfct-plugin`, then run `/artfct:onboard`. The add-on uses the same marketplace flow with `artfct-dev/artfct-pstack`.
- **Cursor, GitHub Copilot, OMP, OpenClaw, Hermes and Antigravity.** Each has its own install line in the [artfct-pstack README](https://github.com/artfct-dev/artfct-pstack) and the [artfct-plugin README](https://github.com/artfct-dev/artfct-plugin).

The [connect guides](https://artfct.dev/connect) cover the Artfct sign-in for each tool if you would rather not use a plugin.

## What lands in the library, and who can see it?

The bundle holds the decision log (`decisions.tsv`, or `.audit/<task-slug>.tsv` when several efforts run at once), the feature maps from any `verify-*` skill folder, and the evidence files the log names.

The defaults are conservative:

| Setting | Default |
| --- | --- |
| Who can open it | Your team. Never public unless you ask. |
| Secrets | Filenames on a denylist are skipped and text is scanned for token patterns before anything leaves the machine. |
| Agent instructions | `AGENTS.md` and `CLAUDE.md` are never published. |
| Re-runs | A new version of the same bundle, at the same link. |

Screenshots and videos ship as they are once they pass the denylist, and a terminal screenshot can show a token. The skill says when it includes one, so you can check before you share further.

## Which tools does it work in?

The add-on's [supported-clients matrix](https://github.com/artfct-dev/artfct-pstack/blob/main/skills/setup-artfct/references/supported-clients.md) marks eight clients as verified live, meaning the maintainers installed the plugin and confirmed its skills load: Claude Code, Codex, GitHub Copilot, OMP, OpenClaw, Hermes, Antigravity and Cursor, where Artfct's own connection is documented but not yet verified.

A few details matter. The Claude app, Cowork and Grok Build read the same marketplace as Claude Code, but they were not tested on their own. In Codex, the add-on ships skills only, so the Artfct connection comes from artfct-plugin. In Hermes, the skills and connection are wired, and the maintainers' sign-in test is still pending.

ChatGPT, VS Code, Devin, Kiro, NanoClaw, OpenHands and Perplexity are marked unconfirmed, mostly because a live test needed an account or a desktop app. OpenCode is not in the matrix yet.

## What this does not do

- **It does not publish without a connection.** With no Artfct connection, runs still work and the files stay local.
- **benny still needs Cursor.** The issue-triage automation is included as content, but it needs Cursor Automations to run.
- **Some features are OMP-only.** Pinned mode and a few dialogs are OMP features. Elsewhere they skip with a one-line note.
- **Cursor's plugin import is for Teams and Enterprise.** Individual Cursor users load the add-on from a local folder and connect Artfct with the connect guide.
- **Perplexity does not fit as it is.** The add-on has 62 skills, and Perplexity allows 32 per plugin.

## Credit where it's due

pstack is Lauren Tan's work ([poteto](https://github.com/poteto)), MIT licensed, and lives at [cursor/plugins](https://github.com/cursor/plugins/tree/main/pstack). artfct-pstack adapts its skills and playbooks under the same license and adds the setup and publishing pieces. It is an independent add-on, not official pstack, and it is not endorsed by Cursor or Lauren Tan.

## Questions

### Do I need artfct-pstack to use pstack?

No. Official pstack works on its own in Cursor and Claude Code. You need the add-on only if you want each run's proof published to a shared library. If you are not on Cursor, read [using pstack without Cursor](https://artfct.dev/blog/pstack-without-cursor).

### Which do I install first?

artfct-plugin first, so your tool is connected to Artfct. Then artfct-pstack. Then `/setup-artfct` and `/setup-pstack-omp`, in that order.

### Is every run published automatically?

Verification and show-me-your-work runs publish at the end while Artfct is connected. Other runs publish when you call `/publish-evidence`.

### Where does the proof go if I never connect Artfct?

It stays in local paths, most of them outside git. [Where pstack saves its proof](https://artfct.dev/blog/where-pstack-artifacts-live) maps each one.

### What does it cost?

The plugins are free and MIT licensed. Publishing needs an Artfct workspace, and the Team plan is free for 90 days.

## Try it

Install the two plugins, run pstack the way you already do, and let the proof land where the next agent will look. [Start a Team trial, free for 90 days](https://artfct.dev/pricing), and find the add-on [on GitHub](https://github.com/artfct-dev/artfct-pstack).
