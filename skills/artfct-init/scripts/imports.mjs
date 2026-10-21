/**
 * Previews and applies team imports from an artifact profile. Artifact text is
 * data only: the parser never acts on it, and applyImport writes nothing except
 * allowlisted personal preference lines and named sections of the shared
 * GUIDELINES and DESIGN files. Warnings are display-only.
 */

import { existsSync, mkdirSync, readFileSync, readdirSync, statSync, writeFileSync } from "node:fs";
import { join } from "node:path";
import { readManifest, writeManifest } from "./manifest.mjs";

/**
 * @typedef {"preference" | "guidelines" | "design"} ChangeTarget
 * @typedef {{target: ChangeTarget, key: string, text: string}} Change
 * @typedef {{target: ChangeTarget, key: string, local: string, incoming: string}} Conflict
 * @typedef {{accept: Change[], keepLocal: string[]}} Selection
 * @typedef {{artifactId: string, version: string}} Source
 * @typedef {{preferences: Record<string, unknown>, guidelinesSections: Record<string, string>, designSections: Record<string, string>}} Existing
 * @typedef {{heading: string, body: string, start: number, end: number}} Section
 */

const PREFERENCE_KEYS = ["explanation_format", "detail", "diagrams", "comparisons", "technical_depth", "language", "units"];
const USER_FILE = "USER.md";
const SHARED_FILES = { guidelines: "GUIDELINES", design: "DESIGN" };
const MAX_PREFERENCE_LENGTH = 200;
const MAX_WARNING_LENGTH = 200;
const MAX_SECTION_NAME_LENGTH = 80;
const WARNING_PATTERN = /\b(allow|grant|permissions?|delete|tokens?|passwords?|secrets?|api keys?|run|execute|ignore previous)\b|(^|\s)(\$ |rm -|curl |wget |sudo |chmod )/i;
const HEADING_PATTERN = /^(#{1,2})[ \t]/;

/**
 * Splits Markdown into `## ` sections. A section ends at the next `#` or `##` heading.
 *
 * @param {string} text
 * @returns {Section[]} Offsets refer to the input text.
 */
function splitSections(text) {
	/** @type {Section[]} */
	const sections = [];
	/** @type {{heading: string, start: number, bodyLines: string[]} | null} */
	let current = null;
	let offset = 0;

	const close = (end) => {
		if (current) {
			sections.push({ heading: current.heading, body: trimBlankLines(current.bodyLines), start: current.start, end });
			current = null;
		}
	};

	for (const rawLine of text.split("\n")) {
		const line = rawLine.replace(/\r$/, "");
		const lineStart = offset;
		offset += rawLine.length + 1;

		const headingMatch = HEADING_PATTERN.exec(line);

		if (headingMatch) {
			close(lineStart);

			if (headingMatch[1] === "##") {
				current = { heading: line.slice(3).trim(), start: lineStart, bodyLines: [] };
			}

			continue;
		}

		if (current) {
			current.bodyLines.push(line);
		}
	}

	close(text.length);

	return sections;
}

/**
 * @param {string[]} lines
 * @returns {string} The lines joined with LF, without leading or trailing blank lines.
 */
function trimBlankLines(lines) {
	let first = 0;
	let last = lines.length;

	while (first < last && lines[first].trim() === "") {
		first++;
	}

	while (last > first && lines[last - 1].trim() === "") {
		last--;
	}

	return lines.slice(first, last).join("\n");
}

/**
 * @param {string} name
 * @returns {boolean} True when the name can be written as a `## ` heading.
 */
function isValidSectionName(name) {
	return name.length > 0 && name.length <= MAX_SECTION_NAME_LENGTH && !/[\r\n]/.test(name) && !name.startsWith("#");
}

/**
 * @param {string} heading
 * @returns {{target: ChangeTarget, key: string} | null} The import target for a heading, or null when it is ignored.
 */
function classifyHeading(heading) {
	const trimmed = heading.trim();
	const normalized = trimmed.toLowerCase().replace(/[\s_]+/g, "_");

	if (PREFERENCE_KEYS.includes(normalized)) {
		return { target: "preference", key: normalized };
	}

	const match = /^(guidelines|design)\s*:\s*(.+)$/i.exec(trimmed);

	if (match) {
		const key = match[2].trim();

		if (isValidSectionName(key)) {
			return { target: /** @type {ChangeTarget} */ (match[1].toLowerCase()), key };
		}
	}

	return null;
}

/**
 * @param {string} name
 * @param {string} other
 * @returns {boolean} True when two section names match ignoring case and surrounding spaces.
 */
function sameName(name, other) {
	return name.trim().toLowerCase() === other.trim().toLowerCase();
}

/**
 * @param {string} body
 * @returns {string} A preference value on one line, capped in length.
 */
function toPreferenceValue(body) {
	return body
		.split(/\r?\n/)
		.map((line) => line.trim())
		.filter((line) => line !== "")
		.join(" ")
		.slice(0, MAX_PREFERENCE_LENGTH)
		.trim();
}

/**
 * @param {string} text
 * @returns {string[]} Unique lines that look like permission, credential or instruction wording.
 */
function findWarnings(text) {
	const lines = text
		.split(/\r?\n/)
		.map((line) => line.trim())
		.filter((line) => line !== "" && WARNING_PATTERN.test(line))
		.map((line) => line.slice(0, MAX_WARNING_LENGTH));

	return [...new Set(lines)];
}

/**
 * Reads an artifact profile. Only `## ` sections that map to a preference key, or to
 * "Guidelines: <name>" / "Design: <name>", are kept. Everything else is ignored.
 *
 * @param {string} text
 * @returns {{sections: Record<string, string>, warnings: string[]}} Sections keyed by heading text, with their bodies.
 */
export function parseProfile(text) {
	/** @type {Record<string, string>} */
	const sections = {};

	for (const section of splitSections(text)) {
		if (classifyHeading(section.heading) === null || Object.hasOwn(sections, section.heading)) {
			continue;
		}

		sections[section.heading] = section.body;
	}

	return { sections, warnings: findWarnings(text) };
}

/**
 * @param {Record<string, unknown> | Record<string, string>} map
 * @param {string} key
 * @returns {{name: string, value: unknown} | null} The entry whose name matches the key ignoring case.
 */
function lookupSection(map, key) {
	const name = Object.keys(map ?? {}).find((candidate) => sameName(candidate, key));

	return name === undefined ? null : { name, value: map[name] };
}

/**
 * Compares an artifact profile with existing local values and lists what would be written.
 * `destinations` names the logical files (USER.md, GUIDELINES.md, DESIGN.md); the real
 * path, including existing capitalization, is resolved when the import is applied.
 *
 * @param {{sections: Record<string, string>, warnings: string[]}} parsed
 * @param {Existing} existing
 * @returns {{apply: Change[], conflicts: Conflict[], destinations: string[]}}
 */
export function previewImport(parsed, existing) {
	/** @type {Change[]} */
	const apply = [];
	/** @type {Conflict[]} */
	const conflicts = [];
	const seen = new Set();

	for (const [heading, body] of Object.entries(parsed.sections)) {
		const classified = classifyHeading(heading);

		if (classified === null || seen.has(`${classified.target}:${classified.key}`)) {
			continue;
		}

		const { target, key } = classified;

		if (target === "preference") {
			const incoming = toPreferenceValue(body);

			if (incoming === "") {
				continue;
			}

			seen.add(`${target}:${key}`);

			const local = lookupSection(existing.preferences, key);
			const localText = local === null || local.value === undefined || local.value === null ? "" : String(local.value).trim();

			if (localText === incoming) {
				continue;
			}

			if (localText === "") {
				apply.push({ target, key, text: `${key}: ${incoming}` });
			} else {
				conflicts.push({ target, key, local: localText, incoming });
			}

			continue;
		}

		const incoming = body.trim();

		if (incoming === "" || /^#{1,2}[ \t]/m.test(incoming)) {
			continue;
		}

		seen.add(`${target}:${key}`);

		const sectionMap = target === "guidelines" ? existing.guidelinesSections : existing.designSections;
		const local = lookupSection(sectionMap, key);
		const localBody = local === null || local.value === undefined || local.value === null ? "" : String(local.value).trim();

		if (localBody === incoming) {
			continue;
		}

		if (localBody === "") {
			apply.push({ target, key, text: `## ${key}\n\n${incoming}` });
		} else {
			conflicts.push({ target, key, local: localBody, incoming });
		}
	}

	const destinations = [];

	if (apply.some((change) => change.target === "preference")) {
		destinations.push(USER_FILE);
	}

	if (apply.some((change) => change.target === "guidelines")) {
		destinations.push(`${SHARED_FILES.guidelines}.md`);
	}

	if (apply.some((change) => change.target === "design")) {
		destinations.push(`${SHARED_FILES.design}.md`);
	}

	return { apply, conflicts, destinations };
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
 * Finds a shared file in the repo root ignoring case, or names the canonical path when absent.
 *
 * @param {string} repoDir
 * @param {string} canonicalName `GUIDELINES` or `DESIGN`
 * @returns {string}
 */
function resolveSharedFile(repoDir, canonicalName) {
	const wanted = `${canonicalName.toLowerCase()}.md`;
	const match = readdirSync(repoDir).find((name) => name.toLowerCase() === wanted && isRegularFile(join(repoDir, name)));

	return join(repoDir, match ?? `${canonicalName}.md`);
}

/**
 * A change is writable only when its shape matches the allowlist and section rules.
 * The text is checked too, so a crafted Change cannot smuggle extra lines or headings.
 *
 * @param {Change} change
 * @returns {boolean}
 */
function isWritable(change) {
	if (change === null || typeof change !== "object" || typeof change.key !== "string" || typeof change.text !== "string") {
		return false;
	}

	if (change.target === "preference") {
		const prefix = `${change.key}: `;

		return (
			PREFERENCE_KEYS.includes(change.key) &&
			change.text.startsWith(prefix) &&
			change.text.length > prefix.length &&
			!/[\r\n]/.test(change.text)
		);
	}

	if (change.target === "guidelines" || change.target === "design") {
		const heading = `## ${change.key}\n\n`;

		if (!isValidSectionName(change.key) || !change.text.startsWith(heading)) {
			return false;
		}

		const body = change.text.slice(heading.length);

		return body.trim() !== "" && !/^#{1,2}[ \t]/m.test(body);
	}

	return false;
}

/**
 * Sets each `key: value` line in USER.md, replacing a line with the same key and keeping every other line.
 *
 * @param {string} configDir
 * @param {Change[]} changes
 * @returns {void}
 */
function applyPreferences(configDir, changes) {
	const path = join(configDir, USER_FILE);
	const current = existsSync(path) ? readFileSync(path, "utf8") : "";
	const eol = current.includes("\r\n") ? "\r\n" : "\n";
	const lines = current === "" ? [] : current.split(/\r?\n/);

	if (lines.length > 0 && lines[lines.length - 1] === "") {
		lines.pop();
	}

	for (const change of changes) {
		const index = lines.findIndex((line) => line.startsWith(`${change.key}:`));

		if (index === -1) {
			lines.push(change.text);
		} else {
			lines[index] = change.text;
		}
	}

	mkdirSync(configDir, { recursive: true });
	writeFileSync(path, lines.join(eol) + eol);
}

/**
 * Replaces the named section when it exists, otherwise appends it. Text outside the section is kept byte-for-byte.
 *
 * @param {string} text
 * @param {string} name
 * @param {string} segment The section text, already using the file's line ending.
 * @param {string} eol
 * @returns {string}
 */
function replaceOrAppendSection(text, name, segment, eol) {
	const section = splitSections(text).find((candidate) => sameName(candidate.heading, name));

	if (section) {
		const hasFollowingSection = section.end < text.length;

		return text.slice(0, section.start) + segment + eol + (hasFollowingSection ? eol : "") + text.slice(section.end);
	}

	if (text.trim() === "") {
		return segment + eol;
	}

	let head = text;

	if (!head.endsWith(eol + eol)) {
		head += head.endsWith(eol) ? eol : eol + eol;
	}

	return head + segment + eol;
}

/**
 * @param {string} repoDir
 * @param {string} canonicalName `GUIDELINES` or `DESIGN`
 * @param {Change[]} changes
 * @returns {void}
 */
function applySections(repoDir, canonicalName, changes) {
	const path = resolveSharedFile(repoDir, canonicalName);
	let text = existsSync(path) ? readFileSync(path, "utf8") : "";
	const eol = text.includes("\r\n") ? "\r\n" : "\n";

	for (const change of changes) {
		const segment = change.text.split("\n").join(eol);
		text = replaceOrAppendSection(text, change.key, segment, eol);
	}

	writeFileSync(path, text);
}

/**
 * Writes the accepted changes and records the import in artfct.json. Writes nothing
 * for an artifact version already recorded, and nothing when no change is applied.
 *
 * @param {Selection} selection
 * @param {string} repoDir
 * @param {string} configDir
 * @param {Source} source
 * @returns {void}
 */
export function applyImport(selection, repoDir, configDir, source) {
	if (typeof source?.artifactId !== "string" || source.artifactId === "" || typeof source.version !== "string" || source.version === "") {
		throw new Error("applyImport needs a source with artifactId and version.");
	}

	const manifest = readManifest(repoDir);

	if (manifest.imports.some((record) => record.artifactId === source.artifactId && record.version === source.version)) {
		return;
	}

	const keepLocal = new Set(selection.keepLocal ?? []);
	const accepted = (selection.accept ?? []).filter((change) => isWritable(change) && !keepLocal.has(change.key));
	const applied = [];

	const preferenceChanges = accepted.filter((change) => change.target === "preference");

	if (preferenceChanges.length > 0) {
		applyPreferences(configDir, preferenceChanges);
		applied.push(...preferenceChanges);
	}

	for (const target of /** @type {const} */ (["guidelines", "design"])) {
		const changes = accepted.filter((change) => change.target === target);

		if (changes.length > 0) {
			applySections(repoDir, SHARED_FILES[target], changes);
			applied.push(...changes);
		}
	}

	if (applied.length === 0) {
		return;
	}

	writeManifest(repoDir, {
		...manifest,
		imports: [
			...manifest.imports,
			{
				artifactId: source.artifactId,
				version: source.version,
				sections: applied.map((change) => `${change.target}:${change.key}`),
				at: new Date().toISOString(),
			},
		],
	});
}
