-- Artifact database quotas (spec 20), pushed from Laravel with the rest of an
-- org's limits (`POST /v1/internal/org-limits`).
--
-- Additive and nullable: NULL means "use the Worker default" (5,000 documents
-- per artifact, 64 MiB per org), so applying 0009 ahead of the new Worker, or
-- pushing limits from a Laravel that does not know these fields yet, changes
-- nothing.
ALTER TABLE org_limits ADD COLUMN db_docs_per_artifact INTEGER;
ALTER TABLE org_limits ADD COLUMN db_bytes_per_org INTEGER;
