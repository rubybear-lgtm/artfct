-- Sharing levels and edit access (RUB-438).
--
-- Additive only: the Worker deployed before this migration neither reads nor
-- writes these columns, so applying 0007 ahead of the new Worker is safe.
--
-- Sharing is stored in the existing `artifacts.tier` column:
--   private -> 'private' (new): the owner and team admins only
--   team    -> 'secure'        : any member of the owning team
--   public  -> 'public'        : anyone with the link
-- Every read path decides visibility with one Worker function; any tier
-- value it does not recognise is treated as private (fail closed).
--
-- `artifacts.user_id` is the owner. Rows published before owners were
-- recorded keep NULL until Laravel calls the owner backfill
-- (`POST /v1/internal/orgs/{org}/owner-backfill`) with the team owner. A
-- private artifact with no owner is visible to team admins only.

ALTER TABLE artifacts ADD COLUMN edit_access TEXT NOT NULL DEFAULT 'view';

-- Per-org sharing settings pushed from Laravel (`POST /v1/internal/org-settings`).
-- No row means public sharing is allowed (the team default).
CREATE TABLE IF NOT EXISTS org_settings (
    org_id TEXT PRIMARY KEY,
    public_sharing_allowed INTEGER NOT NULL DEFAULT 1,
    updated_at TEXT NOT NULL
);

CREATE INDEX IF NOT EXISTS artifacts_org_owner_idx ON artifacts(org_id, user_id);
