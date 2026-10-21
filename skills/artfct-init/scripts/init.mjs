#!/usr/bin/env node
/**
 * Command-line entry for the artfct-init skill. Each subcommand wraps one
 * library function and prints a single JSON document to stdout, so the skill
 * can run it and read the result. Nothing here asks questions; the skill does
 * that and passes the answers as flags.
 *
 *   node init.mjs <command> [--flag value ...]
 *
 * Commands: status, decide, block-upsert, block-remove, files-install,
 * perms-detect, perms-plan, perms-apply, perms-verify, prefs-read,
 * prefs-write, prefs-exclude, prefs-resolve, import-parse, import-preview,
 * import-apply.
 *
 * `--host` takes any agent in levels.mjs (claude, codex, cursor, opencode,
 * vscode, windsurf, zed, antigravity). `--level global|user|repo` picks where
 * the block, permissions and decisions go; without it the commands keep their
 * original repo-level behaviour.
 */

import { existsSync, mkdirSync, readFileSync, writeFileSync } from "node:fs";
import { homedir } from "node:os";
import { dirname, join, resolve } from "node:path";
import { fileURLToPath } from "node:url";
import { findEntrypoints, removeBlock, upsertBlock } from "./block.mjs";
import { installTemplate, resolveSharedFile } from "./files.mjs";
import { applyImport, parseProfile, previewImport } from "./imports.mjs";
import { blockPresence, HOSTS, instructionsTarget, permissionsTarget } from "./levels.mjs";
import { isResolved, readManifest, recordDecision, writeManifest } from "./manifest.mjs";
import { applyChanges, buildProfile, detectServerNames, planChanges, verifyGrants } from "./permissions.mjs";
import { ensureLocalExclude, readPreferences, resolveConfigDir, resolvePreference, writePreference } from "./preferences.mjs";

const HERE = dirname(fileURLToPath(import.meta.url));
const DEFAULT_BLOCK = join(HERE, "..", "assets", "entrypoint-block.md");
const GLOBAL_BLOCK = join(HERE, "..", "assets", "entrypoint-block-global.md");
const CAPABILITIES = ["read", "publish", "organize", "comment"];

/**
 * @param {string[]} argv
 * @returns {{command: string, flags: Record<string, string>}}
 */
function parseArgs(argv) {
	const [command = "", ...rest] = argv;
	/** @type {Record<string, string>} */
	const flags = {};

	for (let index = 0; index < rest.length; index += 2) {
		const name = rest[index];

		if (!name.startsWith("--") || rest[index + 1] === undefined) {
			throw new Error(`Expected "--flag value" pairs, got "${name}".`);
		}

		flags[name.slice(2)] = rest[index + 1];
	}

	return { command, flags };
}

/**
 * @param {Record<string, string>} flags
 * @param {string} name
 * @returns {string}
 */
function need(flags, name) {
	if (flags[name] === undefined) {
		throw new Error(`Missing --${name}.`);
	}

	return flags[name];
}

/**
 * @param {string} path
 * @returns {unknown}
 */
function readJson(path) {
	return JSON.parse(readFileSync(path, "utf8"));
}

/**
 * @param {string} csv
 * @returns {Record<string, boolean>}
 */
function capabilitiesFrom(csv) {
	const wanted = csv.split(",").map((name) => name.trim()).filter(Boolean);
	const unknown = wanted.filter((name) => !CAPABILITIES.includes(name));

	if (unknown.length > 0) {
		throw new Error(`Unknown capability: ${unknown.join(", ")}. Use ${CAPABILITIES.join(", ")}.`);
	}

	return Object.fromEntries(CAPABILITIES.map((name) => [name, wanted.includes(name)]));
}

/**
 * Where grants are written when --file is not given: personal settings for
 * Claude Code, the user config for Codex.
 *
 * @param {string} host
 * @param {string} repoDir
 * @param {string} homeDir
 * @returns {string}
 */
function defaultGrantFile(host, repoDir, homeDir) {
	return host === "codex" ? join(homeDir, ".codex", "config.toml") : join(repoDir, ".claude", "settings.local.json");
}

/**
 * @param {string} host
 * @param {string} path
 * @returns {unknown} Parsed settings JSON for claude, file text for codex.
 */
function currentGrants(host, path) {
	if (!existsSync(path)) {
		return host === "codex" ? "" : {};
	}

	return host === "codex" ? readFileSync(path, "utf8") : JSON.parse(readFileSync(path, "utf8"));
}

/**
 * Picks the file that must carry the block for a host. Claude Code loads
 * CLAUDE.md and not AGENTS.md, so with no CLAUDE.md a thin one is created that
 * imports AGENTS.md (or holds the block itself when AGENTS.md is absent).
 * Codex loads AGENTS.md, so that file is used and created when missing.
 *
 * @param {string} host
 * @param {string} repoDir
 * @param {boolean} create Whether a missing entry file may be created.
 * @returns {string[]}
 */
function entrypointsFor(host, repoDir, create) {
	const existing = findEntrypoints(repoDir);
	const hasName = (/** @type {string} */ name) => existing.some((path) => path.toLowerCase().endsWith(`/${name}`));

	if (host === "codex") {
		return hasName("agents.md") ? existing.filter((path) => path.toLowerCase().endsWith("/agents.md")) : [join(repoDir, "AGENTS.md")];
	}

	if (hasName("claude.md") || !create) {
		return existing;
	}

	if (hasName("agents.md")) {
		writeFileSync(join(repoDir, "CLAUDE.md"), "@AGENTS.md\n");

		return findEntrypoints(repoDir);
	}

	return [join(repoDir, "CLAUDE.md")];
}

/**
 * @param {string} path
 * @param {string} text
 */
function writeCreatingDirs(path, text) {
	mkdirSync(dirname(path), { recursive: true });
	writeFileSync(path, text);
}

/**
 * The files the block goes into: an explicit --entry, the level's file from
 * levels.mjs, or (with no --level, or Claude Code at repo level) the original
 * CLAUDE.md/AGENTS.md choice.
 *
 * @param {string} command
 * @param {Record<string, string>} flags
 * @param {{host: string, repoDir: string, homeDir: string}} context
 * @returns {{paths: string[], exclude?: string, note?: string} | {unsupported: true, reason: string}}
 */
function blockTargets(command, flags, { host, repoDir, homeDir }) {
	if (flags.entry) {
		return { paths: [resolve(flags.entry)] };
	}

	const level = flags.level;

	if (level === undefined || (level === "repo" && (host === "claude" || host === "codex"))) {
		const paths = entrypointsFor(host, repoDir, command === "block-upsert");

		return { paths: paths.length > 0 ? paths : [join(repoDir, "AGENTS.md")] };
	}

	const target = instructionsTarget(host, level, { repoDir, homeDir, env: process.env });

	return "unsupported" in target ? target : { paths: [target.path], exclude: target.exclude, note: target.note };
}

/**
 * Splits markdown into `## Heading` sections keyed by heading text.
 *
 * @param {string} text
 * @returns {Record<string, string>}
 */
function sectionsOf(text) {
	/** @type {Record<string, string>} */
	const sections = {};
	const parts = text.split(/^## /m).slice(1);

	for (const part of parts) {
		const newline = part.indexOf("\n");
		const heading = (newline === -1 ? part : part.slice(0, newline)).trim();

		sections[heading] = newline === -1 ? "" : part.slice(newline + 1).trim();
	}

	return sections;
}

/**
 * @param {string} repoDir
 * @param {string} name
 * @returns {Record<string, string>}
 */
function existingSections(repoDir, name) {
	const file = resolveSharedFile(repoDir, /** @type {"GUIDELINES" | "DESIGN"} */ (name));

	return file.exists ? sectionsOf(readFileSync(file.path, "utf8")) : {};
}

/**
 * @param {string} command
 * @param {Record<string, string>} flags
 * @returns {unknown}
 */
function run(command, flags) {
	const repoDir = resolve(flags.repo ?? process.cwd());
	const homeDir = resolve(flags.home ?? homedir());
	const configDir = flags["config-dir"] ?? resolveConfigDir(process.env, homeDir);
	const host = flags.host ?? "claude";
	const level = flags.level;
	const places = { repoDir, homeDir, env: process.env };
	const manifestDir = level === "global" ? configDir : repoDir;

	switch (command) {
		case "status": {
			const manifest = readManifest(repoDir);

			return {
				repo: repoDir,
				configDir,
				manifest,
				entrypoints: findEntrypoints(repoDir),
				files: {
					guidelines: resolveSharedFile(repoDir, "GUIDELINES"),
					design: resolveSharedFile(repoDir, "DESIGN"),
				},
				servers: { claude: detectServerNames("claude", repoDir, homeDir), codex: detectServerNames("codex", repoDir, homeDir) },
				preferences: readPreferences(configDir, repoDir),
				globalManifest: readManifest(configDir),
				blocks: Object.fromEntries(HOSTS.map((name) => [name, blockPresence(name, places)])),
			};
		}

		case "decide": {
			const key = need(flags, "key");
			const isNew = !existsSync(join(manifestDir, "artfct.json"));
			const before = readManifest(manifestDir);
			const after = recordDecision(before, key, { status: need(flags, "status"), at: new Date().toISOString() });

			if (after !== before) {
				mkdirSync(manifestDir, { recursive: true });
				writeManifest(manifestDir, after);
			}

			const excluded = level === "user" && isNew && after !== before ? ensureLocalExclude(repoDir, "artfct.json") : null;

			return { key, level: level ?? "repo", changed: after !== before, resolved: isResolved(after, key), excluded };
		}

		case "block-upsert":
		case "block-remove": {
			const targets = blockTargets(command, flags, { host, repoDir, homeDir });

			if ("unsupported" in targets) {
				return { host, level, ...targets, files: [] };
			}

			const body = readFileSync(flags.body ?? (level === "global" ? GLOBAL_BLOCK : DEFAULT_BLOCK), "utf8");
			const files = targets.paths.map((path) => {
				const text = existsSync(path) ? readFileSync(path, "utf8") : "";
				const result = command === "block-upsert" ? upsertBlock(text, body) : removeBlock(text);

				if (result.changed) {
					writeCreatingDirs(path, result.text);
				}

				return { path, changed: result.changed };
			});
			const excluded = targets.exclude && command === "block-upsert" ? ensureLocalExclude(repoDir, targets.exclude) : null;

			return { host, level: level ?? "repo", files, note: targets.note, excluded };
		}

		case "files-install":
			if (level !== undefined && level !== "repo") {
				return { unsupported: true, reason: "GUIDELINES.md and DESIGN.md belong to a repo. Run this at the repo level." };
			}

			return installTemplate(repoDir, /** @type {"GUIDELINES" | "DESIGN"} */ (need(flags, "name")));

		case "perms-detect":
			if (host !== "claude" && host !== "codex") {
				return { servers: [], undetectable: true, reason: `artfct-init does not read ${host}'s server config yet.` };
			}

			return detectServerNames(host, repoDir, homeDir);

		case "perms-plan":
		case "perms-apply":
		case "perms-verify": {
			if (flags.file === undefined && level !== undefined) {
				const target = permissionsTarget(host, level, places);

				if ("unsupported" in target) {
					return { host, level, ...target };
				}

				flags.file = target.path;
				flags.note = target.note ?? "";
			}

			const file = flags.file ?? defaultGrantFile(host, repoDir, homeDir);
			const note = flags.note || undefined;
			const wanted = buildProfile(need(flags, "server"), capabilitiesFrom(flags.capabilities ?? "read"));

			if (command === "perms-verify") {
				return { file, note, wanted, ...verifyGrants(host, file, wanted) };
			}

			const plan = planChanges(host, currentGrants(host, file), wanted);

			if (command === "perms-apply") {
				mkdirSync(dirname(file), { recursive: true });
				applyChanges(host, file, plan);
			}

			return { file, note, wanted, plan, applied: command === "perms-apply" };
		}

		case "prefs-read":
			return readPreferences(configDir, repoDir);

		case "prefs-write": {
			const path = flags.scope === "project" ? join(repoDir, "USER.local.md") : join(configDir, "USER.md");

			writePreference(path, need(flags, "key"), need(flags, "value"));

			return { path, excluded: flags.scope === "project" ? ensureLocalExclude(repoDir) : null };
		}

		case "prefs-exclude":
			return ensureLocalExclude(repoDir);

		case "prefs-resolve": {
			const layers = { ...readPreferences(configDir, repoDir), team: flags.team ? readJson(flags.team) : undefined };

			return resolvePreference(need(flags, "key"), {
				task: flags.task ? { [flags.key]: flags.task } : undefined,
				project: layers.project,
				global: layers.global,
				team: /** @type {Record<string, unknown> | undefined} */ (layers.team),
			});
		}

		case "import-parse":
			return parseProfile(readFileSync(need(flags, "text-file"), "utf8"));

		case "import-preview": {
			const parsed = parseProfile(readFileSync(need(flags, "text-file"), "utf8"));
			const existing = {
				preferences: readPreferences(configDir, repoDir).global,
				guidelinesSections: existingSections(repoDir, "GUIDELINES"),
				designSections: existingSections(repoDir, "DESIGN"),
			};

			return { warnings: parsed.warnings, ...previewImport(parsed, existing) };
		}

		case "import-apply": {
			applyImport(/** @type {any} */ (readJson(need(flags, "selection-file"))), repoDir, configDir, {
				artifactId: need(flags, "artifact-id"),
				version: need(flags, "version"),
			});

			return { applied: true, manifest: readManifest(repoDir).imports };
		}

		default:
			throw new Error(`Unknown command "${command}".`);
	}
}

try {
	const { command, flags } = parseArgs(process.argv.slice(2));

	process.stdout.write(`${JSON.stringify(run(command, flags), null, 2)}\n`);
} catch (error) {
	process.stdout.write(`${JSON.stringify({ error: error instanceof Error ? error.message : String(error) })}\n`);
	process.exitCode = 1;
}
