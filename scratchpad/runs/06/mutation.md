# Spec 06 mutation evidence

Implementer's own sanity checks (ran and reverted, per their report):
- Removed the polis-admin-identity throw in `AuthModeTransitioner` →
  `polis_requires_admin_with_polis_identity` failed with the expected
  message; restored → passed.
- Removed the verified-domain throw → `dual_requires_verified_domain`
  failed; restored → passed.
- Removed the `--` check in `TeamSlug` → `org_slug_with_double_hyphen_rejected`
  failed; restored → passed.

Coordinator spot-checks, independently executed 2026-09-04 against
`app/Rules/TeamSlug.php`:

| Guard removed | Exact command | Mutated result | Restored result |
|---|---|---|---|
| Max-length check (`mb_strlen($value) > self::MAX_LENGTH`) | `php artisan test --compact --filter=org_slug_over_length_rejected` | failed: session missing expected `errors` key — a 25-char slug validated | passed |
| Explicit ASCII check (`mb_check_encoding`) | `php artisan test --compact --filter=org_slug_non_ascii_rejected` | still passed — the trailing `preg_match('/^[a-z0-9-]+$/')` also rejects non-ASCII input, so this is defense-in-depth, not a vacuous guard for the test data used | passed (no change) |

The ASCII case is not a mutation-check failure: the test still fails when
slug validation as a whole is broken (confirmed by the length and
double-hyphen mutations using the same code path), it just isn't the sole
guard non-ASCII input trips on for this specific test's input.

Not independently re-verified by the coordinator: `google_and_passkey_resolve_to_one_user`,
`domain_verification_requires_dns_txt`'s full seed/clear/re-verify cycle,
policy tests. Covered by gate re-run (49/49 passing) and zero-context review.
