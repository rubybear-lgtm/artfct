# Storage modes

Ephemeral artifacts remain encrypted in Workers KV and use a fragment passcode. They
retain the five-day sliding expiration policy and the one-megabyte client limit.

Permanent artifacts require an organization bearer token. The client creates a
file manifest, uploads raw file bytes to R2 under `blobs/<sha256>`, and stores
metadata, promoted provenance columns, and the complete provenance JSON in D1.
Content hashes are lowercase SHA-256 values; the public ID is the first 32
characters of the canonical manifest hash.

Use `artfct export ORG DIRECTORY` with `ARTFCT_ORG_TOKEN` to export metadata and
byte-identical blobs locally.

Permanent create, upload, and delete operations serialize per content hash using
short-lived D1 leases. This keeps D1 reference counts and R2 object lifecycles
consistent when identical content is created or deleted concurrently; unrelated
hashes continue independently.

Before the full organization-token service lands, the Worker uses the configured
`ARTFCT_ORG_TOKEN` binding as a temporary, fail-closed authentication seam. A
missing binding never accepts an arbitrary bearer token, and the configured
organization slug scopes permanent reads, uploads, deletes, and exports.

## Permanent bundles

Permanent deployments accept a manifest with up to 500 relative files, 25 MiB per
file and 50 MiB total. The POST response lists only missing SHA-256 values; upload
each listed file with `PUT /v1/artifacts/{id}/files/{sha256}`. The bundle is not
served until every manifest file is present. Files are served at `/p/{id}` (the
entrypoint) and `/p/{id}/{relative-path}` (nested assets). Unknown content types
are stored as `application/octet-stream` and served with `nosniff`.

Manifest files are sorted by path, and the normalized manifest (including sizes,
content types, hashes, entrypoint, and sorted unique `external_origins`) is hashed
to derive the stable 32-character bundle ID. D1 stores one artifact row and one
file row per manifest path; R2 stores each unique file hash once, with a refcount
for every path reference. Incomplete rows expire after one hour. Completion clears
that expiry, while an expired upload removes its artifact, provenance, file rows,
and unreferenced blobs under the per-hash lifecycle locks.
