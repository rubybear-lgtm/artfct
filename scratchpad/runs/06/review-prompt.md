You are reviewing a code change in the artfct repository. You have no prior
context and you should not seek any. Do not read the conversation history. Do
not look for design documents. Judge only what is in front of you.

Note: item 9 below is explicitly scoped to be verified only against a fake/
injectable DNS resolver, not real DNS — this was a deliberate, approved
scope decision, not a gap to penalize. Do not fail item 9 solely because
real `dns_get_record` isn't exercised; PASS it if the fake-resolver path
genuinely enforces the rule end-to-end (seed → verify → clear → re-fail →
re-verify), and say so.

## The contract — the change must satisfy every item

- [ ] Register via AuthKit, land on a personal team, create a second org, invite a member by email, accept, switch between orgs.
- [ ] A user who logs in via Google and later via passkey resolves to **one** `users` row with two `external_identities`.
- [ ] `users` has no `workos_id` column.
- [ ] Creating an org with slug `acme--corp` is rejected.
- [ ] Creating an org with a non-ASCII or >24-char slug is rejected.
- [ ] A new org has `auth_mode = authkit`.
- [ ] Moving an org to `dual` without a verified domain is refused.
- [ ] Moving an org to `polis` with no admin holding a Polis identity is refused, with an error naming the reason.
- [ ] Domain verification succeeds only when the DNS TXT record is present, and re-verification is possible after removal.
- [ ] A `viewer` cannot invite, and a `member` cannot change `auth_mode` — both return 403.

## Tests that must exist

**Pest — feature**
- `user_registers_and_gets_personal_team`
- `user_creates_and_switches_orgs`
- `invitation_flow_completes`
- `google_and_passkey_resolve_to_one_user`
- `second_provider_creates_second_external_identity`
- `org_slug_with_double_hyphen_rejected` *(negative)*
- `org_slug_non_ascii_rejected` *(negative)*
- `org_slug_over_length_rejected` *(negative)*
- `auth_mode_defaults_to_authkit`
- `dual_requires_verified_domain` *(negative)*
- `polis_requires_admin_with_polis_identity` *(negative)*
- `domain_verification_requires_dns_txt` *(negative)*

**Pest — policy**
- `viewer_cannot_invite` *(negative)*
- `member_cannot_change_auth_mode` *(negative)*
- `admin_can_change_auth_mode`
- `member_of_org_a_cannot_read_org_b` *(negative)*

**Pest — browser**
- `registration_and_org_creation_flow`
- `console_pages_have_no_js_errors`

## The diff

Read /Users/ruby/Documents/dev/artfct/scratchpad/runs/06/spec06.diff (5387 lines — it's large, this is a big spec; read the whole thing before judging anything, not just a sample) with the Read tool.

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

Keep your final answer under 1500 words total so it transmits without truncation — be terse per item, evidence-only, no restating the contract text back.
