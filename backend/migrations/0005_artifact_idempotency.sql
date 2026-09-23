-- Permanent create was not idempotent (RUB-371). The public artifact id is
-- derived from (org_id, content_hash), so re-posting identical bytes produced
-- the same id but a brand new `row_id` every time: N posts meant N artifact
-- rows, N file rows, and every blob ref_count inflated N-fold. Blobs were then
-- never reclaimable, because the refcounts never returned to zero.
--
-- Both the serving path and DELETE resolved a single row with
-- `ORDER BY row_id LIMIT 1`, so a DELETE reported 204 while a duplicate row
-- kept serving the artifact — a revocation that silently did not revoke.
--
-- Collapse the duplicates first, so the index at the end can be created. This
-- deliberately collapses only *live* rows: revoked rows are invisible to the
-- serving path and excluded from the index, so removing them here would destroy
-- history for no gain.
--
-- The dependants are deleted explicitly rather than relying on the ON DELETE
-- CASCADE declarations. Whether SQLite enforces foreign keys is a per-connection
-- pragma that defaults to OFF, and this migration has to collapse duplicates
-- correctly however it is applied — a silent no-cascade leaves orphaned file
-- rows, and the refcount recompute below reads exactly those rows, so the
-- inflated counts would survive the very migration meant to fix them. Verified
-- by replaying migrations 0001–0004 into sqlite3 with foreign keys off and
-- duplicates injected: without these four statements the recompute yields the
-- old inflated number.
DELETE FROM files WHERE artifact_row_id IN (
    SELECT row_id FROM (
        SELECT row_id,
               ROW_NUMBER() OVER (
                   PARTITION BY org_id, id
                   ORDER BY created_at, row_id
               ) AS rank
        FROM artifacts
        WHERE revoked_at IS NULL
    ) WHERE rank > 1
);

DELETE FROM provenance WHERE artifact_row_id IN (
    SELECT row_id FROM (
        SELECT row_id,
               ROW_NUMBER() OVER (
                   PARTITION BY org_id, id
                   ORDER BY created_at, row_id
               ) AS rank
        FROM artifacts
        WHERE revoked_at IS NULL
    ) WHERE rank > 1
);

DELETE FROM artifact_versions WHERE artifact_row_id IN (
    SELECT row_id FROM (
        SELECT row_id,
               ROW_NUMBER() OVER (
                   PARTITION BY org_id, id
                   ORDER BY created_at, row_id
               ) AS rank
        FROM artifacts
        WHERE revoked_at IS NULL
    ) WHERE rank > 1
);

DELETE FROM shares WHERE artifact_row_id IN (
    SELECT row_id FROM (
        SELECT row_id,
               ROW_NUMBER() OVER (
                   PARTITION BY org_id, id
                   ORDER BY created_at, row_id
               ) AS rank
        FROM artifacts
        WHERE revoked_at IS NULL
    ) WHERE rank > 1
);

DELETE FROM artifacts WHERE row_id IN (
    SELECT row_id FROM (
        SELECT row_id,
               ROW_NUMBER() OVER (
                   PARTITION BY org_id, id
                   ORDER BY created_at, row_id
               ) AS rank
        FROM artifacts
        WHERE revoked_at IS NULL
    ) WHERE rank > 1
);

-- The inflated refcounts came from the same duplication, so recompute rather
-- than decrement. `ref_count` counts file rows by construction — create
-- increments once per manifest file and delete decrements the same way — and
-- after the collapse the surviving file rows are the source of truth. Blobs
-- whose duplicates were the only referent fall to 0 here, which is what makes
-- them sweepable again.
UPDATE blobs
SET ref_count = (
    SELECT COUNT(*) FROM files WHERE files.content_hash = blobs.content_hash
);

-- The invariant itself: at most one *live* artifact row per (org_id, id).
-- Without this the code path is only idempotent by convention, and the next
-- writer to touch create can reintroduce the duplicates — the missing
-- constraint is what made both RUB-351 and RUB-371 possible.
--
-- Partial on `revoked_at IS NULL` on purpose. A plain UNIQUE (org_id, id) would
-- mean a revoked artifact permanently reserved its id, so re-publishing the same
-- bytes after a revocation could never succeed. Revoked rows do not serve and do
-- not participate, so the real invariant is one *live* row.
CREATE UNIQUE INDEX IF NOT EXISTS artifacts_live_org_public_id_idx
    ON artifacts(org_id, id)
    WHERE revoked_at IS NULL;
