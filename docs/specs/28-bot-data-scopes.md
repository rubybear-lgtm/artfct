# Spec 28 — Bot agent tokens get data:read and data:write (RUB-572)

Part of epic RUB-551. Size: S.

## What and why

A bot teammate's agent token carries the scopes it was minted with. The default sets for new bot tokens omit `data:read` and `data:write`, so bots cannot read or write artifact data (`db_*` tools fail with `insufficient_scope`). The role model already allows members to hold both scopes (`McpScope::forRole`, the Worker's `credential_has_scope`). The gap is the defaults, not enforcement.

## Where the defaults live

Four copies of the same default list:
- `app/Http/Controllers/Teams/TeamController.php` (`botDefaultScopes`, the form's preselection)
- `app/Actions/Teams/RollBotAgentToken.php` (default when rolling with no explicit scopes)
- `app/Http/Controllers/Teams/BotTeammateController.php` (default when creating a bot with no explicit scopes)
- `app/Actions/Teams/CreateBotTeammate.php` (fallback for direct callers of the action)

Not changed: `app/Http/Controllers/Teams/OrgTokenController.php` `defaultScopes`. That list is for human and org API tokens, not bots, so its scopes are a separate decision.

## Decisions

1. Add `data:read` and `data:write` to all three defaults. Members get both; viewers are capped by `forRole` (viewers keep `data:read` only, unchanged).
2. Existing tokens are NOT silently widened. The re-issue path is the API: `POST settings/teams/{team}/bots/{user}/roll` with an explicit `scopes` list including `data:read` and `data:write`. The team settings roll dialog sends no scopes, so from the UI a roll keeps the old set; a UI scope picker is out of scope here and is named in the PR.
   Edge case: a roll falls back to the defaults, which now include the data scopes, whenever no unexpired token exists. That includes a bot whose narrow token has simply expired, so a plain Roll in the UI can grant `data:write` to a bot that never had it. The spec accepts this as an explicit admin action on a bot with no live credential; a stricter variant (derive the fallback from the latest non-revoked token regardless of expiry) is a follow-up if owners object. `RollBotAgentToken` already reuses the previous token's scopes when none are given, so an old token keeps its old scopes. An upgrade is an explicit roll: an admin rolls the token with `scopes` including `data:read` and `data:write`. Silent escalation of live credentials is refused.
3. Free plan keeps no bot access: the existing `plan_required` gate in `BotTeammateController` is untouched.
4. Grantability is unchanged: `data:*` already appear in `McpScope::values()` and `forRole`, so an explicit choice already works.

## Done-when → proof

| Done when (RUB-572) | Proof |
|---|---|
| Bot token on an eligible plan can `db_schema` and read rows | Feature test: a new bot's minted token carries `data:read`; `db_list` on a team artifact returns rows (Worker fake) |
| `data:write` grantable and enforced; writes refused without scope or edit access | Feature test: token without `data:write` gets `insufficient_scope` on `db_set`; with it, the write is sent to the Worker |
| Existing tokens have a clear re-issue path | Feature test: rolling with explicit `data:*` scopes mints a token that carries them; rolling without scopes keeps the old set (no silent widening) |
| Free plan remains without bot access | Existing `plan_required` test on bot creation still passes |
| Backend tests cover grant, deny, cross-team isolation, plan gating | Grant/deny tests above; cross-team: a token minted for team A cannot read team B's artifact data (existing isolation assertions in `tests/Feature/ArtifactDataTest` or equivalent) |
| No secrets logged or committed | Not changed by this issue; check the diff has no token material |
| Staging check | Not run here: needs merged code. After-merge step in PR. |

## Non-goals

- No change to the Worker, the role model or `McpScope`.
- No automatic upgrade of existing bot tokens.
- The env `ARTFCT_BOT_TOKEN` (a pre-existing token) is re-issued by an admin, not by this change.

## Test plan (TDD)

1. Red: a bot created with no explicit scopes has `data:read` and `data:write` in `scopes` (`BotTeammateTest`); the form default (`TeamController`) includes both; a roll with no scopes keeps the previous set.
2. Green: add the two scopes to the three default lists.
3. Negative: remove `data:write` from a token and confirm `db_set` is refused; restore.
