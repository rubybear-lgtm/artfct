# Spec 2 — Provenance capture

**Track:** A (client) · **Depends on:** 0, 1 · **Blocks:** 3 (schema consumes it)

## Scope

**In.** One provenance struct built by two builders — CLI and MCP — and sent on every create request per the spec 0 contract. Git and environment discovery. Per-field source recording. An optional agent-attested `model` argument on `deploy_to_canvas`.

**Out.** Storing it. The Worker accepts and ignores unknown fields until spec 3 adds the columns. This is deliberate: it decouples the client work from the storage work and means provenance starts accumulating before there is anywhere to put it.

## Decisions implemented

- **07** — provenance required, not optional. A deploy that cannot build the struct fails; a deploy that can only build it partially succeeds and records the gaps.

## Design

### One struct, two builders

`deploy_from_cli` (`main.rs`) and `call_tool` (`mcp.rs`) both call `prepare_artifact_request` and both attach provenance. Shared type, divergent population:

| Source | CLI | MCP |
|---|---|---|
| agent / version | `artfct-cli`, `Config` | from `Session` (spec 1) |
| session_id | absent | from `Session` |
| tool | `cli` | `deploy_to_canvas` |
| model | absent | optional argument, `SelfReported` |
| repo / branch / commit / dirty | from cwd | from cwd |
| source_path | the file argument | absent (payload is inline) |

### Git discovery

Shell out to `git` rather than linking a git library — it respects the user's config, worktrees and submodules, and a missing or non-repo cwd is a normal outcome, not an error:

- `git rev-parse --show-toplevel`, `--abbrev-ref HEAD`, `HEAD`
- `git status --porcelain` non-empty → `dirty: true`
- `git remote get-url origin`, **scrubbed of embedded credentials** before sending

Every failure path yields `null` with `sources.<field> = "absent"`. Not being in a repo is the common case and must never fail a deploy.

### Attested vs observed

`model` is whatever the agent claims. It is stored in its own field with `source: self_reported` and must never be merged into observed values. A year from now the difference between "we watched this" and "the agent told us" matters.

### Incidental cleanup

`prepare_artifact_request(&html, tier, ttl, true)` passes an unnamed trailing bool at both call sites. The signature changes here anyway; make it a named field.

## Definition of done

- [ ] `artfct deploy ./x.html` from inside a clean git repo sends `repo_url`, `branch`, `commit_sha`, `dirty: false`, each with `sources.* = "process"`.
- [ ] The same deploy with an uncommitted change sends `dirty: true`.
- [ ] `artfct deploy ./x.html` from `/tmp` (no repo) succeeds and sends git fields as null with `sources.* = "absent"`.
- [ ] A remote of `https://user:token@github.com/o/r.git` is sent as `https://github.com/o/r.git` — credentials never leave the machine.
- [ ] An MCP `deploy_to_canvas` call sends `agent`, `agent_version`, `session_id`, `tool=deploy_to_canvas`.
- [ ] Calling `deploy_to_canvas` with `model: "claude-opus-5"` sends it with `sources.model = "self_reported"`; omitting it sends null with `"absent"`.
- [ ] Every nullable provenance field has a key in `sources`, including when null — verified against the spec 0 enum.
- [ ] The request validates against the spec 0 contract (`cli_create_request_validates_against_contract` passes).
- [ ] The current Worker accepts the enriched payload unchanged and still returns a working URL.

## Tests

**`mcp-server`**
- `git_provenance_from_clean_repo`
- `git_provenance_marks_dirty_worktree`
- `git_provenance_absent_outside_repo`
- `remote_url_credentials_are_scrubbed`
- `mcp_builder_populates_agent_and_session`
- `cli_builder_omits_session_id`
- `model_argument_is_marked_self_reported`
- `model_absent_records_absent_source`
- `every_nullable_field_has_a_source_entry`
- `provenance_serializes_to_contract_shape`

**Integration**
- `deploy_against_current_worker_succeeds_with_provenance` — proves forward compatibility before spec 3 exists.

## Rollback

Reversible. Provenance is sent but not stored; reverting stops sending it and nothing is orphaned.

## Deferred

- Prompt hash. Requires host cooperation that no agent currently offers.
- Verifying attested fields. There is no mechanism; the `sources` field is the honest alternative.
