#!/usr/bin/env node
/**
 * Checks that every published skill in skills/ has frontmatter the skills.sh
 * installer can read (RUB-502). The installer parses it as YAML and silently
 * skips a skill whose plain (unquoted) value contains ": " or " #", which is
 * how artfct-init, artfct-search, artfct-publish and artfct-collaborate went
 * missing from `npx skills add`.
 *
 *   node skills/artfct-init/scripts/test-skill-frontmatter.mjs
 *
 * Exits non-zero if any skill fails.
 */

import assert from "node:assert/strict";
import { spawnSync } from "node:child_process";
import { existsSync, readdirSync, readFileSync } from "node:fs";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";

const SKILLS_DIR = join(dirname(fileURLToPath(import.meta.url)), "..", "..");

let failed = 0;

function check(name, fn) {
	try {
		fn();
		console.log("PASS " + name);
	} catch (error) {
		failed += 1;
		console.log("FAIL " + name);
		console.log("     " + String(error.message).split("\n").join("\n     "));
	}
}

/**
 * Returns the problem with one frontmatter value, or null when a YAML parser
 * reads it as the plain string it looks like.
 *
 * @param {string} value
 * @returns {string | null}
 */
export function plainValueProblem(value) {
	if (/^".*"$/.test(value) || /^'.*'$/.test(value)) {
		return null;
	}

	if (value.includes(": ")) {
		return 'contains ": ", so YAML reads a nested mapping; wrap the value in double quotes';
	}

	if (value.includes(" #")) {
		return 'contains " #", so YAML drops the rest as a comment; wrap the value in double quotes';
	}

	if (/^[[{&*!|>%@`"']/.test(value)) {
		return "starts with a YAML indicator character; wrap the value in double quotes";
	}

	return null;
}

/**
 * @param {string} text
 * @returns {Record<string, string>}
 */
function frontmatterOf(text) {
	const match = text.match(/^---\r?\n([\s\S]*?)\r?\n---\r?\n/);

	assert.ok(match, "SKILL.md must start with a --- frontmatter block");

	/** @type {Record<string, string>} */
	const fields = {};

	for (const line of match[1].split(/\r?\n/)) {
		const field = line.match(/^([A-Za-z0-9_-]+):\s*(.*)$/);

		assert.ok(field, `unexpected frontmatter line: ${line}`);
		fields[field[1]] = field[2];
	}

	return fields;
}

const skills = readdirSync(SKILLS_DIR, { withFileTypes: true })
	.filter((entry) => entry.isDirectory() && existsSync(join(SKILLS_DIR, entry.name, "SKILL.md")))
	.map((entry) => entry.name)
	.sort();

check("finds the published skills", () => {
	assert.ok(skills.includes("artfct"), "skills/artfct is missing");
	assert.ok(skills.includes("artfct-init"), "skills/artfct-init is missing");
});

for (const skill of skills) {
	check(`${skill} has frontmatter the installer can read`, () => {
		const fields = frontmatterOf(readFileSync(join(SKILLS_DIR, skill, "SKILL.md"), "utf8"));

		assert.equal(fields.name, skill, "name must match the folder name");
		assert.ok(fields.description, "description is required");

		for (const [key, value] of Object.entries(fields)) {
			const problem = plainValueProblem(value);

			assert.equal(problem, null, `${key}: ${problem}`);
		}
	});
}

/**
 * The installer also scans .claude/skills/, where this repo keeps the skills
 * its own agents use (Boost's Laravel skills and others). Those are hidden
 * from `npx skills add` with `metadata.internal: true`; Boost rewrites its
 * files on update, so this check catches a flag that went missing.
 */
const REPO_DIR = join(SKILLS_DIR, "..");
const tracked = spawnSync("git", ["ls-files", ".claude/skills"], { cwd: REPO_DIR, encoding: "utf8" });
const internalSkills = tracked.status === 0 ? tracked.stdout.split("\n").filter((path) => path.endsWith("/SKILL.md")) : [];

for (const path of internalSkills) {
	check(`${path} is hidden from the public installer`, () => {
		const text = readFileSync(join(REPO_DIR, path), "utf8");
		const frontmatter = text.match(/^---\r?\n([\s\S]*?)\r?\n---\r?\n/)?.[1] ?? "";

		assert.match(frontmatter, /^metadata:\r?\n(?:\s+.*\r?\n)*?\s+internal: true$/m, "add `metadata:` with `internal: true` to the frontmatter");
	});
}

check("an unquoted colon is caught and a quoted one is allowed", () => {
	assert.notEqual(plainValueProblem("Use when setting up: checks the connection"), null);
	assert.equal(plainValueProblem('"Use when setting up: checks the connection"'), null);
	assert.notEqual(plainValueProblem("Publishes pages #1 first"), null);
	assert.equal(plainValueProblem("Publishes pages and reports"), null);
});

console.log(failed === 0 ? "\nAll frontmatter checks passed" : `\n${failed} frontmatter check(s) failed`);
process.exit(failed === 0 ? 0 : 1);
