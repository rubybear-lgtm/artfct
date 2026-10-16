---
name: spec-coordinator
description: This skill should be used when the user asks to "implement spec N", "run spec 3", "coordinate the specs", "build out the spec", or otherwise asks to execute one of the numbered specs in docs/specs/. Invoking this skill is an explicit request to fan out implementer subagents and zero-context review agents — it partitions a spec into non-conflicting work units, delegates implementation, reviews against the spec's literal DoD checklist, runs a mutation check on negative tests, commits per unit, and keeps CHANGELOG.md, DOCUMENTATION.md and README.md current.
---

# Spec Coordinator

Executes one numbered spec from `docs/specs/` end to end. Each spec is a checkpoint: it is done when every DoD item is observably true and every named test exists and passes.

**Invoking this skill is the user's request to spawn subagents.** Do not fan out under any other circumstance.

## The rule that makes this work

Two rules carry most of the value. Everything else is process around them.

1. **Never fan out before partitioning.** Parallel agents editing the same file produce merge conflicts, not speed.
2. **Reviewers get the DoD, the test list, and the diff. Nothing else.** A reviewer who sees *why* something was done accepts it.

## Workflow

### 1. Read the spec, then partition

Read `docs/specs/NN-*.md` in full. Before anything else, write the partition to `docs/specs/.runs/NN/partition.md`:

| Unit | Owns these files | DoD items | Depends on |
|---|---|---|---|

Rules:

- **Units must own disjoint file sets.** A file appears in exactly one unit.
- Anything that cannot be made disjoint goes into a **serialized remainder** run in order after the parallel units.
- **Some specs are one unit. That is the correct answer**, not a failure to parallelize. Specs 3, 4 and 7 move handlers, schema and the request type together; forcing them apart creates conflicts. Specs 0, 1, 8 and 14 partition cleanly.
- If the partition has one unit, skip fan-out and implement directly.

State the partition to the user before delegating. It is the plan.

### 2. Fan out implementers

One subagent per parallel unit, `model: sonnet` (or `haiku` for mechanical units — config writing, doc updates). Use the template in `references/implementer-prompt.md`.

Each implementer prompt is self-contained: the unit's DoD items verbatim, its named tests verbatim, its exact file list, and the relevant design section. **Never "implement spec 3."** A smaller model needs the acceptance criteria in the prompt, not a pointer to them.

Run the serialized remainder after parallel units report.

### 3. Gate before reviewing

Run on each unit's changes, in the project root. A review agent spent on unformatted code is wasted.

```
cargo fmt --all -- --check
cargo clippy --all-targets -- -D warnings
cargo test
vendor/bin/pint --dirty --format agent
php artisan test --compact
npx @redocly/cli lint openapi/artfct.yaml   # once spec 0 has landed
```

Gate failures go back to the implementer that caused them, not to a reviewer.

### 4. Mutation check — required, not advice

**For every test the spec marks `*(negative)*`:** remove or invert the guard it covers, confirm the test fails, restore the guard, confirm it passes.

A negative test that passes with the guard removed is testing nothing. With 61 negative tests across these specs, a vacuously-passing guard test is the most likely way this ships a hole. Required for **specs 5, 7, 10 and 11** — that is where the guarantees live — and expected everywhere else.

Record results in `docs/specs/.runs/NN/mutation.md`. A negative test that survives its mutation is a **blocking** failure.

### 5. Zero-context review

Spawn a fresh agent per unit using `references/reviewer-prompt.md`. It receives:

- the spec's DoD checklist, verbatim
- the spec's Tests section, verbatim
- the diff (`git diff <base>..HEAD -- <unit files>`)

It receives **nothing else** — no conversation, no implementer notes, no spec prose beyond DoD and Tests, no explanation of intent.

The reviewer reports **per DoD item: pass / fail / cannot verify**, with evidence. Not a freeform opinion. The DoD checklist is the review contract; that is what makes a checkpoint mean anything.

Any `fail` goes back to an implementer with the reviewer's finding. Any `cannot verify` means the DoD item was not observable — fix the implementation or, if the item was badly written, fix the spec and say so.

### 6. Commit

- **Branch per spec**: `spec/NN-short-name`. Never commit on `main`.
- **Commit per unit**, after its gates and review pass.
- Message: `spec NN: <unit> — <what changed>`, with the DoD items closed listed in the body.
- **Surface commits to the user; do not assume standing authorization.** Push and PR only when asked.

### 7. Documentation

Routing is derived from the spec, not guessed:

| File | When | How |
|---|---|---|
| `CHANGELOG.md` | **Always** | Keep a Changelog format under `## [Unreleased]`. One entry per spec, not per unit. |
| `README.md` | Only when the **user-facing surface** changes — CLI flags or commands, API endpoints, install steps, MCP tool signatures | Edit the affected section only. Do not restructure. |
| `DOCUMENTATION.md` | Schema, architecture, or operational procedure changes | Create on first use — it does not exist yet |

`CLAUDE.md` forbids creating documentation files that were not requested. **`DOCUMENTATION.md` is authorized by the request that created this skill**; nothing else is. Do not invent further doc files.

Read the spec's **Deferred** section when writing docs — it says what deliberately did not ship, which is what stops the changelog overclaiming.

### 8. Close out

Report against the spec's headline DoD from `docs/specs/README.md`. State plainly:

- DoD items closed / total
- Tests added, and mutation-check results
- What was deferred and why
- **Whether rollback is still possible** — specs 3, 4, 5, 9 and 11 are marked partially or fully irreversible. Say so explicitly when crossing one.

## Failure handling

- **Gate fails** → back to the implementer that caused it.
- **Review fails** → back to an implementer with the finding, then re-review with a *fresh* agent. Never reuse a reviewer that has seen a previous round; it has context now.
- **Mutation check fails** → blocking. The guard or the test is wrong. Fix before proceeding.
- **Two failed rounds on one unit** → stop and report to the user. Do not iterate silently.

## References

- `references/implementer-prompt.md` — the self-contained unit prompt template
- `references/reviewer-prompt.md` — the zero-context review template, and what it must not receive
