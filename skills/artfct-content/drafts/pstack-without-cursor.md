---
slug: pstack-without-cursor
title: Using pstack without Cursor: Claude Code and ports
description: Official pstack runs in Cursor and Claude Code. On Codex, OpenCode, Copilot or OMP you need a community port, and Cursor-only steps are skipped.
date: 2026-11-27
tag: workflows
published: false
surface: explainer
register: artfct-human
target: pstack without cursor
---

An open issue on the pstack repo starts with one line: "I run pstack in Claude Code and Codex rather than Cursor." If that's you too, the first thing you want to know is where pstack actually runs.

(The issue is [#601](https://github.com/cursor/plugins/issues/601).) Short answer: officially, in Cursor and in Claude Code. Codex, OpenCode, GitHub Copilot and OMP need a community port. On every path outside Cursor, a step that needs a Cursor-only feature is skipped with a one-line note instead of run.

The rest of this is the detail.

## What pstack is

[pstack](https://github.com/cursor/plugins/tree/main/pstack) is Lauren Tan's skill pack, MIT licensed, in the `cursor/plugins` repo. The entry point is `/poteto-mode`: it reads your request, picks a playbook (bug fix, perf, feature, refactoring, shipping and so on) and runs the other skills as the steps need them.

The playbooks share one habit: prove it. A bug fix starts with a reproduction. A perf change is measured against a baseline. A finished change gets verified with runtime evidence, and `/show-me-your-work` keeps a log of every decision, why it was made and what backs it up. `/poteto-help` tells you which skill fits a task, and `/setup-pstack` runs once to pick a reasoning budget and models.

Lauren's AI Engineer talk, [All Code Is Slop (Except Mine)](https://www.youtube.com/watch?v=O_zcc6Xl12g), is the best explanation of why it's built this way.

## In Cursor

This is pstack's home, and everything works as designed. From the [README](https://github.com/cursor/plugins/tree/main/pstack):

```bash
/add-plugin pstack
/setup-pstack
/poteto-mode
```

## In Claude Code

The one official path outside Cursor. Add the `cursor/plugins` marketplace and install from it:

```bash
/plugin marketplace add cursor/plugins
/plugin install pstack@cursor-plugins
```

Three things I'd want to know before the first run:

- **Commands get a prefix.** Claude Code namespaces plugin commands, so it's `/pstack:poteto-mode` and `/pstack:setup-pstack`. The [Claude Code adapter](https://github.com/cursor/plugins/blob/main/pstack/docs/harness/claude-code.md) maps every other Cursor name.
- **Model choices go in a rules file.** Setup writes them to `~/.claude/rules/pstack-models.md`, which Claude Code loads into every session.
- **Auto-update is off.** Claude Code leaves it off for third-party marketplaces, so you stay on the version you installed until you turn it on or update by hand. The README explains both.

## What gets skipped outside Cursor

The adapter is upfront about it. Claude Code has no Cursor Automations, no Bugbot and no Cursor cloud agents. When a step needs one, the agent writes a line saying so and moves on. Cloud subagents become local worktrees. And pstack's review panel names models from more than one lab, so a seat the session can't reach runs on the session's own model instead.

People have filed what they hit:

| Issue | What it's about |
| --- | --- |
| [#701](https://github.com/cursor/plugins/issues/701) | The `poteto-mode` command name shows up inconsistently in Claude Code |
| [#809](https://github.com/cursor/plugins/issues/809) | Every review seat runs on Claude |
| [#1012](https://github.com/cursor/plugins/issues/1012) | `/deslop` depends on a plugin that isn't in the Claude marketplace |
| [#1270](https://github.com/cursor/plugins/issues/1270) | A Cursor model name falls back to a different model in Claude Code |

None of this is a reason to skip Claude Code. It just means a long run prints a few skip lines, and they're worth reading.

## Codex, OpenCode, Copilot and OMP

Not officially supported. Until that changes, you're using a port: someone else's adaptation of pstack's skills for another tool. None of these is an official Cursor or Lauren Tan release, and each makes its own calls about what to keep and how closely to follow upstream. Here's what I found, in no particular order:

| Port | Author | What its README says it covers |
| --- | --- | --- |
| [backnotprop/pstack](https://github.com/backnotprop/pstack) | backnotprop | Claude, Codex and Pi |
| [michael-denyer/pstack-claude](https://github.com/michael-denyer/pstack-claude) | michael-denyer | Claude Code, Codex, OpenCode, Copilot, OMP and a long list of others, tracking upstream with its own policy forks |
| [ericlitman/open-pstack](https://github.com/ericlitman/open-pstack) | ericlitman | Claude Code and Codex, tracking upstream |
| [chhoumann/t3-pstack](https://github.com/chhoumann/t3-pstack) | chhoumann | T3 Code |

For Codex, any of the first three. For OpenCode, Copilot or OMP, michael-denyer's is the one that names them.

I haven't copied install commands for the ports, on purpose. They change faster than a blog post does, and a stale command is worse than none. Use the port's README on the day you install.

I'm not going to pick a winner either. What I'd check before choosing one:

1. **Does it say how it tracks upstream, and when it last synced?**
2. **Does it explain what it changed for the Cursor-only parts, and why?**
3. **Does it add hooks that start `/poteto-mode` on their own?** Some do. Decide whether you want that before a teammate gets surprised by it.

And credit the port's author when you use their work, the same way pstack credits Lauren.

## Where the proof ends up

Wherever pstack runs, most of what a run produces stays on the machine that ran it:

- **The decision log.** `decisions.tsv` in the work directory, or `.audit/<task-slug>.tsv` when several efforts run at once. Out of git by default.
- **The verification skill.** `/create-verification-skill` writes `.cursor/skills/verify-<app>/` in Cursor, which the adapter maps to `.claude/skills/` in Claude Code, with a feature map inside.
- **The evidence.** Screenshots and traces go wherever that verification skill says.

That matters most for exactly the people reading this. If you run pstack in Claude Code and your teammate is in Codex, their agent won't read `.claude/skills/`, won't see an uncommitted log and can't open a screenshot on your laptop. I wrote up [where pstack keeps each piece](https://artfct.dev/blog/where-pstack-artifacts-live) and how to share it with plain git.

The other option is the reason I wrote this at all: [artfct-pstack](https://artfct.dev/blog/artfct-pstack), a plugin that carries pstack's skills and publishes each verification run's log, feature maps and evidence to your team's Artfct library, where any agent connected to the workspace can find it. Setup is two plugins; the [Claude Code](https://artfct.dev/connect/connect-claude-code), [Codex](https://artfct.dev/connect/connect-codex), [Cursor](https://artfct.dev/connect/connect-cursor) and [OMP](https://artfct.dev/connect/connect-omp) guides cover connecting Artfct by hand. It's an independent add-on, not official pstack, and not endorsed by Cursor or Lauren Tan.

*pstack is MIT licensed, copyright 2026 Lauren Tan (poteto). Checked against the pstack README, the Claude Code adapter and each port's README on 25 November 2026.*

## Questions

### Does official pstack work in Claude Code?

Yes. Add the `cursor/plugins` marketplace and install `pstack@cursor-plugins`. Commands get a `/pstack:` prefix and Cursor-only steps are skipped with a note.

### Does official pstack work in Codex, OpenCode, Copilot or OMP?

Not officially. Those need a community port. Check the README on the day you install, since official support can grow.

### What's skipped outside Cursor?

Anything that needs Cursor Automations, Bugbot or Cursor cloud agents. Some review seats also run on your session's model instead of the one the skill names.

### Are the ports official?

No. Each is independent and maintained by its own author.
