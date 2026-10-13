---
description: Run the whole artfct-content loop for one page — draft, review, fix, publish, emit
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
4. Re-lint with `python3 skills/artfct-content/scripts/lint-content.py <draft>` and repeat
   step 2 until it reports no blockers.
5. Publish the draft with the artfct MCP `deploy_artifact` tool at `team` sharing. After any
   later fix, re-publish with `artifact_id` so the link stays stable.
6. Emit the page:
   `node skills/artfct-content/scripts/draft-to-post.mjs <draft> --slug <slug> --tag <tag> --insert resources/js/lib/posts.tsx`,
   then add the metadata row to `$blogPosts` in `routes/web.php`. The description must match
   the draft's meta description exactly, because `tests/Feature/BlogFeatureStoriesTest.php`
   asserts it.
7. Run `vendor/bin/pint --dirty --format agent`, `npx prettier --write resources/js/lib/posts.tsx`,
   `npx eslint --fix`, `npm run build`, then `composer test`.

Report what the reviewer found and what you changed in response. If you cut a claim, say which.

Brief: ${@:-none given — ask the operator before starting}
