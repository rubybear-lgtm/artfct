#!/usr/bin/env node
/**
 * Tests the shared file helpers in files.mjs.
 *
 *   node skills/artfct-init/scripts/test-files.mjs
 *
 * Prints one line per case and exits non-zero if any case fails. Filesystem
 * cases run in throwaway directories under the system temp directory.
 */

import assert from "node:assert/strict";
import { mkdir, mkdtemp, readdir, readFile, rm, writeFile } from "node:fs/promises";
import { tmpdir } from "node:os";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";
import { installTemplate, resolveSharedFile } from "./files.mjs";

const ASSETS_DIR = join(dirname(fileURLToPath(import.meta.url)), "..", "assets");
const VERBATIM_RULE =
	"For questions about this project, search artfct for prior research and decisions before answering. Check code for current implementation when relevant. If artfct is unavailable, state that limitation; distinguish estimates from verified facts.";
const EXISTING_DESIGN = "# Our design\n\nHand-written palette: #701A24.\n";

/**
 * @param {string} base
 * @param {string} name
 * @returns {Promise<string>} A new empty directory inside the base directory.
 */
async function freshDir(base, name) {
	const dir = join(base, name);
	await mkdir(dir);

	return dir;
}

/**
 * @param {string} dir
 * @returns {Promise<string[]>} Entry names in the directory, sorted.
 */
async function entries(dir) {
	return (await readdir(dir)).sort();
}

/**
 * @type {Array<{name: string, run: (base: string) => Promise<string | void>}>}
 *   A case may return "skip" with its reason to report a check this filesystem cannot make.
 */
const cases = [
	{
		name: "existing DESIGN.md is not overwritten",
		run: async (base) => {
			const dir = await freshDir(base, "existing-design");
			await writeFile(join(dir, "DESIGN.md"), EXISTING_DESIGN);
			const before = await readFile(join(dir, "DESIGN.md"));

			const result = installTemplate(dir, "DESIGN");

			assert.equal(result.created, false);
			assert.equal(result.path, join(dir, "DESIGN.md"));
			assert.deepEqual(await readFile(join(dir, "DESIGN.md")), before);
			assert.deepEqual(await entries(dir), ["DESIGN.md"]);
		},
	},
	{
		name: "design.md resolves as existing and keeps its lowercase name",
		run: async (base) => {
			const dir = await freshDir(base, "lower-design");
			await writeFile(join(dir, "design.md"), EXISTING_DESIGN);

			const resolved = resolveSharedFile(dir, "DESIGN");
			assert.deepEqual(resolved, { path: join(dir, "design.md"), exists: true });

			const result = installTemplate(dir, "DESIGN");
			assert.equal(result.created, false);
			assert.equal(result.path, join(dir, "design.md"));
			assert.deepEqual(await entries(dir), ["design.md"]);
			assert.equal(await readFile(join(dir, "design.md"), "utf8"), EXISTING_DESIGN);
		},
	},
	{
		name: "DESIGN.MD resolves as existing and keeps its uppercase extension",
		run: async (base) => {
			const dir = await freshDir(base, "upper-ext-design");
			await writeFile(join(dir, "DESIGN.MD"), EXISTING_DESIGN);

			const resolved = resolveSharedFile(dir, "DESIGN");
			assert.deepEqual(resolved, { path: join(dir, "DESIGN.MD"), exists: true });

			const result = installTemplate(dir, "DESIGN");
			assert.equal(result.created, false);
			assert.deepEqual(await entries(dir), ["DESIGN.MD"]);
		},
	},
	{
		name: "resolveSharedFile returns the canonical path when the file is absent",
		run: async (base) => {
			const dir = await freshDir(base, "absent-resolve");
			assert.deepEqual(resolveSharedFile(dir, "GUIDELINES"), {
				path: join(dir, "GUIDELINES.md"),
				exists: false,
			});
		},
	},
	{
		name: "missing DESIGN.md is created from the template with created:true",
		run: async (base) => {
			const dir = await freshDir(base, "create-design");
			const template = await readFile(join(ASSETS_DIR, "DESIGN.template.md"));

			const result = installTemplate(dir, "DESIGN");

			assert.equal(result.created, true);
			assert.equal(result.path, join(dir, "DESIGN.md"));
			assert.deepEqual(await readFile(join(dir, "DESIGN.md")), template);
		},
	},
	{
		name: "missing GUIDELINES.md is created from the template with created:true",
		run: async (base) => {
			const dir = await freshDir(base, "create-guidelines");
			const template = await readFile(join(ASSETS_DIR, "GUIDELINES.template.md"));

			const result = installTemplate(dir, "GUIDELINES");

			assert.equal(result.created, true);
			assert.equal(result.path, join(dir, "GUIDELINES.md"));
			assert.deepEqual(await readFile(join(dir, "GUIDELINES.md")), template);
		},
	},
	{
		name: "second install after creation is a no-op",
		run: async (base) => {
			const dir = await freshDir(base, "second-install");
			installTemplate(dir, "DESIGN");
			const created = await readFile(join(dir, "DESIGN.md"));

			const again = installTemplate(dir, "DESIGN");

			assert.equal(again.created, false);
			assert.deepEqual(await readFile(join(dir, "DESIGN.md")), created);
			assert.deepEqual(await entries(dir), ["DESIGN.md"]);
		},
	},
	{
		name: "install creates no second file under another capitalization",
		run: async (base) => {
			const dir = await freshDir(base, "no-duplicate");
			installTemplate(dir, "GUIDELINES");
			installTemplate(dir, "DESIGN");

			assert.deepEqual(await entries(dir), ["DESIGN.md", "GUIDELINES.md"]);
		},
	},
	{
		name: "two files differing only by case resolve to the first sorted one",
		run: async (base) => {
			const dir = await freshDir(base, "two-case-variants");
			await writeFile(join(dir, "DESIGN.md"), "upper\n");
			await writeFile(join(dir, "design.md"), "lower\n");

			if ((await entries(dir)).length < 2) {
				return "skip: this filesystem folds case, so two case variants cannot coexist";
			}

			assert.deepEqual(resolveSharedFile(dir, "DESIGN"), {
				path: join(dir, "DESIGN.md"),
				exists: true,
			});
		},
	},
	{
		name: "GUIDELINES template contains the verbatim onboarding rule",
		run: async () => {
			const text = await readFile(join(ASSETS_DIR, "GUIDELINES.template.md"), "utf8");
			assert.ok(text.includes(VERBATIM_RULE), "verbatim rule not found in GUIDELINES template");
		},
	},
	{
		name: "neither template contains the words MCP or harness",
		run: async () => {
			for (const file of ["GUIDELINES.template.md", "DESIGN.template.md"]) {
				const text = await readFile(join(ASSETS_DIR, file), "utf8");
				assert.doesNotMatch(text, /\bmcp\b/i, `${file} mentions MCP`);
				assert.doesNotMatch(text, /harness/i, `${file} mentions harness`);
			}
		},
	},
];

const base = await mkdtemp(join(tmpdir(), "artfct-files-"));
let failed = 0;
let skipped = 0;

try {
	for (const testCase of cases) {
		try {
			const outcome = await testCase.run(base);

			if (typeof outcome === "string" && outcome.startsWith("skip")) {
				skipped++;
				console.log(`skip ${testCase.name} (${outcome.slice("skip: ".length)})`);
			} else {
				console.log(`ok   ${testCase.name}`);
			}
		} catch (error) {
			failed++;
			console.log(`FAIL ${testCase.name}`);
			console.log(error.message.split("\n").map((line) => `     ${line}`).join("\n"));
		}
	}
} finally {
	await rm(base, { recursive: true, force: true });
}

const passed = cases.length - failed - skipped;
console.log(`\n${passed}/${cases.length} cases passed${skipped > 0 ? `, ${skipped} skipped` : ""}`);
process.exitCode = failed > 0 ? 1 : 0;
