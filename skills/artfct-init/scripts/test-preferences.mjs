#!/usr/bin/env node
/**
 * Tests the personal preference helpers.
 *
 *   node skills/artfct-init/scripts/test-preferences.mjs
 *
 * Every case works inside a throwaway directory from mkdtemp. The home
 * directory is always passed in explicitly, so the real one is never read.
 */

import assert from "node:assert/strict";
import { existsSync, mkdirSync, mkdtempSync, readFileSync, rmSync, writeFileSync } from "node:fs";
import { tmpdir } from "node:os";
import { join, relative } from "node:path";
import {
	PREFERENCE_KEYS,
	ensureLocalExclude,
	readPreferences,
	resolveConfigDir,
	resolvePreference,
	writePreference,
} from "./preferences.mjs";

let failed = 0;

/**
 * @param {string} name
 * @param {() => void} body
 * @returns {void}
 */
function check(name, body) {
	try {
		body();
		console.log("  PASS " + name);
	} catch (error) {
		failed += 1;
		console.log("  FAIL " + name + "  -- " + (error instanceof Error ? error.message : String(error)));
	}
}

/**
 * @param {string} prefix
 * @returns {string}
 */
function makeDir(prefix) {
	return mkdtempSync(join(tmpdir(), prefix));
}

console.log("resolvePreference");

check("task layer wins when it sets the key", () => {
	const result = resolvePreference("detail", { task: { detail: "brief" }, project: { detail: "full" } });
	assert.deepEqual(result, { value: "brief", source: "task" });
});

check("project layer wins when task lacks the key", () => {
	const result = resolvePreference("detail", { task: {}, project: { detail: "full" }, global: { detail: "brief" } });
	assert.deepEqual(result, { value: "full", source: "project" });
});

check("global layer wins when task and project lack the key", () => {
	const result = resolvePreference("detail", { project: { units: "metric" }, global: { detail: "brief" } });
	assert.deepEqual(result, { value: "brief", source: "global" });
});

check("team layer wins when no personal layer sets the key", () => {
	const result = resolvePreference("language", { global: { detail: "brief" }, team: { language: "en-GB" } });
	assert.deepEqual(result, { value: "en-GB", source: "team" });
});

check("unspecified when no layer sets the key", () => {
	const result = resolvePreference("diagrams", { task: {}, project: { detail: "full" }, global: {}, team: {} });
	assert.deepEqual(result, { value: undefined, source: "unspecified" });
});

check("unspecified when called with no layers", () => {
	assert.deepEqual(resolvePreference("diagrams"), { value: undefined, source: "unspecified" });
});

check("project beats global when both set the key", () => {
	const dir = makeDir("artfct-prec-");
	const configDir = join(dir, "config");
	const repoDir = join(dir, "repo");
	mkdirSync(configDir, { recursive: true });
	mkdirSync(repoDir, { recursive: true });
	writeFileSync(join(configDir, "USER.md"), "detail: brief\n");
	writeFileSync(join(repoDir, "USER.local.md"), "detail: full\n");

	const layers = readPreferences(configDir, repoDir);
	assert.deepEqual(resolvePreference("detail", layers), { value: "full", source: "project" });
	rmSync(dir, { recursive: true, force: true });
});

console.log("readPreferences");

check("missing files give empty layers", () => {
	const dir = makeDir("artfct-missing-");
	assert.deepEqual(readPreferences(join(dir, "config"), dir), { global: {}, project: {} });
	rmSync(dir, { recursive: true, force: true });
});

check("blank lines, comments, unknown keys and non-allowlisted keys are ignored", () => {
	const dir = makeDir("artfct-read-");
	writeFileSync(
		join(dir, "USER.md"),
		"\n# a comment\nunits: metric\nmystery: value\nno colon here\nemail: someone@example.com\n",
	);
	assert.deepEqual(readPreferences(dir, join(dir, "no-repo")).global, { units: "metric" });
	rmSync(dir, { recursive: true, force: true });
});

console.log("writePreference");

check("rejects a non-allowlisted key and creates no file", () => {
	const dir = makeDir("artfct-reject-");
	const path = join(dir, "USER.md");
	assert.throws(() => writePreference(path, "email", "x@example.com"), /not in the allowlist/);
	assert.throws(() => writePreference(path, "Detail", "brief"), /not in the allowlist/);
	assert.equal(existsSync(path), false);
	rmSync(dir, { recursive: true, force: true });
});

check("rejects a value that spans lines", () => {
	const dir = makeDir("artfct-multiline-");
	assert.throws(() => writePreference(join(dir, "USER.md"), "detail", "brief\ndetail: full"));
	rmSync(dir, { recursive: true, force: true });
});

check("round trip through readPreferences, creating parent directories", () => {
	const dir = makeDir("artfct-roundtrip-");
	const configDir = join(dir, "nested", "config");
	writePreference(join(configDir, "USER.md"), "explanation_format", "short bullets");
	writePreference(join(configDir, "USER.md"), "units", "metric");
	assert.deepEqual(readPreferences(configDir, dir).global, {
		explanation_format: "short bullets",
		units: "metric",
	});
	rmSync(dir, { recursive: true, force: true });
});

check("same value written again leaves the bytes identical", () => {
	const dir = makeDir("artfct-same-");
	const path = join(dir, "USER.md");
	writePreference(path, "diagrams", "yes");
	const first = readFileSync(path);
	writePreference(path, "diagrams", "yes");
	assert.deepEqual(readFileSync(path), first);
	rmSync(dir, { recursive: true, force: true });
});

check("same value already written by hand leaves the bytes identical", () => {
	const dir = makeDir("artfct-hand-");
	const path = join(dir, "USER.md");
	writeFileSync(path, "# mine\ndiagrams:yes\n");
	const before = readFileSync(path);
	writePreference(path, "diagrams", "yes");
	assert.deepEqual(readFileSync(path), before);
	rmSync(dir, { recursive: true, force: true });
});

check("changing a value replaces only its line", () => {
	const dir = makeDir("artfct-replace-");
	const path = join(dir, "USER.md");
	writeFileSync(path, "# keep me\nunits: metric\ndetail: brief\n# and me\n");
	writePreference(path, "detail", "full");
	assert.equal(readFileSync(path, "utf8"), "# keep me\nunits: metric\ndetail: full\n# and me\n");
	rmSync(dir, { recursive: true, force: true });
});

check("comments and unknown lines survive an append", () => {
	const dir = makeDir("artfct-append-");
	const path = join(dir, "USER.md");
	const original = "# my notes: keep this\nsomething unknown\n\n# detail: not a real line\n";
	writeFileSync(path, original);
	writePreference(path, "language", "en-GB");
	assert.equal(readFileSync(path, "utf8"), original + "language: en-GB\n");
	rmSync(dir, { recursive: true, force: true });
});

check("append to a file without a trailing newline adds the separator", () => {
	const dir = makeDir("artfct-nonl-");
	const path = join(dir, "USER.md");
	writeFileSync(path, "units: metric");
	writePreference(path, "detail", "brief");
	assert.equal(readFileSync(path, "utf8"), "units: metric\ndetail: brief\n");
	rmSync(dir, { recursive: true, force: true });
});

check("CRLF file stays CRLF and mixed endings are kept line by line", () => {
	const dir = makeDir("artfct-crlf-");
	const path = join(dir, "USER.md");
	writeFileSync(path, "# head\r\nunits: metric\r\ndetail: brief\n");
	writePreference(path, "units", "imperial");
	assert.equal(readFileSync(path, "utf8"), "# head\r\nunits: imperial\r\ndetail: brief\n");
	writePreference(path, "language", "de");
	assert.equal(readFileSync(path, "utf8"), "# head\r\nunits: imperial\r\ndetail: brief\nlanguage: de\r\n");
	rmSync(dir, { recursive: true, force: true });
});

check("every allowlisted key can be written and read", () => {
	const dir = makeDir("artfct-allkeys-");
	const path = join(dir, "USER.md");

	for (const key of PREFERENCE_KEYS) {
		writePreference(path, key, `value for ${key}`);
	}

	assert.deepEqual(readPreferences(dir, join(dir, "none")).global, Object.fromEntries(PREFERENCE_KEYS.map((key) => [key, `value for ${key}`])));
	rmSync(dir, { recursive: true, force: true });
});

console.log("skip-all scenario");

check("skipping every question writes nothing and leaves no USER.md", () => {
	const dir = makeDir("artfct-skip-");
	const configDir = join(dir, "config");
	const repoDir = join(dir, "repo");
	mkdirSync(repoDir, { recursive: true });

	const layers = readPreferences(configDir, repoDir);

	for (const key of PREFERENCE_KEYS) {
		assert.deepEqual(resolvePreference(key, layers), { value: undefined, source: "unspecified" });
	}

	assert.equal(existsSync(join(configDir, "USER.md")), false);
	assert.equal(existsSync(join(repoDir, "USER.local.md")), false);
	rmSync(dir, { recursive: true, force: true });
});

console.log("config directory");

check("global config dir is outside the repo dir", () => {
	const dir = makeDir("artfct-outside-");
	const repoDir = join(dir, "repo");
	const home = join(dir, "home");
	const configDir = resolveConfigDir({}, home);
	assert.ok(relative(repoDir, configDir).startsWith(".."), configDir);
	rmSync(dir, { recursive: true, force: true });
});

check("ARTFCT_CONFIG_DIR wins over XDG_CONFIG_HOME and home", () => {
	assert.equal(
		resolveConfigDir({ ARTFCT_CONFIG_DIR: "/x/override", XDG_CONFIG_HOME: "/x/xdg" }, "/x/home"),
		"/x/override",
	);
});

check("XDG_CONFIG_HOME is used when no override is set", () => {
	assert.equal(resolveConfigDir({ XDG_CONFIG_HOME: "/x/xdg" }, "/x/home"), join("/x/xdg", "artfct"));
});

check("falls back to <home>/.config/artfct", () => {
	assert.equal(resolveConfigDir({}, "/x/home"), join("/x/home", ".config", "artfct"));
});

console.log("ensureLocalExclude");

check("no .git leaves everything alone", () => {
	const dir = makeDir("artfct-nogit-");
	assert.deepEqual(ensureLocalExclude(dir), { changed: false });
	assert.equal(existsSync(join(dir, ".git")), false);
	rmSync(dir, { recursive: true, force: true });
});

check("adds USER.local.md to info/exclude once and never touches .gitignore", () => {
	const dir = makeDir("artfct-exclude-");
	mkdirSync(join(dir, ".git"));
	const gitignore = "node_modules/\n";
	writeFileSync(join(dir, ".gitignore"), gitignore);

	assert.deepEqual(ensureLocalExclude(dir), { changed: true });
	assert.deepEqual(ensureLocalExclude(dir), { changed: false });

	const exclude = readFileSync(join(dir, ".git", "info", "exclude"), "utf8");
	assert.equal(exclude.split("\n").filter((line) => line === "USER.local.md").length, 1);
	assert.equal(readFileSync(join(dir, ".gitignore"), "utf8"), gitignore);
	rmSync(dir, { recursive: true, force: true });
});

check("appends to an existing exclude file that lacks a trailing newline", () => {
	const dir = makeDir("artfct-exist-");
	mkdirSync(join(dir, ".git", "info"), { recursive: true });
	writeFileSync(join(dir, ".git", "info", "exclude"), "# git ls-files --others");
	assert.deepEqual(ensureLocalExclude(dir), { changed: true });
	assert.equal(readFileSync(join(dir, ".git", "info", "exclude"), "utf8"), "# git ls-files --others\nUSER.local.md\n");
	rmSync(dir, { recursive: true, force: true });
});

check("a worktree .git file is followed to the common git directory", () => {
	const dir = makeDir("artfct-worktree-");
	const main = join(dir, "main");
	const worktree = join(dir, "wt");
	mkdirSync(join(main, ".git", "worktrees", "wt"), { recursive: true });
	mkdirSync(worktree);
	writeFileSync(join(main, ".git", "worktrees", "wt", "commondir"), "../..\n");
	writeFileSync(join(worktree, ".git"), `gitdir: ${join(main, ".git", "worktrees", "wt")}\n`);

	assert.deepEqual(ensureLocalExclude(worktree), { changed: true });
	assert.equal(readFileSync(join(main, ".git", "info", "exclude"), "utf8"), "USER.local.md\n");
	rmSync(dir, { recursive: true, force: true });
});

console.log(failed === 0 ? "preferences: all cases passed" : "preferences: " + failed + " failed");
process.exit(failed === 0 ? 0 : 1);
