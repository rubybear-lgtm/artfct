# Spec 14 partition

Solo session (no subagent budget since spec 08). One unit. Stripe billing
and Cloudflare for SaaS custom hostnames are external services per the
user's earlier mock-only decision (same category as specs 10/15) —
implemented against injectable fakes.

## Closable honestly in this environment

- `PlanGate::requireEnterprise()` — the single call site every enterprise
  feature checks (SIEM export, retention config, SSO transition, legal
  hold). `plan_gate_has_single_call_site` is a structural grep test, not
  a behavioral one — it fails the build if any file besides `PlanGate.php`
  compares `$team->plan` directly.
- `QuotaService`: warning at 80%, refusal at 100% with `errorCode:
  quota_exceeded` (named `errorCode`, not `code` — redeclaring
  `Exception::$code` with a different type is a PHP fatal error, caught
  and fixed mid-session, see mutation.md), a distinct
  `BundleTooLargeException` (`errorCode: bundle_too_large`) below spec 4's
  absolute ceiling. Payment-past-due also refuses creation through the
  same gate (spec 14: "new creates are refused").
- `BillingService`: seat counting from active memberships, checkout
  session creation + `applyCheckoutCompleted` (sets `plan = team`),
  `applyPaymentFailed`/`applyPaymentSucceeded` (the read-only
  degrade/restore, driven by webhook-shaped methods rather than a live
  webhook route — see below).
- Custom hostname uniqueness: a real, DB-level unique constraint (not
  merely an application check a race could slip past) plus a validation
  rule so a violation surfaces as a clear message, not a 500 — mutation
  tested by removing the validation rule and confirming a raw
  `PDOException` (not a friendly error) is what the guard prevents.

## Structural / documented, not fully wired

- **Quota enforcement is not wired into the Worker's create path.**
  Artifact creation happens on the Worker (`POST /v1/artifacts`), which
  Laravel doesn't sit in front of — the same cross-boundary gap as spec
  11's audit-on-create, spec 12's indexing-trigger, and spec 13's (solved)
  search auth. This is the fourth occurrence of the identical structural
  gap in this run; see DOCUMENTATION.md for the pattern named plainly.
  `QuotaService::assertCanCreateArtifact()` is real, tested code — it is
  simply not yet the thing the Worker's create handler calls.
- **No live Stripe webhook route.** `applyCheckoutCompleted`/
  `applyPaymentFailed`/`applyPaymentSucceeded` are real methods with real
  tests; a route that verifies Stripe's webhook signature and calls them
  was not built (no live Stripe account to sign a real webhook against).
- **No billing settings UI.** Checkout/hostname/quota-warning routes and
  services exist and are tested; a React settings page presenting them
  was not built this session — this is also why the two Pest browser
  tests (`checkout_flow_completes_and_sets_plan`,
  `console_loads_at_branded_hostname`) are not implemented. A `visit()`
  test against a real Stripe checkout URL couldn't complete anyway
  (external domain, fake session id).

## Not closable here

- `artfct.acme.com` resolving with a valid certificate needs a live
  Cloudflare for SaaS zone.
- "Usage figures reconcile with Analytics Engine" needs the live account
  spec 9 already deferred.
