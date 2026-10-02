# Spec 13 — Retrieval

**Track:** F (thesis) · **Depends on:** 12 · **Blocks:** nothing

## Scope

**In.** Search across an org's artifacts — semantic and full-text. A retrieval MCP tool so agents query the corpus before rebuilding something. Provenance-aware filtering. Console search.

**Out.** Nothing meaningful; this is the last thesis spec.

**Checkpoint:** an agent asks for "the billing dashboard from last month" and gets it *before* rebuilding it. That is the entire product thesis reduced to one interaction.

## Decisions implemented

- **07** — provenance, which is what makes "from the billing repo, last month" answerable rather than just "about billing".

## Design

### The second MCP tool

`deploy_to_canvas` writes. `search_artifacts` reads. Adding it makes the artfct MCP server bidirectional, and turns the store into something an agent consults rather than only writes to.

```json
{
  "name": "search_artifacts",
  "arguments": {
    "query": "billing dashboard",
    "repo": "acme/billing",
    "agent": "claude-code",
    "since": "2026-08-01",
    "limit": 5
  }
}
```

Returns title, description, URL, provenance summary and a snippet — **never the full bundle**. An agent should decide whether to open something, not have 2 MB of HTML pushed into its context.

### Why the tool description matters as much as the code

Agents call tools based on their descriptions. This one has to say *when* to search — before building a dashboard, when the user references something previously made — or it will be ignored in favour of generating from scratch. Expect the description to need iteration with real agents; treat it as part of the deliverable, not documentation.

### Ranking

Hybrid: semantic similarity plus recency plus provenance match. An exact repo match should outrank a semantically closer artifact from an unrelated repo — "the billing dashboard" almost always means the one from the billing repo.

### Authorization

Search runs strictly within the caller's org, resolved from the credential, never from a parameter. **A search result is a read** — it must respect artifact-level sharing and revocation. A revoked artifact must not surface, and this is exactly the boundary where an inherited-permissions bug leaks the whole corpus.

Searches are audited (`search.performed`) — an org that just bought a governance product will ask who searched for what.

### Console search

Same backend, in the admin UI, sharing the filter vocabulary from spec 8 so the two do not diverge into different mental models.

## Definition of done

- [ ] `search_artifacts` with `"billing dashboard"` returns the relevant artifact from an org with 1,000 artifacts.
- [ ] Filtering by `repo` returns only that repo's artifacts.
- [ ] Filtering by `since` excludes older artifacts.
- [ ] An exact repo match outranks a semantically closer artifact from an unrelated repo.
- [ ] Results include a snippet and provenance summary, never the full bundle.
- [ ] A search by org A's token returns nothing belonging to org B.
- [ ] A revoked artifact does not appear in results.
- [ ] An artifact the caller cannot access does not appear, and its non-appearance is indistinguishable from non-existence.
- [ ] Each search writes a `search.performed` audit event.
- [ ] Search returns in under 500 ms at 1,000 artifacts.
- [ ] An end-to-end run in a real agent: asked for a previously built dashboard, the agent calls `search_artifacts` and returns the existing link rather than generating a new one.

## Tests

**`mcp-server`**
- `search_tool_listed_alongside_deploy`
- `search_returns_snippet_not_bundle`
- `search_respects_limit`

**`backend` / Pest**
- `semantic_query_finds_relevant_artifact`
- `repo_filter_narrows_results`
- `since_filter_excludes_older`
- `exact_repo_match_outranks_semantic_match`
- `search_scoped_to_credential_org` *(negative)*
- `revoked_artifact_absent_from_results` *(negative)*
- `inaccessible_artifact_indistinguishable_from_absent` *(negative)*
- `search_writes_audit_event`
- `search_under_500ms_at_1k_artifacts`

**Integration**
- `agent_finds_existing_dashboard_instead_of_rebuilding` — the thesis test

## Rollback

Reversible. Read-only; removing the tool and the endpoint changes nothing stored.

## Deferred

- Cross-org search for multi-org users. Ambiguous authorization; wait for demand.
- Agent feedback on result usefulness. Would improve ranking; needs usage first.
