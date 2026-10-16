-- Spec 08: soft-delete revocation for the admin console.
--
-- `revoked_at` is nullable and additive to the spec 3/4 `artifacts` table --
-- revoking never deletes the row or its blob (retention/legal hold in spec
-- 11 depend on the row surviving). A separate index backs both the
-- "is this artifact still servable" check on the hot serving path
-- (`resolve_permanent_artifact`) and cursor-paginated admin listing, which
-- filters/orders on `(org_id, created_at, id)`.
ALTER TABLE artifacts ADD COLUMN revoked_at TEXT;

CREATE INDEX IF NOT EXISTS artifacts_org_created_id_idx ON artifacts(org_id, created_at, id);
