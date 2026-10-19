#!/usr/bin/env node
/**
 * Manages the artfct guidelines block inside agent entrypoint files
 * (AGENTS.md and CLAUDE.md). The text functions never touch anything outside
 * the artfct tags, except for the whitespace that separates the block from
 * the rest of the file. Used by the artfct-init skill.
 */

import { readdirSync, readFileSync, statSync } from "node:fs";
import { join } from "node:path";

const OPEN_TAG = "<artfct-guidelines>";
const CLOSE_TAG = "</artfct-guidelines>";
const AGENTS_IMPORT_LINE = "@AGENTS.md";

/**
 * @param {string} fileText
 * @returns {string} CRLF when the file uses it, otherwise LF.
 */
function lineEndingOf(fileText) {
	return fileText.includes("\r\n") ? "\r\n" : "\n";
}

/**
 * @param {string} blockBody
 * @param {string} eol
 * @returns {string} The full block, tags included, using the given line ending.
 */
function buildBlock(blockBody, eol) {
	const body = blockBody.replace(/^(?:\r?\n)+|(?:\r?\n)+$/g, "").split(/\r?\n/).join(eol);

	return [OPEN_TAG, body, CLOSE_TAG].join(eol);
}

/**
 * @param {string} fileText
 * @returns {{start: number, end: number} | null} Span of the artfct block, tags included.
 * @throws {Error} When the tags exist but do not form exactly one block.
 */
function locateBlock(fileText) {
	const open = fileText.indexOf(OPEN_TAG);
	const close = fileText.indexOf(CLOSE_TAG);

	if (open === -1 && close === -1) {
		return null;
	}

	if (open === -1 || close === -1 || close < open) {
		throw new Error('Malformed artfct block: "<artfct-guidelines>" and "</artfct-guidelines>" do not pair up.');
	}

	if (fileText.indexOf(OPEN_TAG, open + 1) !== -1 || fileText.indexOf(CLOSE_TAG, close + 1) !== -1) {
		throw new Error("Malformed artfct block: more than one artfct block is present.");
	}

	return { start: open, end: close + CLOSE_TAG.length };
}

/**
 * Inserts or replaces the artfct block. A new block is appended after one blank line.
 *
 * @param {string} fileText
 * @param {string} blockBody
 * @returns {{text: string, changed: boolean}}
 */
export function upsertBlock(fileText, blockBody) {
	const eol = lineEndingOf(fileText);
	const block = buildBlock(blockBody, eol);
	const existing = locateBlock(fileText);

	let text;

	if (existing) {
		text = fileText.slice(0, existing.start) + block + fileText.slice(existing.end);
	} else {
		const head = fileText.replace(/(?:\r?\n[ \t]*)+$/, "");
		text = head === "" ? block + eol : head + eol + eol + block + eol;
	}

	return { text, changed: text !== fileText };
}

/**
 * Removes the artfct block, along with the blank line that separated it from the preceding text.
 *
 * @param {string} fileText
 * @returns {{text: string, changed: boolean}}
 */
export function removeBlock(fileText) {
	const existing = locateBlock(fileText);

	if (!existing) {
		return { text: fileText, changed: false };
	}

	const eol = lineEndingOf(fileText);
	let start = existing.start;
	let end = existing.end;

	if (fileText.startsWith(eol, end)) {
		end += eol.length;
	}

	if (fileText.slice(0, start).endsWith(eol + eol)) {
		start -= eol.length;
	}

	const text = fileText.slice(0, start) + fileText.slice(end);

	return { text, changed: true };
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
 * Lists the entrypoint files that should receive the block. A CLAUDE.md that
 * imports AGENTS.md is skipped in favour of AGENTS.md when both exist.
 *
 * @param {string} repoDir
 * @returns {string[]} Absolute paths, sorted, using the on-disk capitalization.
 */
export function findEntrypoints(repoDir) {
	const matches = readdirSync(repoDir)
		.filter((name) => {
			const lower = name.toLowerCase();

			return (lower === "agents.md" || lower === "claude.md") && isRegularFile(join(repoDir, name));
		})
		.map((name) => ({ name: name.toLowerCase(), path: join(repoDir, name) }));

	const agents = matches.filter((match) => match.name === "agents.md");
	const claudes = matches.filter((match) => match.name === "claude.md");

	const claudeImportsAgents = claudes.some((claude) =>
		readFileSync(claude.path, "utf8").split(/\r?\n/).includes(AGENTS_IMPORT_LINE),
	);

	const chosen = agents.length > 0 && claudeImportsAgents ? agents : matches;

	return [...new Set(chosen.map((match) => match.path))].sort();
}
