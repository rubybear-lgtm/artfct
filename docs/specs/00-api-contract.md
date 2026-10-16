# Spec 0 — API contract (OpenAPI)

**Track:** Foundation · **Depends on:** nothing · **Blocks:** every spec that touches the wire

## Scope

**In.** A single OpenAPI 3.1 document describing the artfct HTTP surface: artifact create / read / update / delete, the bundle manifest upload flow, authentication schemes, the error envelope, and reserved-but-unimplemented paths for the admin and retrieval surfaces. Contract validation wired into CI for both the Worker and the CLI. The published `/docs` page generated from it.

**Out.** Implementing any of it. This spec produces a contract and the machinery that enforces it; specs 3–13 fill it in.

## Decisions implemented

- **05** — one codebase, two storage modes. The request body is a tagged union, not two endpoints.
- **07** — provenance required, not optional. Field names and nullability are fixed here because they become D1 columns in spec 3.
- **09** — Laravel control plane, Worker data plane. The document defines which endpoints accept a Laravel-minted JWT versus an org API token.

## Design

Document lives at `openapi/artfct.yaml`, checked into the repo root alongside `backend/` and `mcp-server/`.

### The two-mode request

`POST /v1/artifacts` takes a discriminated union on `mode`:

```yaml
oneOf:
  - $ref: '#/components/schemas/EphemeralArtifactRequest'   # mode: ephemeral
  - $ref: '#/components/schemas/PermanentArtifactRequest'   # mode: permanent
discriminator:
  propertyName: mode
```

`EphemeralArtifactRequest` carries `body_ciphertext_b64`, `body_iv_b64`, `ttl_minutes`, and public metadata — today's shape plus the discriminant. `PermanentArtifactRequest` carries a `manifest` and omits the crypto fields entirely; the server never sees a key for ephemeral artifacts and never expects one for permanent.

### Provenance

A single `provenance` object, required on every create. Field names here are load-bearing — they become column names in spec 3 and renaming them later is a migration.

| Field | Type | Null? | Notes |
|---|---|---|---|
| `agent` | string | yes | `claude-code`, `cursor`, `codex`, … normalized |
| `agent_raw` | string | yes | the unnormalized self-report, kept verbatim |
| `agent_version` | string | yes | |
| `model` | string | yes | **agent-attested, not verified** — never merged with observed fields |
| `session_id` | string | yes | server-minted per MCP process; absent for CLI |
| `tool` | string | yes | `deploy_to_canvas` or `cli` |
| `repo_url` | string | yes | |
| `branch` | string | yes | |
| `commit_sha` | string | yes | |
| `dirty` | boolean | yes | |
| `source_path` | string | yes | |
| `client` | string | no | `artfct-cli` |
| `client_version` | string | no | |
| `sources` | object | no | per-field: `config` \| `client_info` \| `process` \| `env` \| `self_reported` \| `absent` |

`sources` is what makes "unknown" distinguishable from "not captured yet" a year from now. Every nullable field above has a key in `sources`, including when the value is null.

### Bundle manifest

```yaml
Manifest:
  entrypoint: string        # must be present in files[]
  files:
    - path: string          # relative, no traversal, no leading slash
      content_type: string
      size_bytes: integer
      sha256: string
  external_origins: [string]  # declared CDN hosts, feeds the per-artifact CSP in spec 5
```

`external_origins` is declared at upload rather than sniffed. Spec 5 derives the artifact's CSP from it, so an undeclared origin is blocked at serve time.

### Auth schemes

- `orgToken` — `Authorization: Bearer <token>`, on artifact create/delete and the admin surface.
- `sessionJwt` — Laravel-minted, short TTL, verified at the edge. Spec 7.
- Absent — anonymous ephemeral creates, exactly as today.

### Error envelope

One shape everywhere, so the CLI can render something useful:

```json
{ "error": { "code": "bundle_too_large", "message": "…", "details": {} } }
```

`code` is a stable machine-readable string; `message` is human-facing. The CLI switches on `code`, never on `message` or HTTP status alone.

### Reserved paths

Admin (`/v1/orgs/…`, `/v1/artifacts` list) and retrieval (`/v1/search`) are present in the document, tagged `x-status: unimplemented`, and return 501. Reserving the shape is useful; describing endpoints that don't exist without saying so is fiction other people will build against.

## Definition of done

- [ ] `openapi/artfct.yaml` exists, validates as OpenAPI 3.1 (`npx @redocly/cli lint openapi/artfct.yaml` exits 0).
- [ ] Every currently implemented Worker route appears in the document with request and response schemas.
- [ ] Every unimplemented path carries `x-status: unimplemented` and is documented as returning 501.
- [ ] `cargo test -p artfct-backend` includes a test asserting each currently implemented handler's success response validates against its documented schema; the reserved permanent-create response validates as a contract fixture until spec 4 implements its handler.
- [ ] `cargo test -p artfct` includes a test asserting the create request the CLI builds validates against `EphemeralArtifactRequest`.
- [ ] CI fails on a deliberately introduced field-name mismatch between code and document — demonstrated once, then reverted.
- [ ] `https://artfct.dev/docs` renders from the document rather than hand-maintained content; the existing Inertia `docs` page consumes generated output.
- [ ] The `provenance.sources` enum in the document matches the resolution chain spec 1 implements, exactly.

## Tests

**Rust — `backend`**
- `create_ephemeral_response_matches_schema`
- `create_permanent_response_matches_schema`
- `error_envelope_matches_schema_for_each_error_code`
- `unimplemented_paths_return_501`

**Rust — `mcp-server`**
- `cli_create_request_validates_against_contract`
- `mcp_create_request_validates_against_contract`

**CI**
- `openapi_lint` — Redocly lint, blocking.
- `contract_drift` — runs both crates' contract tests; a schema/code mismatch fails the build.

## Rollback

Fully reversible. Nothing is stored, no URLs are issued. Deleting the document and the CI jobs returns the repo to its current state.

## Deferred

- Generating Rust types from the schema. Tempting, but codegen for `worker`-crate handlers adds a build step for little gain at this size — revisit if the surface triples.
- SDK generation for other languages. No demand.
