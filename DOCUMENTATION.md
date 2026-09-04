# Storage modes

Ephemeral artifacts remain encrypted in Workers KV and use a fragment passcode. They
retain the five-day sliding expiration policy and the one-megabyte client limit.

Permanent artifacts require an organization bearer token. The client creates a
single-file manifest, uploads the raw `index.html` bytes to R2 under
`blobs/<sha256>`, and stores metadata, promoted provenance columns, and the complete
provenance JSON in D1. Content hashes are lowercase SHA-256 values; the public ID is
the first 32 characters.

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
