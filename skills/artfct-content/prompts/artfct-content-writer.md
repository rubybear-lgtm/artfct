---
description: Draft an artfct.dev page with the artfct-content-writer subagent
argument-hint: "<surface> <slug>: <what the page must say>"
---
Delegate this to the `artfct-content-writer` agent. Call `subagent` once with
`agent: "artfct-content-writer"`, `async: false`, and a task built from the brief below.

The child runs in an isolated context, so the task has to stand on its own. Build it to include:

- the surface — `feature-showcase`, `comparison`, `explainer` or `programmatic`
- the slug, the tag, and the date line
- the draft path, `skills/artfct-content/drafts/<slug>.html`, and that cwd is the repository root
- what the page has to say, in one or two sentences
- a reminder that every capability claim must trace to a row in
  `skills/artfct-content/references/feature-inventory.md`, including its known-gaps section

Brief: ${@:-none given — ask the operator which surface, which slug, and what the page must claim before launching}

Print the child's report verbatim. Do not edit the draft yourself in this turn.
