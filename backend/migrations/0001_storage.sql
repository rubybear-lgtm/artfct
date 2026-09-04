CREATE TABLE IF NOT EXISTS orgs (
    id TEXT PRIMARY KEY,
    slug TEXT NOT NULL UNIQUE CHECK (length(slug) BETWEEN 1 AND 24 AND slug NOT LIKE '%--%' AND slug NOT GLOB '*[^a-z0-9-]*' AND substr(slug, 1, 1) <> '-' AND substr(slug, -1, 1) <> '-'),
    created_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS users (
    id TEXT PRIMARY KEY,
    created_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS memberships (
    org_id TEXT NOT NULL REFERENCES orgs(id) ON DELETE CASCADE,
    user_id TEXT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    role TEXT NOT NULL,
    PRIMARY KEY (org_id, user_id)
);

CREATE TABLE IF NOT EXISTS api_tokens (
    id TEXT PRIMARY KEY,
    org_id TEXT NOT NULL REFERENCES orgs(id) ON DELETE CASCADE,
    token_hash TEXT NOT NULL UNIQUE,
    created_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS blobs (
    content_hash TEXT PRIMARY KEY,
    size_bytes INTEGER NOT NULL,
    content_type TEXT NOT NULL,
    ref_count INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS blob_locks (
    content_hash TEXT PRIMARY KEY,
    owner TEXT NOT NULL,
    expires_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS artifacts (
    row_id TEXT PRIMARY KEY,
    id TEXT NOT NULL,
    org_id TEXT NOT NULL REFERENCES orgs(id) ON DELETE CASCADE,
    user_id TEXT REFERENCES users(id) ON DELETE SET NULL,
    content_hash TEXT NOT NULL REFERENCES blobs(content_hash),
    entrypoint TEXT NOT NULL,
    created_at TEXT NOT NULL,
    superseded_by TEXT,
    retention_class TEXT NOT NULL DEFAULT 'permanent',
    legal_hold INTEGER NOT NULL DEFAULT 0,
    tier TEXT NOT NULL DEFAULT 'public',
    UNIQUE (row_id)
);

CREATE INDEX IF NOT EXISTS artifacts_org_idx ON artifacts(org_id);
CREATE INDEX IF NOT EXISTS artifacts_content_idx ON artifacts(content_hash);
CREATE INDEX IF NOT EXISTS artifacts_created_idx ON artifacts(created_at);

CREATE TABLE IF NOT EXISTS artifact_versions (
    id TEXT PRIMARY KEY,
    artifact_row_id TEXT NOT NULL REFERENCES artifacts(row_id) ON DELETE CASCADE,
    version INTEGER NOT NULL,
    created_at TEXT NOT NULL,
    UNIQUE (artifact_row_id, version)
);

CREATE TABLE IF NOT EXISTS files (
    artifact_row_id TEXT NOT NULL REFERENCES artifacts(row_id) ON DELETE CASCADE,
    path TEXT NOT NULL,
    content_hash TEXT NOT NULL REFERENCES blobs(content_hash),
    content_type TEXT NOT NULL,
    size_bytes INTEGER NOT NULL,
    PRIMARY KEY (artifact_row_id, path)
);

CREATE TABLE IF NOT EXISTS shares (
    id TEXT PRIMARY KEY,
    artifact_row_id TEXT NOT NULL REFERENCES artifacts(row_id) ON DELETE CASCADE,
    created_at TEXT NOT NULL,
    revoked_at TEXT
);

CREATE TABLE IF NOT EXISTS audit_events (
    id TEXT PRIMARY KEY,
    org_id TEXT REFERENCES orgs(id) ON DELETE CASCADE,
    event_type TEXT NOT NULL,
    payload TEXT NOT NULL,
    created_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS provenance (
    artifact_row_id TEXT PRIMARY KEY REFERENCES artifacts(row_id) ON DELETE CASCADE,
    agent TEXT,
    repo_url TEXT,
    commit_sha TEXT,
    payload TEXT NOT NULL CHECK (json_valid(payload))
);

CREATE INDEX IF NOT EXISTS provenance_agent_idx ON provenance(agent);
CREATE INDEX IF NOT EXISTS provenance_repo_idx ON provenance(repo_url);
CREATE INDEX IF NOT EXISTS provenance_commit_idx ON provenance(commit_sha);
