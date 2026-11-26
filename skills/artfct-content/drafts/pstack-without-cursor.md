---
slug: pstack-without-cursor
title: Using pstack without Cursor: Claude Code and ports
description: Official pstack runs in Cursor and Claude Code. On Codex, OpenCode, Copilot or OMP you need a community port, and Cursor-only steps are skipped.
date: 2026-11-27
tag: workflows
published: false
surface: explainer
register: glean-formal
target: pstack without cursor
---

pstack is Lauren Tan's MIT-licensed skill pack for rigorous agent work, and it is not limited to Cursor. The official release runs in Cursor and in Claude Code. Codex, OpenCode, GitHub Copilot and OMP rely on independent community ports, and on every one of those paths a step that needs a Cursor-only feature is skipped with a short note rather than run.

That is the short answer. The rest of this guide covers what the official Claude Code path looks like, which ports exist for the other tools, what gets skipped outside Cursor, and what happens to the proof a run leaves behind.

## Why are so many people asking this right now?

pstack has been around for a while inside the [cursor/plugins](https://github.com/cursor/plugins/tree/main/pstack) repository. What changed is the talk. Lauren Tan, who goes by [poteto](https://github.com/poteto), gave [All Code Is Slop (Except Mine)](https://www.youtube.com/watch?v=O_zcc6Xl12g) at AI Engineer, published on the conference's channel in November, and the argument landed with a wide audience: the work is no longer only writing code, it is building an environment where agents can be trusted, and pstack is one concrete version of that environment.

Most of the people who watched it do not work in one tool. A team might run Claude Code for most work, Codex for a second opinion and Copilot inside the editor. The first question after "how do I install this" is often "does it run where I already work?" The official repository has an open issue that starts with exactly that situation: "I run pstack in Claude Code and Codex rather than Cursor" ([#601](https://github.com/cursor/plugins/issues/601)).

## What does pstack actually do?

pstack is a set of skills and playbooks that change how an agent approaches a task. The entry point is `/poteto-mode`. It reads the request, picks a playbook such as bug fix, perf, feature, refactoring or shipping, and runs the other skills as the steps need them.

The playbooks share a habit: prove it. A bug fix starts with a reproduction. A perf change is measured against a baseline. A finished change is verified with runtime evidence, not with a claim that it works. Along the way, `/show-me-your-work` keeps a log of each decision, why it was made and what evidence supports it.

If it is unclear which skill fits a task, `/poteto-help` suggests one. `/setup-pstack` runs once at the start to pick a reasoning budget and the models each role should use.

## What does official pstack support today?

The [official README](https://github.com/cursor/plugins/tree/main/pstack) documents two homes: Cursor, and Claude Code through a published adapter. Install commands change over time, so the safest habit is to copy them from the README on the day of installation.

### In Cursor

Cursor is pstack's native home. The README's install is a single command, followed by setup and the main mode:

```bash
/add-plugin pstack
/setup-pstack
/poteto-mode
```

Every skill, automation and review seat works here as designed, because this is the environment pstack was written for.

### In Claude Code

Claude Code is the one official path outside Cursor. The README adds the `cursor/plugins` marketplace and installs pstack from it:

```bash
/plugin marketplace add cursor/plugins
/plugin install pstack@cursor-plugins
```

Three details are worth knowing before the first run:

- **Commands carry a prefix.** Claude Code prefixes plugin commands, so `/poteto-mode` becomes `/pstack:poteto-mode` and `/setup-pstack` becomes `/pstack:setup-pstack`. The [Claude Code adapter](https://github.com/cursor/plugins/blob/main/pstack/docs/harness/claude-code.md) maps every other Cursor name.
- **Model choices live in a rules file.** Setup writes them to `~/.claude/rules/pstack-models.md`, which Claude Code loads into every new session.
- **Auto-update is off by default.** Claude Code leaves auto-update off for third-party marketplaces, so pstack stays on the installed version until auto-update is switched on or the plugin is updated by hand. The README explains both.

## What is skipped outside Cursor?

The adapter is direct about the gaps. Claude Code has no Cursor Automations, no Bugbot and no Cursor cloud agents. When a step needs one of them, the agent writes one line naming the skipped step and the reason, then moves on. Cloud subagents map to local worktrees instead.

Some review-panel seats also behave differently. pstack's default review panel names models from more than one lab, and a model the session cannot reach runs on the session's own model instead.

People have filed the friction they hit on the official repository:

| Issue | What it reports |
| --- | --- |
| [#701](https://github.com/cursor/plugins/issues/701) | The `poteto-mode` command name shows up inconsistently in Claude Code |
| [#809](https://github.com/cursor/plugins/issues/809) | Review seats run only on Claude |
| [#1012](https://github.com/cursor/plugins/issues/1012) | `/deslop` depends on a Cursor-only plugin |
| [#1270](https://github.com/cursor/plugins/issues/1270) | A Cursor model name falls back in Claude Code |

None of this makes Claude Code a poor choice for pstack. It means a long run will print a few skip lines, and those lines are worth reading rather than assuming every step ran.

## What about Codex, OpenCode, Copilot and OMP?

These tools are not on the official support list. People ask for them regularly: [#601](https://github.com/cursor/plugins/issues/601) asks for a Codex mapping, and other issues ask about still more tools. Until official support changes, the route is an independent port.

A port is someone else's adaptation of pstack's skills for a different tool. Ports are written and maintained by people other than Lauren Tan and Cursor, and none of them is an official release. Each makes its own choices about what to keep, what to change and how closely to follow upstream. These are the ones we found, in no order of preference:

| Port | Author | What its README describes |
| --- | --- | --- |
| [backnotprop/pstack](https://github.com/backnotprop/pstack) | backnotprop | Use with Claude, Codex and Pi |
| [michael-denyer/pstack-claude](https://github.com/michael-denyer/pstack-claude) | michael-denyer | Claude Code, Codex, OpenCode, Copilot, OMP and others, tracking upstream with its own policy forks |
| [ericlitman/open-pstack](https://github.com/ericlitman/open-pstack) | ericlitman | Claude Code and Codex, tracking upstream |
| [chhoumann/t3-pstack](https://github.com/chhoumann/t3-pstack) | chhoumann | pstack for T3 Code |

Mapped to the tool a team already uses:

- **Claude Code.** The official path above. No port is needed.
- **Codex.** A port that names Codex, such as backnotprop/pstack, michael-denyer/pstack-claude or ericlitman/open-pstack.
- **OpenCode.** michael-denyer/pstack-claude names OpenCode.
- **GitHub Copilot.** michael-denyer/pstack-claude names Copilot.
- **OMP.** michael-denyer/pstack-claude names OMP.

This guide does not copy install commands for the ports, on purpose. They change faster than an article does, and a stale command is worse than none. The port's own README is the source on the day of installation.

## How should a team choose a port?

There is no official pick, and this guide does not name a winner. Three questions separate a port that will age well from one that will drift:

1. **Does it say how it tracks upstream pstack?** A port that states its sync policy, and when it last synced, is easier to trust than one that does not say.
2. **Does it explain what it changed and why?** Every port has to substitute something for Cursor-only features. The good ones write those substitutions down.
3. **Does it add hooks that start `/poteto-mode` on their own?** Some do. That can be convenient, and it can also surprise a teammate who did not expect it. It is worth deciding on purpose.

Credit the port's author by name when using their work, the same way pstack itself credits Lauren Tan.

## Where does a run's proof end up?

pstack is built around proof, and a run leaves a trail behind it. Wherever pstack runs, most of that trail stays on the machine that ran it:

- **Decision logs.** `/show-me-your-work` keeps its log at `decisions.tsv` in the work directory, or `.audit/<task-slug>.tsv` when several efforts run at once, and leaves it out of git by default.
- **Verification skills.** `/create-verification-skill` writes a project skill to `.cursor/skills/verify-<app>/` in Cursor, which the adapter maps to `.claude/skills/` in Claude Code, with a feature map inside.
- **Evidence.** Screenshots and traces go wherever the generated verification skill names.

This matters most for exactly the teams reading this guide. If one person runs pstack in Claude Code and a teammate works in Codex, the second agent will not read `.claude/skills/`, will not see an uncommitted decision log and cannot open a screenshot on someone else's laptop. [Where pstack saves its proof](https://artfct.dev/blog/where-pstack-artifacts-live) maps every location and how to share each one with plain git.

## Can the proof follow the team across tools?

Sharing through git works, as long as someone remembers to commit the right files. For teams that would rather not rely on that, we built [artfct-pstack](https://artfct.dev/blog/artfct-pstack): a plugin that carries pstack's skills and publishes each verification run's decision trail, feature maps and evidence to the team's Artfct library. Any connected agent, in any of those tools, can then find the bundle by searching the library, and a person can open it in a browser.

It installs as two plugins from the same marketplaces these tools already use, and the launch post has the setup for each tool. It is an independent add-on, not official pstack, and it is not endorsed by Cursor or Lauren Tan.

## Questions

### Does official pstack work in Claude Code, or is a port needed?

Official pstack works in Claude Code. The README adds the `cursor/plugins` marketplace and installs pstack from it. Commands carry a `/pstack:` prefix, and Cursor-only steps are skipped with a note.

### Does official pstack work in Codex, OpenCode, Copilot or OMP?

Not officially. Those tools rely on independent community ports. Official support can expand, so the README is worth checking on the day of installation.

### What is skipped when pstack runs outside Cursor?

Steps that need Cursor Automations, Bugbot or Cursor cloud agents. The agent writes one line naming the skipped step and continues. Some review-panel seats also run on the session's model instead of the model the skill names.

### Which community port is the best one?

There is no official pick, and we are not naming one. The useful checks are whether it tracks upstream, whether it explains its changes and whether it adds hooks that start `/poteto-mode` automatically.

### Are the ports official Cursor or Lauren Tan releases?

No. Each port is independent and maintained by its own author.

### Can a teammate on a different tool see the proof from my run?

Not by default. The decision log is usually outside git and evidence may live on one machine. Committing the right files works, and [artfct-pstack](https://artfct.dev/blog/artfct-pstack) can publish the trail to a shared library instead.
