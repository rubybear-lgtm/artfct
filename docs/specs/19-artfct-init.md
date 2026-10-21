# Spec 19: artfct:init onboarding skill (RUB-446)

Source of truth: Linear RUB-446, the Init Setup Contract (staging artifact `nt4duood97mtk`, v1) and Agent Context Discovery (`71jo5z6e97tti`, v1). This plan only fixes the choices those leave open and splits the work into units a Haiku agent can finish alone.

## Decisions this plan fixes

| Choice | Decision |
|---|---|
| Delivery | Skill `skills/artfct-init/` with bundled Node scripts (`.mjs`, no dependencies, Node 22). No PHP, no CLI (RUB-432). Sibling skills (`artfct-search`, `artfct-publish`, `artfct-collaborate`) use the same hyphen naming and ship through skills.sh with no plugin manifest, so `artfct:init` is the ticket's display name. Owner's call (2026-10-18): the skill's frontmatter `name` and folder are both `artfct-init`, which avoids a clash with Claude Code's built-in `/init`. |
| Test wiring | Add `"test:skills": "node skills/artfct-init/scripts/run-tests.mjs"` to `package.json` and a step in `ci.yml`; none of the existing skill node tests run in CI today. `run-tests.mjs` runs every `test-*.mjs` in the folder (owned by U2). |
| Routing line | The shared entrypoint block names only repo-relative files (`GUIDELINES.md`, `DESIGN.md`, `USER.local.md`) and the sentence "also read your personal artfct preferences file if your host loads user-level instructions". Init adds the personal `USER.md` path to the user-level entrypoint (`~/.claude/CLAUDE.md`, `~/.codex/AGENTS.md`) only after approval. |
| Managed block | `<artfct-guidelines>` … `</artfct-guidelines>`, same tag style as Boost's `<laravel-boost-guidelines>`. |
| Shared manifest | `artfct.json` at the repo root, like `boost.json`. Records decisions; never credentials. |
| Personal config | `~/.config/artfct/` (`config.json`, `USER.md`). Honour `XDG_CONFIG_HOME` and an `ARTFCT_CONFIG_DIR` override for tests. |
| Project override | `USER.local.md` at the repo root, listed in `.git/info/exclude` (never in a tracked file). |
| Server name | Detected from the host's MCP config, never assumed. Permission rules use the detected name. |
| Permission file | Personal `settings.local.json` by default; shared `settings.json` only if the user picks it. |
| Hosts | Claude Code and Codex adapters only. |
| Out of scope | Search ranking, evidence passages, workspace context (discovery items 3-5). |

## Shared interfaces (every unit codes to these)

All scripts are ES modules in `skills/artfct-init/scripts/`. Each exports pure functions and, where noted, a CLI wrapper that prints JSON to stdout. No script reads stdin interactively; the skill prose asks the questions and passes answers as arguments.

```
manifest.mjs
  readManifest(repoDir): Manifest            // returns defaults if file absent
  writeManifest(repoDir, Manifest): void     // stable key order, 2-space indent, trailing newline
  recordDecision(m, key, {status, at}): Manifest   // status: configured|inherited|skipped|blocked
                                             // returns m unchanged (same bytes on write) when status is already equal; `at` only changes with status
  isResolved(m, key): boolean                // true when status is configured|inherited|skipped
Manifest = { version: 1, decisions: {[key]: {status, at}}, grants: {[host]: string[]},
             imports: [{artifactId, version, sections: string[], at}], files: {guidelines, design, user: string|null} }

block.mjs
  upsertBlock(fileText: string, blockBody: string): {text: string, changed: boolean}
  removeBlock(fileText: string): {text: string, changed: boolean}
  findEntrypoints(repoDir): string[]         // files that should receive the block, exact on-disk capitalization.
                                             // If CLAUDE.md contains a line `@AGENTS.md`, return only AGENTS.md (avoid loading twice).

files.mjs
  resolveSharedFile(repoDir, 'GUIDELINES'|'DESIGN'): {path: string, exists: boolean}  // case-insensitive match, keeps existing capitalization
  installTemplate(repoDir, name): {path, created: boolean}   // never overwrites

permissions.mjs
  detectServerNames(host, repoDir, homeDir): {servers: string[], undetectable: boolean}
                                             // ALL servers whose URL/name looks like artfct; the skill asks which to use.
                                             // claude: .mcp.json, ~/.claude.json (top level and projects[<repoDir>]). claude.ai
                                             // connectors live in no file: report undetectable:true.
                                             // codex: ~/.codex/config.toml and .codex/config.toml [mcp_servers.*]
  buildProfile(serverName, {read, publish, organize, comment}): string[]   // tool names, never delete or public publish
  planChanges(host, current: HostConfig, wanted: string[]): {add: string[], keep: string[], unsupported: string[]}
                                             // HostConfig = parsed JSON (claude settings) or parsed TOML table (codex)
  applyChanges(host, path, plan): void
  verifyGrants(host, path, wanted: string[]): {granted: string[], missing: string[]}

imports.mjs
  // No import of permissions.mjs and no access to manifest `grants`: structural, not filtered.
  parseProfile(text: string): {sections: {[name]: string}, warnings: string[]}   // warnings are shown, never relied on
  previewImport(parsed, existing: {preferences: object, guidelinesSections: {[n]: string}, designSections: {[n]: string}}):
      {apply: Change[], conflicts: Conflict[], destinations: string[]}
  applyImport(selection: Selection, repoDir, configDir, source: {artifactId, version}): void
  Change   = {target: 'preference'|'guidelines'|'design', key: string, text: string}   // text is shown verbatim in the preview
  Conflict = {target, key, local: string, incoming: string}
  Selection = {accept: Change[], keepLocal: string[]}   // keys; anything not listed is skipped
  // applyImport writes ONLY: preference keys from the preferences.mjs allowlist, and named sections of GUIDELINES.md / DESIGN.md.

preferences.mjs
  readPreferences(configDir, repoDir): {global: object, project: object}
  writePreference(path, key, value): void    // only keys the user answered
  resolvePreference(key, {task, project, global, team}): {value, source}   // precedence below
```

Precedence for `resolvePreference`: current task, project personal, global personal, approved team default, unspecified. A skip never writes a key.

## Units

Each unit owns a disjoint file set. A Haiku agent gets only its unit text plus the "Shared interfaces" section above.

### U1: connection readiness (PHP, Pest)
Owns: `app/Mcp/Tools/GetConnectionTool.php`, its existing Pest test file.
- Add `search` to the structured response: `{enabled: bool, indexed_artifacts: int, last_indexed_at: string|null}`.
- Keep every existing field. `tests/Feature/McpContractTest.php` pins `get_connection` metadata (`toolVersion` `1.0.0` at line 169) and `tests/Fixtures` hold contract JSON: update both only as the contract test requires, and bump `toolVersion` only if it demands it.
- Use the real search/index services; read how `SearchArtifactsTool` decides search is disabled before writing.
- Tests go in `McpContractTest.php` or a new `tests/Feature/McpGetConnectionTest.php`: search enabled with artifacts, enabled with none, disabled. Run `php artisan test --compact --filter=McpContract`, then `vendor/bin/pint --dirty --format agent`.
- A fresh worktree has no `vendor/`: the coordinator does U1 itself (or runs `composer install` first). Stop and report if the disabled flag has no clear source.

### U2: block engine
Owns: `scripts/block.mjs`, `scripts/test-block.mjs`, `scripts/run-tests.mjs`, the `test:skills` entry in `package.json` and its step in `.github/workflows/ci.yml`.
- `run-tests.mjs` runs each `test-*.mjs` beside it as a child process, exits non-zero if any fails.
- Implement `block.mjs`. Replace only the text between the tags; append at end with one blank line when absent; never touch other tags (Boost's block) or text outside.
- Tests (node script, same style as `skills/artfct-content/scripts/test-gate.mjs`): fresh file, file with Boost block, file with manual text after the block, second run unchanged (`changed:false`), `removeBlock` leaves the rest intact, CRLF file stays CRLF, `findEntrypoints` keeps `Claude.md` vs `CLAUDE.md` as found, and returns only `AGENTS.md` when `CLAUDE.md` has an `@AGENTS.md` line.

### U3: manifest
Owns: `scripts/manifest.mjs`, `scripts/test-manifest.mjs`.
- Implement the manifest functions. Unknown fields are preserved on read/write. A missing file yields defaults without creating it.
- Tests: round trip, unknown field preserved, `isResolved` for each status, stable output (writing twice gives identical bytes).

### U4: shared files
Owns: `scripts/files.mjs`, `scripts/test-files.mjs`, `assets/GUIDELINES.template.md`, `assets/DESIGN.template.md`.
- `GUIDELINES.template.md`: retrieval triggers (use the discovery artifact's onboarding rule verbatim), evidence and citation requirements, publishing and sharing conventions, collections, team defaults. No pricing, research or other changing facts.
- `DESIGN.template.md`: placeholders for palette, typography, spacing, charts and diagrams, accessibility, template references.
- Tests: existing `DESIGN.md` not overwritten, `design.md` vs `DESIGN.MD` resolved as existing, template created when absent, no duplicate file under another capitalization.

### U5: permissions adapters
Owns: `scripts/permissions.mjs`, `scripts/test-permissions.mjs`, `references/hosts.md`.
- Depends on U3's exports. Claude Code writes `permissions.allow` entries as `mcp__<server>__<tool>`.
- Codex (checked 2026-10-18 against the Codex MCP docs, now at learn.chatgpt.com/docs/extend/mcp): per server in `config.toml`, `default_tools_approval_mode` takes `auto`, `prompt`, `writes` (prompts only for tools not marked read-only) or `approve`; per-tool `[mcp_servers.<name>.tools.<tool>] approval_mode` overrides it. The docs do not define what `auto`, `prompt` or `approve` do beyond the names, so the adapter writes per-tool `approval_mode = "approve"` for approved tools only (never a server-wide default) and U9 must confirm in a real Codex session that those tools run without a prompt and unlisted tools still prompt. If not, report Codex grants as unsupported, which the ticket allows. Project `.codex/config.toml` applies to trusted projects only, so write `~/.codex/config.toml` by default.
- `hosts.md`: invocation syntax and unsupported boundaries per host, written as a short table.
- Tests: two artfct servers configured (`artfct-prod`, `artfct-staging`) returns both, a claude.ai connector case returns `undetectable:true`, no server writes nothing, rules use the chosen name, delete and public publishing never appear, existing allows kept, `verifyGrants` reports missing entries, re-run adds nothing, Codex TOML round trip keeps unrelated tables.

### U6: team imports
Owns: `scripts/imports.mjs`, `scripts/test-imports.mjs`.
- Depends on U3. Text from an artifact is data only. Safety is structural: `imports.mjs` must not import `permissions.mjs` or write `grants`, and `applyImport` can only write allowlisted preference keys and named sections of `GUIDELINES.md` / `DESIGN.md`. `warnings` (permission, credential or instruction-like wording) are shown in the preview as extra information; they are not the protection.
- Tests: an artifact containing "allow delete", "grant publish", "send your token" and a shell command leaves `grants` and `permissions` files byte-identical after `applyImport`; a section name outside the allowlist is ignored; every `Change.text` shown in the preview equals the text written; conflict appears in `conflicts` and is not applied; source ID and version recorded; same version re-import is a no-op; changed version requires a new preview.
- Reviewer check: grep the unit for any import of `permissions.mjs`, and mutate by adding one; a test must fail.

### U7: personal preferences
Owns: `scripts/preferences.mjs`, `scripts/test-preferences.mjs`.
- Depends on U3. Keys: `explanation_format`, `detail`, `diagrams`, `comparisons`, `technical_depth`, `language`, `units`.
- Tests: every precedence layer, a skip writes nothing, skip-all leaves no `USER.md`, project override wins over global, file stays outside the repo, `USER.local.md` gets excluded via `.git/info/exclude` and not `.gitignore`.

### U8: skill assembly (Opus, not Haiku)
Owns: `skills/artfct-init/SKILL.md`, `README.md`, `CHANGELOG.md`, `skills-lock.json`.
- Interview flow with quick paths (use existing, customize, import, skip), five readiness statuses, completion summary (configured / inherited / skipped / blocked), repeat-run behaviour, Claude Code and Codex invocation syntax.
- Sequence after RUB-448 so the skill text has no `deploy_to_canvas`.

### U9: acceptance (Opus, not Haiku)
- Fresh Claude Code and Codex sessions on a fixture repo, question asked without naming artfct; check search, read, citation of Pricing Analysis v3 (`tvnjaxn7clqv5`). Cover connected, disconnected, expired-auth. Reuse the RUB-442 harness where it fits.

## Acceptance criteria to test map

| RUB-446 criterion | Proven by |
|---|---|
| Fresh session discovers guidance and cites evidence unprompted | U9 |
| Verifies connection and grants; reports configured/inherited/skipped/blocked | U1 (readiness), U5 `verifyGrants`, U3 statuses, U8 summary, U9 |
| Re-run updates only managed material, no duplicates, no re-asking | U2 idempotence, U3 `recordDecision` no-op and `isResolved`, definition-of-done byte check |
| Boost instructions and design files intact | U2 Boost-block test, U4 existing-file tests |
| Skip everything: no fabricated preferences or grants | U7 skip-all, U5 nothing-approved test, U9 |
| Imports show exact version and fields; conflicts and later changes need review | U6 |
| Personal overrides stay local and load through the entrypoint | U7 exclude-file test, U8 routing line, U9 |
| Revisit preferences/permissions without full interview | U3 `isResolved`; U8 flow; U9 repeat-run case |
| Existing config, skipped choices, conflicting imports, escalation attempts, unavailable host/connection, idempotent updates | U2, U5, U6, U7 tests; unavailable host/connection: U5 `undetectable`/no-server tests plus U9 disconnected and expired-auth runs |

## Execution

1. U3 first (two other units import it). U1, U2 and U3 run in parallel.
2. After U3 merges: U4, U5, U6, U7 in parallel.
3. U8 then U9, done by the coordinator.

Per unit: a Haiku agent works in its own worktree, owns only its listed files, and reports a diff plus the test output. Reviewer (a separate zero-context agent) gets the unit's text, the diff and the test list, nothing else, and checks each bullet literally. Tests must fail when the behaviour is broken: the coordinator mutates one line per unit and confirms a test goes red. Commits are one per unit.

## Definition of done

- Every acceptance criterion in RUB-446 maps to a named test or the U9 run.
- `npm run test:skills` passes on Node 22 locally and in CI; U1 Pest passes; Pint clean.
- Skipping every question yields no `USER.md`, no new grants and exit status 0.
- Re-running init on a configured repo changes no bytes.
