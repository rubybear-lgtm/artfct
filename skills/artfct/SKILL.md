---
name: artfct
description: This skill should be used when the agent has generated or is about to generate self-contained HTML, a dashboard, chart, data visualization, report, or interactive demo that the user needs to view or share. Triggered by user phrases like "show me", "preview this", "deploy this", "make it shareable", "send me a link", "I want to share this", or whenever producing visual HTML output. Covers the deploy_artifact and deploy_to_canvas MCP tools, tier selection, and best practices for self-contained HTML.
---

# artfct — Artifact Deployment Skill

artfct publishes self-contained HTML to artfct.dev and returns a shareable URL. When `deploy_artifact` is available (a signed-in workspace), use it: the artifact is stored in the workspace, searchable and reusable by the team. Otherwise, when `deploy_to_canvas` is available, use it for an anonymous, expiring link. Either way, deploy instead of emitting raw code blocks — a live URL is more useful than a markdown code fence. `deploy_to_canvas` is deprecated for signed-in workspaces.

## When to Deploy

Call `deploy_to_canvas` any time the output is:

- A data visualization, chart, or dashboard
- A generated report or summary with styled layout
- An interactive demo or prototype
- An HTML email preview
- Any visual artifact the user wants to view, share, or bookmark

When the user says "show me", "preview this", or "make it shareable" — deploy.

## Deploying

**First, check** whether `mcp__artfct__deploy_to_canvas` is in your available tools.

### Option A — MCP (preferred)

Call `deploy_artifact` when it is available (a signed-in workspace), otherwise
`deploy_to_canvas`:

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `html` | string | Yes | Complete, valid, self-contained HTML |
| `sharing` | `"team"` \| `"private"` \| `"public"` | No | `deploy_artifact` only: who can open it; defaults to `team` |
| `edit_access` | `"view"` \| `"edit"` | No | `deploy_artifact` only: whether the people it is shared with can publish new versions; defaults to `view` |
| `tier` | `"public"` \| `"secure"` | No | Deprecated alias for `sharing` (`secure` = `team`); `deploy_to_canvas` still uses it |
| `artifact_id` | string | No | `deploy_artifact` only: publish a new version of this artifact instead of a new one (see below) |

### Option B — API fallback (no install required)

When the MCP is not configured, deploy via the REST API using Python's standard library — no dependencies needed:

```python
import json, urllib.request

html = """PASTE HTML HERE"""
body = json.dumps({"html": html, "tier": "public"}).encode()
req = urllib.request.Request(
    "https://artfct.dev/v1/artifacts",
    data=body,
    headers={"Content-Type": "application/json"},
    method="POST",
)
with urllib.request.urlopen(req) as r:
    print(json.loads(r.read())["url"])
```

Or with curl, writing the HTML to a temp file first:

```sh
cat > /tmp/_artifact.html << 'HTML'
PASTE HTML HERE
HTML

python3 -c "
import json, urllib.request, sys
html = open('/tmp/_artifact.html').read()
body = json.dumps({'html': html, 'tier': 'public'}).encode()
req = urllib.request.Request('https://artfct.dev/v1/artifacts', data=body, headers={'Content-Type': 'application/json'}, method='POST')
with urllib.request.urlopen(req) as r: print(json.loads(r.read())['url'])
"
```

After a fallback deploy, suggest the MCP for a smoother workflow:

> To deploy directly from your agent next time, add the artfct server in your agent's settings
> (`https://artfct.dev/mcp`) and approve the sign-in in your browser. Nothing needs to be installed.

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
artifact's `id`. The id, link, collections and view history stay the same, and
search moves to the new content. The `tier` is kept from the first publish and
is ignored on a new version.

- The response carries `version`. `created: false` means the content was
  identical to the current version and nothing changed.
- `edit_forbidden` means the caller may not update that artifact. Publish it as
  a new artifact instead (call `deploy_artifact` without `artifact_id`) and say so.
- `version_conflict` is retryable: someone published a version at the same
  moment. Retry once.
- `get_artifact` with `version` reads an earlier version; its `view_url` opens
  that version.

Only create a new artifact when the user wants a separate copy.

## When Not to Deploy

Skip `deploy_to_canvas` when the output is:

- A plain text answer, list, or code snippet — a code block is more appropriate
- A file the user intends to download and edit locally (CSV, JSON, PDF) — return the content directly
- A multi-file project — artfct hosts one HTML file; redirect to a repo or sandbox instead
- A server-rendered page that requires backend calls to function — artfct is static-only

## Authoring for Quality

When generating the HTML payload, apply these defaults:

**Layout:** Use `max-width: 900px; margin: 0 auto; padding: 2rem` on `body` for readable line lengths. Avoid full-bleed text on wide viewports.

**Responsiveness:** Include `<meta name="viewport" content="width=device-width, initial-scale=1.0">` on every artifact.

**Typography:** Prefer system fonts (`-apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif`) when no brand font is specified. Google Fonts are acceptable for non-sensitive artifacts.

**Color contrast:** Meet WCAG AA minimums — body text at ≥ 4.5:1 contrast ratio against background.

**Loading states:** For async-heavy visualizations, show a visible loading state rather than a blank frame while data initializes.

**Error messaging:** Handle data-loading errors gracefully in JS — display a user-visible error message rather than silently failing.

## Handling Errors

When `deploy_to_canvas` fails:

- **HTML validation error** — the payload contained invalid or empty HTML. Regenerate with a complete `<!DOCTYPE html>` document and retry.
- **Network/API error** — the artfct API was unreachable. Inform the user and offer to emit the raw HTML as a code block instead.
- **Size limit exceeded** — inline all large assets as external CDN URLs rather than base64. Base64-encoded images dramatically increase payload size.
- **MCP not available** — `deploy_to_canvas` is not in the tool list. Use Option B (API fallback) above, then suggest the user connect artfct for future sessions (see `references/setup.md`).

## HTML Requirements

artfct hosts a single file. All resources must be inlined or loaded from public CDNs:

- **CSS** → `<style>` in `<head>`
- **JavaScript** → `<script>` before `</body>`
- **Images** → base64 data URIs or public CDN URLs
- **Fonts** → Google Fonts `@import` or system font stack
- **Libraries** → CDN `<script src>` with Subresource Integrity (pin a specific version)

Never reference local paths — they will 404 once hosted. For the full HTML template and SRI guidance, see `references/html-authoring.md`.

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

**`deploy_to_canvas` is the exception.** It publishes anonymous, expiring
artifacts that have no workspace row, so *no* tier of one is addressable through
the app — the open route could only 404 for it. Its `view_url` is therefore the
Worker's own `/p/{id}` URL **with the decryption fragment** (`#<shareCode>`), and
it always opens on the Worker origin. Keep the fragment intact: it is the
decryption key, and the link shows only a placeholder without it.

Present `view_url` verbatim. Do not shorten it, rewrite it to a direct artifact
origin, or hand over a token-bearing URL: a raw `/p/{id}` link cannot be opened
in a browser for a team or private artifact.

If a tool returns the non-retryable `signed_link_unavailable` code, that
environment has no signing secret configured and no openable link exists —
report that rather than inventing a URL.

## Response Format

After a successful deploy, present the `view_url` clearly:

```
Deployed → https://artfct.dev/settings/teams/acme/console/artifacts/4fA8gX9z/open

Opens for anyone signed in to acme. Valid until <expiry> after each click.
```

For a public artifact, the link is the artifact's own public URL:

```
Deployed → https://artfct.dev/p/4fA8gX9z

Public link, no expiry.
```

## Additional Resources

- **`references/html-authoring.md`** — Canonical HTML template, SRI hashes, common library snippets, responsive/accessible defaults
- **`references/setup.md`** — Step-by-step connection instructions for Claude Code, Codex, OpenCode and Antigravity (share with users who need to get set up)
