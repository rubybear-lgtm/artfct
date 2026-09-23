# Spec 09 partition

Solo, single unit (no subagents available — account spend limit). Working
directly in the main session.

## Why this spec's split differs from 05's

Specs 10/14/15's "implement against mocks" policy covers services whose
shape can be modeled (an IdP, a billing provider, Slack). Spec 09 needs
Workers for Platforms — a paid Cloudflare tier not enabled here, plus real
Logpush/Analytics Engine. Its own DoD text explicitly rejects a fake
standing in for the real guarantee: "The DoD requires executing a
partial-failure run, not describing one," "verified with concurrent
load," "verified against a second provisioned tenant." A fake provisioner
that reports success proves nothing about those specific claims — closing
them against a fake would be the vacuous-guard failure caught four times
already in this run (specs 04, 05, 06, 08), at spec scale.

## DoD split

Closable now, honestly, in Laravel against a fake provisioner (records
calls, supports per-tenant fault injection) — this is where the actual
engineering value in the spec lives, per the spec's own "the recurring
cost... the price floor" framing of the fleet migration runner:
- `php artisan tenant:provision acme` creates the script/D1/R2/hostname
  registration (against the fake) — orchestration + idempotency
- Running it twice produces no change, exits 0
- Killing it midway leaves `provisioning_failed` with the failed step
  recorded; re-running completes it
- `tenant:migrate --all` across N tenants brings all to head, reports
  versions
- One tenant's D1 deliberately made to fail: migrate the other N-1, exit
  non-zero, name the failed tenant, `tenant:status` shows the skew;
  re-running after the fault clears brings the fleet to head
- `tenant:deprovision` removes the script, D1/R2 retained (fake-verified)

Closable now in Rust, as pure functions (same shape as spec 07/08's
`decide_artifact_visibility`/`artifact_matches_filter`):
- `router_resolves_hostname_to_script`
- `unknown_hostname_returns_404_not_500`

NOT closable without a live Workers-for-Platforms dispatch namespace —
written as `#[ignore]`d integration tests naming the missing infra, same
as specs 03-05's pattern, not silently claimed:
- 60-second real provisioning wall-clock (DoD #1)
- `request.cf` unavailable inside a real untrusted script (DoD #6)
- CPU-limit isolation under real concurrent load (`dispatch_cpu_limit_isolates_noisy_tenant`)
- Logpush showing real tenant script logs (DoD #12)
- `tenant_script_cannot_read_other_tenant_bindings`,
  `two_tenants_are_fully_isolated_end_to_end` — need two real provisioned
  tenants

## Contract shape

`TenantProvisionerContract` follows `DnsResolverContract`/
`AuthKitClientContract` exactly: `RealTenantProvisioner` fails closed
(throws) unless a Cloudflare API token and account/namespace IDs are
configured; `FakeTenantProvisioner` is an in-memory double that records
every call and supports injecting a fault for one named tenant, bound in
testing the same way `FakeAuthKitClient`/`FakeDnsResolver` are.

New columns on `teams` (new migration — do not touch spec 06's):
`provisioned_at`, `provisioning_failed_step`, `release_version`,
`schema_version`.

## Documentation routing

Three user-facing Artisan commands land here (`tenant:provision`,
`tenant:migrate --all`, `tenant:status`, `tenant:deprovision`) — README's
"CLI flags or commands" trigger applies; add them without restructuring.
DOCUMENTATION.md gets a "Tenant provisioning" section in the established
style.

## Process note

No subagent budget for a zero-context review of this diff — the
implementer (me) cannot self-review with the rigor the skill calls for.
Mutation checks are still run and are still real evidence; the review gap
is stated plainly at close-out, not disguised as a completed review.
