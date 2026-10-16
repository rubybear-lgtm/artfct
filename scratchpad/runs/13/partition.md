# Spec 13 partition

Solo session (no subagent budget since spec 08). One unit spanning three
crates/apps (Laravel search service + new `/api/search` endpoint,
`mcp-server`'s new `search_artifacts` tool) — kept together since the MCP
tool's request/response shape and the endpoint it calls are one contract.

## Closable honestly in this environment

- `SearchService`: embeds the query, queries the org-scoped
  `VectorIndexContract`, cross-references `ArtifactDirectory` for title/
  description/URL/revocation, applies repo/agent/since filters, ranks via
  `SearchRanking` (pure: semantic + recency + provenance-match boost),
  returns snippet-only results, audits `search.performed`.
- Org scoping and revoked/inaccessible filtering: both mutation-checked.
- `/api/search`: a genuinely new, real HTTP endpoint (not deferred) —
  `AuthenticateOrgToken` middleware verifies the org JWT bearer token via
  a new `OrgJwtService::verify()` (derives the RS256 public key from the
  private key already held; no new secret to configure) and resolves the
  org strictly from the credential, matching DoD: "resolved from the
  credential, never from a parameter."
- `mcp-server`'s `search_artifacts` tool: listed alongside
  `deploy_to_canvas`, request/response shaping (`search_request_payload`/
  `format_search_response`) is pure and unit-tested without a live call —
  same precedent as `deploy_to_canvas`'s own tests, which don't exercise
  a live network call either.
- `search_under_500ms_at_1k_artifacts`: a **real** wall-clock assertion,
  not structural — the whole pipeline (FakeEmbeddings + FakeVectorIndex
  + FakeArtifactDirectory) is genuinely in-memory and fast, so timing it
  for real is honest here (unlike spec 11/12's Worker-side backpressure
  claims, which needed a live Worker to measure for real).

## Structural / documented, not fully wired

- Console search: spec 8's `q` free-text filter (via `ArtifactDirectory`)
  already covers the full-text half of "semantic and full-text." A
  dedicated semantic-search UI panel calling `SearchService` was not
  built this session — same UI-deferral pattern as spec 12's dead-letter
  panel.
- The end-to-end "agent asks for a previously built dashboard" thesis
  test (`agent_finds_existing_dashboard_instead_of_rebuilding`) needs a
  real agent and a live deployment; not runnable here.

## Not closable here

- `RealEmbeddings`/`RealVectorIndex` (spec 12's fail-closed real
  implementations) still apply — no live Workers AI/Vectorize account.
- Production routing: `/api/search` assumes Cloudflare's routing rules
  let `/api/*` fall through to Laravel (only `/v1/*` and `/p/*` are
  Worker-routed per the spec-0 changelog entry) — this session did not
  verify that against a live Cloudflare zone.
