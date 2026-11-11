-- Artifact database (spec 20): documents an artifact's own page and agents
-- read and write.
--
-- Additive only: the Worker deployed before this migration never touches this
-- table, so applying 0008 ahead of the new Worker is safe. Apply it to staging
-- by hand before deploying the Worker (see STAGING.md).
--
-- `org_id` is the org slug, like `org_limits.org_id`. `artifact_id` is the
-- stable artifact id (`artifacts.id`), never a version, so rows survive a
-- republish. Both are bound from the verified request host, never from the
-- request body or path.
--
-- Dropping this table deletes user data. Export first.
CREATE TABLE IF NOT EXISTS artifact_docs (
    org_id TEXT NOT NULL,
    artifact_id TEXT NOT NULL,
    collection TEXT NOT NULL,
    doc_id TEXT NOT NULL,
    body TEXT NOT NULL,
    index_value TEXT,
    version INTEGER NOT NULL,
    updated_at TEXT NOT NULL,
    updated_by TEXT NOT NULL,
    PRIMARY KEY (org_id, artifact_id, collection, doc_id)
);

CREATE INDEX IF NOT EXISTS artifact_docs_index_idx
    ON artifact_docs(org_id, artifact_id, collection, index_value);
