# Spec 15 — Slack

**Track:** F (thesis) · **Depends on:** 8, 11, 13 · **Blocks:** nothing

## Scope

**In.** A Slack app: link unfurls, a search slash command, and agent-to-channel posting. Unfurl richness scaled to the artifact's sharing level.

**Out.** Slack as an auth provider. Slack Canvas integration.

## Why this is core, not a nice-to-have

Slack is where sharing already happens — artfct links get pasted there today. And for **business users it is the entire sharing surface**: a PM will never open a console to find an artifact.

It is also distribution. An artifact shared in a channel is seen by people who never installed the CLI, which is organic pull the free tier alone will not generate.

The metadata design already anticipated this. The Worker stores title, description and thumbnail as *public* metadata specifically so previews work while the payload stays encrypted — that is exactly what an unfurl consumes.

## Decisions implemented

- **01** — public metadata separate from artifact content, which is what makes an unfurl possible without decryption.

## Design

### Unfurls, and the privacy constraint

**A Slack unfurl renders to everyone in the channel** — including guests and people outside the artifact's share scope. Rendering title, description and thumbnail for an org-private artifact is a metadata leak in a governance product.

Richness therefore scales with sharing level:

| Artifact sharing | Unfurl shows |
|---|---|
| Public / link-with-passcode | title, description, thumbnail, provenance summary |
| Domain-restricted | title only, plus "sign in to view" |
| Org-private | **bare card: "artfct artifact — sign in to view"**. No title, no thumbnail |

Decided here, not patched after a customer notices.

### Search command

`/artfct <query>` — the human counterpart to spec 13's `search_artifacts` MCP tool, same backend, same ranking. Results are **ephemeral messages** visible only to the caller, because search results reveal titles across the org and a channel-visible response would leak them.

Slack identity maps to an artfct user through `external_identities` (spec 6). An unmapped Slack user gets a link-to-connect prompt, never results.

### Agent posting

An agent that deployed an artifact can post it to a channel, given an org-scoped token with a channel allowlist. Rate-limited per channel — an agent in a loop must not be able to flood a channel.

## Definition of done

- [ ] Pasting a public artifact link in a channel unfurls with title, description and thumbnail.
- [ ] Pasting an **org-private** artifact link unfurls to a bare card with no title and no thumbnail.
- [ ] A domain-restricted artifact unfurls with title only.
- [ ] A user outside the org pasting a link they somehow obtained sees the bare card.
- [ ] `/artfct billing dashboard` returns matching artifacts as an ephemeral message.
- [ ] Search results are not visible to other channel members.
- [ ] A Slack user with no linked artfct identity gets a connect prompt, not results.
- [ ] A Slack user linked to org A gets no results from org B.
- [ ] An agent posts an artifact to an allowlisted channel; posting to a non-allowlisted channel is refused.
- [ ] Exceeding the per-channel post rate limit is refused, not queued.
- [ ] Every unfurl and search writes an audit event (spec 11).

## Tests

**Pest — feature**
- `public_artifact_unfurls_with_full_metadata`
- `private_artifact_unfurls_bare_card` *(negative)*
- `domain_restricted_artifact_unfurls_title_only` *(negative)*
- `outside_user_unfurl_reveals_nothing` *(negative)*
- `slash_command_returns_ephemeral_results`
- `search_results_not_visible_to_channel` *(negative)*
- `unlinked_slack_user_gets_connect_prompt` *(negative)*
- `slack_user_cannot_search_other_org` *(negative)*
- `agent_post_to_unlisted_channel_refused` *(negative)*
- `channel_post_rate_limit_enforced` *(negative)*
- `unfurl_and_search_are_audited`

## Rollback

Reversible. Removing the Slack app stops unfurls; no stored artifact changes.

## Deferred

- Slack as an identity provider.
- Posting artifact updates when a version supersedes another.
