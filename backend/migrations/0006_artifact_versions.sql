-- Artifact versioning (RUB-437): one `artifacts` row per stable id, every
-- version in `artifact_versions`, every version's files in `version_files`.
--
-- Data model invariants. Every Worker path that writes artifacts must keep
-- these; each one has a named test.
--
-- 1. Current copy. `artifacts` (content_hash, entrypoint, manifest, title,
--    description), its `files` rows and its `provenance` row are a copy of the
--    *current* version, so every existing read path (serving, content read,
--    metadata, list, export, governance) keeps working unchanged.
--    `artifacts.current_version` names that version. The copy is replaced only
--    by promotion (5).
--
-- 2. History. `artifact_versions` holds one row per version (current, past and
--    pending), with its own manifest and provenance. `version_files` holds the
--    uploaded files of every version. A version is complete when every
--    manifest path has a `version_files` row; pending versions carry
--    `expires_at` (upload deadline) and are never listed or served.
--
-- 3. Refcount basis. `blobs.ref_count` counts manifest file entries across
--    *all* versions of *all* artifact rows (live or revoked), i.e.
--    `json_each(artifact_versions.manifest, '$.files')`. Publishing a version
--    increments once per manifest file (as create always has); deleting an
--    artifact decrements once per manifest file of every version; deleting an
--    abandoned pending version decrements once per its manifest file. Any
--    recompute uses exactly the statement at the end of this file. The orphan
--    sweep deletes blobs at ref_count <= 0, so a blob only an old version uses
--    must keep a positive count.
--
-- 4. Pending uploads. A pending v1 is the artifact's own row (artifacts.expires_at
--    set, as before). A pending v2+ lives only on its `artifact_versions` row
--    and never touches `artifacts.expires_at`, so the current version keeps
--    serving. Expiry of a pending v2+ deletes only that version.
--
-- 5. Promotion. When a version becomes complete it is promoted in one D1 batch:
--    replace the artifact's `files` rows with the version's `version_files`,
--    copy its columns onto `artifacts`, replace `provenance`, set
--    `current_version`. `artifact.version_created` is emitted after promotion,
--    never at the POST.
--
-- 6. Legal hold and revocation stay on the single `artifacts` row, so they cover
--    every version by construction.
--
-- As in 0005, dependants are written explicitly rather than relying on ON
-- DELETE CASCADE, because foreign keys are a per-connection pragma that
-- defaults to OFF.

ALTER TABLE artifacts ADD COLUMN current_version INTEGER NOT NULL DEFAULT 1;
ALTER TABLE artifacts ADD COLUMN updated_at TEXT;

ALTER TABLE artifact_versions ADD COLUMN content_hash TEXT;
ALTER TABLE artifact_versions ADD COLUMN entrypoint TEXT;
ALTER TABLE artifact_versions ADD COLUMN manifest TEXT NOT NULL DEFAULT '{}';
ALTER TABLE artifact_versions ADD COLUMN title TEXT;
ALTER TABLE artifact_versions ADD COLUMN description TEXT;
ALTER TABLE artifact_versions ADD COLUMN created_by TEXT;
ALTER TABLE artifact_versions ADD COLUMN agent TEXT;
ALTER TABLE artifact_versions ADD COLUMN repo_url TEXT;
ALTER TABLE artifact_versions ADD COLUMN commit_sha TEXT;
ALTER TABLE artifact_versions ADD COLUMN provenance TEXT NOT NULL DEFAULT '{}';
ALTER TABLE artifact_versions ADD COLUMN expires_at TEXT;
ALTER TABLE artifact_versions ADD COLUMN restored_from INTEGER;

CREATE TABLE IF NOT EXISTS version_files (
    version_id TEXT NOT NULL REFERENCES artifact_versions(id) ON DELETE CASCADE,
    path TEXT NOT NULL,
    content_hash TEXT NOT NULL,
    content_type TEXT NOT NULL,
    size_bytes INTEGER NOT NULL,
    PRIMARY KEY (version_id, path)
);

CREATE INDEX IF NOT EXISTS version_files_content_idx ON version_files(content_hash);
CREATE INDEX IF NOT EXISTS artifact_versions_row_idx ON artifact_versions(artifact_row_id, version);
CREATE INDEX IF NOT EXISTS artifacts_org_content_idx ON artifacts(org_id, content_hash);

-- Stable ids minted from now on are 13-character lowercase base36 and global:
-- `/p/{id}` and the metadata read resolve an id without an org filter, so two
-- orgs must never share one. The 0005 index only covers live rows within one
-- org. A mint that hits this index retries with a fresh id.
CREATE UNIQUE INDEX IF NOT EXISTS artifacts_stable_id_idx
    ON artifacts(id)
    WHERE length(id) = 13;

-- Backfill: every existing artifact row (live, revoked, pending) becomes v1.
-- The version id is derived from the row id so the backfill is idempotent.
INSERT INTO artifact_versions (
    id, artifact_row_id, version, created_at, content_hash, entrypoint, manifest,
    title, description, created_by, agent, repo_url, commit_sha, provenance, expires_at
)
SELECT
    a.row_id || '-v1', a.row_id, 1, a.created_at, a.content_hash, a.entrypoint, a.manifest,
    a.title, a.description, a.user_id, p.agent, p.repo_url, p.commit_sha,
    COALESCE(p.payload, '{}'), a.expires_at
FROM artifacts a
LEFT JOIN provenance p ON p.artifact_row_id = a.row_id
WHERE NOT EXISTS (
    SELECT 1 FROM artifact_versions v WHERE v.artifact_row_id = a.row_id AND v.version = 1
);

INSERT OR IGNORE INTO version_files (version_id, path, content_hash, content_type, size_bytes)
SELECT f.artifact_row_id || '-v1', f.path, f.content_hash, f.content_type, f.size_bytes
FROM files f
JOIN artifact_versions v ON v.id = f.artifact_row_id || '-v1';

UPDATE artifacts SET current_version = 1, updated_at = COALESCE(updated_at, created_at);

-- Recompute every refcount on the basis in invariant 3.
UPDATE blobs
SET ref_count = (
    SELECT COUNT(*)
    FROM artifact_versions v, json_each(v.manifest, '$.files') mf
    WHERE json_extract(mf.value, '$.sha256') = blobs.content_hash
);
