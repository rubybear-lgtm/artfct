#!/usr/bin/env node
/**
 * Finds and installs the shared GUIDELINES.md and DESIGN.md files at a
 * repository root. Matching ignores case, so an existing file keeps its
 * on-disk capitalization and no second copy is ever created under another
 * spelling. Used by the artfct-init skill.
 */

import { readdirSync, readFileSync, statSync, writeFileSync } from "node:fs";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";

const ASSETS_DIR = join(dirname(fileURLToPath(import.meta.url)), "..", "assets");
const SHARED_NAMES = ["GUIDELINES", "DESIGN"];

/**
 * @param {string} name
 * @returns {asserts name is 'GUIDELINES' | 'DESIGN'}
 * @throws {Error} When the name is not one of the shared file names.
 */
function assertSharedName(name) {
	if (!SHARED_NAMES.includes(name)) {
		throw new Error(`Unknown shared file "${name}". Expected GUIDELINES or DESIGN.`);
	}
}

/**
 * @param {string} path
 * @returns {boolean}
 */
function isRegularFile(path) {
	try {
		return statSync(path).isFile();
	} catch {
		return false;
	}
}

/**
 * Finds the shared file in the repository root, ignoring case. When several
 * files differ only by case, the first one in sorted order wins.
 *
 * @param {string} repoDir
 * @param {'GUIDELINES' | 'DESIGN'} name
 * @returns {{path: string, exists: boolean}} The on-disk path when the file exists,
 *   otherwise the canonical `<repoDir>/<NAME>.md` path with exists set to false.
 */
export function resolveSharedFile(repoDir, name) {
	assertSharedName(name);

	const wantedName = `${name}.md`.toLowerCase();
	const match = readdirSync(repoDir)
		.filter((entry) => entry.toLowerCase() === wantedName && isRegularFile(join(repoDir, entry)))
		.sort()[0];

	if (match !== undefined) {
		return { path: join(repoDir, match), exists: true };
	}

	return { path: join(repoDir, `${name}.md`), exists: false };
}

/**
 * Copies the bundled template to the shared file path, but only when no file
 * matching that name exists yet. An existing file is never overwritten.
 *
 * @param {string} repoDir
 * @param {'GUIDELINES' | 'DESIGN'} name
 * @returns {{path: string, created: boolean}}
 */
export function installTemplate(repoDir, name) {
	const resolved = resolveSharedFile(repoDir, name);

	if (resolved.exists) {
		return { path: resolved.path, created: false };
	}

	const template = readFileSync(join(ASSETS_DIR, `${name}.template.md`), "utf8");

	writeFileSync(resolved.path, template, { flag: "wx" });

	return { path: resolved.path, created: true };
}
