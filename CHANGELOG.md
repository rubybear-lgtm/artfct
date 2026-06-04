# Changelog

All notable changes to Artifact Engine will be recorded in this file.

## Unreleased

### Added

- Started the `artfct` CLI with standalone `deploy`, `doctor`, and `mcp serve` commands.
- Added CLI parsing and API client tests for artifact deployment.
- Added a public shell installer script for GitHub Release binaries.
- Added a GitHub Release workflow for publishing CLI binaries.
- Added Rust and CLI CI jobs plus matching pre-commit checks.

## 0.1.0 - 2026-06-04

### Added

- Added a Rust Cloudflare Worker backend for creating, deleting, and rendering ephemeral HTML artifacts.
- Added Workers KV storage with Brotli-compressed artifact payloads and TTL-based expiration.
- Added `artfct.dev/v1/*` and `artfct.dev/p/*` Worker routes.
- Added a stdio MCP server exposing the `deploy_to_canvas` tool.
- Added Cloudflare rate-limit automation for artifact creation and preview routes.
- Added CI checks for Laravel, Node, and Rust.
- Added Worker deployment workflow with WAF rate-limit application.
