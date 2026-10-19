#!/usr/bin/env node
/**
 * Host adapters for artfct MCP tool grants. Claude Code keeps grants as
 * `mcp__<server>__<tool>` entries in `permissions.allow` of a settings JSON
 * file. Codex keeps one `[mcp_servers.<server>.tools.<tool>]` table per
 * approved tool in config.toml. Used by the artfct-init skill.
 *
 * The TOML handling is a line-based reader plus an append-only writer, so
 * every byte outside the appended tables is left as it was.
 */

import { existsSync, mkdirSync, readFileSync, writeFileSync } from "node:fs";
import { dirname, join } from "node:path";

/**
 * @typedef {{server: string, tool: string}} Grant
 * @typedef {{add: string[], keep: string[], unsupported: string[]}} Plan
 * @typedef {{read?: boolean, publish?: boolean, organize?: boolean, comment?: boolean}} Capabilities
 */

/** Tools that are never granted, whatever the caller asks for. */
const NEVER_GRANTED_TOOL = "delete_artifact";

/** @type {Record<string, string[]>} */
const TOOLS_BY_CAPABILITY = {
	read: [
		"search_artifacts",
		"read_artifact",
		"get_artifact",
		"list_artifacts",
		"get_connection",
		"get_guidance",
		"get_usage",
		"list_collections",
		"list_artifact_comments",
	],
	publish: ["deploy_artifact"],
	organize: ["create_collection", "add_collection_artifact"],
	comment: ["add_artifact_comment", "resolve_artifact_comment"],
};

const CAPABILITY_ORDER = ["read", "publish", "organize", "comment"];
const CODEX_APPROVE = "approve";
const CLAUDE_PREFIX = "mcp__";
const SERVER_NAME_PATTERN = /^[A-Za-z0-9_-]+$/;
const TOOL_NAME_PATTERN = /^[A-Za-z0-9_]+$/;
const HEADER_PATTERN = /^\s*\[(?!\[)(.*?)\]\s*(?:#.*)?$/;
const ARRAY_HEADER_PATTERN = /^\s*\[\[(.*?)\]\]\s*(?:#.*)?$/;
const KEY_VALUE_PATTERN = /^\s*([A-Za-z0-9_-]+|"[^"]*"|'[^']*')\s*=\s*(.*)$/;
const DOTTED_KEY_PART = /\s*(?:"((?:[^"\\]|\\.)*)"|'([^']*)'|([A-Za-z0-9_-]+))\s*(\.|$)/y;

/**
 * @param {string} host
 * @returns {void}
 * @throws {Error} When the host is neither claude nor codex.
 */
function assertHost(host) {
	if (host !== "claude" && host !== "codex") {
		throw new Error(`Unknown host: ${host}`);
	}
}

/**
 * @param {unknown} value
 * @returns {boolean}
 */
function isPlainObject(value) {
	return value !== null && typeof value === "object" && !Array.isArray(value);
}

/**
 * @param {string} tool
 * @returns {boolean} True for tools that must never be granted.
 */
function isBlockedTool(tool) {
	return tool === NEVER_GRANTED_TOOL || /public/i.test(tool);
}

/**
 * Accepts `mcp__<server>__<tool>` (Claude Code form) or `<server>/<tool>` (Codex form).
 *
 * @param {unknown} entry
 * @returns {Grant | null} Null when the entry is not a well-formed grant.
 */
function parseGrant(entry) {
	if (typeof entry !== "string") {
		return null;
	}

	let server;
	let tool;

	if (entry.startsWith(CLAUDE_PREFIX)) {
		const split = entry.lastIndexOf("__");

		if (split <= CLAUDE_PREFIX.length) {
			return null;
		}

		server = entry.slice(CLAUDE_PREFIX.length, split);
		tool = entry.slice(split + 2);
	} else {
		const slash = entry.indexOf("/");

		if (slash === -1) {
			return null;
		}

		server = entry.slice(0, slash);
		tool = entry.slice(slash + 1);
	}

	if (!SERVER_NAME_PATTERN.test(server) || !TOOL_NAME_PATTERN.test(tool)) {
		return null;
	}

	return { server, tool };
}

/**
 * @param {string} host
 * @param {Grant} grant
 * @returns {string} The entry in the host's own form.
 */
function formatGrant(host, grant) {
	return host === "claude" ? `${CLAUDE_PREFIX}${grant.server}__${grant.tool}` : `${grant.server}/${grant.tool}`;
}

/**
 * Splits wanted entries into usable grants and entries that can never be written.
 *
 * @param {string[]} wanted
 * @returns {{grants: Grant[], unsupported: string[]}}
 */
function normalizeWanted(wanted) {
	/** @type {Grant[]} */
	const grants = [];
	/** @type {string[]} */
	const unsupported = [];
	const seen = new Set();

	for (const entry of wanted) {
		const grant = parseGrant(entry);

		if (grant === null || isBlockedTool(grant.tool)) {
			if (!unsupported.includes(entry)) {
				unsupported.push(entry);
			}

			continue;
		}

		const key = `${grant.server}/${grant.tool}`;

		if (!seen.has(key)) {
			seen.add(key);
			grants.push(grant);
		}
	}

	return { grants, unsupported };
}

/**
 * @param {string} path
 * @returns {unknown} Parsed JSON, or null when the file does not exist.
 * @throws {Error} When the file exists but is not valid JSON.
 */
function readJsonFile(path) {
	if (!existsSync(path)) {
		return null;
	}

	const text = readFileSync(path, "utf8");

	if (text.trim() === "") {
		return {};
	}

	try {
		return JSON.parse(text);
	} catch (error) {
		throw new Error(`Invalid JSON in ${path}: ${error.message}`);
	}
}

/**
 * @param {string} path
 * @returns {string} File text, or "" when the file does not exist.
 */
function readTextFile(path) {
	return existsSync(path) ? readFileSync(path, "utf8") : "";
}

/**
 * @param {unknown} settings
 * @returns {unknown[]} The raw permissions.allow array, or [] when absent.
 */
function claudeAllowArray(settings) {
	const allow = isPlainObject(settings) && isPlainObject(settings.permissions) ? settings.permissions.allow : undefined;

	return Array.isArray(allow) ? allow : [];
}

/**
 * @param {unknown} settings
 * @returns {Set<string>} String entries of permissions.allow.
 */
function claudeAllowSet(settings) {
	return new Set(claudeAllowArray(settings).filter((entry) => typeof entry === "string"));
}

/**
 * @param {string} value
 * @returns {string} The value of a TOML scalar, quotes removed, trailing comment dropped.
 */
function parseScalar(value) {
	const text = value.trim();
	const quoted = /^"((?:[^"\\]|\\.)*)"/.exec(text) ?? /^'([^']*)'/.exec(text);

	if (quoted) {
		return quoted[1];
	}

	return text.replace(/\s#.*$/, "").trim();
}

/**
 * Splits a dotted TOML table name such as `mcp_servers."artfct-prod".tools` into parts.
 *
 * @param {string} text
 * @returns {string[] | null} The key parts, or null when the name is not valid.
 */
function parseDottedKey(text) {
	/** @type {string[]} */
	const parts = [];
	let position = 0;

	for (;;) {
		DOTTED_KEY_PART.lastIndex = position;
		const match = DOTTED_KEY_PART.exec(text);

		if (!match) {
			return null;
		}

		parts.push(match[1] ?? match[2] ?? match[3]);
		position = DOTTED_KEY_PART.lastIndex;

		if (match[4] === "") {
			return parts;
		}
	}
}

/**
 * Reads a TOML file into tables. Each table records its header path and the raw text of its
 * key values. Multi-line strings and arrays of tables are not interpreted beyond their headers.
 *
 * @param {string} text
 * @returns {Array<{path: string[], values: Map<string, string>}>}
 */
function parseTables(text) {
	/** @type {Array<{path: string[], values: Map<string, string>}>} */
	const tables = [{ path: [], values: new Map() }];

	for (const line of text.split(/\r?\n/)) {
		const header = HEADER_PATTERN.exec(line) ?? ARRAY_HEADER_PATTERN.exec(line);

		if (header) {
			const path = parseDottedKey(header[1]);

			if (path !== null) {
				tables.push({ path, values: new Map() });
			}

			continue;
		}

		const pair = KEY_VALUE_PATTERN.exec(line);

		if (pair) {
			const key = pair[1].replace(/^["']|["']$/g, "");
			tables[tables.length - 1].values.set(key, pair[2]);
		}
	}

	return tables;
}

/**
 * @param {string} text Codex config.toml text.
 * @returns {Map<string, string | null>} Keyed by `<server>/<tool>`; the value is the approval_mode, or null when the table has none.
 */
function readCodexApprovals(text) {
	/** @type {Map<string, string | null>} */
	const approvals = new Map();

	for (const table of parseTables(text)) {
		const tablePath = table.path;

		if (tablePath.length === 4 && tablePath[0] === "mcp_servers" && tablePath[2] === "tools") {
			const raw = table.values.get("approval_mode");

			approvals.set(`${tablePath[1]}/${tablePath[3]}`, raw === undefined ? null : parseScalar(raw));
		}
	}

	return approvals;
}

/**
 * @param {string} text Codex config.toml text.
 * @returns {Set<string>} Keys of tools whose approval_mode is `approve`.
 */
function codexApprovedSet(text) {
	const approved = new Set();

	for (const [key, mode] of readCodexApprovals(text)) {
		if (mode === CODEX_APPROVE) {
			approved.add(key);
		}
	}

	return approved;
}

/**
 * @param {...unknown} values
 * @returns {boolean} True when the name or any value contains "artfct".
 */
function looksLikeArtfct(...values) {
	return values.some((value) => typeof value === "string" && value.toLowerCase().includes("artfct"));
}

/**
 * @param {string} repoDir
 * @param {string} homeDir
 * @returns {string[]} Names of artfct servers in .mcp.json and ~/.claude.json.
 */
function detectClaudeServers(repoDir, homeDir) {
	/** @type {unknown[]} */
	const serverMaps = [];
	const projectConfig = readJsonFile(join(repoDir, ".mcp.json"));

	if (isPlainObject(projectConfig)) {
		serverMaps.push(projectConfig.mcpServers);
	}

	const userConfig = readJsonFile(join(homeDir, ".claude.json"));

	if (isPlainObject(userConfig)) {
		serverMaps.push(userConfig.mcpServers);

		const projects = isPlainObject(userConfig.projects) ? userConfig.projects : {};

		if (isPlainObject(projects[repoDir])) {
			serverMaps.push(projects[repoDir].mcpServers);
		}
	}

	/** @type {string[]} */
	const found = [];

	for (const servers of serverMaps) {
		if (!isPlainObject(servers)) {
			continue;
		}

		for (const [name, config] of Object.entries(servers)) {
			const fields = isPlainObject(config) ? [config.url, config.command] : [];

			if (looksLikeArtfct(name, ...fields)) {
				found.push(name);
			}
		}
	}

	return found;
}

/**
 * @param {string} repoDir
 * @param {string} homeDir
 * @returns {string[]} Names of artfct servers in the home and repo Codex config files.
 */
function detectCodexServers(repoDir, homeDir) {
	/** @type {string[]} */
	const found = [];

	for (const directory of [homeDir, repoDir]) {
		const path = join(directory, ".codex", "config.toml");

		if (!existsSync(path)) {
			continue;
		}

		for (const table of parseTables(readFileSync(path, "utf8"))) {
			if (table.path.length !== 2 || table.path[0] !== "mcp_servers") {
				continue;
			}

			const url = table.values.has("url") ? parseScalar(table.values.get("url")) : null;
			const command = table.values.has("command") ? parseScalar(table.values.get("command")) : null;

			if (looksLikeArtfct(table.path[1], url, command)) {
				found.push(table.path[1]);
			}
		}
	}

	return found;
}

/**
 * Finds the artfct MCP servers configured for a host. Claude.ai connectors live in no file,
 * so for claude an empty result is reported as undetectable.
 *
 * @param {'claude' | 'codex'} host
 * @param {string} repoDir
 * @param {string} homeDir
 * @returns {{servers: string[], undetectable: boolean}}
 */
export function detectServerNames(host, repoDir, homeDir) {
	assertHost(host);

	const names = host === "claude" ? detectClaudeServers(repoDir, homeDir) : detectCodexServers(repoDir, homeDir);
	const servers = [...new Set(names)].sort();

	return { servers, undetectable: host === "claude" && servers.length === 0 };
}

/**
 * Lists the artfct tools a capability set needs, in Claude Code form. Delete and public
 * publishing are never returned. planChanges and verifyGrants accept this form for both hosts.
 *
 * @param {string} serverName Letters, digits, underscore and hyphen only.
 * @param {Capabilities} capabilities
 * @returns {string[]} Entries such as `mcp__artfct-prod__search_artifacts`.
 * @throws {Error} When the server name is not a plain identifier.
 */
export function buildProfile(serverName, capabilities) {
	if (typeof serverName !== "string" || !SERVER_NAME_PATTERN.test(serverName)) {
		throw new Error(`Invalid server name: ${serverName}`);
	}

	/** @type {string[]} */
	const entries = [];

	for (const capability of CAPABILITY_ORDER) {
		if (!capabilities?.[capability]) {
			continue;
		}

		for (const tool of TOOLS_BY_CAPABILITY[capability]) {
			if (!isBlockedTool(tool)) {
				entries.push(`${CLAUDE_PREFIX}${serverName}__${tool}`);
			}
		}
	}

	return entries;
}

/**
 * @param {Record<string, unknown> | string | undefined} current
 * @param {string[]} wanted
 * @returns {Plan}
 */
function planClaude(current, wanted) {
	const allowed = claudeAllowSet(current);
	const { grants, unsupported } = normalizeWanted(wanted);

	/** @type {Plan} */
	const plan = { add: [], keep: [], unsupported };

	for (const grant of grants) {
		const entry = formatGrant("claude", grant);

		if (allowed.has(entry)) {
			plan.keep.push(entry);
		} else {
			plan.add.push(entry);
		}
	}

	return plan;
}

/**
 * @param {string} text Codex config.toml text.
 * @param {string[]} wanted
 * @returns {Plan}
 */
function planCodex(text, wanted) {
	const approvals = readCodexApprovals(text);
	const { grants, unsupported } = normalizeWanted(wanted);

	/** @type {Plan} */
	const plan = { add: [], keep: [], unsupported };

	for (const grant of grants) {
		const entry = formatGrant("codex", grant);

		if (!approvals.has(entry)) {
			plan.add.push(entry);
		} else if (approvals.get(entry) === CODEX_APPROVE) {
			plan.keep.push(entry);
		} else {
			plan.unsupported.push(entry);
		}
	}

	return plan;
}

/**
 * Compares wanted grants with the host's current configuration without writing anything.
 * Codex tables that already exist with another approval_mode are reported as unsupported,
 * because they are not rewritten.
 *
 * @param {'claude' | 'codex'} host
 * @param {Record<string, unknown> | string | undefined} current Parsed settings JSON for claude; config.toml text for codex.
 * @param {string[]} wanted Entries from buildProfile, or `<server>/<tool>` strings.
 * @returns {Plan}
 */
export function planChanges(host, current, wanted) {
	assertHost(host);

	if (host === "claude") {
		return planClaude(current, wanted);
	}

	return planCodex(typeof current === "string" ? current : "", wanted);
}

/**
 * Writes the plan's additions. An empty plan writes nothing and never creates the file.
 * Claude settings keep every other field; Codex config only gains appended per-tool tables.
 *
 * @param {'claude' | 'codex'} host
 * @param {string} path Settings JSON for claude; config.toml for codex.
 * @param {Plan} plan
 * @returns {void}
 * @throws {Error} When the existing file has a shape this adapter will not edit.
 */
export function applyChanges(host, path, plan) {
	assertHost(host);

	if (host === "claude") {
		applyClaude(path, plan);
	} else {
		applyCodex(path, plan);
	}
}

/**
 * @param {string} path
 * @param {Plan} plan
 * @returns {void}
 */
function applyClaude(path, plan) {
	const { grants } = normalizeWanted(plan.add);
	const settings = readJsonFile(path) ?? {};

	if (!isPlainObject(settings)) {
		throw new Error(`Settings file ${path} is not a JSON object.`);
	}

	const existing = claudeAllowSet(settings);
	const missing = grants.map((grant) => formatGrant("claude", grant)).filter((entry) => !existing.has(entry));

	if (missing.length === 0) {
		return;
	}

	if (settings.permissions === undefined) {
		settings.permissions = {};
	}

	if (!isPlainObject(settings.permissions)) {
		throw new Error(`"permissions" in ${path} is not an object.`);
	}

	if (settings.permissions.allow !== undefined && !Array.isArray(settings.permissions.allow)) {
		throw new Error(`"permissions.allow" in ${path} is not an array.`);
	}

	settings.permissions.allow = [...claudeAllowArray(settings), ...missing];

	mkdirSync(dirname(path), { recursive: true });
	writeFileSync(path, `${JSON.stringify(settings, null, 2)}\n`, "utf8");
}

/**
 * @param {string} path
 * @param {Plan} plan
 * @returns {void}
 */
function applyCodex(path, plan) {
	const text = readTextFile(path);
	const approvals = readCodexApprovals(text);
	const { grants } = normalizeWanted(plan.add);
	const pending = grants.filter((grant) => !approvals.has(formatGrant("codex", grant)));

	if (pending.length === 0) {
		return;
	}

	const eol = text.includes("\r\n") ? "\r\n" : "\n";
	let head = text;

	if (head !== "" && !head.endsWith("\n")) {
		head += eol;
	}

	if (head !== "") {
		head += eol;
	}

	const blocks = pending
		.map((grant) =>
			[`[mcp_servers.${grant.server}.tools.${grant.tool}]`, `approval_mode = "${CODEX_APPROVE}"`, ""].join(eol),
		)
		.join(eol);

	mkdirSync(dirname(path), { recursive: true });
	writeFileSync(path, head + blocks, "utf8");
}

/**
 * Reports which wanted grants are effective in the host's current configuration.
 *
 * @param {'claude' | 'codex'} host
 * @param {string} path Settings JSON for claude; config.toml for codex.
 * @param {string[]} wanted
 * @returns {{granted: string[], missing: string[]}} Entries in the host's form. Entries that can never be granted are listed as missing.
 */
export function verifyGrants(host, path, wanted) {
	assertHost(host);

	const effective =
		host === "claude" ? claudeAllowSet(readJsonFile(path) ?? {}) : codexApprovedSet(readTextFile(path));
	const { grants, unsupported } = normalizeWanted(wanted);

	/** @type {string[]} */
	const granted = [];
	/** @type {string[]} */
	const missing = [];

	for (const grant of grants) {
		const entry = formatGrant(host, grant);

		if (effective.has(entry)) {
			granted.push(entry);
		} else {
			missing.push(entry);
		}
	}

	missing.push(...unsupported);

	return { granted, missing };
}
