---
name: release-to-production
description: >
  Ship work to production through the standard pipeline: babysit each PR's CI
  until green and merge it into develop, optionally verify on staging, then run
  the Release to production Action and confirm the deploy. Use when asked to
  "release to production", "ship this", "release this PR", "get this live",
  or "cut a production release".
metadata:
  internal: true
---

# Release to production

Standard path: feature branch → PR into `develop` → CI loop until green →
merge → optional staging acceptance → Release to production Action
(fast-forwards `main`) → verify Railway deploy + smoke. Production deploys
from `main`, staging from `develop`. Full reference: `RELEASING.md`,
`STAGING.md`.

## Phase 0 — Intake

1. Resolve scope: one PR number/branch, or the whole `develop` queue.
2. `git fetch origin develop main --tags`. Record `develop` HEAD; that (or the
   named PR head once merged) is the release candidate SHA.

## Phase 1 — PR CI loop (every PR targets `develop`)

1. Open the PR against `develop`, never `main`:
   `gh pr create --base develop --title "<scope>: <change>" --body "..."`.
   UI changes need before/after screenshots in the body; big changes
   (~300+ lines, migrations, auth/security, billing, public API/MCP) need the
   independent agent review round before merge.
2. Push. The pre-push hook runs the full workspace suite (Rust, PHP+frontend,
   Pest). Fix local failures first; `--no-verify` is for docs-only pushes,
   and say so when used.
3. Watch checks: `gh pr checks <PR> --watch --interval 30`.
4. On failure, find the run (`gh run list --branch <branch>`) and read the
   failure: `gh run view <run-id> --log-failed`. Fix it on the branch, push,
   go back to step 3. Loop until green. Never merge red, never
   close-and-reopen to reset CI.
5. Green means (mirrors `scripts/release/ci-checks.mjs`): `Pest (browser)`
   success, `MCP local end-to-end (Postgres + Worker + hosted OAuth)`
   success or skipped, `application` success, and every other non-Railway
   check success. `artfct - …` / Railway statuses are ignored.
6. Multiple PRs in flight: babysit each branch independently, serialize the
   merges. Before merging a PR that is conflicted or stale,
   `git fetch origin develop`, sync it (`git merge origin/develop`), push, and
   re-run the loop — merging PR A never re-validates PR B.

Merge with `gh pr merge <PR> --merge` (repo history uses merge commits).

## Phase 2 — Staging acceptance (only when warranted)

Default is isolated CI. Staging is warranted when the change touches hosted
MCP/OAuth, auth/session/WorkOS, billing, Worker data-plane contracts,
migrations with deploy side effects, Railway IaC, or sharing/privacy/token
paths (`scripts/staging/policy.mjs` classifies this).

- Warranted: `gh workflow run "Staging acceptance" --ref develop
  -f sha=<develop-SHA>` (read-only by default; add
  `-f authorize_mutations=true -f run_smoke=true` only for mutating smoke),
  then `gh run watch`. The SHA must be the exact commit live on staging.
- Not warranted: say so explicitly ("staging not warranted: <reason>").

Never use a Railway PR preview URL as evidence; they are not acceptance
targets (RUB-518).

## Phase 3 — Release (Action only)

Concurrency group `release-to-production` serializes releases; check
`gh run list --workflow release-to-production.yml` for one already in flight
before dispatching.

1. Dry run first:
   `gh workflow run "Release to production" --ref develop -f dry_run=true
   [-f sha=<sha>]` (default `sha` is current `develop` HEAD), then
   `gh run watch <run-id>`. It evaluates every gate plus credentials and
   prints the plan; it pushes and tags nothing.
2. Real run: same dispatch without `dry_run`. The Action refuses unless the
   SHA is on `develop`, `main` is its ancestor (fast-forward only, never
   force), `main` is not already on it, and the required checks on that exact
   commit pass (Railway statuses ignored, ~30 min timeout). It then
   fast-forwards `main`, tags `release-YYYYMMDD-HHMM`, and opens a GitHub
   Release with PR notes since the previous tag.

Never push to `main` yourself and never force-push. If `main` is not an
ancestor of `develop` (old develop→main merge commits), sync `main` into
`develop` first — the Action tells you exactly this.

## Phase 4 — Verify the deploy (merged is not live)

From the Action's job summary confirm: `main` == released SHA,
`release-*` tag and GitHub Release exist, Railway production
`web`/`queue`/`scheduler` report `SUCCESS` for that commit, and smoke passes
on `https://artfct.dev/up` and `/mcp` (`initialize` + `tools/list`). Confirm
the serving deploy carries the SHA before claiming the release is live.

On failure, the summary carries rollback pointers (previous release
tag/commit): redeploy the previous release commit in Railway or investigate
before the next fast-forward.

## Rules

- PRs target `develop`; only the Action writes `main`.
- One release at a time; watch (`gh run watch`) every dispatched workflow to
  completion and report the terminal state.
- Railway preview/deploy statuses are never gates and never evidence.
- Secrets (`RAILWAY_API_TOKEN_*`, `ARTFCT_SMOKE_BOT_TOKEN`) live in repo
  secrets and workflow masks; never print them, never export them locally —
  that is what `dry_run=true` on the Action is for.
