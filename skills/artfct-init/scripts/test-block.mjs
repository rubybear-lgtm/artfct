#!/usr/bin/env node
/**
 * Tests the artfct guidelines block helpers in block.mjs.
 *
 *   node skills/artfct-init/scripts/test-block.mjs
 *
 * Prints one line per case and exits non-zero if any case fails. Filesystem
 * cases run in throwaway directories under the system temp directory.
 */

import assert from "node:assert/strict";
import { mkdir, mkdtemp, rm, writeFile } from "node:fs/promises";
import { tmpdir } from "node:os";
import { join } from "node:path";
import { findEntrypoints, removeBlock, upsertBlock } from "./block.mjs";

const BODY = "Use the artfct tools.\n\n- Keep answers plain.";
const BOOST_BLOCK = "<laravel-boost-guidelines>\n# Laravel Boost\n\nUse Boost.\n</laravel-boost-guidelines>";

/**
 * @param {string} body
 * @param {string} [eol]
 * @returns {string} The expected artfct block for a body, tags included.
 */
function wrap(body, eol = "\n") {
	return ["<artfct-guidelines>", body.split("\n").join(eol), "</artfct-guidelines>"].join(eol);
}

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
 * @type {Array<{name: string, run: (base: string) => Promise<void>}>}
 */
const cases = [
	{
		name: "fresh file gets the block appended after one blank line",
		run: async () => {
			const result = upsertBlock("# Notes\n", BODY);
			assert.equal(result.text, `# Notes\n\n${wrap(BODY)}\n`);
			assert.equal(result.changed, true);
		},
	},
	{
		name: "empty file gets only the block",
		run: async () => {
			const result = upsertBlock("", BODY);
			assert.equal(result.text, `${wrap(BODY)}\n`);
			assert.equal(result.changed, true);
		},
	},
	{
		name: "Boost block is left byte-for-byte untouched",
		run: async () => {
			const original = `# Project\n\n${BOOST_BLOCK}\n`;
			const result = upsertBlock(original, BODY);
			assert.equal(result.text, `# Project\n\n${BOOST_BLOCK}\n\n${wrap(BODY)}\n`);
			assert.equal(removeBlock(result.text).text, `# Project\n\n${BOOST_BLOCK}\n`);
		},
	},
	{
		name: "manual text after the artfct block is preserved on update",
		run: async () => {
			const installed = upsertBlock("# Notes\n", BODY).text;
			const withManual = `${installed}\nManual notes stay here.\n`;
			const updated = upsertBlock(withManual, "New body.");
			assert.equal(updated.text, `# Notes\n\n${wrap("New body.")}\n\nManual notes stay here.\n`);
		},
	},
	{
		name: "second upsert with the same body is a no-op",
		run: async () => {
			const first = upsertBlock("# Notes\n", BODY);
			const second = upsertBlock(first.text, BODY);
			assert.equal(second.changed, false);
			assert.equal(second.text, first.text);
		},
	},
	{
		name: "body change replaces only the block text",
		run: async () => {
			const original = `Intro\n\n${wrap(BODY)}\n\nOutro\n`;
			const result = upsertBlock(original, "Changed body.");
			assert.equal(result.changed, true);
			assert.equal(result.text, `Intro\n\n${wrap("Changed body.")}\n\nOutro\n`);
		},
	},
	{
		name: "removeBlock removes the block and its separating blank line",
		run: async () => {
			const installed = upsertBlock("# Notes\n", BODY).text;
			const removed = removeBlock(installed);
			assert.equal(removed.text, "# Notes\n");
			assert.equal(removed.changed, true);
		},
	},
	{
		name: "removeBlock on a file without the block is a no-op",
		run: async () => {
			const result = removeBlock("# Notes\n");
			assert.equal(result.changed, false);
			assert.equal(result.text, "# Notes\n");
		},
	},
	{
		name: "removeBlock from the middle keeps the text on both sides",
		run: async () => {
			const result = removeBlock(`Intro\n\n${wrap(BODY)}\n\nOutro\n`);
			assert.equal(result.text, "Intro\n\nOutro\n");
		},
	},
	{
		name: "CRLF file stays CRLF on insert and round-trips on remove",
		run: async () => {
			const original = "# Notes\r\nLine two\r\n";
			const inserted = upsertBlock(original, BODY);
			assert.equal(inserted.text, `# Notes\r\nLine two\r\n\r\n${wrap(BODY, "\r\n")}\r\n`);
			assert.doesNotMatch(inserted.text, /(^|[^\r])\n/);
			assert.equal(removeBlock(inserted.text).text, original);
		},
	},
	{
		name: "findEntrypoints keeps Claude.md as found on disk",
		run: async (base) => {
			const dir = await freshDir(base, "claude-case");
			await writeFile(join(dir, "Claude.md"), "# Claude\n");
			assert.deepEqual(findEntrypoints(dir), [join(dir, "Claude.md")]);
		},
	},
	{
		name: "findEntrypoints returns only AGENTS.md when CLAUDE.md imports it",
		run: async (base) => {
			const dir = await freshDir(base, "import-case");
			await writeFile(join(dir, "CLAUDE.md"), "# Claude\n@AGENTS.md\n");
			await writeFile(join(dir, "AGENTS.md"), "# Agents\n");
			assert.deepEqual(findEntrypoints(dir), [join(dir, "AGENTS.md")]);
		},
	},
	{
		name: "findEntrypoints returns both files when CLAUDE.md does not import AGENTS.md",
		run: async (base) => {
			const dir = await freshDir(base, "no-import-case");
			await writeFile(join(dir, "CLAUDE.md"), "# Claude\n");
			await writeFile(join(dir, "AGENTS.md"), "# Agents\n");
			assert.deepEqual(findEntrypoints(dir), [join(dir, "AGENTS.md"), join(dir, "CLAUDE.md")]);
		},
	},
	{
		name: "findEntrypoints returns [] for an empty directory",
		run: async (base) => {
			const dir = await freshDir(base, "empty-case");
			assert.deepEqual(findEntrypoints(dir), []);
		},
	},
];

const base = await mkdtemp(join(tmpdir(), "artfct-block-"));
let failed = 0;

try {
	for (const testCase of cases) {
		try {
			await testCase.run(base);
			console.log(`ok   ${testCase.name}`);
		} catch (error) {
			failed++;
			console.log(`FAIL ${testCase.name}`);
			console.log(error.message.split("\n").map((line) => `     ${line}`).join("\n"));
		}
	}
} finally {
	await rm(base, { recursive: true, force: true });
}

console.log(`\n${cases.length - failed}/${cases.length} cases passed`);
process.exitCode = failed > 0 ? 1 : 0;
