import { readFile, readdir } from 'node:fs/promises';
import { join, relative, resolve } from 'node:path';

const roots = ['resources/js/pages', 'resources/js/layouts', 'resources/js/components'];
const extensions = new Set(['.ts', '.tsx']);
const routeLiteral = /(['"`])\/(?:settings|invitations|teams|account|docs|blog|login|terms|privacy|free|logout|sso|mcp)(?:[/'"`?#]|$)/g;
const rootLink = /\bhref\s*=\s*(['"`])\/(?:[#'"`]|$)/g;

async function filesIn(directory) {
    const entries = await readdir(directory, { withFileTypes: true });
    const files = [];

    for (const entry of entries) {
        const path = join(directory, entry.name);

        if (entry.isDirectory()) {
            files.push(...(await filesIn(path)));
        } else if (extensions.has(path.slice(path.lastIndexOf('.')))) {
            files.push(path);
        }
    }

    return files;
}

const violations = [];

for (const root of roots) {
    for (const path of await filesIn(resolve(root))) {
        const source = await readFile(path, 'utf8');

        for (const match of [...source.matchAll(routeLiteral), ...source.matchAll(rootLink)]) {
            const line = source.slice(0, match.index).split('\n').length;
            violations.push(`${relative(process.cwd(), path)}:${line}`);
        }
    }
}

if (violations.length > 0) {
    console.error('Hardcoded internal frontend routes found. Use Wayfinder route functions instead:');
    console.error(violations.join('\n'));
    process.exit(1);
}

console.log('Wayfinder route guard passed.');
