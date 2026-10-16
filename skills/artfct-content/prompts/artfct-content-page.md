---
description: Run the whole artfct-content loop for one page — draft, review, fix, publish
argument-hint: "<surface> <slug>: <what the page must say>"
---
Run the full artfct-content loop for one page. Do not skip the review, and do not let the
author review their own draft.

1. Delegate the draft to `artfct-content-writer` with `subagent`, `async: false`.
2. Delegate the same draft to `artfct-content-reviewer` with `subagent`, `async: false`.
3. Fix every blocking and major finding yourself. Where a finding says a claim is not in the
   inventory, either cut the claim or add a sourced row to
   `skills/artfct-content/references/feature-inventory.md`. Never soften an unsupported claim
   into a hedge — a hedge still reaches the customer.
4. Re-render and re-lint:
   `php artisan blog:render skills/artfct-content/drafts/<slug>.md --out=/tmp/<slug>.html`, then
   `python3 skills/artfct-content/scripts/lint-content.py /tmp/<slug>.html`. Repeat step 2 until
   it reports no blockers.
5. Read `/tmp/<slug>.html` in a browser, then publish that render with the artfct MCP
   `deploy_artifact` tool at `team` sharing. After any later fix, re-render and re-publish with
   `artifact_id` so the link stays stable.
6. Set `published: true` in the front matter. That is the publish — the file is the only copy, so
   `/blog`, `/blog/<slug>`, the sitemap and the structured data all read it at request time and no
   code changes. Leave it `false` to hold the page back: written, linted and reviewed, and absent
   from all three.
7. Run `composer test`.

Report what the reviewer found and what you changed in response. If you cut a claim, say which.

Brief: ${@:-none given — ask the operator before starting}
