# Feature inventory

**This file is the allowlist.** Every capability claim in an artfct-content draft must be traceable to a row here. If a claim is not in this file, the draft does not make it — not softened, not hedged, not as a question. The linter enforces the vocabulary in the last section; the rest is on you.

Sources of truth, in order of authority:

| Source | What it settles |
|---|---|
| `app/Support/AiToolSetup.php` | The MCP surface, in plain language. If this file and a draft disagree, this file wins. |
| `routes/teams.php`, `routes/web.php` | Whether a console capability is actually reachable. |
| `app/Mcp/Tools/` | Tool argument surface — sharing levels, versioning, bundle limits. |
| `DOCUMENTATION.md` | Storage, isolation, identity, governance mechanics. |
| `docs/prd.md` | Positioning, non-goals, and what is deliberately out. |

Anything from `docs/specs/` is a **plan**, not a shipped fact. Specs 12–16 describe Track F; the routes exist, but "the spec says so" is not evidence a customer can observe it. When you need a claim that only a spec supports, write it as a roadmap sentence and say so.

---

## 1. Shipped — claim freely

### Publishing and storage

| Capability | Detail worth using | Where |
|---|---|---|
| Publish one HTML file | Self-contained document, one call | `deploy_artifact`, `html` |
| Publish a multi-file bundle | Up to 500 files and **8 MB in total** through `deploy_artifact`, which is the limit a customer actually meets. A single-page `html` payload is capped at **1 MB**. Plan ceilings bind lower still: free 5 MB, team 10 MB, enterprise 25 MB. The Worker's own storage bounds (25 MiB per file, 50 MiB total) are not reachable from any shipped publish tool — do not quote them. | `DeployArtifactTool` (`MAX_HTML_BYTES`, `MAX_BUNDLE_BYTES`), `config/billing.php` |
| Same content returns the same artifact | Content-addressed: identical content re-published yields the same artifact rather than a duplicate | manifest-hash id |
| Update without changing the link | Pass `artifact_id`; publishes a new version on the same URL. An optional `base_version` makes the write conditional: if the artifact has moved on since that version, the publish is refused rather than quietly overwriting a colleague's change. | `deploy_artifact` |
| Artifact type | Every artifact carries one type: `report`, `table`, `document`, `mockup`, `slide_deck`, `dashboard` or `other`. Filterable through `artifact_type` on `list_artifacts` and `search_artifacts`. A type is a label, **not a rendering promise** — see the non-HTML entrypoint gap in §2a. | `app/Enums/ArtifactType.php` |
| Read the text of an artifact | `read_artifact` returns the extracted, indexed text one bounded page at a time (`offset`, `limit`) with a `next_offset`, and never the HTML bundle. Version-honest: only the last-indexed version can be read. **Gated on indexing** — with it off, the call answers "Reading artifact text is not enabled for this workspace yet." | `ReadArtifactTool` |
| Comments on an artifact | People and connected agents can comment on an artifact: plain text, up to 4,000 characters, threaded with `parent_id`, and resolvable or reopenable. Over MCP it requires the `comments:write` scope. In the browser the viewer shows the thread and lets a signed-in reader post or resolve, throttled. | `AddArtifactCommentTool`, `ResolveArtifactCommentTool`, `ArtifactViewerController` |
| Find what changed since a known time | `search_artifacts` accepts `changed_since`, so an agent can ask for work updated after a time it already holds | `search_artifacts` |
| Version history | List earlier versions, restore one | `console.versions`, `console.versions.restore` |
| Provenance | A per-artifact record with per-field source attribution. On the supported MCP path it holds the **model** (self-reported, when the caller supplies it), the **MCP session id**, the tool name and the client name and version. Repository, branch, commit and dirty state are recorded as **absent** — see the known gaps below. | `DeployArtifactTool::provenance()` |
| Free tier | Encrypted, passcode-protected, five-day sliding expiry, 1 MB single file, no account | `DOCUMENTATION.md` |
| Permanent tier | Org-held keys, durable storage, no expiry | `DOCUMENTATION.md` |

### Sharing and access

- Three sharing levels: **team** (default), **private**, **public** (anyone with the link).
- **Public links can be switched off for the whole team.** `public_sharing_allowed` is a team setting (`app/Models/Team.php`). With it off, a publish that asks for public is refused with "Your team has turned off public links" (`DeployArtifactTool`), and the viewer refuses the same way (`ArtifactViewerController`). Any sentence offering a public link as an option must not read as unconditional.
- **Edit access** as a separate axis from visibility: view or edit, so a teammate can publish the next version.
- One viewer route, `/a/{id}`, for every sharing level. A signed-out visitor to a team artifact is sent to sign in and back; the route never confirms whether an id exists.
- **A connection grants named scopes, not blanket access.** `artifacts:read`, `artifacts:deploy`, `artifacts:delete`, `collections:read`, `collections:write`, `usage:read` and `comments:write`. Each carries a risk tier — Read, Write or Destructive — and the consent screen shows a plain-language label for it ("Comment on shared work", "Delete shared work"). A member's approval covers the scopes it asked for and nothing beyond them.
- **Comments are a write surface.** A reader with access can post a comment on an artifact in the browser or, with the `comments:write` scope, through a connected tool. Any sentence asserting the product "does not write or edit" must be scoped to the artifact's own content: commenting is a write, drafting and editing are not.
- Per-artifact origin isolation: each artifact gets its own hostname, cookieless, short-lived signed token, and a content security policy derived from what that artifact declared. **One artifact's JavaScript cannot read another's.**

### Teams, identity and governance

- Sign up and sign in through WorkOS AuthKit — social, passkeys, Magic Auth.
- Teams with invitations, roles (admin / member / viewer), ownership transfer, leave, switch.
- Domain verification by DNS TXT record before SSO is enforced.
- SAML / OIDC single sign-on through self-hosted Ory Polis, with a tested downgrade path. **The gate between Team and Enterprise is SSO.**
- SCIM directory sync: provisioning and de-provisioning arrive as signed webhooks from Polis, so removing someone from the directory removes their access.
- Admin console: list, filter, search, revoke, and export everything the org owns as a zip of metadata plus byte-identical files, each verified against its hash.
- The console's **member-facing** surfaces are narrower than the tool surface: the console filter bar carries repository, producing agent and title-or-description search, with **no sort control**; the dashboard search exposes a query, a collection, a since-window and a mine/team scope. Never attribute `list_artifacts`' full argument surface (owner, sharing level, file kind, date windows, sorts) to the console.
- **Markdown and table artifacts are not converted to HTML.** An entrypoint that is not HTML is served as a download rather than rendered as a page. Do not claim that a markdown document or a table "renders as a page".
- Audit log of create, view, share and revoke, exportable **on Enterprise**. The JSON Lines export is plan-gated (`PlanGate::isEnterprise`); on a lower plan the page reports `canExport` false. Do not write "the audit log is exportable" unqualified, and do not attach a plan qualifier to the log itself — only to its export. A view of an artifact that needs no account records an open attributed to a daily-salted pseudonymous key rather than a person, because there is no account to attribute it to (`backend/src/ephemeral_routes.rs`).
- Retention policy: a team may set `retention_days`; **when it is unset the default period is 90 days** (`RetentionService::defaultRetentionDays`, `config('governance.default_retention_days')`). Do not claim there is no default period. Legal hold and release of hold are Enterprise-tier governance features; qualify them the way SSO is qualified.
- Admin console export of everything the org owns as a zip of metadata plus byte-identical files, each verified against its hash. This is a separate route from the audit-log export and is not the plan-gated one.
- Retention policy, legal hold, and release of hold.
- Usage and quota readout.
- Billing through Stripe: subscribe, portal, cancel, resume.
- Revocable org tokens for the REST API, and a list of connected AI tools an admin can revoke.

### Finding and organizing

- **`search_artifacts`** — hybrid search: semantic similarity fused with keyword matching, then ranked by recency, provenance match, how often an artifact has been opened, and whether it sits in a pinned collection. A minimum relevance threshold gates candidates that do not match at all, so an empty result is a real answer rather than the least-bad row. Filterable by repo, agent, collection, date, `artifact_type` and `changed_since`. Returns summaries, an evidence excerpt and a link; the excerpt a connected tool receives is longer than the one the console shows, because a tool cannot open the page itself. This is the retrieval story: the next agent finds the report before rebuilding it, and a canonical artifact outranks a semantically closer one nobody has opened.
- **`read_artifact`** — when an excerpt is not enough, read the extracted text of one artifact, one bounded page at a time. Gated on indexing, like search.
- **`get_guidance`** — the server serves its own rules, one topic at a time: `publish`, `search` or `collaborate`. Static and versioned (contract version `1.0.0`), never built from user input, and shipped alongside the same guidance as Agent Skills files. Do not describe it as documentation for people — it is instructions addressed to an agent.
- **`list_artifacts`** — filter by owner, sharing level, collection, agent, date window, title text, file kind and `artifact_type`; sort by updated, created, most viewed, or title. Note the console's own filters are narrower: title or description, producing agent, repository, and collection.
- **Render before indexing** — extraction is not always a matter of reading markup. An artifact whose substance appears only once the page runs is detected, rendered, and indexed by the text a reader would see. Gated by `config('indexing.enabled')`, which defaults off, and a renderer must be configured. | `IndexingService`, `ExtractionHeuristics::needsRender()`
- Collections: create, rename, add and remove artifacts, pin.
- Console search page and a reindex action.
- **Slack**: `/artfct <query>` returns results **ephemerally** — only the person who asked sees them, never the channel. Inbound requests are signature-verified. Unfurling a shared link into a card is *not* shipped; do not describe it.

### The connection

- One way in: the hosted OAuth MCP server at `/mcp`. The CLI and local stdio server were retired in RUB-432 and must not be described as available.
- **Fifteen advertised tools**, and the catalog fits one page so a client that ignores paging does not silently lose any: `deploy_artifact`, `search_artifacts`, `read_artifact`, `list_artifacts`, `get_artifact`, `get_guidance`, `get_connection`, `get_usage`, `list_artifact_comments`, `add_artifact_comment`, `resolve_artifact_comment`, `list_collections`, `create_collection`, `add_collection_artifact`, `delete_artifact`. A sixteenth, `deploy_to_canvas`, is **deprecated** — it makes anonymous expiring artifacts the workspace cannot search. Never recommend it, and never count it among the supported fifteen.
- Quick install across tools: `npx add-mcp@latest <mcp-url> --name artfct --global`.
- An agent skill for publishing well: `npx skills add rubybear-lgtm/artfct@artfct`.
- Machine-readable docs at `/llms.txt` and `/llms-full.txt`; the docs page and `llms-full.txt` render from the same PHP source, so they cannot drift.
- Rate limiting, with a retry-after the tool reports.

### Tool connections, by verification status

`verified` means someone completed browser sign-in and a tool call against staging. Anything else is a documented path, not a tested one — describe it as "documented", never as "works".

| Tool | Status |
|---|---|
| Claude Code, Codex, OpenCode, Antigravity | **Verified** |
| Claude app, ChatGPT, Cursor, VS Code (Copilot Chat), Windsurf, Zed | Documented, unverified |
| Any other MCP client | Remote/HTTP only — a tool that can only start local programs cannot connect |

---

## 2. Roadmap — future tense only

These may appear in a page only with explicit forward framing ("planned", "on the roadmap", "not yet"). Never as a present-tense benefit, never in a comparison table as a capability, never in a feature list without the marker.

- **Slack link unfurls.** The slash command ships; turning a pasted artifact link into a card does not. These are easy to conflate — check which one the sentence is about.
- **Tenant-branded domains** (`artifacts.acme.com`) as a paid add-on. The console field and the hostname write exist; the end-to-end served flow is not confirmed. Write it as an add-on in development or leave it out.
- **Data residency by deployment region.**
- **Export tooling beyond the zip** — metadata plus byte-identical files, verified per hash, is what exists today.
- **Proof that indexed artifacts change how AI tools work.** This is goal 6, the thesis under test. It is the reason the product exists, not a result anyone can quote.

## 2a. Known gaps — true, but not a selling point

These are real and worth knowing, because a draft that assumes the opposite will read as correct and be wrong.

- **Repository provenance is not captured on the supported connection path.** `DeployArtifactTool::provenance()` sets `repo_url`, `branch`, `commit_sha` and `dirty` to `null` with a source of `absent`. Those fields were populated by the retired CLI. **Do not claim that an artifact records the repository or commit it came from.** The knock-on effect: `search_artifacts`'s `repo` filter and `list_artifacts`'s `agent` filter have nothing to match on for artifacts published through the hosted server, and the "provenance match" term in search ranking is inert for them.
- **`agent` is likewise absent.** `tool` is the constant `deploy_artifact` and `client` is the constant `artfct-remote-mcp`, so nothing on the hosted path distinguishes Claude Code from Codex from Cursor. The console's "AI tool" column reads `agent`, which is null, and renders **"Unknown tool"** — a customer can falsify any origin-identification claim in one step. **The model is `self_reported`, not observed.**
- **There is no per-person sharing.** Sharing is exactly `private` / `team` / `public`, plus the owner and team admins. The landing page says "or only with the people you pick"; the code does not support that. Do not repeat it in content.
- **Indexing is off by default and switched on at the deployment level.** `config/indexing.php` reads `env('INDEXING_ENABLED', false)`, and with it off `search_artifacts` returns "Search is not enabled for this workspace yet." Do not present search as unconditional. Re-indexing is rate-limited to once per ten minutes per artifact.
- **Deletion is not in the exportable audit log.** `delete_artifact` records telemetry, not an audit entry; the `ArtifactDeleted` rows come from operator governance flows (legal hold, erasure). Do not list deletion among the actions an audit log records.
- **There is no version comparison.** A version history can be listed, opened and restored; there is no diff.
- **Connections are per person.** One member's connection does not authorise another, and each tool asks that person to sign in.

One more category, because it is the most tempting: **inference from the architecture.** "Every paying tenant gets a dedicated deployment" is true and shipping-relevant; "therefore your data is physically isolated at the hardware level" is not a claim the architecture makes. Stop one step before the conclusion.

---

## 3. Never — these are defects, not weak claims

### Out of scope by decision

- **Not a developer tool.** No surface a member sees may require a terminal, a token, JSON, or protocol vocabulary. Developer features stay available but out of the primary path.
- **Not static site hosting.** No custom builds, no frameworks, no `npm run build`.
- **Not a CDN.** Shared with tens of people, not millions.
- **Not a wiki.** No authoring, no editing, no Notion or Confluence comparison. Artfct stores and finds what an AI already made.
- **Not general file storage.** No arbitrary office files, images, or archives for their own sake. An artifact is something an AI made that a person can open — rendered at a link, or downloaded as a file when the entrypoint is not HTML. Say "renders as a page" only about an HTML entrypoint, never as the universal definition of an artifact.
- **Not notebooks, PDFs, or video.**
- **Not a memory or context layer.** It moves finished artifacts, not reasoning. Do not claim it improves what a model knows or how it reasons.
- **Not BYOC or self-hosting.** Out of scope, not deferred.

### Claims that are false as stated

- **"The labs give you no controls."** False since 2026. Claude Code Artifacts are admin-gated with share permissions; ChatGPT canvas sites have RBAC and per-site access modes. Pitching the absence of controls loses the room in front of anyone who has configured them. The true and structural claim is **cross-vendor**: no lab will index a competitor's output.
- **"An artifact store."** To a platform lead that phrase means Artifactory or Harness — binaries, packages, SBOMs. Say "a record of what your agents built".
- **"End-to-end encrypted."** True of the free ephemeral tier only. Permanent artifacts use org-held keys. Qualify it or drop it.
- **"Never used to train AI"** is the supported claim. **"We never see your data"** is not.
- **Any certification** — SOC 2, ISO 27001, HIPAA, GDPR-compliant — unless it is in the certification list you were given for that page. Do not infer one from "passes a security review".
- **Guarantees of any kind.** No SLA, no uptime number, no "never loses", no "always available".
- **Connection methods that no longer exist.** No CLI, no command-line install, no stdio server, no local server, no `npx artfct`.
- **Verified status for an unverified tool.** Claiming ChatGPT or Cursor is verified when only the path is documented is the exact kind of claim a support ticket refutes.

### Vocabulary the linter blocks

`artifact store` · `static site hosting` · `self-hosted` · `on-prem` · `BYOC` · `wiki` · `memory layer` · `CDN` · `PDF` · `notebook` · `CLI` · `stdio` · `local server` · `military-grade` · `bank-level` · `guarantee` · `unlimited` · `the only` · `first and only` · `100%` · `SOC 2` · `HIPAA` · `ISO 27001`

Marketing filler the linter warns on: `seamless` · `revolutionary` · `game-changing` · `cutting-edge` · `unleash` · `supercharge` · `empower` · `leverage` · `delve` · `in today's fast-paced` · `robust solution` · `best-in-class`

---

## 4. The two registers, and which claims each may carry

Capability claims are identical across registers. Only the delivery changes.

| | **glean-formal** (default) | **artfct-terse** |
|---|---|---|
| Where | Blog, perspectives, comparisons, explainers, programmatic pages | Landing-adjacent, free-tier, launch notes |
| Lead | Answer-first, definitional, 40–70 words | One or two sentences, under 30 words |
| Second person | "your team" | "you" |
| Sentence | ~18 words | ~12 words |
| Hedging | Precise qualification | Plain statement |

Mixing them in one page is a defect. Pick one per page and hold it.

**The trap to avoid:** the corpus is Glean's, and Glean sells an enterprise search product. Borrowing a sentence shape is the point; borrowing a *capability* is not. Every noun in a draft that names something the product does must be checked against section 1 — including nouns that arrived because the corpus rhythm made them feel natural.

