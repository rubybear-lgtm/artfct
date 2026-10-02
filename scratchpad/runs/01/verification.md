# Spec 01 verification

Verified on 2026-09-02 from the repository root.

| Gate | Result |
|---|---|
| `cargo fmt --all -- --check` | Passed |
| `cargo clippy --workspace --all-targets -- -D warnings` | Passed |
| `cargo test --workspace` | Passed: 45 CLI/MCP tests and 18 Worker tests |
| `cargo build -p artfct` | Passed |
| `npx --yes @redocly/cli@2.15.2 lint openapi/artfct.yaml` | Exit 0; valid OpenAPI 3.1 with the existing 20 advisory response-class warnings |
| `git diff --check` | Passed |

The built binary was run as
`./target/debug/artfct mcp serve --host cursor < /dev/null`; it exited 0 and
reported `host=cursor source=config` on stderr with a UUID-shaped session ID.

`./target/debug/artfct setup --list` exited 0 and displayed host-aware commands
for Claude Code, Cursor, Gemini, Codex, and OpenCode. Its JSON example included
`["mcp", "serve", "--host", "cursor"]`. The current-project entry remained
explicitly hostless because a project config does not identify its owning agent.

No tests in Spec 01 are marked negative, so no mutation gate applies. Per the
user's deployment instruction, nothing is deployed until all specs are complete.
