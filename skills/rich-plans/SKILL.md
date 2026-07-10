---
name: rich-plans
description: This skill should be used when the agent creates or is asked to create an architecture plan, implementation plan, project roadmap, design specification, technical proposal, sprint plan, migration strategy, or any structured multi-section planning document. Instead of dumping a markdown plan into the chat, render it as a rich, navigable HTML document and deploy via artfct's deploy_to_canvas so the user can review it in their browser with collapsible sections, status badges, and visual hierarchy. Triggered by phrases like "create a plan for...", "design an architecture for...", "write up a spec...", "proposal for...", "how should we implement...", "roadmap for...", "break this down into steps...", or any request where the answer is a structured, multi-section document meant for review.
---

# Rich Plans Skill

Render structured plans as rich, navigable HTML documents and deploy them via artfct — so the user reviews them in a browser instead of scrolling through a code block. The template handles section hierarchy, status tracking, risk callouts, and dependency sequencing.

## When to Use This Skill

Use this skill whenever the agent's response is a **structured planning document** — not a quick answer, not a code snippet, but a document the user needs to read, review, and possibly share with a team. Examples:

- **Architecture plans** — system design, component diagrams, data flow, trade-off analysis
- **Implementation plans** — phased rollout, numbered steps, file manifests, migration checklists
- **Project roadmaps** — timeline, milestones, deliverables, dependencies
- **Design specs** — API contracts, schema designs, UI specifications
- **Technical proposals** — problem statement, options considered, recommendation, risks
- **Sprint plans** — backlog breakdown, task assignments, acceptance criteria

When the user says "plan this out", "design the architecture", "create a spec", or "write a proposal" — use this skill.

## Workflow

1. **Plan the content** — outline sections, statuses, dependencies, and risks in your reasoning
2. **Read** `assets/plan-template.html` — the canonical starting point
3. **Customize** the template:
   - Replace `{{PLACEHOLDER}}` tokens with real content
   - Add or remove sections to match the plan structure
   - Set status badges on items (`planned`, `in-progress`, `done`, `blocked`)
   - Populate callout boxes for risks, decisions, and dependencies
4. **Optionally embed diagrams** — inline Mermaid.js via CDN for architecture diagrams
5. **Deploy** via `deploy_to_canvas` — default `tier: "public"`, use `tier: "ephemeral"` with `ttl_minutes: 1440` for draft reviews
6. **Present the URL** clearly with a one-line summary

## Template Structure

The template provides these building blocks. Customize sections based on the plan type.

### Sections

Each section is a `<section>` with an `<h2>` or `<h3>` heading. The template supports:

| Block type | HTML pattern | Use for |
|------------|-------------|---------|
| Section header | `<section><h2>...</h2>` | Top-level plan categories |
| Sub-section | `<section><h3>...</h3>` | Nested detail within a section |
| Item list | `<ul class="plan-list">` / `<ol class="plan-list">` | Steps, tasks, requirements |
| Status badge | `<span class="badge badge-{status}">` | Visual tracking of item state |
| Callout | `<div class="callout callout-{type}">` | Risks, decisions, notes, warnings |
| Code block | `<pre><code>...</code></pre>` | API contracts, schemas, config |
| Phase header | `<div class="phase">` | Timeline groupings |
| Dependency link | `<span class="depends">→ {id}</span>` | Item dependencies |

### Status Badges

```html
<span class="badge badge-planned">planned</span>
<span class="badge badge-progress">in progress</span>
<span class="badge badge-done">done</span>
<span class="badge badge-blocked">blocked</span>
```

### Callout Types

```html
<div class="callout callout-info">
  <strong>ℹ Note</strong>
  <p>Background context or clarification.</p>
</div>

<div class="callout callout-risk">
  <strong>⚠ Risk</strong>
  <p>Potential issue with mitigation strategy.</p>
</div>

<div class="callout callout-decision">
  <strong>✓ Decision</strong>
  <p>Key architectural choice and rationale.</p>
</div>

<div class="callout callout-depends">
  <strong>⛓ Dependency</strong>
  <p>Blocks or is blocked by other work.</p>
</div>
```

### Phase Headers

For time-sequenced plans, group items under phase headers:

```html
<div class="phase">
  <span class="phase-label">Phase 1</span>
  <span class="phase-name">Foundation — Weeks 1–2</span>
</div>
<ul class="plan-list">
  <li><span class="badge badge-done">done</span> Set up project scaffolding</li>
  <li><span class="badge badge-done">done</span> Configure CI pipeline</li>
</ul>
```

### Numbered Steps

For implementation plans with ordered steps:

```html
<ol class="plan-list steps">
  <li>
    <div class="step-header">
      <span class="step-num">1</span>
      <strong>Create the database migration</strong>
      <span class="badge badge-planned">planned</span>
    </div>
    <p class="step-detail">Add a <code>users</code> table with columns for name, email, and password_hash. Run <code>php artisan make:migration create_users_table</code>.</p>
  </li>
</ol>
```

### File Manifests

For implementation plans listing files to create or modify:

```html
<ul class="file-manifest">
  <li><code class="file-add">+</code> <span>app/Models/User.php</span> — new model</li>
  <li><code class="file-mod">~</code> <span>routes/web.php</span> — add user routes</li>
  <li><code class="file-del">−</code> <span>app/Http/Controllers/OldController.php</span> — deprecated</li>
</ul>
```

## Diagrams

For architecture plans, embed Mermaid.js via CDN to render diagrams:

```html
<script type="module">
  import mermaid from 'https://cdn.jsdelivr.net/npm/mermaid@11/dist/mermaid.esm.min.mjs';
  mermaid.initialize({ startOnLoad: true, theme: 'neutral' });
</script>

<pre class="mermaid">
graph TD
    A[Client] --> B[API Gateway]
    B --> C[Auth Service]
    B --> D[Data Service]
    D --> E[(PostgreSQL)]
</pre>
```

Use Mermaid for: system architecture, data flow, sequence diagrams, ERDs, state machines.

For simpler diagrams, use ASCII art inside `<pre class="ascii-art">`:

```html
<pre class="ascii-art">
┌──────────┐     ┌──────────┐     ┌──────────┐
│  Browser  │────▶│  Laravel  │────▶│  MySQL   │
└──────────┘     └──────────┘     └──────────┘
</pre>
```

## Deployment

**First, check** whether `deploy_to_canvas` is in your available tools.

### With the Pi Extension (preferred)

```json
{ "html": "<!DOCTYPE html>...", "tier": "public" }
```

### API fallback

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

**Tier guide:**
- `"public"` — permanent link. Use for finalized plans meant to be shared or bookmarked.
- `"ephemeral"` with `ttl_minutes: 1440` — 24-hour draft link. Use for review and iteration.

After deploy, present the URL:

```
Plan deployed → https://artfct.dev/p/4fA8gX9z

Architecture plan for the payment service — 5 sections, 12 action items.
```

## Authoring Guidelines

**Section count:** 3–8 top-level sections. If you need more, consider splitting into separate plan documents.

**Item granularity:** Each list item should be a single actionable task or decision. Avoid paragraphs inside list items — use `<p class="step-detail">` for elaboration.

**Status discipline:** Only mark items `done` if they're already completed. Most items should be `planned`. Use `blocked` sparingly and always include the blocker in a `callout-depends`.

**Consistency:** Use the same status labels throughout. Don't mix "planned" / "todo" / "not started".

**Readability:** Break long blocks of text with callouts, code blocks, or sub-sections. A wall of prose is what the template exists to avoid.

**Mobile:** The template is responsive — sections stack, ToC collapses to a hamburger. However, plans are detail-heavy documents optimized for desktop review. That's the primary use case.

## When Not to Use This Skill

- **Quick answers** — "What does this flag do?" — just answer inline
- **Code changes** — the user wants you to implement, not plan — write the code
- **Single-file edits** — the scope is too small for a structured plan document
- **Bug reports** — use a concise format, not a full plan template

## Additional Resources

- **`assets/plan-template.html`** — Complete working template; read this and modify rather than writing from scratch
- **`references/mermaid-cheatsheet.md`** — Common Mermaid diagram patterns for architecture and sequence diagrams
