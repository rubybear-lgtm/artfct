You are reviewing a code change in the artfct repository. You have no prior
context and you should not seek any. Do not read the conversation history. Do
not look for design documents. Judge only what is in front of you.

Notes on scope, stated up front so you don't penalize deliberate decisions:
- Item 3's "documented propagation window" is closed by documentation plus a
  test of the denylist check itself, not a real-time wait — that's fine, PASS
  it if the denylist check is genuinely enforced.
- Item 10 ("no origin round-trip — verified in a trace") is closed
  structurally: the claim is that the JWT-verification code path takes no
  `&Env`/fetch capability and cannot reach the network, not that a real
  production trace was captured. PASS it if the diff supports that structural
  claim; don't fail it for lacking a real trace.
- This diff intentionally only wires the new credential seam into artifact
  creation and a new read-metadata endpoint. Four pre-existing permanent-
  artifact endpoints (resolve/upload/delete/export) are NOT touched and still
  use the prior spec 03/05 auth mechanism. This is a stated scope boundary,
  not an oversight — do not fail any DoD item solely because those four
  endpoints weren't migrated; the DoD items below don't ask for that.

## The contract — the change must satisfy every item

- [ ] `artfct deploy ./x.html --tier permanent` with a valid org token creates an artifact owned by that token's org.
- [ ] The same request with no credential is rejected with 401 and `code: authentication_required`.
- [ ] Revoking the token in the console causes the next deploy to fail within the documented propagation window.
- [ ] A JWT for org A requesting an artifact belonging to org B returns 404 — **not 403**, so existence is not disclosed. The artifact must genuinely exist and belong to org B in the test — verify this explicitly, it's the item most likely to be accidentally vacuous.
- [ ] An expired JWT is rejected at the edge without reaching Laravel.
- [ ] A JWT signed with the wrong key is rejected.
- [ ] A request supplying `org_id` for another org in its body is ignored and resolves to the credential's org.
- [ ] Exceeding the per-token rate limit returns 429 while a different token in the same org is unaffected at its own limit.
- [ ] Anonymous ephemeral creates still work and are still limited per-IP.
- [ ] Worker latency on the authenticated path shows no origin round-trip — verified in a trace. (See scope note above.)

## Tests that must exist

**`backend`**
- `valid_org_token_resolves_org`
- `missing_credential_rejected` *(negative)*
- `revoked_token_is_rejected_at_edge` *(negative)*
- `expired_jwt_rejected` *(negative)*
- `jwt_with_wrong_signature_rejected` *(negative)*
- `jwt_for_org_a_cannot_read_org_b` *(negative)*
- `cross_org_read_returns_404_not_403` *(negative)*
- `org_id_in_body_is_ignored`
- `rate_limit_keyed_on_token_not_ip`
- `anonymous_path_still_rate_limited_by_ip`
- `jwks_cached_in_kv`

**Pest — feature**
- `token_creation_returns_value_once_only`
- `token_revocation_writes_denylist`
- `member_cannot_revoke_another_users_token` *(negative)*

## The diff

Read /Users/ruby/Documents/dev/artfct/scratchpad/runs/07/spec07.diff (1525 lines) with the Read tool before judging anything — the whole thing, not a sample.

## Your report

For EACH DoD item above, report exactly one of:

- PASS — with the specific evidence in the diff that makes it true
- FAIL — with what is missing or wrong
- CANNOT VERIFY — the item is not observable from this diff

Then, for the tests:

- Which named tests are present, and which are missing
- For each test marked (negative): does it actually exercise the failure path,
  or would it pass even if the protection were removed? Quote the assertion.
  Pay particular attention to `cross_org_read_returns_404_not_403` and
  `jwt_for_org_a_cannot_read_org_b` — confirm the test data genuinely has an
  existing row owned by a different org, not merely a missing row.

Finally, note anything in the diff that is NOT required by the contract. Extra
work is scope creep and is worth flagging even when it looks harmless.

Do not suggest improvements outside the contract. Do not comment on style —
formatters and linters have already run. Your job is: does this diff satisfy
this contract, item by item.

Keep your final answer under 1500 words so it transmits without truncation.
