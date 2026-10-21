#!/usr/bin/env node
/**
 * Reads and writes the personal preference files used by the artfct-init
 * skill: the global file in the personal config directory (USER.md) and the
 * project override at the repo root (USER.local.md). Only allowlisted keys
 * are ever read or written, and every other line in a file is kept as is.
 */

import { existsSync, mkdirSync, readFileSync, statSync, writeFileSync } from "node:fs";
import { dirname, isAbsolute, join, resolve } from "node:path";

export const PREFERENCE_KEYS = [
	"explanation_format",
	"detail",
	"diagrams",
	"comparisons",
	"technical_depth",
	"language",
	"units",
];

const GLOBAL_FILE = "USER.md";
const PROJECT_FILE = "USER.local.md";
const EXCLUDE_ENTRY = PROJECT_FILE;

/**
 * @typedef {{[key: string]: string}} PreferenceLayer
 * @typedef {{task?: PreferenceLayer, project?: PreferenceLayer, global?: PreferenceLayer, team?: PreferenceLayer}} PreferenceLayers
 * @typedef {{value: string | undefined, source: 'task'|'project'|'global'|'team'|'unspecified'}} Resolution
 */

/**
 * @param {string} key
 * @returns {void}
 * @throws {Error} When the key is not in the preference allowlist.
 */
function assertAllowedKey(key) {
	if (!PREFERENCE_KEYS.includes(key)) {
		throw new Error(`Preference key "${key}" is not in the allowlist.`);
	}
}

/**
 * Parses one line as a `key: value` pair. Blank lines, comments and lines
 * without a colon are not assignments.
 *
 * @param {string} line
 * @returns {{key: string, value: string} | null}
 */
function parseAssignment(line) {
	const trimmed = line.trim();

	if (trimmed === "" || trimmed.startsWith("#")) {
		return null;
	}

	const colon = trimmed.indexOf(":");

	if (colon === -1) {
		return null;
	}

	return { key: trimmed.slice(0, colon).trim(), value: trimmed.slice(colon + 1).trim() };
}

/**
 * Reads the allowlisted assignments from a preference file. Later lines win.
 * Empty values are treated as unset.
 *
 * @param {string} path
 * @returns {PreferenceLayer}
 */
function readLayer(path) {
	if (!existsSync(path)) {
		return {};
	}

	/** @type {PreferenceLayer} */
	const layer = {};

	for (const line of readFileSync(path, "utf8").split(/\r?\n/)) {
		const assignment = parseAssignment(line);

		if (assignment && PREFERENCE_KEYS.includes(assignment.key) && assignment.value !== "") {
			layer[assignment.key] = assignment.value;
		}
	}

	return layer;
}

/**
 * Splits text into lines, keeping each line's own terminator so that the
 * untouched lines of a mixed-ending file are written back byte for byte.
 *
 * @param {string} text
 * @returns {{content: string, eol: string}[]}
 */
function splitLines(text) {
	const parts = text.split(/(\r?\n)/);
	const lines = [];

	for (let index = 0; index < parts.length; index += 2) {
		lines.push({ content: parts[index], eol: parts[index + 1] ?? "" });
	}

	return lines;
}

/**
 * Reads the global (personal) and project (repo-local) preferences.
 *
 * @param {string} configDir Personal config directory, outside the repo.
 * @param {string} repoDir Repo root holding USER.local.md.
 * @returns {{global: PreferenceLayer, project: PreferenceLayer}}
 */
export function readPreferences(configDir, repoDir) {
	return {
		global: readLayer(join(configDir, GLOBAL_FILE)),
		project: readLayer(join(repoDir, PROJECT_FILE)),
	};
}

/**
 * Sets one allowlisted preference. Replaces the last existing line for the
 * key, or appends one. Writes nothing when the value is already set, and
 * creates the file and its parent directories when they are absent.
 *
 * @param {string} path
 * @param {string} key
 * @param {string} value
 * @returns {void}
 * @throws {Error} When the key is not allowlisted or the value cannot be stored on one line.
 */
export function writePreference(path, key, value) {
	assertAllowedKey(key);

	if (typeof value !== "string" || value.trim() === "" || /[\r\n]/.test(value)) {
		throw new Error(`Preference "${key}" needs a non-empty single-line string value.`);
	}

	const cleanValue = value.trim();
	const text = existsSync(path) ? readFileSync(path, "utf8") : "";
	const lines = splitLines(text);

	let lastIndex = -1;

	for (let index = 0; index < lines.length; index += 1) {
		const assignment = parseAssignment(lines[index].content);

		if (assignment && assignment.key === key) {
			lastIndex = index;
		}
	}

	if (lastIndex !== -1) {
		const current = parseAssignment(lines[lastIndex].content);

		if (current.value === cleanValue) {
			return;
		}

		lines[lastIndex] = { content: `${key}: ${cleanValue}`, eol: lines[lastIndex].eol };
		writeText(path, lines.map((line) => line.content + line.eol).join(""));

		return;
	}

	const eol = text.includes("\r\n") ? "\r\n" : "\n";
	const separator = text === "" || /\r?\n$/.test(text) ? "" : eol;

	writeText(path, text + separator + `${key}: ${cleanValue}` + eol);
}

/**
 * Writes a file, creating its parent directories first.
 *
 * @param {string} path
 * @param {string} text
 * @returns {void}
 */
function writeText(path, text) {
	mkdirSync(dirname(path), { recursive: true });
	writeFileSync(path, text, "utf8");
}

/**
 * Adds a personal file (USER.local.md by default, or CLAUDE.local.md for a
 * user-level setup) to the repo's local exclude file so it is never
 * committed. Never edits .gitignore. A worktree's `.git` file is followed to
 * the common git directory, where the exclude file lives.
 *
 * @param {string} repoDir
 * @param {string} [entry]
 * @returns {{changed: boolean}}
 */
export function ensureLocalExclude(repoDir, entry = EXCLUDE_ENTRY) {
	const gitPath = join(repoDir, ".git");

	if (!existsSync(gitPath)) {
		return { changed: false };
	}

	const gitDir = resolveCommonGitDir(gitPath);
	const excludePath = join(gitDir, "info", "exclude");
	const text = existsSync(excludePath) ? readFileSync(excludePath, "utf8") : "";

	if (splitLines(text).some((line) => line.content.trim() === entry)) {
		return { changed: false };
	}

	const eol = text.includes("\r\n") ? "\r\n" : "\n";
	const separator = text === "" || /\r?\n$/.test(text) ? "" : eol;

	writeText(excludePath, text + separator + entry + eol);

	return { changed: true };
}

/**
 * @param {string} gitPath Path to a `.git` directory or a `.git` file.
 * @returns {string} The directory that holds `info/exclude`.
 */
function resolveCommonGitDir(gitPath) {
	if (!statSync(gitPath).isFile()) {
		return gitPath;
	}

	const pointer = readFileSync(gitPath, "utf8").match(/^gitdir:\s*(.+)$/m);

	if (!pointer) {
		return gitPath;
	}

	const gitDir = resolve(dirname(gitPath), pointer[1].trim());
	const commonDirFile = join(gitDir, "commondir");

	if (!existsSync(commonDirFile)) {
		return gitDir;
	}

	const commonDir = readFileSync(commonDirFile, "utf8").trim();

	return isAbsolute(commonDir) ? commonDir : resolve(gitDir, commonDir);
}

/**
 * Resolves a preference across the layers, highest precedence first. A layer
 * without the key is skipped.
 *
 * @param {string} key
 * @param {PreferenceLayers} layers
 * @returns {Resolution}
 */
export function resolvePreference(key, { task, project, global, team } = {}) {
	assertAllowedKey(key);

	const ordered = [
		["task", task],
		["project", project],
		["global", global],
		["team", team],
	];

	for (const [source, layer] of ordered) {
		if (layer && Object.prototype.hasOwnProperty.call(layer, key) && layer[key] != null) {
			return { value: layer[key], source };
		}
	}

	return { value: undefined, source: "unspecified" };
}

/**
 * Resolves the personal config directory. The ARTFCT_CONFIG_DIR override wins,
 * then XDG_CONFIG_HOME, then ~/.config.
 *
 * @param {Record<string, string | undefined>} env
 * @param {string} home
 * @returns {string}
 */
export function resolveConfigDir(env, home) {
	if (env.ARTFCT_CONFIG_DIR) {
		return env.ARTFCT_CONFIG_DIR;
	}

	if (env.XDG_CONFIG_HOME) {
		return join(env.XDG_CONFIG_HOME, "artfct");
	}

	return join(home, ".config", "artfct");
}
