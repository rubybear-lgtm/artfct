-- Artifact database rollout gate (spec 20), per org.
--
-- NULL means "no explicit choice": the environment variable
-- `ARTFCT_ARTIFACT_DB_ENABLED` decides. `0` turns the database off for the org
-- even when the environment allows everyone; `1` turns it on when the
-- environment is in `orgs` mode. Laravel pushes this with the rest of an org's
-- settings (`POST /v1/internal/org-settings`).
--
-- Additive and nullable, so applying it ahead of the Worker changes nothing.
ALTER TABLE org_settings ADD COLUMN artifact_db_enabled INTEGER;
