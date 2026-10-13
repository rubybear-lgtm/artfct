---
description: Review an artfct.dev draft with the read-only artfct-content-reviewer subagent
argument-hint: "[draft path, slug, or 'all']"
---
Delegate to the `artfct-content-reviewer` agent — read-only, and deliberately not the author.

For one draft, call `subagent` once with `agent: "artfct-content-reviewer"`, `async: false`,
and a task that names the draft path (cwd is the repository root), tells it to read
`skills/artfct-content/references/` first, and tells it to run
`python3 skills/artfct-content/scripts/lint-content.py <draft> --json`.

For several drafts, write one workflow script with `runs.all` and a child per draft, then call
`subagent({ workflow: true })` in the same reply.

Target: ${@:-every draft in skills/artfct-content/drafts/}

Print the findings verbatim, then list only the blocking ones and say which you would fix.
Do not fix anything in this turn.
