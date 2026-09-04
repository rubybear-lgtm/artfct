# Changelog

All notable changes to Artifact Engine will be recorded in this file.

## [Unreleased]

### Added

- Added the OpenAPI 3.1 contract, blocking contract-drift CI checks, and a generated API reference on `/docs` for Spec 0.
- Added stateful MCP client identity, deterministic host-source resolution, per-process session IDs, and host-aware setup configuration for Spec 1.
- Added required CLI/MCP provenance with Git discovery, per-field source attribution, credential-safe remotes, and optional self-reported model identity for Spec 2.
- Added Spec 3 permanent single-file storage with D1 metadata, R2 raw blobs, content-addressed IDs, deduplication, and CLI export; ephemeral KV behavior remains unchanged.
- Added Spec 4 deterministic multi-file permanent bundles, manifest validation, missing-file uploads, nested asset previews, and directory deployment with `--entrypoint`.
- Added Spec 5 server-side origin isolation: per-artifact `<tenant-slug>--<artifact-id>.artfct.dev` hostnames, cookieless HMAC-signed access tokens with artifact scoping and expiry, and per-artifact CSP derived from manifest `external_origins`/`unsafe_eval`; free-tier `/p/{id}` links are unchanged.
- Added Spec 6 Laravel identity foundation: starter-kit Teams (admin/member/viewer roles) for orgs, WorkOS AuthKit login backed by an injectable client, `external_identities` linking one user to many provider identities, an `auth_mode` (`authkit`/`dual`/`polis`) state machine with domain-verification and Polis-identity preconditions, and DNS TXT domain verification against an injectable resolver.

## 0.0.1 - 2026-06-03

### Added

- Started the `artfct` CLI with standalone `deploy`, `doctor`, and `mcp serve` commands.
- Added CLI parsing and API client tests for artifact deployment.
- Added a public shell installer script for GitHub Release binaries.
- Added a GitHub Release workflow for publishing CLI binaries.
- Added Rust and CLI CI jobs plus matching pre-commit checks.
- Expanded CLI help with command descriptions, examples, and environment variable guidance.
- Added a Rust Cloudflare Worker backend for creating, deleting, and rendering ephemeral HTML artifacts.
- Added Workers KV storage with Brotli-compressed artifact payloads and TTL-based expiration.
- Added `artfct.dev/v1/*` and `artfct.dev/p/*` Worker routes.
- Added a stdio MCP server exposing the `deploy_to_canvas` tool.
- Added Cloudflare rate-limit automation for artifact creation and preview routes.
- Added CI checks for Laravel, Node, and Rust.
- Added Worker deployment workflow with WAF rate-limit application.
