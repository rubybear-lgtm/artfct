# Spec 9 — Tenant provisioning

**Track:** D (tenancy) · **Depends on:** 3, 5, 6, 7 · **Blocks:** 10, 11

## Scope

**In.** Workers for Platforms dispatch namespace. One script, one D1 database and one R2 prefix per tenant. One-call provisioning. A fleet migration runner that survives partial failure. Per-tenant observability. De-provisioning.

**Out.** Enterprise features (10, 11) and billing (14).

**This is the real milestone.** Everything before it is substrate; this is where revenue becomes possible.

## Decisions implemented

- **03** — dispatch-namespace script per tenant: untrusted mode, per-tenant bindings, per-dispatch limits, tag-based fleet operations.
- **12** — Cloudflare for the data plane, Railway for the control plane.
- **13** — every paying tenant gets a dedicated deployment at every tier, which is what makes the Team→Enterprise upgrade a flag flip rather than a migration.

## Design

### Why dispatch namespaces

The three isolation models and what each says to a security reviewer:

| Model | Claim | Ops |
|---|---|---|
| CF account per tenant | strongest | separate billing, tokens, limits; no cross-account tooling |
| **dispatch-namespace script per tenant** | **strong: untrusted mode, own D1/R2 bindings, per-dispatch CPU and subrequest limits, tag-based fleet ops** | **moderate — chosen** |
| one Worker, tenant-routed | "we check a tenant id in code" | cheapest, weakest thing you can say at review |

Scripts run **untrusted by default**: no `request.cf`, isolated cache, `caches.default` disabled. Keep it that way — the isolation claim is the product.

### Provisioning, as one call

`php artisan tenant:provision {org}` performs, idempotently:

1. Create D1 database, run migrations to head.
2. Create the R2 prefix.
3. Upload the tenant script into the dispatch namespace with its bindings, tagged `org:{slug}` and `release:{version}`.
4. Register the hostname pattern.
5. Record `provisioned_at` and the release version on the org.

Re-running is a no-op. Failure at any step leaves the org `provisioning_failed` with the failed step recorded — never half-provisioned and silently broken.

### Fleet migrations — the recurring cost

`php artisan tenant:migrate --all` runs the migration across every tenant D1. This is the operational cost that sets the price floor, so it is built properly rather than discovered later:

- Each tenant records its schema version.
- Runs are **resumable** — a rerun skips tenants already at head.
- Partial failure is expected, not exceptional: failures are collected, the run continues, and the command exits non-zero with a report naming every failed tenant and its version.
- `tenant:status` shows the version distribution across the fleet, so skew is visible rather than inferred.

**The DoD requires executing a partial-failure run, not describing one.** An untested migration runner means the price floor is a guess.

### Router

The dispatch Worker resolves hostname → tenant script via KV, then `env.DISPATCHER.get(script)`. `Worker not found` returns 404, never a 500. Per-dispatch `limits` cap CPU and subrequests per tenant.

### Observability

Logpush on the dispatch Worker captures all tenant script logs, filterable by script name. Analytics Engine records per-tenant usage — the input to spec 14's quotas.

### De-provisioning

`tenant:deprovision` removes the script, keeps D1 and R2 for the retention window, then hard-deletes. Reversible until the window closes; the window is defined in spec 11.

## Definition of done

- [ ] `php artisan tenant:provision acme` creates the script, D1, R2 prefix and hostname registration, and completes in under 60 seconds.
- [ ] Running it twice produces no change and exits 0.
- [ ] Killing the command midway leaves the org `provisioning_failed` with the failed step recorded; re-running completes it.
- [ ] A deploy with acme's org token serves from acme's script and reads acme's D1.
- [ ] Deploying with acme's token cannot read beta's artifacts — verified against a second provisioned tenant.
- [ ] Tenant scripts run untrusted: `request.cf` is unavailable inside one.
- [ ] A tenant exceeding its per-dispatch CPU limit gets 429 and **no other tenant is affected** — verified with concurrent load.
- [ ] A request for an unprovisioned hostname returns 404, not 500.
- [ ] `tenant:migrate --all` across 10 tenants brings all to head and reports versions.
- [ ] **With one tenant's D1 deliberately made to fail, `tenant:migrate --all` migrates the other nine, exits non-zero, names the failed tenant, and `tenant:status` shows the version skew. Re-running after the fault is cleared brings the fleet to head.**
- [ ] `tenant:deprovision` removes the script while D1 and R2 survive the retention window.
- [ ] Logpush shows tenant script logs filterable by script name.

## Tests

**Pest — feature**
- `provision_creates_all_resources`
- `provision_is_idempotent`
- `provision_failure_records_step_and_is_resumable`
- `deprovision_removes_script_retains_data`
- `migrate_all_brings_fleet_to_head`
- `migrate_all_continues_past_failure_and_reports` — the partial-failure case
- `migrate_all_is_resumable_after_fault_cleared`
- `tenant_status_reports_version_skew`

**`backend`**
- `router_resolves_hostname_to_script`
- `unknown_hostname_returns_404_not_500` *(negative)*
- `tenant_script_cannot_read_other_tenant_bindings` *(negative)*
- `dispatch_cpu_limit_isolates_noisy_tenant` *(negative)*

**Integration**
- `two_tenants_are_fully_isolated_end_to_end`

## Rollback

**Irreversible in practice once tenants exist.** Provisioning creates per-tenant resources holding customer data. De-provisioning is the supported reversal and is retention-bounded. Test both directions before the first customer.

## Deferred

- Multi-region tenant placement — spec 11's residency requirement.
- Automatic rollback of a bad fleet release. The runner reports and halts; automated rollback across N tenants needs the version history to be trustworthy first.
