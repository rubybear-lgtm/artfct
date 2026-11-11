# Releasing Artfct to production

Production Laravel services (Railway `web`, `queue`, and `scheduler`) deploy
from the `main` branch. Feature work merges into `develop`; staging deploys
from `develop`.

## Release to production (GitHub Action)

Releases are a manually triggered workflow: **Actions → “Release to production”
→ Run workflow**.

Inputs:

| Input | Default | Meaning |
| --- | --- | --- |
| `sha` | current `develop` HEAD | Exact commit to release. Must be reachable from `develop`. |
| `dry_run` | `false` | Run every gate, check the Railway and smoke-bot credentials with read-only calls, and print the plan; push and tag nothing. A bad or missing secret fails the dry run. |

What the Action does:

1. Confirms the commit is on `develop`.
2. Confirms `main` is an ancestor of that commit (fast-forward only; never a
   force push), and that `main` does not already point at it. Releasing the
   commit `main` is on fails with "Nothing to release".
3. Waits for required GitHub Actions checks on that exact commit. The Action's
   own run is excluded from the wait. Pest
   (browser), the MCP e2e job, and `application` must be present; browser and
   `application` must be `success`, and MCP e2e must be `success` or
   `skipped` (path filter). Other non-Railway checks on the commit must also
   succeed. Pending checks time out as failure. Railway preview/deploy
   statuses (`artfct - …`) are ignored. When GitHub retried a check, only the
   latest attempt per name counts.
4. Unless `dry_run`: creates an annotated tag `release-YYYYMMDD-HHMM` (UTC)
   locally, then atomically fast-forwards `main` and publishes that tag
   (`git push --atomic origin <sha>:main refs/tags/<tag>` — never force), and
   opens a GitHub Release whose notes list PRs since the previous `release-*`
   tag. Atomic push avoids leaving `main` advanced when the tag is rejected.
5. Polls Railway until production `web`, `queue`, and `scheduler` show
   `SUCCESS` for that commit.
6. Smoke-tests `https://artfct.dev/up` and `https://artfct.dev/mcp`
   (`initialize` + `tools/list` against the tool catalog in
   `app/Mcp/Servers/ArtfctServer.php`).
7. Writes a job summary with rollback pointers (previous release tag/commit).

Local dry-run of the gates and credentials (no push). It needs the Railway and
smoke-bot tokens exported as `RAILWAY_API_TOKEN` and `ARTFCT_SMOKE_BOT_TOKEN`:

```sh
GITHUB_TOKEN=$(gh auth token) \
  node scripts/release/release.mjs --sha "$(git rev-parse origin/develop)" --dry-run
```

Automated tests for the gates and poller:

```sh
node --test scripts/release/*.test.mjs
```

## Release readiness CI check

`.github/workflows/ci.yml` still runs on pushes to `main` and on pull
requests into `main`/`develop`. The named check **“Release readiness: OpenAPI,
contract and hosted MCP checks”** is part of that workflow, so a fast-forward
push to `main` still creates it. Railway production `web` waits on check suites
(`checkSuites: true` in `.railway/production.ts`); do not remove the `main`
push trigger or the `release_readiness` job, or production web deploys will
hang.

## First-time / one-shot notes

If `main` is not an ancestor of `develop` (merge commits from the old
develop→main release PR flow), the Action will refuse to push and tell you to
sync `main` into `develop` first. After the Action itself is on `main` (one
last conventional promotion of this workflow, or a tiny workflow-only PR into
`main`), stop opening develop→main release PRs and use the Action only.

## Secrets

Repo secrets used by the Action (set by a repo admin; not committed):

- `RAILWAY_API_TOKEN_PRODUCTION` — a Railway **project token** created for the
  `production` environment (Project settings → Tokens). It is exposed to the
  script as `RAILWAY_API_TOKEN` and sent in the `Project-Access-Token` header.
  The dry run checks that the token is bound to the production project and
  environment. Staging acceptance reads `RAILWAY_API_TOKEN_STAGING` the same
  way. To run the scripts locally with an account token instead, set
  `RAILWAY_API_TOKEN_KIND=bearer`.
- `ARTFCT_SMOKE_BOT_TOKEN` — bot Bearer token that can call production MCP
  `initialize` / `tools/list`.
- `RELEASE_PUSH_TOKEN` (optional) — fine-grained PAT or similar that can push
  to `main` and create tags/releases if branch protection blocks
  `github.token`.

Never print these values. The workflow masks them with `::add-mask::`.
