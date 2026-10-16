-- Spec 14 follow-up (RUB-310): per-org quota limits and payment state,
-- pushed from Laravel (`POST /v1/internal/org-limits`). No row means the
-- Worker falls back to its default limits. `org_id` is the org slug (the
-- same value the create path stores in `orgs.id`); deliberately no foreign
-- key, since limits can be pushed before an org's first artifact.
CREATE TABLE IF NOT EXISTS org_limits (
    org_id TEXT PRIMARY KEY,
    storage_bytes INTEGER NOT NULL,
    artifacts_per_month INTEGER NOT NULL,
    bundle_ceiling_bytes INTEGER NOT NULL,
    read_only INTEGER NOT NULL DEFAULT 0,
    updated_at TEXT NOT NULL
);
