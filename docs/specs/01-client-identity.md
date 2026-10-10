# Spec 1 — Client identity

**Track:** A (client) · **Depends on:** 0 · **Blocks:** 2

## Scope

**In.** Session state in the MCP server so `initialize` stops discarding `clientInfo`. A `--host` flag on `artfct mcp serve`, written into every agent config by `artfct setup`. A four-step resolution chain that records *how* the host was determined, not just what it was.

**Out.** Sending any of this to the server — that's spec 2. This spec makes the CLI and MCP server *know* who is calling them.

## Decisions implemented

- **07** — provenance required, with `agent_source` per field. This spec produces the agent half.

## Design

### The problem, precisely

`mcp-server/src/mcp.rs:101` — `initialize_result()` takes no arguments and discards `request.params`, which is where `clientInfo` lives. `handle_json_rpc` is a free async fn with no state between lines. So capturing client identity is not a field addition; it requires introducing a session object.

### Session

```rust
pub struct Session {
    client_name: Option<String>,      // clientInfo.name, verbatim
    client_version: Option<String>,
    host: HostIdentity,               // resolved, with source
    session_id: String,               // server-minted UUID, process lifetime
    started_at: DateTime<Utc>,
}

pub struct HostIdentity {
    normalized: Option<String>,       // claude-code | cursor | codex | gemini | opencode
    raw: Option<String>,              // whatever was actually observed
    source: HostSource,               // Config | ClientInfo | Process | Env | Absent
}
```

`run_stdio_server` owns a `Session`. `handle_json_rpc` takes `&mut Session`. `initialize` populates it; `call_tool` reads it.

MCP over stdio has no session concept, so `session_id` is minted by us. It correlates artifacts within one agent run and deliberately does not correlate across runs.

### Resolution chain

In order, first hit wins, source recorded:

1. **`--host` flag** → `Config`. Written by `artfct setup`, which already knows the target agent for each config file it writes (`setup.rs` enumerates Claude Code, Cursor, Gemini, Codex, OpenCode).
2. **`clientInfo.name`** → `ClientInfo`. Self-reported and unnormalized across hosts; keep a mapping table and retain the raw string.
3. **Parent process name** → `Process`. `getppid()` then `libproc` on macOS, `/proc/<pid>/comm` on Linux. Often just `node`, so corroboration rather than primary.
4. **Environment variables** → `Env`. `CLAUDECODE`, `CLAUDE_CODE_ENTRYPOINT`, `CURSOR_TRACE_ID`. Undocumented and version-fragile; last resort.
5. Nothing → `Absent`. Never an error.

### CLI changes

`cli.rs`: `McpCommand::Serve` becomes `Serve(McpServeArgs)` with `--host <HOST>`.
`setup.rs`: each `install_*` writes `["mcp", "serve", "--host", "<agent>"]` into `args`.

## Definition of done

- [ ] `artfct mcp serve --host cursor` starts and reports `host=cursor source=config` in a debug log line.
- [ ] Sending `initialize` with `{"clientInfo":{"name":"claude-code","version":"2.1"}}` and no `--host` yields `host=claude-code source=client_info`.
- [ ] Sending `initialize` with `params: {}` and no `--host` falls back through process then env, and lands on `source=absent` without erroring.
- [ ] `artfct setup --silent` writes `--host` into all five agent configs; `artfct setup --list` shows it.
- [ ] Running `artfct setup` twice is idempotent — the second run produces no change.
- [ ] `session_id` is stable across multiple `tools/call` invocations in one process and differs between processes.
- [ ] `artfct uninstall --silent` still removes every entry cleanly with the new args present.
- [ ] The existing `handles_initialize` and `lists_deploy_to_canvas_tool` tests pass against the new signature.

## Tests

**`mcp-server`**
- `initialize_captures_client_info`
- `initialize_with_empty_params_does_not_error`
- `host_flag_beats_client_info`
- `client_info_beats_process_name`
- `unresolvable_host_records_absent_not_error`
- `session_id_stable_within_process`
- `session_id_differs_between_processes`
- `call_tool_reads_session_host`
- `handles_initialize` / `lists_deploy_to_canvas_tool` — updated for `&mut Session`

**`setup`**
- `setup_writes_host_flag_for_each_agent`
- `setup_is_idempotent`
- `uninstall_removes_entry_with_host_flag`

## Rollback

Fully reversible. No stored data, no issued URLs. Reverting the commit and re-running `artfct setup` restores the previous configs.

## Deferred

- Model identification. Not available in MCP at any layer; spec 2 adds an optional self-reported field, stored separately from observed values.
- Cross-run session correlation. Would require host cooperation that does not exist.
