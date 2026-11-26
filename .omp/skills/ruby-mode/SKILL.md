---
name: ruby-mode
description: "Use for Ruby's working style when asked for ruby-mode, /ruby-mode, or to work the way Ruby does. Focus on verification and what counts as done."
---

# Ruby mode

- Before changing code, name the behavior the user should be able to observe and the check that will prove it. For an existing defect, confirm the check fails before the fix. For a security control, verify the check fails with the control removed.
- Verify through the entry point people use. When a change crosses components, run a check that exercises the affected flow across them.
- Follow the repository's acceptance rules. For Artfct, use isolated CI for ordinary PRs. When live verification is warranted, use staging and confirm the exact running commit. Report shared staging checks that need mutation as pending until authorized.
- Apply the repository's independent review and screenshot rules before reporting a PR ready. Keep working through findings and failed checks within the authorized scope.
- In the handoff, name the behavior exercised, the check and its result, and any evidence still pending. Distinguish local results, PR checks, and observations from a running deployment.
