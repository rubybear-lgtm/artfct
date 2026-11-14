/**
 * Read the hosted MCP tool catalog from ArtfctServer.php so smoke tests do
 * not hard-code a count (RUB-517). Feature-flagged tools are kept apart from
 * the base catalog: they only exist for teams the feature is released to, so a
 * smoke must not require them, and must not fail when a released team sees
 * them.
 */

import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '../..');

/**
 * @param {string} serverPath
 * @returns {{ base: string[], flagged: string[] }}
 */
function readMcpToolNames(serverPath) {
    const source = readFileSync(serverPath, 'utf8');
    const toolsBlock = source.match(
        /protected\s+array\s+\$tools\s*=\s*\[([\s\S]*?)\];/,
    );

    if (!toolsBlock) {
        throw new Error(`Could not find $tools array in ${serverPath}`);
    }

    const classNames = [...toolsBlock[1].matchAll(/([A-Za-z0-9_]+)::class/g)].map(
        (match) => match[1],
    );

    if (classNames.length === 0) {
        throw new Error(`No tool classes found in ${serverPath}`);
    }

    /** @type {string[]} */
    const base = [];
    /** @type {string[]} */
    const flagged = [];

    for (const className of classNames) {
        const toolPath = resolve(root, `app/Mcp/Tools/${className}.php`);
        const toolSource = readFileSync(toolPath, 'utf8');
        const named = toolSource.match(/#\[Name\(\s*'([^']+)'\s*\)\]/);

        if (!named) {
            throw new Error(`Missing #[Name('…')] on ${toolPath}`);
        }

        // A feature-flagged tool (FeatureFlaggedTool, or DatabaseTool which
        // extends it) is listed only for teams the feature is released to.
        if (/extends\s+(FeatureFlaggedTool|DatabaseTool)\b/.test(toolSource)) {
            flagged.push(named[1]);
        } else {
            base.push(named[1]);
        }
    }

    return { base, flagged };
}

/**
 * Tools every team sees.
 *
 * @param {string} [serverPath]
 * @returns {string[]}
 */
export function readExpectedMcpToolNames(
    serverPath = resolve(root, 'app/Mcp/Servers/ArtfctServer.php'),
) {
    return readMcpToolNames(serverPath).base;
}

/**
 * Feature-flagged tools: allowed in tools/list, never required.
 *
 * @param {string} [serverPath]
 * @returns {string[]}
 */
export function readFlaggedMcpToolNames(
    serverPath = resolve(root, 'app/Mcp/Servers/ArtfctServer.php'),
) {
    return readMcpToolNames(serverPath).flagged;
}
