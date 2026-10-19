# Spec 01 partition

| Unit | Owns these files | DoD items | Depends on |
|---|---|---|---|
| MCP session identity | `mcp-server/src/mcp.rs` | `initialize` captures `clientInfo`; host resolution records Config > ClientInfo > Process > Env > Absent; configured host is logged; session IDs are stable within a process and distinct between processes; tool calls read the session; existing MCP tests use the stateful signature | Public `run_stdio_server(Option<String>)` contract agreed with CLI/config unit |
| CLI and agent configuration | `mcp-server/src/cli.rs`; `mcp-server/src/main.rs`; `mcp-server/src/setup.rs`; `mcp-server/src/uninstall.rs`; `README.md` | `mcp serve --host` parses and reaches the server; setup writes the correct normalized host into all five named agent configs and lists it; setup remains idempotent; uninstall removes entries containing the host flag | Public `run_stdio_server(Option<String>)` contract agreed with MCP session unit |
| Serialized coordinator remainder | `CHANGELOG.md`; `scratchpad/runs/01/verification.md`; `scratchpad/runs/01/mutation.md` only if a negative test is introduced | Spec-level changelog entry and release evidence | Both implementation units |

Each implementation path has one owner. `mcp-server/Cargo.toml` and `Cargo.lock`
are deliberately excluded: the design can use existing dependencies and native
platform interfaces, and dependency changes require separate user approval.

The units share only this interface contract: `mcp::run_stdio_server` accepts an
optional configured host string. The MCP unit owns normalization, provenance,
session construction, and logging; the CLI/config unit owns parsing and passing
the value through.
