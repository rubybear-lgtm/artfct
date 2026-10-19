/**
 * Reads and writes artfct.json, the per-repo record of setup decisions made by
 * the artfct-init skill.
 *
 * Reading never creates the file. Writing is deterministic: known keys come
 * first in a fixed order, unknown top-level keys follow alphabetically, and
 * decision keys are sorted, so rewriting an unchanged manifest yields
 * identical bytes.
 */

import { existsSync, readFileSync, writeFileSync } from "node:fs";
import { join } from "node:path";

/**
 * @typedef {"configured" | "inherited" | "skipped" | "blocked"} DecisionStatus
 * @typedef {{ status: DecisionStatus, at: string }} Decision
 * @typedef {{ artifactId: string, version: string, sections: string[], at: string }} ImportRecord
 * @typedef {{ version: 1, decisions: Record<string, Decision>, grants: Record<string, string[]>, imports: ImportRecord[], files: { guidelines: string | null, design: string | null, user: string | null } }} Manifest
 */

const FILENAME = "artfct.json";
const STATUSES = new Set(["configured", "inherited", "skipped", "blocked"]);
const RESOLVED_STATUSES = new Set(["configured", "inherited", "skipped"]);
const KNOWN_KEYS = ["version", "decisions", "grants", "imports", "files"];
const FILE_KEYS = ["guidelines", "design", "user"];

/**
 * @returns {Manifest}
 */
function defaultManifest() {
	return {
		version: 1,
		decisions: {},
		grants: {},
		imports: [],
		files: { guidelines: null, design: null, user: null },
	};
}

/**
 * @param {string} repoDir
 * @returns {string}
 */
function manifestPath(repoDir) {
	return join(repoDir, FILENAME);
}

/**
 * @param {unknown} status
 */
function assertStatus(status) {
	if (!STATUSES.has(status)) {
		throw new Error(`Invalid decision status: ${JSON.stringify(status)}`);
	}
}

/**
 * @param {Record<string, unknown>} object
 * @returns {Record<string, unknown>}
 */
function sortKeys(object) {
	return Object.fromEntries(Object.keys(object).sort().map((key) => [key, object[key]]));
}

/**
 * @param {Manifest["files"]} files
 * @returns {Manifest["files"]}
 */
function orderFiles(files) {
	return Object.fromEntries(FILE_KEYS.map((key) => [key, files[key] ?? null]));
}

/**
 * Returns the manifest in artfct.json, or defaults when the file is absent.
 * Unknown top-level fields are kept so that a write does not drop them.
 *
 * @param {string} repoDir
 * @returns {Manifest}
 */
export function readManifest(repoDir) {
	const path = manifestPath(repoDir);

	if (!existsSync(path)) {
		return defaultManifest();
	}

	let parsed;

	try {
		parsed = JSON.parse(readFileSync(path, "utf8"));
	} catch (error) {
		throw new Error(`Invalid JSON in ${path}: ${error.message}`);
	}

	if (parsed === null || typeof parsed !== "object" || Array.isArray(parsed)) {
		throw new Error(`${path} must contain a JSON object`);
	}

	const defaults = defaultManifest();

	return {
		...parsed,
		version: parsed.version ?? defaults.version,
		decisions: parsed.decisions ?? defaults.decisions,
		grants: parsed.grants ?? defaults.grants,
		imports: parsed.imports ?? defaults.imports,
		files: { ...defaults.files, ...(parsed.files ?? {}) },
	};
}

/**
 * Writes artfct.json at the repo root with 2-space indent and a trailing newline.
 *
 * @param {string} repoDir
 * @param {Manifest} manifest
 * @returns {void}
 */
export function writeManifest(repoDir, manifest) {
	const ordered = {
		version: manifest.version ?? 1,
		decisions: sortKeys(manifest.decisions ?? {}),
		grants: manifest.grants ?? {},
		imports: manifest.imports ?? [],
		files: orderFiles(manifest.files ?? {}),
	};

	const unknownKeys = Object.keys(manifest)
		.filter((key) => !KNOWN_KEYS.includes(key))
		.sort();

	for (const key of unknownKeys) {
		ordered[key] = manifest[key];
	}

	writeFileSync(manifestPath(repoDir), JSON.stringify(ordered, null, 2) + "\n");
}

/**
 * Returns a manifest with the decision recorded. Pure: the input is never
 * mutated. If the key already has the same status, the input manifest is
 * returned as-is, keeping the original `at`.
 *
 * @param {Manifest} manifest
 * @param {string} key
 * @param {{ status: DecisionStatus, at: string }} decision
 * @returns {Manifest}
 */
export function recordDecision(manifest, key, { status, at }) {
	assertStatus(status);

	const current = Object.hasOwn(manifest.decisions, key) ? manifest.decisions[key] : undefined;

	if (current?.status === status) {
		return manifest;
	}

	return {
		...manifest,
		decisions: { ...manifest.decisions, [key]: { status, at } },
	};
}

/**
 * True when the decision is configured, inherited or skipped. False for
 * blocked, and for a key that has no decision.
 *
 * @param {Manifest} manifest
 * @param {string} key
 * @returns {boolean}
 */
export function isResolved(manifest, key) {
	if (!Object.hasOwn(manifest.decisions, key)) {
		return false;
	}

	return RESOLVED_STATUSES.has(manifest.decisions[key]?.status);
}
