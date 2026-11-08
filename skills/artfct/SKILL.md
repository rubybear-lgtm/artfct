---
name: artfct
description: This skill should be used when the agent has generated or is about to generate self-contained HTML, a dashboard, chart, data visualization, report, or interactive demo that the user needs to view or share. Triggered by user phrases like "show me", "preview this", "deploy this", "make it shareable", "send me a link", "I want to share this", or whenever producing visual HTML output. Covers the deploy_artifact MCP tool, sharing selection, and best practices for self-contained HTML.
---

# artfct — Artifact Deployment Skill

artfct publishes self-contained HTML to artfct.dev and returns a shareable URL. Call `deploy_artifact` when it is available (a signed-in workspace): the artifact is stored in the workspace, searchable and reusable by the team. Deploy instead of emitting raw code blocks — a live URL is more useful than a markdown code fence.

## When to Deploy

Call `deploy_artifact` any time the output is:

- A data visualization, chart, or dashboard
- A generated report or summary with styled layout
- An interactive demo or prototype
- An HTML email preview
- Any visual artifact the user wants to view, share, or bookmark

When the user says "show me", "preview this", or "make it shareable" — deploy.

## Deploying

**First, check** whether `deploy_artifact` (or `mcp__artfct__deploy_artifact`) is in your available tools. If it is not, tell the user to connect the artfct MCP server (see `references/setup.md`) — do not invent a fallback publish path.

Call `deploy_artifact`:

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `html` | string | Yes* | Complete, valid, self-contained HTML (single-page publishes) |
| `files` | object | Yes* | Multi-file bundle: relative path → contents (`entrypoint` names the main HTML) |
| `entrypoint` | string | No | Main HTML path in a bundle; defaults to `index.html` |
| `title` | string | No | Short name for search and the console (two to four words) |
| `description` | string | No | One sentence saying what the artifact is for |
| `sharing` | `"team"` \| `"private"` \| `"public"` | No | Who can open it; defaults to `team` |
| `edit_access` | `"view"` \| `"edit"` | No | Whether people it is shared with can publish new versions; defaults to `view` |
| `artifact_id` | string | No | Publish a new version of this artifact instead of a new one |
| `base_version` | integer | No | Version you built on; required with `artifact_id` when updating (see below) |

\* Pass either `html` or `files`, not both. Use `files` for anything interactive, with images, or with fonts (see HTML Requirements).

### Choosing who can open it

| `sharing` | Who can open it | Use when |
|-----------|-----------------|----------|
| `team` (default) | Everyone on the user's team | Work meant for colleagues |
| `private` | Only the user and team admins | Drafts, personal notes, anything the user has not decided to share |
| `public` | Anyone with the link | Content meant for people outside the team, with no sensitive data |

Default to `team`. Choose `public` only when the user asks for a link people
outside the team can open. If `deploy_artifact` returns
`public_sharing_disabled`, the team has turned public links off: publish with
`team` instead and tell the user. Set `edit_access: "edit"` only when the user
wants colleagues to be able to update it.

## Common Patterns

### Dashboard or report for the team
```json
{ "sharing": "team" }
```
Use for: weekly summaries, analytics dashboards, anything colleagues should find and reuse.

### Personal or sensitive content
```json
{ "sharing": "private" }
```
Use for: drafts, HR or financial material, anything only the user (and team admins) should see.

### Shared outside the team
```json
{ "sharing": "public" }
```
Use for: material the user explicitly wants to send to people outside the team.

## Finding Artifacts: List or Search

- **`search_artifacts`** finds artifacts by what they are about ("the Q3 churn
  analysis", "pricing page mockups").
- **`list_artifacts`** lists them by facts: who made them (`owner: "me"` or a
  teammate's email), when (`created_after`, `updated_after`), how they are
  shared (`sharing`), which collection, which AI tool (`agent`), title words
  (`title_contains`) or type (`kind`). Sort by `updated` (default), `created`,
  `title` or `most_viewed`. Use it for "what did I publish this week?", "our
  most-opened dashboards" or "everything shared publicly". Page with
  `next_cursor`.

Each listed item says whether the user can edit it (`can_edit`); use that
before offering to publish a new version.

## Updating an Artifact Instead of Duplicating It

When the user asks to change something already published (in this conversation
or found with `search_artifacts`), publish the new content as a **new version**:
call `deploy_artifact` with the same parameters plus `artifact_id` set to the
artifact's `id` and `base_version` set to the version you built on (from
`get_artifact` or `read_artifact`). The id, link, collections and view history
stay the same, and search moves to the new content. Sharing is kept from the
first publish unless you pass a new `sharing` value.

- The response carries `version`. `created: false` means the content was
  identical to the current version and nothing changed.
- `stale_base` means someone changed it since the version you built on. Read
  the live version with `read_artifact`, merge your change into it, and publish
  again with the new `base_version`. Do not retry the same publish.
- `edit_forbidden` means the caller may not update that artifact. Publish it as
  a new artifact instead (call `deploy_artifact` without `artifact_id`) and say so.
- `version_conflict` is retryable: someone published a version at the same
  moment. Retry once.
- `get_artifact` with `version` reads an earlier version; its `view_url` opens
  that version.

Only create a new artifact when the user wants a separate copy.

## When Not to Deploy

Skip `deploy_artifact` when the output is:

- A plain text answer, list, or code snippet — a code block is more appropriate
- A file the user intends to download and edit locally (CSV, JSON, PDF) — return the content directly
- A server-rendered page that requires backend calls to function — artfct is static-only

## Authoring for Quality

When generating the HTML payload, apply these defaults:

**Layout:** Use `max-width: 900px; margin: 0 auto; padding: 2rem` on `body` for readable line lengths. Avoid full-bleed text on wide viewports.

**Responsiveness:** Include `<meta name="viewport" content="width=device-width, initial-scale=1.0">` on every artifact.

**Typography:** Prefer a system font stack when no brand font is specified. Outside web fonts (including Google Fonts) are blocked on permanent artifacts — put font files in the bundle, or stick to system fonts.

**Color contrast:** Meet WCAG AA minimums — body text at ≥ 4.5:1 contrast ratio against background.

**Loading states:** For async-heavy visualizations, show a visible loading state rather than a blank frame while data initializes.

**Error messaging:** Handle data-loading errors gracefully in JS — display a user-visible error message rather than silently failing.

## Handling Errors

When `deploy_artifact` fails:

- **HTML validation error** — the payload contained invalid or empty HTML. Regenerate with a complete `<!DOCTYPE html>` document and retry.
- **Network/API error** — the artfct API was unreachable. Inform the user and offer to emit the raw HTML as a code block instead.
- **Size limit exceeded** — shrink assets or drop large base64 embeds; for images and libraries, use a `files` bundle instead of inlining.
- **MCP not available** — `deploy_artifact` is not in the tool list. Ask the user to connect artfct (see `references/setup.md`) rather than inventing another publish path.

## HTML Requirements

A permanent artifact (`deploy_artifact`) is served with a policy that loads only the artifact's own files, so plan for that:

- **CSS** → `<style>` in `<head>`; inline styles work
- **JavaScript** → a separate file in a bundle (`files`), loaded with `<script src="app.js">`. Inline `<script>` blocks and `onclick=` attributes do not run
- **Images** → files in the bundle. Base64 `data:` images and images from other sites are blocked
- **Fonts** → a system font stack, or font files in the bundle. Google Fonts and other outside web fonts are blocked
- **Libraries** → add the library file to the bundle. A CDN `<script src>` is blocked

A page with no script, no images and no outside fonts can be one `html` string; anything else is a bundle. Every file the page uses must be in the bundle: a path that is not in it will 404. `references/html-authoring.md` has the bundle layout (RUB-444).

## The Link You Hand Back

`deploy_artifact`, `get_artifact`, `list_artifacts` and `search_artifacts` return the openable
link as **`view_url`** — never reconstruct a `/p/{id}` URL from an artifact id
yourself. What it points at depends on the sharing level:

- Every level uses the same short link, `https://artfct.dev/a/<id>`: the
  app's artifact viewer, which shows the artifact under a header with its
  title, versions and (for people who may change it) a Share control.
- **`public`**: anyone with the link can open it, signed in or not.
- **`team` and `private`**: the viewer checks who is signed in each time it is
  opened. A signed-out visitor is asked to sign in and then lands back on the
  artifact; someone not allowed to see it gets "not found".
- A specific version is `https://artfct.dev/a/<id>/v/<n>`.

No credential travels in the link, so there is nothing to strip before sharing.

Present `view_url` verbatim. Do not shorten it, rewrite it to a direct artifact
origin, or hand over a token-bearing URL: a raw `/p/{id}` link cannot be opened
in a browser for a team or private artifact.

If a tool returns the non-retryable `signed_link_unavailable` code, that
environment has no signing secret configured and no openable link exists —
report that rather than inventing a URL.

## Response Format

After a successful deploy, present the `view_url` clearly:

```
Deployed → https://artfct.dev/a/4fA8gX9z

Opens for anyone signed in to the team.
```

For a public artifact:

```
Deployed → https://artfct.dev/a/4fA8gX9z

Public link — anyone with it can open it.
```

## Additional Resources

- **`references/html-authoring.md`** — Bundle layout, canonical HTML template notes, SRI hashes for vendored libraries
- **`references/setup.md`** — Step-by-step connection instructions for Claude Code, Codex, OpenCode and Antigravity (share with users who need to get set up)
