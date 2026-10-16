# Spec 15 partition

Solo session (no subagent budget since spec 08). One unit. Slack is an
external service per the user's mock-only decision (same category as
specs 10/14).

## Closable honestly in this environment

- `UnfurlService::unfurl()`: richness is a pure function of
  `ArtifactSharingContract::sharingLevelFor()` — no viewer parameter at
  all, since Slack fetches and caches an unfurl once per URL, not once
  per viewer. All three DoD rows (public/domain-restricted/org-private)
  and the "outside user" case (same bare card, not a different shape)
  are the same code path — mutation-checked for the OrgPrivate and
  DomainRestricted branches.
- `SlashCommandService::handle()`: resolves the Slack workspace to a
  `Team` via `teams.slack_workspace_id` (unique), the Slack user to a
  `User` via `external_identities` (provider `slack`), and — critically —
  requires that user to actually be a *member of that team*, not just
  linked anywhere, before running `SearchService::search()`. That
  membership check is what makes "linked to org A, workspace is org B"
  fail closed rather than accidentally succeed; mutation-checked.
- `SlackPostService::postArtifact()`: allowlist check (mutation-checked)
  and a `RateLimiter`-backed per-channel cap (mutation-checked) — both
  refuse synchronously, never queue.
- `unfurl_and_search_are_audited`: unfurl audits via
  `AuditEventType::ArtifactViewed` (actor `slack:unfurl`); search reuses
  spec 13's `search.performed` unchanged, since a Slack-originated search
  is still just a `SearchService::search()` call.
- `SlashCommandController`'s ephemeral response shape
  (`response_type: ephemeral`, never `in_channel`) is what actually
  proves search results stay private to the caller — real and tested.

## Structural / documented, not fully wired

- **Slack request-signature verification is not implemented.** Slack
  signs every inbound request (slash commands, Events API) with an
  HMAC over the raw body + timestamp against a live app's signing
  secret; there is no live Slack app here to verify a real signature
  against. `SlashCommandController` accepts any well-formed POST — see
  its docblock. This is a security-relevant gap for a real deployment
  and is named as such, not glossed over.
- **No Events API `link_shared` webhook.** `UnfurlService` is real,
  tested, callable code; the HTTP route Slack's Events API would call it
  from was not built (would need the same signature-verification gap
  closed first, plus Slack's URL-verification handshake).
- **No live Slack workspace/app install flow.** `slack_workspace_id`
  and `external_identities` (provider `slack`) are populated directly by
  tests; the OAuth install flow that would set them for real was not
  built (same category as spec 6's WorkOS AuthKit flow, which *is*
  built — Slack's own OAuth is a distinct, separate integration this
  spec's Slack app would need, out of scope here per the mock-only
  decision).

## Not closable here

- Anything requiring a live Slack workspace to visually confirm (actual
  rendered unfurl cards, actual slash-command UX in a real client).
