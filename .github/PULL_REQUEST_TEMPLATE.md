## Summary

<!-- What changed and why. -->

## Release changelog

- [ ] Added this PR to the [Artfct Release Changelog](https://artfct.dev/a/y0qwyqfntnayc) and to `CHANGELOG.md` under **Unreleased**.
- Ledger entry (artifact link and PR number):
- User or operational impact, verification, and release notes:

## Verification (required for code changes)

<!--
pstack / prove-it-works: exercise the changed behaviour against the real artifact
(or the mapped `verify-artfct` journey), run the focused tests, and link the
evidence artifact. Record the commit, the command, and the exit status. Lint or
compile alone is not verification. If there are no code changes, write: No code changes
-->

- Behaviour exercised (journey or command):
- Focused tests (commit + result):
- Evidence artifact (Artfct link) or blocker:

## Screenshots (required for UI changes)

<!--
If this PR touches anything user-visible (Blade/Inertia/Vue/React components,
CSS/Tailwind, layouts, emails, icons, copy): run the app locally and embed
before/after screenshots here (desktop, and mobile width when layout is affected).
Do not mark a UI PR ready without them.

If there are no UI changes, write: No UI changes
-->

No UI changes

## Independent agent review (required for big changes)

<!--
For big PRs (roughly 300+ changed lines, multiple subsystems, migrations,
auth/security, billing, or public API/MCP — when unsure, treat as big): start a
new, independent Cursor agent to review the PR (fresh agent, no shared context;
not the authoring agent reviewing itself), address or explicitly answer each
finding, re-request review until clear, and summarize the review rounds and
outcome here.

If this is not a big change, write: Not a big change — independent review not required
-->

Not a big change — independent review not required

## Ready to approve (reviewer)

- [ ] Required CI is green at the head SHA; none failed, cancelled, or bypassed
- [ ] Every review finding is fixed or has a written reason it does not apply, and its thread is resolved
- [ ] Description is current for the head, with the `#### Guidance Sources:` footer
- [ ] Code changes show pstack verification: real-artifact behaviour, focused tests, and the evidence artifact or blocker
- [ ] Ledger and CHANGELOG entries link this PR; big changes summarize the independent review
