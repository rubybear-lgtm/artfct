# Staging acceptance (RUB-518)

Artfct has **staging** and **production** only. There are no PR preview
environments for acceptance. Not every pull request needs deployed
verification.

## Default path: isolated CI

Every PR still runs the required GitHub Actions checks in
`.github/workflows/ci.yml` (unit/feature Pest, browser Pest, hosted MCP
contract tests, path-filtered MCP local e2e, OpenAPI/contract, rust, secrets,
`application`, Release readiness). Those jobs do **not** need:

- a Railway PR preview URL
- staging credentials
- a Railway `artfct - *` preview status

Ordinary docs/copy/skill-draft changes stop there.

## When staging acceptance is warranted

Use live staging when the change can only be proven on a deployed control
plane / Worker pair, for example:

- hosted MCP tool or OAuth behavior
- auth / session / WorkOS flows
- billing
- Worker / artifact data-plane contracts
- database migrations with deploy side effects
- Railway staging IaC / deploy scripts
- sharing / privacy / access-token paths

Risk classification helpers live in `scripts/staging/policy.mjs`.

## How to run it

Manual workflows only (never on every PR):

1. **Staging acceptance** — Actions → “Staging acceptance”
   - `sha`: exact commit that must be live on staging (`develop` deploy; at
     least 12 hex chars). `SLEEPING` staging-web counts as deployed.
   - `base_url`: must be `https://staging.artfct.dev`
   - `authorize_mutations`: default `false` (read-only SHA check). Required
     before any shared-staging mutation.
   - `run_smoke`: default `false`. Hosted MCP smoke mutates staging, so it
     only runs when both `run_smoke` and `authorize_mutations` are true.
2. **mcp-live** — hosted MCP smoke only; the workflow rejects production and
   Railway preview hosts before running `scripts/mcp-live-smoke.mjs`. That
   workflow is inherently mutating; prefer Staging acceptance for SHA-gated
   read-only checks.

Local checks:

```sh
node scripts/staging/assert-target.mjs https://staging.artfct.dev
node --test scripts/staging/*.test.mjs
```

SHA verification talks to Railway with `RAILWAY_API_TOKEN` (staging
environment secrets) and requires `staging-web`, `staging-queue`, and
`staging-scheduler` to be `SUCCESS` or `SLEEPING` for that commit.
Production `web` / `queue` / `scheduler` is never a fallback.

## Evidence requirements

- Isolated CI green on the PR commit (required checks unchanged).
- When staging is warranted: the acceptance run must name the SHA, the
  staging URL, and pass/fail per check. Do not claim static tests prove
  deployed behavior.
- When staging is not warranted: say so explicitly (or leave live
  verification pending). Do not create a PR preview to “fill the gap”.

## What this does not do

- Does not disable Railway native `prDeploys` (see RUB-519).
- Does not mutate shared staging unless `authorize_mutations` is set.
- Does not replace production release gates (see pending RUB-517 / PR #101).
