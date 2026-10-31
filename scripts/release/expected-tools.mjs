/**
 * Read the hosted MCP tool catalog from ArtfctServer.php so smoke tests do
 * not hard-code a count (RUB-517).
 */

import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '../..');

/**
 * @param {string} [serverPath]
 * @returns {string[]}
 */
export function readExpectedMcpToolNames(
    serverPath = resolve(root, 'app/Mcp/Servers/ArtfctServer.php'),
) {
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
    const names = [];
    for (const className of classNames) {
        const toolPath = resolve(root, `app/Mcp/Tools/${className}.php`);
        const toolSource = readFileSync(toolPath, 'utf8');
        const named = toolSource.match(/#\[Name\(\s*'([^']+)'\s*\)\]/);
        if (!named) {
            throw new Error(`Missing #[Name('…')] on ${toolPath}`);
        }
        names.push(named[1]);
    }

    return names;
}
