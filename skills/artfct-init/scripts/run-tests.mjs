#!/usr/bin/env node
/**
 * Runs every test-*.mjs file in this directory as its own node process.
 *
 *   node skills/artfct-init/scripts/run-tests.mjs
 *
 * Exits non-zero if any test file fails or if none are found.
 */

import { spawnSync } from "node:child_process";
import { readdirSync } from "node:fs";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";

const HERE = dirname(fileURLToPath(import.meta.url));
const files = readdirSync(HERE)
	.filter((name) => /^test-.+\.mjs$/.test(name))
	.sort();

if (files.length === 0) {
	console.error(`No test-*.mjs files found in ${HERE}`);
	process.exit(1);
}

let failures = 0;

for (const file of files) {
	console.log(`\n== ${file}`);
	const result = spawnSync(process.execPath, [join(HERE, file)], { stdio: "inherit" });
	const passed = result.status === 0;

	if (!passed) {
		failures++;
	}

	console.log(`${passed ? "PASS" : "FAIL"} ${file}`);
}

console.log(`\n${files.length - failures}/${files.length} test files passed`);
process.exit(failures > 0 ? 1 : 0);
