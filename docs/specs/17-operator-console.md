# Spec 17 — Operator console

**Track:** H (operations) · **Depends on:** 6, 8, 9, 11, 14 · **Blocks:** nothing

## Scope

**In.** A staff-only console for artfct operators, at `/ops`, outside any team. Phase 1 is read-only: a tenant list, a tenant detail page, and a platform health page. Phase 2 adds a small set of audited support actions that wrap operations which today are artisan commands. A staff identity that is separate from `TeamRole`, and an operator audit trail that tenants can see.

**Out.**
- The team admin console (spec 8) and team settings pages. They are unchanged.
- Impersonation and "view as". Deferred; see below.
- Reading artifact content or titles. The operator console shows metadata and counts only.
- Replacing the Stripe, WorkOS, Railway or Cloudflare dashboards. The console links to them and does not mirror them.
- Dedicated per-tenant databases (RUB-318, undecided). This spec assumes the shared database that exists today.

## Decisions implemented

- **09** — the console lives in Laravel/Inertia.
- **13** — plan is a column. The console reads and, in phase 2, sets that column through the existing billing service.

New decisions, recorded here for the decision record:

- **Staff identity is not a team role.** It is a nullable `staff_at` timestamp on `users`, set only by an artisan command. A staff user has no implicit powers inside any team.
- **Operator actions are tenant-visible by default.** Every operator read of a tenant detail page and every write lands in that tenant's own audit log, labelled as artfct staff.
- **Operator reads can be withheld under a suppression case.** Only for legal requests and abuse investigations, time-bound, created by a separate privilege, and always recorded in an internal operator log. Operator writes are never withheld.

## Design

### What exists today

Verified against `develop`:

- `users` has no staff concept. It carries `deactivated_at` and `terms_*`, which is the pattern `staff_at` follows.
- `teams` already holds what a tenant list needs without a Worker call: `plan`, `payment_status`, `stripe_customer_id`, `stripe_subscription_id`, `seats_billed`, `retention_days`, `region`, `release_version`, `schema_version`, `provisioned_at`, `provisioning_failed_step`, `cancel_at_period_end`, `current_period_end`, `deleted_at`.
- `audit_events.actor` is a free string and `audit_events.team_id` is nullable. The table is append-only, enforced in `AuditEvent`.
- There is no `artifacts` table in Laravel. Artifact data, storage bytes and monthly counts live in the Worker's D1. `UsageContract` / `RealUsage` already reads `GET /v1/orgs/{org}/usage` and returns storage, artifacts this period, period bounds and enforced limits.
- Laravel already holds the operational signals a health page needs: `failed_jobs`, `jobs`, `artifact_indexing_failures`, `org_token_revocation_retries`, `stripe_events_received`, `worker_events_received`, `mcp_activities`.
- Operator operations exist as commands: `governance:erase`, `tenant:status`, `tenant:provision`, `tenant:migrate`, `index:*`, `billing:sync-limits`, `billing:sync-seats`, `readiness`.

### Staff identity and access

- Migration: `users.staff_at` nullable timestamp. It is not in `$fillable` and is not reachable from any request.
- Commands: `staff:grant {email}` and `staff:revoke {email}`. Each writes an audit event with `team_id` null.
- Middleware `EnsureStaff` on the whole `/ops` group. A non-staff **or signed-out** user gets **404**, not 403 and not a login redirect, so the surface is not discoverable. This matches spec 8's cross-org 404 posture. For that reason `EnsureStaff` runs first and replaces the `auth` middleware on this group, since `auth` would redirect a guest to the sign-in page and reveal that the route exists.
- Routes: `Route::prefix('ops')->name('ops.')->middleware(['web', EnsureStaff::class, 'throttle:ops'])`, defined outside `{current_team}`. The team membership and team URL-default middleware do not apply.
- **No `Gate::before` for staff.** A `before` hook returning true would grant every ability, including `ConsolePolicy` and `TeamPolicy`, inside tenants. Staff authorization is one explicit `operate` gate plus the middleware, and nothing else. A staff user who is not a member of a tenant gets the normal 404 on that tenant's team routes.
- The staff flag is not placed in Inertia shared data for non-staff users. The `/ops` shell reads it from its own props.
- `NoIndexOutsideProduction` plus an explicit `noindex` header on `/ops`.
- **Staff sessions are bound to a dedicated WorkOS organization.** The application cannot observe whether a given sign-in used MFA: the authenticate response reports `authentication_method` and `organization_id`, and documents no MFA indicator. And an environment-wide MFA requirement would force MFA on every customer, so it is not an option. WorkOS does offer a per-organization MFA policy (`domainMfaRequired` and `nonDomainMfaRequired` on the organization's settings). So:
  - Create an `artfct staff` organization in WorkOS with both flags on. Its id is configured as `services.workos.staff_organization_id`.
  - At the AuthKit callback, capture `organization_id` from the authenticate response and, only when it equals the staff organization id and the real (not fake) client produced the session, set a session flag `staff_session`. `AuthKitProfile` gains a nullable `organizationId`.
  - `EnsureStaff` requires both `users.staff_at` and the `staff_session` flag. A staff user who signed in any other way, or whose session predates the flag, gets 404 and must sign in again through the staff organization. Sign-in to `/ops` is started with the staff organization as a hint so the organization's policy applies.
  - The residual risk is that the policy is switched off in the WorkOS dashboard without the application noticing. Mitigation is on the health page (`ops.health`): show the configured staff organization id and when it was last used, and treat a periodic read of the policy as deferred.
- **Dev login must never produce a staff session.** `AUTHKIT_DEV_LOGIN_ENABLED` is an opt-in that exists outside local and testing (with a domain allowlist), and its sessions come from `FakeAuthKitClient` with no organization. `staff_session` is set only on the real client path, so `staff_at` plus dev login yields 404 on `/ops`. In `local` and `testing` environments only (an explicit `app()->environment()` check, never the dev-login flag), `EnsureStaff` accepts `staff_at` alone so the console is usable without WorkOS.
- **Staff who are also tenant members.** The two identities do not interact. Actions taken through normal team routes are attributed to the person as a member, not as staff. Anything done under `/ops` is always staff, including on a tenant the person belongs to, and `operator.tenant_viewed` still fires there.

### Content boundary

**Decision: artfct staff cannot read a team's artifacts or anything derived from their content. Team admins can, as they do today and unchanged.**

This is an application-level boundary, enforced by what `/ops` is able to reach. It is not a cryptographic one: search and indexing require the platform to read content, and anyone with direct database or storage access can still read it. The spec makes no claim beyond what the application enforces.

What staff never see anywhere under `/ops`, in any phase:
- Artifact titles, filenames, descriptions, bytes, rendered output, versions' content, and provenance fields such as repository URL and commit message.
- Indexed text: `artifact_chunks.text`, `artifact_index_entries` text, headings and titles.
- Comment bodies, collection names and descriptions.
- Search queries. `SearchService` stores the query text in `audit_events.target` for `search.performed`, so the tenant detail page's recent-events panel **omits `target`** for any event type not on an explicit allowlist of non-content targets (member, role, token, billing, team and operator events). For all others it shows type, actor, outcome and time only.

How it is enforced, not just promised:
- **No code path.** `/ops` controllers and services do not depend on `ArtifactContentSource`, `ArtifactDirectory`, the viewer, preview or link-minting services, or the models `ArtifactChunk`, `ArtifactIndexEntry`, `ArtifactComment` and `Collection`. A Pest `arch()` test fails the build if any class under the `/ops` namespace uses them.
- **No membership shortcut.** Staff get no team abilities from `staff_at`, so the existing viewer, console open and export routes (all gated on membership and `isAdminOf`) return 404 or 403 to a staff user who is not a member.
- **Server-side actions stay content-blind.** Phase 2 reindex runs the platform's own indexing, which reads content inside the job; its result shown to staff is a status and counts, never text.
- **A staff user who is a member is a member.** If a customer invites a staff person into their team, that person sees what a member (or admin) of that team sees, through normal team routes, attributed as a member, as set out above. Operator accounts are not added to customer teams by artfct.

Team admins are unaffected: they keep read access to every private artifact in their team, the console open and export routes, and the reindex and revoke actions. A regression test pins this.

### Phase 1 — read-only

**Tenant list** (`ops.tenants.index`).
- Search over name, slug, billing email, Stripe customer id.
- Filters: plan, payment status, provisioning failed, region, past due, cancelling at period end, soft-deleted.
- Cursor pagination on `(created_at, id)`, as spec 8.
- Columns: name, slug, plan, payment status, seats, region, release and schema version, created, state.

**Tenant detail** (`ops.tenants.show`).
- Core: identity, plan, payment status, Stripe ids with deep links, retention, region, versions, provisioning state and failed step.
- Members: count, admins listed by name and email, pending invitations.
- Connections: active MCP connections and org tokens, counts and last used.
- SSO: connection state through `PolisAdminClient`.
- Usage versus quota: storage, artifacts this period, enforced limits, from the Worker. Loaded as a deferred prop with a skeleton. A Worker failure degrades that panel only; the page still renders.
- Recent audit events for the tenant, including operator events, with `target` omitted for non-allowlisted event types (see the content boundary).
- Counts only for artifacts. No titles, no content, nothing derived from content.

**Health** (`ops.health`).
- Failed jobs and queue depth, artifact indexing failures, pending org token revocation retries, newest Stripe and Worker events received, and the integration readiness already computed by the `readiness` command (extract its checks into a service so the page and the command share them).
- Platform-wide only. Per-tenant slices link to the tenant detail page.

### Operator audit trail

- New `AuditEventType` cases, all prefixed `operator.`: `operator.tenant_viewed` for a detail page view (at most once per staff user and tenant per hour), and one per phase 2 action.
- `actor` is stored as `staff:{user_id}`. The tenant audit page renders any `staff:` actor as "artfct staff" without naming the individual. The console resolves the id to a person for artfct's own use.
- `team_id` is always set for tenant-scoped events. An event with a null `team_id` would never appear in the tenant's own log.
- Mutating actions require a reason, stored in a nullable `reason` text column on `audit_events`. This is additive and preserves the append-only guarantee, because the model still refuses update and delete. The reason is **not** put in `target`: `target` is the object acted on, and tenants and SIEM consumers filter by it.
  - The JSON Lines export built by `SiemExportService` has a fixed key set; `reason` is added as an additional key (null when absent). This is a Laravel-side export and is not described in `openapi/artfct.yaml`, so no API contract change is required. The export test (`siem_export_is_valid_jsonl`) is extended to cover the key.
  - Rows the Worker emits reach the same table through `ProcessWorkerEvent`, so they simply carry a null `reason`.
  - The reason is shown to the tenant, so the input says so: "This reason is visible to the customer's admins."
  - Tenant visibility is the default. The only exception is a suppression case, below.
- The tenant audit page resolves only numeric actors to names; any other actor string is shown as stored. It gains a branch that renders `staff:*` as "artfct staff".
- Because `team_id` is `nullOnDelete`, events for a hard-erased tenant are orphaned. That is existing behaviour and is left as is.

### Internal operator log and suppression cases

Suppression is implemented as **never writing the tenant copy**, not as hiding rows. `audit_events` is read by more than one tenant-facing path (`AuditLogController::index`, its person picker, and `SiemExportService::export`), and a visibility column would have to be filtered in every one of them; a single missed path leaks the event. Not writing the row means none of them can leak it, and none of them change.

- **`operator_audit_events` (new, append-only like `AuditEvent`).** Receives every operator event at full fidelity, always: `team_id` and `team_slug` (no foreign key, so it survives a tenant erase), `event_type`, `staff_user_id`, `target`, `reason`, `ip`, `user_agent`, `outcome`, `suppression_case_id` (null when the tenant copy was written), `created_at`. It has the same append-only guards as `AuditEvent`.
- **`OperatorAuditLogger`.** The only way `/ops` records anything. It writes the internal row first, then calls the existing `AuditLogger` for the tenant copy unless a suppression applies. With no active case, the result is visible, which is the default.
- **`operator_suppression_cases` (new, mutable; every change is also written to the internal log only).** Columns: `team_id`, `category` (`legal_request` or `abuse_investigation`), `reference` (ticket or order id, required), `created_by_user_id`, `expires_at` (required, at most 90 days from creation or renewal), `released_at`.
- **A case, not a per-event toggle.** While a case is active for a tenant, operator **read** events on that tenant skip the tenant copy. There is no checkbox on any action form, because a checkbox gets ticked by mistake.
- **A separate privilege to create, renew or release a case.** `users.case_manager_at`, granted only by `staff:grant {email} --case-manager`. Holding `staff_at` is not enough. Creating, renewing and releasing a case are recorded in the internal log only, never in a tenant's log.
- **Time-bound.** A case must expire. Renewing needs a new reference or reason from a case manager and starts a new window of at most 90 days. Open-ended withholding therefore always has a recurring human decision behind it.
- **Release on expiry.** A scheduled command (`ops:release-suppressed`) writes the withheld tenant copies with their **original timestamps** once a case expires or is released, then sets `released_at`, in one transaction, so it is idempotent per case. `AuditEvent` has `$timestamps = false` and `created_at` is not fillable, so the release job assigns `created_at` on a new instance before insert (the save guard allows the insert of a new row).
- **Scope: reads only.** Suppression covers `operator.tenant_viewed` and any future view-as or content access. Phase 2 mutating actions (reindex, limits, plan change, erase) are **never** suppressed: the tenant will observe their effects, and hiding the explanation only causes confusion.
- **No tell.** The tenant audit page shows nothing that indicates withheld events (no count, no gap marker).
- **Published terms.** The privacy page describes "an audit log of actions in your team" and says audit records are append-only and kept with the team. A suppression feature must be disclosed there (operator access may be withheld from your audit log where legally required or during an abuse investigation) before this ships. That edit is made by the owner of the legal pages, not by this spec's implementation.

### Phase 2 — audited actions

Every action calls the same service class the matching artisan command calls. There is no second implementation, and the CLI and the UI produce the same audit row apart from `actor`.

| Action | Wraps | Guard |
|---|---|---|
| Reindex an artifact or a tenant | `IndexArtifactCommand`, `IndexBackfillCommand` | reason |
| Push org limits (re-sync to the Worker) | `OrgLimitsWriter`, `billing:sync-limits` | reason |
| Change plan or payment status (comp, extend) | `BillingService` | reason, shows the diff first |
| Erase a tenant | `GovernanceEraseCommand` service | dry run shown first, typed confirmation of the slug, reason |

Erase is the only irreversible action. It always runs the dry run first and displays the plan, and the confirm step requires typing the team slug.

The commands leave the same rows when an operator runs them: `billing:sync-limits` and `indexing:backfill` take `--reason` (5 to 500 characters, the console's rule) and then record the same `operator.limits_changed` / `operator.reindex_requested` event, with the same target, reason and outcome (`failure` on a refusal), through `OperatorAuditLogger::recordForCli`. Only the actor differs (`staff` for a command line, `staff:{id}` for the console), and the internal log keeps `cli` as the address. A run with no `--reason` is routine and records nothing: `billing:sync-limits` is scheduled hourly, and a tenant must not see a "limits changed" row every hour. `governance:erase` already writes the same rows through `ErasureService`.

Reindex only dispatches a queued job: the `/ops` namespaces never depend on the content services, so the job (which reads content as the platform's own indexer) is the only place that does, and staff see "queued", never counts or text.

A plan or payment-status change is pushed to the Worker only after the change and its audit row have committed, so a rolled-back change is never enforced.

Real `/ops` POST routes keep CSRF protection. A missing or expired token on an `/ops` URL answers the ordinary 404 instead of 419, so it does not reveal the route; every other route keeps 419.

## Definition of done

**Phase 1**

- [ ] A user without `staff_at` requesting any `/ops` URL receives 404, including a team admin and a team owner, and a signed-out request receives 404 rather than a redirect to sign-in.
- [ ] A user with `staff_at` whose session came through dev login, or through any WorkOS organization other than the staff organization, receives 404 on `/ops`.
- [ ] A user with `staff_at` who signs in through the staff organization reaches `/ops`.
- [ ] `staff:grant` sets `users.staff_at`; `staff:revoke` clears it; both leave an audit event.
- [ ] No HTTP request, including profile and account updates, can set `staff_at`.
- [ ] A staff user who is not a member of tenant X receives 404 on tenant X's console and settings routes, or 403 on the routes already guarded by the existing `EnsureTeamMembership` middleware (`teams.edit`, `teams.authentication.show`), whose behaviour this spec does not change.
- [ ] The tenant list filtered by plan, payment status and region returns only matching tenants, and search by slug, billing email and Stripe customer id finds the tenant.
- [ ] Paging to the end of the tenant list produces no duplicated or skipped rows.
- [ ] The tenant detail page shows usage against the enforced limits from the Worker; with the Worker unreachable, the page still renders and only the usage panel shows an error.
- [ ] No artifact title or content appears anywhere under `/ops`, and no indexed text, comment body, collection name or search query does either.
- [ ] A tenant with a `search.performed` event shows that event on the detail page with type, actor, outcome and time, and without its query text.
- [ ] A staff user who is not a member of a tenant receives 404 or 403 on that tenant's artifact viewer, console open, and export routes.
- [ ] A team admin of the same tenant can still open another member's private artifact and run the org export.
- [ ] The health page reflects a seeded failed job and a seeded indexing failure.
- [ ] Viewing a tenant detail page creates one `operator.tenant_viewed` audit event for that tenant, and a second view within an hour does not create another.
- [ ] With no suppression case active, that event appears on the tenant's own audit page as "artfct staff", with no individual named.
- [ ] With a suppression case active for the tenant, the same view creates an `operator_audit_events` row with `suppression_case_id` set and no `audit_events` row, and the event is absent from the tenant's audit page, its person picker, and its JSON Lines export.
- [ ] A staff user without `case_manager_at` cannot create, renew or release a case; the attempt is refused and recorded in the internal log.
- [ ] Creating a case requires a category, a reference and an expiry of at most 90 days, and a case cannot be created without them.
- [ ] When a case expires, `ops:release-suppressed` writes the withheld events to the tenant's log with their original timestamps, and running it again writes nothing more.
- [ ] The published privacy text discloses that operator access may be withheld from a team's audit log, before the suppression feature is enabled outside tests.
- [ ] Every `/ops` route is classified as `staff` in `scripts/isolation/route-classification.json`, `scripts/isolation/matrix.mjs` attacks each with an anonymous request and a signed-in non-staff user and requires 404, and a new unclassified `/ops` route fails the isolation run.
- [ ] A staff user viewing a tenant they are also a member of produces an `operator.tenant_viewed` event, and an action they take on that tenant's normal team routes is attributed to them as a member, not as staff.
- [ ] The JSON Lines audit export includes a `reason` key, null for events without one.

**Phase 2**

- [ ] Each action refuses to run without a reason and stores the reason on the audit event.
- [ ] The erase action performs nothing until the dry run has been shown and the typed slug matches.
- [ ] Setting limits through the console and through `billing:sync-limits` produce identical `org_limits` rows.
- [ ] A staff action is visible to the tenant's admins on their audit page the same minute it happens, and this holds even while a suppression case is active for that tenant.
- [ ] An action rejected by a guard leaves no state change and an audit event with a failure outcome.
- [ ] Running `billing:sync-limits` or `indexing:backfill` with `--reason` leaves the same tenant-visible event (type, target, reason, outcome) as the console action, differing only in actor; a run without `--reason` leaves none.

## Tests

**Pest — feature**
- `non_staff_gets_404_on_every_ops_route` *(negative, dataset over the route list)*
- `team_admin_and_owner_are_not_staff` *(negative)*
- `staff_at_is_not_mass_assignable_or_settable_over_http` *(negative)*
- `staff_has_no_team_policy_abilities_without_membership` *(negative)*
- `staff_grant_and_revoke_write_audit_events`
- `tenant_list_filters_by_plan_payment_status_region`
- `tenant_list_search_matches_slug_email_and_stripe_id`
- `tenant_list_cursor_pagination_has_no_gaps_or_duplicates`
- `tenant_detail_shows_worker_usage_and_limits`
- `tenant_detail_renders_when_worker_usage_fails`
- `tenant_detail_exposes_no_artifact_titles_or_content` *(negative)*
- `ops_namespace_does_not_depend_on_content_services_or_models` *(Pest `arch()`, negative)*
- `recent_events_omit_target_for_search_and_artifact_events` *(negative)*
- `staff_non_member_is_refused_on_viewer_console_open_and_export` *(negative)*
- `team_admin_still_reads_private_artifacts_and_exports` *(regression)*
- `reindex_result_shown_to_staff_contains_no_content` *(Phase 2, negative)*
- `tenant_view_audit_event_is_deduped_within_an_hour`
- `operator_event_has_team_id_and_staff_actor`
- `tenant_audit_page_labels_staff_without_naming_them`
- `health_page_reports_failed_jobs_and_indexing_failures`
- `signed_out_request_to_ops_gets_404_not_a_redirect` *(negative)*
- `dev_login_session_with_staff_at_gets_404_on_ops` *(negative)*
- `session_from_another_workos_organization_gets_404_on_ops` *(negative)*
- `staff_org_session_with_staff_at_reaches_ops`
- `callback_sets_staff_session_only_for_the_staff_organization_on_the_real_client`
- `staff_who_is_a_member_is_attributed_as_member_on_team_routes_and_staff_on_ops`
- `siem_export_includes_reason_key`
- `suppressed_view_is_absent_from_index_picker_and_export` *(negative)*
- `suppressed_view_is_present_in_the_internal_log`
- `staff_without_case_manager_cannot_create_a_case` *(negative)*
- `case_requires_category_reference_and_expiry_within_90_days` *(negative)*
- `mutating_actions_stay_visible_during_a_case` *(negative)*
- `case_changes_are_logged_internally_and_never_to_the_tenant`
- `expiry_releases_withheld_events_with_original_timestamps`
- `release_command_is_idempotent`
- `tenant_page_shows_no_marker_for_withheld_events`
- `internal_log_rows_are_append_only` *(negative)*
- `ops_routes_are_classified_staff_in_the_isolation_matrix` *(runs `scripts/isolation/matrix.mjs`)*
- `action_requires_reason` *(Phase 2, negative)*
- `erase_requires_dry_run_then_typed_slug` *(Phase 2, negative)*
- `console_limits_match_billing_sync_limits` *(Phase 2)*
- `ui_and_cli_actions_write_equivalent_audit_rows` *(Phase 2; erase rows, and the reindex jobs)*
- `cli_runs_with_a_reason_write_the_same_audit_rows_as_the_console` *(Phase 2; limits and reindex)*
- `cli_failures_write_the_same_failure_rows_as_the_console` *(Phase 2)*
- `routine_cli_runs_without_a_reason_write_no_audit_rows` *(Phase 2, negative)*
- `cli_reason_must_meet_the_console_rules` *(Phase 2, negative)*

**Pest — browser**
- `staff_finds_a_tenant_and_reads_its_usage` — the demo flow, end to end
- `ops_pages_have_no_js_errors`

## Rollback

Reversible. Phase 1 is read paths only; removing the route group removes the surface. `users.staff_at` and `audit_events.reason` are nullable and additive. Operator audit events already written remain, by design, because the table is append-only. Phase 2 actions inherit the reversibility of the operation they wrap; **tenant erase is not reversible**, which is why it carries a dry run and a typed confirmation.

## Deferred

- **Impersonation and "view as".** The highest-risk piece. If built, start with a read-only view-as with a visible banner, a reason, a time limit, and the same tenant-visible audit labelling. Read-write impersonation is a separate decision.
- **Bulk actions.** Same reasoning as spec 8: sequence after the operator audit trail has been in use.
- **Per-tenant custom limits.** The limits action re-pushes the limits derived from the plan. A custom storage or monthly-artifact number needs a stored per-tenant override; without one, the next `billing:sync-limits` would silently overwrite it. The read-only flag follows payment status and is changed through the plan and payment-status action.
- **Operator access to artifact titles or content.** Decided against, not merely deferred. Any future support need (for example a customer-initiated, time-boxed grant) would be a new decision and a new spec, not an extension of this one.
- **Stronger-than-application guarantees** (tenant-held keys, restricted infrastructure access for engineers). Search and indexing read content, so this is a separate architectural decision; until then the boundary is the application's, and anything stronger is process and access control.
- **Per-tenant dedicated databases** (RUB-318). A cross-tenant query layer over separate databases changes the tenant list and health page. Revisit when that is decided.
- **Alerting.** The health page is a view, not a pager.
- **Retention of the internal operator log.** The retention policy from spec 11 should cover `operator_audit_events` and expired suppression cases too. Until it does, they are kept indefinitely.

## Open questions

- **Suppression scope.** The spec suppresses operator **reads** only and never mutating actions. Confirm this is what is wanted.
- **Published terms wording.** The privacy page needs a disclosure line before suppression is enabled; the wording and the edit are the legal-page owner's.
- **Authorization URL hint.** Starting AuthKit sign-in scoped to the staff organization is expected to be possible through an organization parameter on the authorization URL. Confirm against `RealAuthKitClient` and the SDK at implementation; if it is not, the user selects the organization at sign-in and the `organization_id` check above still holds.
- **WorkOS organization setup.** Creating the `artfct staff` organization with both MFA flags on is a WorkOS dashboard change in staging and, later, production. It is not made by this spec, and production stays on hold.
