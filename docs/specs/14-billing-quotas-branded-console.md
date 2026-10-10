# Spec 14 — Billing, quotas and branded console

**Track:** G (commercial) · **Depends on:** 8, 9, 11 · **Blocks:** nothing

## Scope

**In.** Plan gates, per-tenant quotas, Stripe billing for the Team tier, usage metering, and the tenant-branded console.

**Out.** Enterprise invoicing — annual contracts are handled manually until volume justifies otherwise. Branded *artifact* origins, which need a Cloudflare Enterprise zone.

## Decisions implemented

- **13** — the upgrade is a flag flip; plan is a column and features are gates on it.

## Design

### Plan gates

`plan` on the org: `team` | `enterprise`. Every enterprise feature — audit export, retention, legal hold, residency, SSO — checks it in one place, not scattered through controllers. A gate that exists in three files is a gate that will be wrong in one of them.

Upgrading is setting the column plus the `auth_mode` transition from spec 10. Nothing moves.

### Quotas — the only line that can run away

Infrastructure is roughly $60 per tenant per year, so cost is not the constraint. **Bundle size is**: everything scales off it linearly, and 20 MB average bundles instead of 2 MB takes storage from $145 to $1,450 a month at 100 tenants.

Per-tenant: total storage, artifacts per month, bundle size ceiling (below the absolute ceilings from spec 4), and browser-render minutes.

Behaviour at the limit is a soft warning at 80%, then refusal to create new artifacts — **never deletion of existing ones, and never a serving outage**. A quota breach must not take down artifacts someone already shared.

Usage comes from Analytics Engine, wired up in spec 9.

### Billing

Stripe for Team, per seat. Seats are active members, counted at period boundaries. Enterprise is invoiced manually — with a handful of contracts, automating it costs more than it saves.

Payment failure degrades to read-only: artifacts keep serving, new creates are refused. Deleting a paying-then-lapsed customer's data is how you get written about.

### Branded console

`artfct.acme.com` pointing at the Laravel app. **One** custom hostname per tenant — Cloudflare for SaaS includes 100 on Free, Pro and Business, then $0.10 each. Effectively free.

Explicitly **not** branded artifact origins (`<id>.artifacts.acme.com`). Those need a *wildcard* custom hostname, which is Cloudflare Enterprise-plan only. The distinction changes the cost by an order of magnitude, and the console is what buyers usually mean by "our domain". Do not price the second as a cheap add-on.

## Definition of done

- [ ] An org on `team` is refused audit export, retention config and SSO — each with a clear upgrade message, not a 500.
- [ ] Setting `plan = enterprise` grants all of them with no other change.
- [ ] Every enterprise feature checks the plan through one shared gate — verified by there being a single call site.
- [ ] An org at 80% of its storage quota sees a warning in the console.
- [ ] An org at 100% is refused new artifact creation with `code: quota_exceeded`.
- [ ] **An org over quota continues to serve every existing artifact.**
- [ ] A bundle exceeding the tenant's per-artifact ceiling is refused, below spec 4's absolute ceiling.
- [ ] Usage figures in the console reconcile with Analytics Engine within one billing period.
- [ ] Stripe checkout creates a subscription and sets `plan = team`.
- [ ] Adding a member mid-period bills correctly at the next boundary.
- [ ] Payment failure moves the org read-only: artifacts serve, creates refused, nothing deleted.
- [ ] Restoring payment restores create access without operator intervention.
- [ ] `artfct.acme.com` resolves to the console with a valid certificate.
- [ ] A tenant cannot claim a hostname already claimed by another tenant.

## Tests

**Pest — feature**
- `team_plan_refused_audit_export` *(negative)*
- `team_plan_refused_retention_config` *(negative)*
- `team_plan_refused_sso` *(negative)*
- `enterprise_plan_grants_all_gated_features`
- `plan_gate_has_single_call_site`
- `quota_warning_at_eighty_percent`
- `quota_exceeded_refuses_creation` *(negative)*
- `quota_exceeded_still_serves_existing_artifacts`
- `bundle_over_tenant_ceiling_refused` *(negative)*
- `seat_count_matches_active_members`
- `payment_failure_degrades_to_read_only`
- `payment_failure_does_not_delete_data` *(negative)*
- `payment_restored_restores_creates`
- `duplicate_custom_hostname_rejected` *(negative)*

**Pest — browser**
- `checkout_flow_completes_and_sets_plan`
- `console_loads_at_branded_hostname`

## Rollback

Reversible. Plan and quota are columns; Stripe subscriptions can be cancelled. Custom hostnames can be released.

## Deferred

- Branded artifact origins — needs a CF Enterprise zone; revisit when a customer's contract justifies it.
- Usage-based pricing beyond seats. Seat pricing is simpler to sell and simpler to reason about; revisit if artifact volume decouples from headcount.
- Automated enterprise invoicing.
