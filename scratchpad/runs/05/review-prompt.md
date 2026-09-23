You are reviewing a code change in the artfct repository. You have no prior
context and you should not seek any. Do not read the conversation history. Do
not look for design documents. Judge only what is in front of you.

Note: this diff closes only a SUBSET of a larger spec's Definition of Done —
the subset that is server-side-enforceable and testable without a live
browser or real DNS. Only the items listed below are in scope for this
review; do not penalize the diff for not covering cross-origin fetch/iframe
browser behavior, CSP client-side enforcement, or wildcard certificate
provisioning — those are explicitly out of scope for this diff.

## The contract — the change must satisfy every item

- [ ] Artifact A and artifact B in the same org serve from different hostnames.
- [ ] No `Set-Cookie` appears on any artifact-origin response.
- [ ] An access token expires and subsequent requests return 403.
- [ ] A token minted for artifact A returns 403 on artifact B.
- [ ] An artifact declaring no external origins gets `default-src 'self'`, verified in the response header.
- [ ] `unsafe-eval` is absent unless the manifest declares it.
- [ ] Free-tier `/p/{id}` links continue to work unchanged.

## Tests that must exist

- `hostname_derives_from_slug_and_id`
- `artifact_origin_sets_no_cookie`
- `expired_access_token_rejected` *(negative)*
- `token_for_other_artifact_rejected` *(negative)*
- `csp_defaults_to_self_when_nothing_declared`
- `csp_includes_only_declared_origins`
- `undeclared_origin_absent_from_csp` *(negative)*
- `unsafe_eval_absent_unless_declared` *(negative)*

## The diff

Read /Users/ruby/Documents/dev/artfct/scratchpad/runs/05/spec05.diff (581 lines) with the Read tool before judging anything.

## Your report

For EACH DoD item above, report exactly one of:

- PASS — with the specific evidence in the diff that makes it true
- FAIL — with what is missing or wrong
- CANNOT VERIFY — the item is not observable from this diff

Then, for the tests:

- Which named tests are present, and which are missing
- For each test marked (negative): does it actually exercise the failure path,
  or would it pass even if the protection were removed? Quote the assertion.

Finally, note anything in the diff that is NOT required by the contract. Extra
work is scope creep and is worth flagging even when it looks harmless.

Do not suggest improvements outside the contract. Do not comment on style —
formatters and linters have already run. Your job is: does this diff satisfy
this contract, item by item.
