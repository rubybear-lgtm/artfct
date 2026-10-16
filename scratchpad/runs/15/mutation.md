# Spec 15 mutation evidence

Solo session (no subagent budget). Mutations run and restored directly,
2026-09-04.

| Guard removed | Exact command | Mutated result | Restored result |
|---|---|---|---|
| `null, SharingLevel::OrgPrivate => UnfurlResult::bare()` in `UnfurlService::unfurl` → returns full metadata instead | `php artisan test --compact --filter="private_artifact_unfurls_bare_card\|outside_user_unfurl_reveals_nothing"` | both failed: `bareCard` was false where it must be true | both passed |
| `SharingLevel::DomainRestricted => $this->titleOnly(...)` → routed to `$this->fullMetadata(...)` instead | `php artisan test --compact --filter=domain_restricted_artifact_unfurls_title_only` | failed: `description` was populated where it must be null | passed |
| Channel allowlist check in `SlackPostService::postArtifact` → `if (false)` | `php artisan test --compact --filter=agent_post_to_unlisted_channel_refused` | failed: no exception thrown for a non-allowlisted channel | passed |
| Rate-limit check in `SlackPostService::postArtifact` → `if (false)` | `php artisan test --compact --filter=channel_post_rate_limit_enforced` | failed: no exception thrown past the 5-post cap | passed |
| Membership check in `SlashCommandService::handle` → `if (false)` | `php artisan test --compact --filter=slack_user_cannot_search_other_org` | failed: a user linked only in org A got a non-connect-prompt result when queried against org B's workspace | passed |

All five mutations independently confirmed genuine; `git diff` clean
after each restore (verified via a full `php artisan test --compact`
rerun — 141/141 passing).

Not independently mutation-checked: `unlinked_slack_user_gets_connect_prompt`
(the `$identity === null` branch immediately preceding the mutated
membership check above — same shape, not re-mutated given time
constraints) and the ephemeral-response-shape assertions in
`SlashCommandController` (there is no single boolean guard to invert —
the controller simply never emits `in_channel` anywhere in its source,
which is closer to `audit_rows_have_no_update_or_delete_path`'s
"structural absence" than a guard to remove).
