# Spec 22: OpenAI plugin listing (RUB-530 follow-up)

Source: RUB-530 plugin distribution research (2026-11-11) and its Phase 1 PR (#136). The Claude plugin repo (`artfct-dev/artfct-plugin`) already exists; this spec covers only what OpenAI requires beyond it. OpenAI's requirements below were read from its plugin docs on 2026-11-11 and must be re-checked against platform.openai.com/plugins at submission, since the portal changes.

Repo facts below were checked on 2026-11-12 against `origin/develop` at `03c4005` (includes #136), not the working branch. Production (`origin/main`) is behind develop: it has 26 files in `app/Mcp/Tools` and the `artifact-db` tools are live there. Re-run the audit commands in Tests against whichever ref gets submitted.

## Scope

In:
- Make the hosted OAuth server satisfy OpenAI's MCP-plugin auth contract: `openid` and `email` scopes, a UserInfo endpoint, discovery metadata for it.
- Serve the domain-verification file at `/.well-known/openai-apps-challenge`.
- Bring tool descriptions, error text and consent copy inside OpenAI's content rules (no pricing, plan, upgrade or checkout text).
- Decide and document the tool surface submitted (hints, delete tools, flagged tools).
- Provision a review demo account and write the listing assets (4 URLs, 5 positive and 3 negative test cases, a video script).
- Submit through the OpenAI portal and track review.

Out:
- Skills-only ChatGPT plugin (the portal converts the Claude manifest; separate and cheaper, can ship first).
- Client ID Metadata Documents. OpenAI lists `provided`, `dcr` and `cimd` modes; we already support DCR, so `cimd` stays off (`client_id_metadata_document_supported: false`).
- Claude, Grok and Cursor listings.
- Changing sign-in providers or billing.

## Decisions implemented

- RUB-530 decision 3: coding agents get plugins, web clients get connectors. A ChatGPT plugin with MCP is the connector path with a directory listing on top; it does not change the playbooks.
- Hosted MCP only (2026-10-04): the listed server is `https://artfct.dev/mcp`, with no second server.

## Design

### Gaps found in this repo (2026-11-12)

| OpenAI requires | Today | Change |
|---|---|---|
| OAuth with `openid` + `email` scopes | `McpScope` has 9 scopes (`artifacts:*`, `comments:write`, `collections:*`, `usage:read`, `data:read`, `data:write`), none for identity | Add identity scopes **outside** `McpScope` (see below) |
| UserInfo endpoint returning `email` and `email_verified: true` | None | New `GET /oauth/userinfo` |
| Domain file `/.well-known/openai-apps-challenge` | None | New route, value from config |
| Accurate readOnly/destructive/openWorld hints on every tool | All 25 tools (18 core + 7 `db_*`) carry all four hint attributes; `DatabaseTool` is an abstract base | None. Add a test that locks it |
| No pricing/plan/upgrade/checkout text | `PlanGateException` message: "…requires the Enterprise plan. Upgrade your team's plan to enable it." Returned by `SearchArtifactsTool` with code `plan_upgrade_required` and action `upgrade_plan`. `DeployArtifactTool:427`: "over its plan limits". `McpScope::UsageRead` label: "See usage and plan limits". `GetUsageTool` description says "quota limits". `AiToolSetup` connect copy says "paid plans" | Reword the messages (see below). The `/connect` copy matters because it is the listing's website URL |
| 4 listing URLs | `/terms`, `/privacy`, `/support` routes exist; website is `/connect` | Verify they render signed out and are not login-gated |
| Streamable HTTP, no hooks, no server headers, one server | Already true (`streamable-http`, one `Mcp::web('/mcp')`) | None |

### OAuth changes

Do **not** add `openid` and `email` as `McpScope` cases. `McpScope::values()` and `forRole()` feed far more than the OAuth metadata: the default JWT claim (`claimForRole` in `AuthenticateOrgToken`), request validation for org tokens, bot teammates and MCP connections (`Rule::in(McpScope::values())`), and the scope pickers in the console. Adding them there would put identity scopes into every default token and every scope picker. (The Worker is not the risk: `credential_has_scope` in `backend/src/auth.rs` only checks the one scope a route requires, so extra claims are ignored.)

Instead, keep a small separate list used only by `AuthorizationServerController`:
- `openid` (consent label "Confirm who you are") and `email` ("See your email address"), both read-risk, offered to every role.
- The authorize validation at line ~874 (`array_diff($scopes, McpScope::values())`) accepts `McpScope::values()` plus these two. The consent screen renders them.
- They go into an issued token's scope claim only when the client requested them. Tokens issued to Claude Code, Cursor and the plugin are unchanged.
- `scopes_supported` in both metadata documents lists them after the MCP scopes.

`GET /oauth/userinfo` (bearer access token, `throttle:auth`):
- 401 with `WWW-Authenticate: Bearer error="invalid_token"` for a missing, expired or revoked token. 403 `insufficient_scope` when the token lacks `openid`.
- Returns `{"sub": <stable user id>, "email": <only with email scope>, "email_verified": <bool>}`. `email_verified` is `true` only when `users.email_verified_at` is set; never hard-coded.
- Cache-Control `no-store`.

Metadata: add `userinfo_endpoint` to `authorizationServerMetadata()`. No `/.well-known/openid-configuration` alias: OpenAI's auth docs (checked 2026-11-12) say ChatGPT reads `/.well-known/oauth-authorization-server` or `/.well-known/openid-configuration` and the server must expose one of them. We already serve the first.

### Getting ChatGPT to request the right scopes

OpenAI's docs say only this about scopes (developers.openai.com/plugins/build/auth, 2026-11-12): OIDC scopes advertised in the authorization server metadata (`openid`, `email`, `profile`) are requested by default; per-tool `securitySchemes` should list the scopes you will request "so the consent screen is accurate"; `scopes_supported` in protected-resource metadata "helps ChatGPT explain" permissions; a missing scope should be signalled with a `WWW-Authenticate` challenge so ChatGPT re-authorizes. The docs **do not state** that ChatGPT requests non-OIDC custom scopes such as `artifacts:delete` or `data:write`, or where that list comes from.

What production does today (probed 2026-11-12): the `/mcp` 401 sends `WWW-Authenticate: Bearer realm="mcp", resource_metadata="…"` with no `scope`; `oauth-protected-resource/mcp` lists all 9 scopes. The authorize endpoint rejects a request with no scopes.

Plan, in order, with a gate:
1. **Spike first (staging, ChatGPT developer mode):** connect and record the exact `scope` parameter ChatGPT sends to `/oauth/authorize`. No code beyond a log line.
2. Regardless of the result, add the mechanisms the docs name: per-tool `_meta.securitySchemes` (`oauth2` with that tool's scope; the Laravel MCP `Tool` class supports `_meta`), a `scope="…"` parameter on the 401 challenge, and an `insufficient_scope` challenge (HTTP 403 with `WWW-Authenticate: Bearer error="insufficient_scope", scope="…"`) when a tool needs a scope the token lacks. Today `McpContext::requireScope` returns a tool-level error; confirm against `origin/develop` whether it also challenges, and change it only for the ChatGPT-reachable path if not.
3. **Gate:** if the spike plus step 2 still don't get ChatGPT to hold `artifacts:delete`, `data:read` and `data:write`, stop and bring the owner a decision. The fallback is for the authorize endpoint to treat a request carrying only identity scopes as a request for the role's default MCP scopes, shown explicitly on the consent screen. That widens grants for any client that sends only `openid email`, so it is not adopted without the owner's say-so.

Revocation: the existing revocation path (`oauth/revoke`, connection revoke) must make UserInfo return 401 for the revoked token. No new token store.

### Domain challenge

`Route::get('.well-known/openai-apps-challenge')` returns `text/plain` with `config('services.openai.apps_challenge')` and 404 when the value is empty. The value comes from the portal at submission time, set through an environment variable (pinchpass for the secret hand-off, per the staging/prod convention). Production and staging use separate values; only production is submitted.

### Content rules

Reword the message strings a ChatGPT user can reach: tool descriptions, error `message` fields, guidance resources, consent labels, and the `/connect` page the listing links to. OpenAI's rule, as recorded in the research, is about pricing, plan, upgrade and checkout text; it says nothing about machine-readable error codes.
- `PlanGateException` message becomes "{feature} isn't available for this workspace. Ask a workspace admin." (no plan name, no "Upgrade").
- `DeployArtifactTool:427` becomes "This workspace has reached a limit. Use get_usage to see which."
- `McpScope::UsageRead` label becomes "See workspace limits". `GetUsageTool` description drops "quota" wording only if the grep in Tests flags it.
- `/connect` copy for ChatGPT: remove "paid plans". If that sentence is needed for non-ChatGPT users, serve the listing a plan-free URL instead.
- The error **codes** (`plan_upgrade_required`, `quota_exceeded`) and the action `upgrade_plan` stay as they are in this spec. Renaming them is a breaking contract change for every client and is listed under Open questions.
- Tool names, descriptions and the listing name contain no "MCP" or "Plugin" and are not generic. The listing name is "Artfct".

Changing message strings still changes pinned output: update the `tests/Fixtures` the contract test reads and note it in `CHANGELOG.md`.

### Tool surface submitted

- 25 tool classes are registered on develop: 18 core and 7 `db_*` (`db_get`, `db_list`, `db_schema`, `db_set`, `db_update`, `db_delete`, `db_batch`). The `db_*` tools register only for teams where the `artifact-db` flag is released, and need the `data:read` / `data:write` scopes. They are live in production for the pilot teams. What ChatGPT sees therefore depends on the review team's flag, so the submission must say which.
- All 25 carry `readOnly`, `idempotent`, `destructive` and `openWorld`. The destructive ones are `delete_artifact`, `delete_collection`, `db_delete` and `db_batch`.
- `deploy_artifact` stays `openWorld: true`. Its `sharing` input accepts `public` ("anyone with the link"), so `false` would be inaccurate and a reviewer can test it. Document the reason in the submission notes. Do not flip the hint to pass review.
- Owner decision (2026-11-12): the submission includes the delete tools and the `db_*` tools. So the review account's team must have the `artifact-db` flag released, and the ChatGPT connector must be able to request every scope the 25 tools need (`artifacts:delete`, `data:read`, `data:write` among them). The submitted `tools/list` is all 25; the docs page and server card must agree.
- The scope spike (see "Getting ChatGPT to request the right scopes") gates this decision. If ChatGPT cannot be made to hold `artifacts:delete`, the delete tools would be listed and always fail, and the owner revisits it.
- The destructive tools (`delete_artifact`, `delete_collection`, `db_delete`, `db_batch`) are the ones a reviewer is most likely to exercise. Their descriptions and hints must say plainly what they remove, and the review notes must list them.
- A reviewer who runs a delete case removes data from the demo team. Every positive delete case acts on an artifact the same run created, and the demo team is re-seeded before each review pass.

### Review assets (no code)

- Listing URLs: website `https://artfct.dev/connect`, support `/support`, privacy `/privacy`, terms `/terms`.
- Demo account: a dedicated user and team on production with sample artifacts. Checked in the production WorkOS environment on 2026-11-12: password sign-in is on, MFA is `Off`, sign-up is allowed, and email verification is required. So an email-and-password demo user works, but it must be created and its email verified before submission (`email_verified_at` must be set for UserInfo to report `true`). The team must not be a `polis` team, because `TeamSignIn` restricts those to their SSO. Credentials go through pinchpass, never into the repo or Linear.
- Test cases, positive (OpenAI asks for at least 5): search for a topic and get cited results; read one artifact by id; list recent artifacts; publish a new artifact and open its link; comment on an artifact; delete the artifact just published; write and read back one `db_set` / `db_get` row on a seeded demo artifact that has a database. Negative (at least 3): ask for a deleted or other-team artifact id and get "not found"; publish with an invalid body and get a clear error; send a `db_batch` with a stale `if_version` and see that no write applies.
- Video: one run through connect, sign-in, and the positive cases, under the length the portal states.

## Definition of done

- `GET /.well-known/oauth-authorization-server` lists `userinfo_endpoint`, and `scopes_supported` includes `openid` and `email`.
- A client that registers via DCR, runs PKCE with scope `openid email artifacts:read`, and calls `/oauth/userinfo` with the access token gets `sub`, `email` and `email_verified: true` for a verified user, and `email_verified: false` for an unverified one.
- The same call with a token that lacks `openid` gets 403; with a revoked or expired token gets 401.
- A Claude Code connection using its existing scope string still completes sign-in unchanged.
- `GET https://artfct.dev/.well-known/openai-apps-challenge` returns the portal-issued value; the same path returns 404 on a deployment with no value configured.
- No tool description, error `message`, guidance text, consent label or `/connect` sentence reachable by a ChatGPT user contains the words plan, upgrade, pricing, price, checkout, billing, subscription or a currency amount (checked with a word-boundary grep against live `tools/list` output, the guidance resources and the rendered consent page). Error codes such as `plan_upgrade_required` are not message text and are not covered by this check.
- A token issued for a client that requested only `artifacts:read` carries no `openid` or `email` scope, and `McpScope::values()` is unchanged (9 scopes).
- Every tool in `tools/list` for the review account returns all four annotation hints, and `deploy_artifact` returns `openWorldHint: true`. The listed tool count is 25 and equals the count the submission states, with the `artifact-db` flag released for the review team.
- A ChatGPT session signed in as the demo account can delete an artifact it just published, and can write and read back a `db_set` row; a `db_batch` with a stale `if_version` applies nothing.
- The consent screen shown to the ChatGPT client lists the delete and data-write scopes with their risk labels.
- A fresh ChatGPT workspace can add the submitted plugin, sign in as the demo account, and complete the 5 positive test cases; the 3 negative cases fail as written.
- The portal shows the submission as submitted, and the review outcome is recorded on the Linear issue.
- Reversibility: server changes are additive apart from reworded message strings, which revert with the commit. The listing can be withdrawn in the portal.

## Tests

Pest, in `tests/Feature/McpOAuthTest.php` (or a new `OAuthUserInfoTest.php`) and `McpContractTest.php`:
- `authorization_server_metadata_advertises_userinfo_and_identity_scopes`
- `userinfo_returns_sub_email_and_verified_for_openid_email_token`
- `userinfo_omits_email_without_email_scope`
- `userinfo_reports_unverified_email_as_false`
- `userinfo_rejects_missing_expired_and_revoked_tokens` (negative)
- `userinfo_rejects_token_without_openid_scope` (negative)
- `userinfo_does_not_leak_another_users_email` (negative: token for user A never returns user B)
- `viewer_role_can_grant_identity_scopes`
- `identity_scopes_are_not_in_mcp_scope_values_or_default_claims` (guards the design: org tokens, bot teammates and scope pickers never offer `openid` or `email`)
- `identity_scopes_appear_in_token_only_when_requested`
- `every_tool_declares_oauth_security_scheme_with_its_scope`
- `mcp_401_challenge_includes_scope_parameter`
- `missing_scope_returns_insufficient_scope_challenge` (negative: a token without `artifacts:delete` calling `delete_artifact`)
- `existing_scope_strings_still_authorize` (Claude Code's current request)
- `openai_challenge_returns_configured_value_as_plain_text`
- `openai_challenge_is_404_when_unconfigured`
- `every_registered_tool_declares_all_four_hints` (build the catalog with the `artifact-db` flag on, so the `db_*` tools are included)
- `deploy_artifact_declares_open_world`
- `mcp_surface_contains_no_pricing_or_plan_wording` (walks tool descriptions, schema descriptions, scope labels, and `McpErrorResponse` message strings; ignores error codes)
- `plan_gate_message_has_no_plan_name_or_upgrade_text`
- `contract_fixtures_match_after_message_rewording`

Audit commands to re-run at submission against the exact ref being submitted (use `git show <ref>:path` or a worktree, since the working branch may be stale): count `app/Mcp/Tools/*Tool.php` and confirm each has the four `#[Is…]` attributes; word-boundary grep (`\b(upgrade|pricing|checkout|plans?|paid)\b`) across `app/Mcp`, `resources/guidance`, `app/Enums/McpScope.php` and `app/Support/AiToolSetup.php`.

Manual, once, before submission: DCR + PKCE run against staging with `openid email`; ChatGPT workspace add-and-use run on production with the demo account, recorded as the review video.

## Open questions for the owner

Decided (2026-11-12): delete tools and `db_*` tools are included in the submission. The four earlier open points:

1. **Scopes ChatGPT requests: not answerable from docs.** OpenAI documents only OIDC scopes being requested by default (see "Getting ChatGPT to request the right scopes"). The staging spike decides it; if it fails, the owner decides on the fallback. This is the only point that can still block the plan.
2. **Error codes: do not rename.** `plan_upgrade_required` and `upgrade_plan` are machine codes shared with every client. OpenAI's auth page states no rule on error wording or pricing text; the no-pricing/plan rule comes from the submission requirements recorded in the research file, and it governs text. Reword messages, keep codes. Revisit only if review flags a code.
3. **`get_usage`: keep.** It is read-only and honest, no listed rule forbids it, and removing it for one client adds a per-client tool catalog we would have to maintain.
4. **`openid-configuration` alias: dropped.** ChatGPT reads either that or `oauth-authorization-server`, and we serve the latter.

## Rollback

- Server changes ship behind no flag because they are additive; revert the PR to remove them.
- The message rewording is a separate commit, so it can be reverted alone if a client matched on the old text.
- Withdraw or unpublish the listing in the OpenAI portal. Nothing stored changes, and no issued URLs change.

## Deferred

- Skills-only ChatGPT plugin from the Claude manifest (RUB-453).
- CIMD support.
- Hiding `get_usage`, the delete tools or the `db_*` tools per client, if review pushes back.
- Claude directory, Grok and Cursor submissions (tracked under RUB-530/RUB-453).
- Re-reading OpenAI's requirements at submission time; this spec records them as of 2026-11-11.
