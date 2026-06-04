# Artifact CLI Onboarding Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Convert the current MCP-only Rust binary into a useful `artfct` CLI with standalone deploy commands and MCP server mode.

**Architecture:** Keep one binary crate and split behavior into focused modules: CLI parsing, API client, MCP stdio server, config, and future agent installers. Start with the smallest useful CLI slice: `deploy`, `mcp serve`, and `doctor`.

**Tech Stack:** Rust, `clap` for CLI parsing, `reqwest` for API calls, `tokio` for async runtime, existing MCP JSON-RPC implementation.

---

### Task 1: CLI Parser and Binary Rename

**Files:**

- Modify: `mcp-server/Cargo.toml`
- Create: `mcp-server/src/cli.rs`
- Modify: `mcp-server/src/main.rs`

- [ ] **Step 1: Write parser tests**

Add unit tests in `mcp-server/src/cli.rs` proving these commands parse:

```rust
artfct deploy --stdin
artfct deploy preview.html
artfct mcp serve
artfct doctor
```

- [ ] **Step 2: Verify tests fail**

Run: `PATH=/opt/homebrew/opt/rustup/bin:$PATH cargo test -p artfct-mcp-server cli`

Expected: compile failure because `cli.rs` and `Cli` do not exist.

- [ ] **Step 3: Add `clap` and parser implementation**

Rename the package binary to `artfct` and add `clap` derive support. Implement `Cli`, `Command`, and `McpCommand`.

- [ ] **Step 4: Verify tests pass**

Run: `PATH=/opt/homebrew/opt/rustup/bin:$PATH cargo test -p artfct`

Expected: parser tests pass.

### Task 2: API Client and Deploy Command

**Files:**

- Create: `mcp-server/src/api.rs`
- Modify: `mcp-server/src/main.rs`

- [ ] **Step 1: Write request-building tests**

Add tests for endpoint construction and JSON payload shape for `deploy --stdin`.

- [ ] **Step 2: Verify tests fail**

Run: `PATH=/opt/homebrew/opt/rustup/bin:$PATH cargo test -p artfct api`

Expected: compile failure because `api.rs` does not exist.

- [ ] **Step 3: Implement API client**

Move artifact request/response structs into `api.rs`. Implement `deploy_artifact(client, base_url, args)`.

- [ ] **Step 4: Wire `artfct deploy`**

Read HTML from file or stdin, call the API, print URL only by default.

- [ ] **Step 5: Verify**

Run:

```bash
PATH=/opt/homebrew/opt/rustup/bin:$PATH cargo test -p artfct
printf '<h1>CLI test</h1>' | PATH=/opt/homebrew/opt/rustup/bin:$PATH cargo run -p artfct -- deploy --stdin --ttl-minutes 1
```

Expected: tests pass and live CLI command prints an `https://artfct.dev/p/...` URL.

### Task 3: MCP Server Mode

**Files:**

- Create: `mcp-server/src/mcp.rs`
- Modify: `mcp-server/src/main.rs`

- [ ] **Step 1: Move MCP tests**

Add unit tests for initialize and tools/list JSON-RPC handling.

- [ ] **Step 2: Verify tests fail**

Run: `PATH=/opt/homebrew/opt/rustup/bin:$PATH cargo test -p artfct mcp`

Expected: compile failure until MCP module exists.

- [ ] **Step 3: Move existing MCP implementation**

Move existing stdio server and JSON-RPC handling into `mcp.rs`. Keep behavior unchanged.

- [ ] **Step 4: Wire `artfct mcp serve`**

Dispatch `artfct mcp serve` to the MCP stdio loop.

- [ ] **Step 5: Verify**

Run: `PATH=/opt/homebrew/opt/rustup/bin:$PATH cargo test -p artfct`

Expected: all Rust tests pass.

### Task 4: Doctor Command

**Files:**

- Create: `mcp-server/src/doctor.rs`
- Modify: `mcp-server/src/main.rs`

- [ ] **Step 1: Write doctor output tests**

Test that doctor reports API base URL and exits successfully when endpoint construction is valid.

- [ ] **Step 2: Implement minimal doctor**

Print API base URL, binary path hint, and MCP command hint. Avoid creating live artifacts in the first slice.

- [ ] **Step 3: Verify**

Run: `PATH=/opt/homebrew/opt/rustup/bin:$PATH cargo test -p artfct`

Expected: all Rust tests pass.

### Task 5: Final Verification

**Files:**

- Modify: `CHANGELOG.md`

- [ ] **Step 1: Update changelog**

Add CLI onboarding work under an Unreleased section.

- [ ] **Step 2: Run full checks**

Run:

```bash
PATH=/opt/homebrew/opt/rustup/bin:$PATH cargo fmt --all -- --check
PATH=/opt/homebrew/opt/rustup/bin:$PATH cargo check --workspace
PATH=/opt/homebrew/opt/rustup/bin:$PATH cargo check -p artfct-backend --target wasm32-unknown-unknown
PATH=/opt/homebrew/opt/rustup/bin:$PATH cargo test --workspace
npm run lint:check
```

Expected: all checks pass.
