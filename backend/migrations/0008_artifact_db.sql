-- Artifact database (spec 20): a small per-artifact document store behind the
-- artifact's own isolated origin (`/__artfct/db/`).
--
-- Additive only: the Worker deployed before this migration neither reads nor
-- writes these rows/columns, so applying 0008 ahead of the new Worker is safe.
--
-- `artifact_docs` is exactly the table spec 20's Storage section declares.
-- Rows are keyed by the stable artifact id (RUB-437), never a version, so a
-- republish (new version, same stable id) leaves rows untouched. Every query
-- binds `org_id` and `artifact_id` from the verified access token, never from
-- the request path or body.
--
-- The two `org_limits` columns beside it serve spec 20's Quotas section:
-- `max_db_docs_per_artifact` and `max_db_bytes_per_org` are pushed from
-- Laravel through the existing limits channel. NULL means unset and the
-- Worker falls back to its conservative defaults. Added with plain
-- `ADD COLUMN` (no backfill, no default) so the migration stays safe to apply
-- early: old Workers never select these columns, new Workers treat NULL as
-- "no pushed value".

CREATE TABLE IF NOT EXISTS artifact_docs (
    org_id TEXT NOT NULL,
    artifact_id TEXT NOT NULL,      -- stable id (RUB-437), never a version
    collection TEXT NOT NULL,
    doc_id TEXT NOT NULL,
    body TEXT NOT NULL,             -- JSON, validated object
    index_value TEXT,               -- value of the collection's declared index field
    version INTEGER NOT NULL,
    updated_at TEXT NOT NULL,
    updated_by TEXT NOT NULL,       -- viewer id or 'agent:<client>'
    PRIMARY KEY (org_id, artifact_id, collection, doc_id)
);
CREATE INDEX IF NOT EXISTS artifact_docs_index_idx
    ON artifact_docs(org_id, artifact_id, collection, index_value);

ALTER TABLE org_limits ADD COLUMN max_db_docs_per_artifact INTEGER;
ALTER TABLE org_limits ADD COLUMN max_db_bytes_per_org INTEGER;
