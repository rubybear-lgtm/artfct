#!/usr/bin/env node
/**
 * Fail closed unless MCP_LIVE_BASE_URL / argv target is staging-only.
 *
 * Used by `.github/workflows/mcp-live.yml` before the smoke suite.
 */

import { assertStagingOnlyBaseUrl } from './target.mjs';

const raw = process.argv[2] ?? process.env.MCP_LIVE_BASE_URL;
const result = assertStagingOnlyBaseUrl(raw);

if (!result.ok) {
    console.error(result.message);
    process.exit(1);
}

console.log(`Staging target OK: ${result.baseUrl}`);
