/**
 * Where artfct-init writes for each coding agent at each level (RUB-502).
 *
 * Levels follow Claude Code's scopes (owner decision 2026-10-21):
 *   global  - this person, every project on the machine
 *   user    - this person, this repo only, kept out of git
 *   repo    - committed to the repo, shared with the team
 *
 * Every path below was checked against the agent's own docs on 2026-10-21;
 * references/hosts.md lists the source for each row. A cell with no
 * documented file is `unsupported` with a reason, so the skill reports it as
 * blocked instead of writing to a guessed path.
 */

import { existsSync, readdirSync, readFileSync, statSync } from "node:fs";
import { join } from "node:path";
import { findEntrypoints } from "./block.mjs";

export const LEVELS = ["global", "user", "repo"];

export const HOSTS = ["claude", "codex", "cursor", "opencode", "vscode", "windsurf", "zed", "antigravity"];

/** Hosts with a tested permissions adapter in permissions.mjs. */
const PERMISSION_HOSTS = ["claude", "codex"];

/**
 * Zed reads only the first of these it finds at the worktree root
 * (zed.dev/docs/ai/rules), so the block goes into whichever one Zed uses.
 */
const ZED_RULE_FILES = [".rules", ".cursorrules", ".windsurfrules", ".clinerules", ".github/copilot-instructions.md", "AGENT.md", "AGENTS.md", "CLAUDE.md", "GEMINI.md"];

/**
 * @typedef {{path: string, exclude?: string, note?: string}} Target
 * @typedef {{unsupported: true, reason: string}} Unsupported
 * @typedef {{repoDir: string, homeDir: string, env: Record<string, string | undefined>}} Places
 */

/**
 * @param {string} value
 * @param {readonly string[]} allowed
 * @param {string} label
 */
function assertOneOf(value, allowed, label) {
	if (!allowed.includes(value)) {
		throw new Error(`Unknown ${label} "${value}". Use ${allowed.join(", ")}.`);
	}
}

/**
 * @param {string} reason
 * @returns {Unsupported}
 */
function unsupported(reason) {
	return { unsupported: true, reason };
}

/**
 * @param {string} path
 * @returns {boolean}
 */
function isFile(path) {
	return existsSync(path) && statSync(path).isFile();
}

/**
 * The repo's AGENTS.md with its on-disk capitalization, or a new one.
 *
 * @param {string} repoDir
 * @returns {string}
 */
function agentsFile(repoDir) {
	const existing = readdirSync(repoDir).find((name) => name.toLowerCase() === "agents.md" && isFile(join(repoDir, name)));

	return join(repoDir, existing ?? "AGENTS.md");
}

/**
 * @param {Places} places
 * @returns {string}
 */
function codexHome({ homeDir, env }) {
	return env.CODEX_HOME || join(homeDir, ".codex");
}

/**
 * The instructions file that carries the artfct block for one agent at one
 * level. For Claude Code at repo level the existing CLAUDE.md/AGENTS.md
 * logic in init.mjs still decides between them; this returns its default.
 *
 * @param {string} host
 * @param {string} level
 * @param {Places} places
 * @returns {Target | Unsupported}
 */
export function instructionsTarget(host, level, places) {
	assertOneOf(host, HOSTS, "host");
	assertOneOf(level, LEVELS, "level");

	const { repoDir, homeDir } = places;

	if (level === "global") {
		switch (host) {
			case "claude":
				return { path: join(homeDir, ".claude", "CLAUDE.md") };
			case "codex":
				return { path: join(codexHome(places), "AGENTS.md") };
			case "opencode":
				return { path: join(homeDir, ".config", "opencode", "AGENTS.md") };
			case "vscode":
				return { path: join(homeDir, ".copilot", "copilot-instructions.md") };
			case "windsurf":
				return { path: join(homeDir, ".codeium", "windsurf", "memories", "global_rules.md"), note: "Windsurf caps global rules at 6,000 characters." };
			case "antigravity":
				return { path: join(homeDir, ".gemini", "GEMINI.md") };
			case "cursor":
				return unsupported("Cursor keeps user rules in its settings (Customize, then Rules), not in a file. Paste the block there, or use the repo level.");
			case "zed":
				return unsupported("Zed keeps global rules in its Rules Library, not in a file. Add the block there, or use the repo level.");
		}
	}

	if (level === "user") {
		if (host === "claude") {
			return { path: join(repoDir, "CLAUDE.local.md"), exclude: "CLAUDE.local.md" };
		}

		return unsupported(`${host} has no private per-project instructions file. Use the global level for just you, or the repo level for the team.`);
	}

	switch (host) {
		case "claude":
			return { path: join(repoDir, "CLAUDE.md") };
		case "vscode":
			return { path: join(repoDir, ".github", "copilot-instructions.md") };
		case "zed": {
			const used = ZED_RULE_FILES.find((name) => isFile(join(repoDir, name)));

			return { path: join(repoDir, used ?? "AGENTS.md"), note: used ? `Zed reads only ${used} in this repo, so the block goes there.` : undefined };
		}
		default:
			return { path: agentsFile(repoDir) };
	}
}

/**
 * The settings file that carries tool permissions for one agent at one level.
 * Only agents with a tested adapter get a file; for the rest the person
 * approves tools in the agent itself.
 *
 * @param {string} host
 * @param {string} level
 * @param {Places} places
 * @returns {Target | Unsupported}
 */
export function permissionsTarget(host, level, places) {
	assertOneOf(host, HOSTS, "host");
	assertOneOf(level, LEVELS, "level");

	if (!PERMISSION_HOSTS.includes(host)) {
		return unsupported(`artfct-init cannot write ${host}'s tool approvals yet. Approve the artfct tools in ${host} the first time it asks.`);
	}

	const { repoDir, homeDir } = places;

	if (host === "claude") {
		return {
			global: { path: join(homeDir, ".claude", "settings.json"), note: "Applies to every project on this machine." },
			user: { path: join(repoDir, ".claude", "settings.local.json") },
			repo: { path: join(repoDir, ".claude", "settings.json"), note: "Committed: everyone who opens this repo gets these approvals." },
		}[level];
	}

	if (level === "global") {
		return { path: join(codexHome(places), "config.toml"), note: "Applies to every project on this machine." };
	}

	if (level === "repo") {
		return { path: join(repoDir, ".codex", "config.toml"), note: "Codex reads a project .codex/config.toml only in trusted projects." };
	}

	return unsupported("Codex has no private per-project settings file. Use the global level for just you.");
}

/**
 * Where the artfct block already sits for this agent, at every level, so a
 * global run plus a repo run never loads the instructions twice.
 *
 * @param {string} host
 * @param {Places} places
 * @returns {Record<string, {path: string, hasBlock: boolean} | Unsupported>}
 */
export function blockPresence(host, places) {
	/** @type {Record<string, {path: string, hasBlock: boolean} | Unsupported>} */
	const presence = {};

	for (const level of LEVELS) {
		const target = instructionsTarget(host, level, places);

		if ("unsupported" in target) {
			presence[level] = target;
			continue;
		}

		const paths = host === "claude" && level === "repo" ? [target.path, ...findEntrypoints(places.repoDir)] : [target.path];
		const found = paths.find((path) => isFile(path) && readFileSync(path, "utf8").includes("<artfct-guidelines>"));

		presence[level] = { path: found ?? target.path, hasBlock: found !== undefined };
	}

	return presence;
}
