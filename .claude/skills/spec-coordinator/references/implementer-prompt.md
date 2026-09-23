# Implementer prompt template

One subagent per work unit. `model: sonnet`, or `haiku` for mechanical units — config writing, doc edits, boilerplate.

**The prompt must be self-contained.** A smaller model needs the acceptance criteria in the prompt, not a pointer to a file it has to find and interpret. Never send "implement spec 3."

## Template

```
You are implementing one unit of work in the artfct repository at /Users/ruby/Documents/dev/artfct.

## Your unit: <unit name>

## Files you own — do not edit anything outside this list

<exact paths>

Other agents are working on other files in parallel. Editing a file outside your
list will cause a merge conflict and your work will be discarded.

## What done means — these exact conditions must be observably true

<the unit's DoD items, copied verbatim from the spec, as a checklist>

## Tests you must write — these exact names

<the unit's test names, copied verbatim from the spec>

Tests marked (negative) must fail when the guard they cover is removed. If your
test passes with the protection deleted, it is testing nothing — rewrite it.

## Design constraints

<the relevant Design subsection from the spec, copied>

## Project conventions

- PHP: curly braces always, constructor property promotion, explicit return types
  and parameter type hints, PHPDoc over inline comments. Run
  `vendor/bin/pint --dirty --format agent` before finishing.
- Rust: `cargo fmt` and `cargo clippy --all-targets -- -D warnings` must pass clean.
- Tests: Pest for PHP (`php artisan test --compact`), `cargo test` for Rust.
  Create PHP tests with `php artisan make:test --pest <Name>`.
- Match the conventions of sibling files. Check them before inventing a pattern.
- Do not add dependencies. Do not create documentation files.
- Use `php artisan make:` commands for new Laravel files, with `--no-interaction`.

## Report back

- Which DoD items you closed, and how each is observable
- Which tests you wrote
- Anything in the DoD you could NOT close, and why — say so plainly rather
  than reporting success
- Any file outside your list that you believe needs to change
```

## Notes for the coordinator

- **Copy DoD and test names verbatim.** Paraphrasing loses the observability that makes an item checkable.
- **Include the design subsection, not the whole spec.** The scope, rollback and deferred sections are coordinator concerns and only dilute the prompt.
- **Name the file list exhaustively.** "The worker" is not a file list; `backend/src/lib.rs` is.
- If an implementer reports a needed change outside its list, that is a **partition error**. Fix the partition and re-run, rather than letting the agent widen its own scope.
