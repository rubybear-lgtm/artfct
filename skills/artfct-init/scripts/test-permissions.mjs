#!/usr/bin/env node
/**
 * Tests the host permission adapters in permissions.mjs.
 *
 *   node skills/artfct-init/scripts/test-permissions.mjs
 *
 * Prints one line per case and exits non-zero if any case fails. Every case
 * uses throwaway repo and home directories under the system temp directory,
 * never the real home.
 */

import assert from "node:assert/strict";
import { existsSync } from "node:fs";
import { mkdir, mkdtemp, readFile, rm, writeFile } from "node:fs/promises";
import { tmpdir } from "node:os";
import { dirname, join } from "node:path";
import { applyChanges, buildProfile, detectServerNames, planChanges, verifyGrants } from "./permissions.mjs";

const PROD = "artfct-prod";
const STAGING = "artfct-staging";
const READ_AND_PUBLISH = { read: true, publish: true, organize: false, comment: false };
const ALL_CAPABILITIES = { read: true, publish: true, organize: true, comment: true };

/**
 * @param {string} base
 * @param {string} name
 * @returns {Promise<{repoDir: string, homeDir: string}>} New empty repo and home directories.
 */
async function freshFixture(base, name) {
	const root = join(base, name);
	const repoDir = join(root, "repo");
	const homeDir = join(root, "home");

	await mkdir(repoDir, { recursive: true });
	await mkdir(homeDir, { recursive: true });

	return { repoDir, homeDir };
}

/**
 * @param {string} path
 * @param {string} text
 * @returns {Promise<void>} Writes the file, creating parent directories.
 */
async function writeText(path, text) {
	await mkdir(dirname(path), { recursive: true });
	await writeFile(path, text, "utf8");
}

/**
 * @param {string} repoDir
 * @returns {string} The Claude Code settings file used by default.
 */
function claudeSettingsPath(repoDir) {
	return join(repoDir, ".claude", "settings.local.json");
}

/**
 * @param {string} homeDir
 * @returns {string} The user-level Codex config file.
 */
function codexConfigPath(homeDir) {
	return join(homeDir, ".codex", "config.toml");
}

/**
 * @type {Array<{name: string, run: (base: string) => Promise<void>}>}
 */
const cases = [
	{
		name: "two artfct servers in different files are both detected",
		run: async (base) => {
			const { repoDir, homeDir } = await freshFixture(base, "two-servers");
			await writeText(
				join(repoDir, ".mcp.json"),
				JSON.stringify({
					mcpServers: {
						[PROD]: { type: "http", url: "https://prod.example.test/mcp" },
						github: { command: "gh-mcp" },
					},
				}),
			);
			await writeText(
				join(homeDir, ".claude.json"),
				JSON.stringify({
					projects: { [repoDir]: { mcpServers: { [STAGING]: { command: "/usr/local/bin/artfct-mcp" } } } },
				}),
			);

			assert.deepEqual(detectServerNames("claude", repoDir, homeDir), {
				servers: [PROD, STAGING],
				undetectable: false,
			});
		},
	},
	{
		name: "a server named differently is detected by its url",
		run: async (base) => {
			const { repoDir, homeDir } = await freshFixture(base, "url-match");
			await writeText(
				join(homeDir, ".claude.json"),
				JSON.stringify({ mcpServers: { knowledge: { url: "https://mcp.artfct.example.test/mcp" } } }),
			);

			assert.deepEqual(detectServerNames("claude", repoDir, homeDir), {
				servers: ["knowledge"],
				undetectable: false,
			});
		},
	},
	{
		name: "claude.ai connectors only report undetectable",
		run: async (base) => {
			const { repoDir, homeDir } = await freshFixture(base, "claude-ai-only");
			await writeText(
				join(homeDir, ".claude.json"),
				JSON.stringify({ mcpServers: { github: { command: "gh-mcp" } }, projects: {} }),
			);

			assert.deepEqual(detectServerNames("claude", repoDir, homeDir), { servers: [], undetectable: true });
		},
	},
	{
		name: "no config files at all reports undetectable for claude",
		run: async (base) => {
			const { repoDir, homeDir } = await freshFixture(base, "no-files");

			assert.deepEqual(detectServerNames("claude", repoDir, homeDir), { servers: [], undetectable: true });
		},
	},
	{
		name: "codex servers come from the home and repo config.toml files",
		run: async (base) => {
			const { repoDir, homeDir } = await freshFixture(base, "codex-detect");
			await writeText(
				codexConfigPath(homeDir),
				`[mcp_servers.${PROD}]\nurl = "https://prod.example.test/mcp"\n\n[mcp_servers.other]\nurl = "https://example.com/mcp"\n`,
			);
			await writeText(join(repoDir, ".codex", "config.toml"), `[mcp_servers.${STAGING}]\ncommand = "artfct-mcp"\n`);

			assert.deepEqual(detectServerNames("codex", repoDir, homeDir), {
				servers: [PROD, STAGING],
				undetectable: false,
			});
		},
	},
	{
		name: "buildProfile puts the chosen server name in every rule",
		run: async () => {
			const entries = buildProfile(STAGING, ALL_CAPABILITIES);

			assert.ok(entries.length > 0);
			assert.ok(entries.every((entry) => entry.startsWith(`mcp__${STAGING}__`)));
			assert.ok(entries.every((entry) => !entry.includes(PROD)));
			assert.ok(entries.includes(`mcp__${STAGING}__search_artifacts`));
		},
	},
	{
		name: "buildProfile maps capabilities to exactly their tools",
		run: async () => {
			assert.equal(buildProfile(PROD, { read: true }).length, 9);
			assert.deepEqual(buildProfile(PROD, { publish: true }), [`mcp__${PROD}__deploy_artifact`]);
			assert.deepEqual(buildProfile(PROD, {}), []);
		},
	},
	{
		name: "delete_artifact never appears, even when requested",
		run: async () => {
			const requested = { ...ALL_CAPABILITIES, delete: true, deleteArtifact: true, publicPublish: true };
			const entries = buildProfile(PROD, requested);

			assert.ok(!entries.some((entry) => entry.includes("delete_artifact")));
			assert.ok(!entries.some((entry) => /public/i.test(entry)));

			const plan = planChanges("claude", {}, [...entries, `mcp__${PROD}__delete_artifact`]);

			assert.ok(!plan.add.some((entry) => entry.includes("delete_artifact")));
			assert.deepEqual(plan.unsupported, [`mcp__${PROD}__delete_artifact`]);

			const codexPlan = planChanges("codex", "", [`${PROD}/delete_artifact`]);

			assert.deepEqual(codexPlan.add, []);
			assert.deepEqual(codexPlan.unsupported, [`${PROD}/delete_artifact`]);
		},
	},
	{
		name: "a public-publishing tool is never planned for either host",
		run: async () => {
			const claudePlan = planChanges("claude", {}, [`mcp__${PROD}__publish_public_artifact`]);

			assert.deepEqual(claudePlan.add, []);
			assert.deepEqual(claudePlan.unsupported, [`mcp__${PROD}__publish_public_artifact`]);

			const codexPlan = planChanges("codex", "", [`${PROD}/publish_public_artifact`]);

			assert.deepEqual(codexPlan.add, []);
			assert.deepEqual(codexPlan.unsupported, [`${PROD}/publish_public_artifact`]);
		},
	},
	{
		name: "planChanges keeps existing allow entries and adds only missing ones",
		run: async () => {
			const current = {
				permissions: { allow: ["Bash(npm test)", `mcp__${PROD}__search_artifacts`] },
			};
			const wanted = buildProfile(PROD, READ_AND_PUBLISH);
			const plan = planChanges("claude", current, wanted);

			assert.deepEqual(plan.keep, [`mcp__${PROD}__search_artifacts`]);
			assert.deepEqual(plan.add, wanted.filter((entry) => entry !== `mcp__${PROD}__search_artifacts`));
			assert.deepEqual(plan.unsupported, []);
		},
	},
	{
		name: "either entry form maps to the host's own form",
		run: async () => {
			const claudePlan = planChanges("claude", {}, [`${PROD}/search_artifacts`]);

			assert.deepEqual(claudePlan.add, [`mcp__${PROD}__search_artifacts`]);

			const codexPlan = planChanges("codex", "", [`mcp__${PROD}__search_artifacts`]);

			assert.deepEqual(codexPlan.add, [`${PROD}/search_artifacts`]);
		},
	},
	{
		name: "claude apply keeps existing allows and unrelated settings fields",
		run: async (base) => {
			const { repoDir } = await freshFixture(base, "claude-keep");
			const settingsPath = claudeSettingsPath(repoDir);
			const original = {
				env: { FEATURE_FLAG: "1" },
				permissions: { allow: ["Bash(npm test)"], deny: ["Read(./.env)"] },
				customField: { nested: [1, 2, 3] },
			};
			await writeText(settingsPath, `${JSON.stringify(original, null, 2)}\n`);

			const wanted = buildProfile(PROD, READ_AND_PUBLISH);
			applyChanges("claude", settingsPath, planChanges("claude", original, wanted));

			const written = JSON.parse(await readFile(settingsPath, "utf8"));

			assert.deepEqual(written.permissions.allow, ["Bash(npm test)", ...wanted]);
			assert.deepEqual(written.permissions.deny, ["Read(./.env)"]);
			assert.deepEqual(written.env, { FEATURE_FLAG: "1" });
			assert.deepEqual(written.customField, { nested: [1, 2, 3] });
		},
	},
	{
		name: "second claude apply adds nothing and leaves bytes identical",
		run: async (base) => {
			const { repoDir } = await freshFixture(base, "claude-rerun");
			const settingsPath = claudeSettingsPath(repoDir);
			const wanted = buildProfile(PROD, READ_AND_PUBLISH);

			applyChanges("claude", settingsPath, planChanges("claude", {}, wanted));

			const firstBytes = await readFile(settingsPath, "utf8");
			const current = JSON.parse(firstBytes);
			const plan = planChanges("claude", current, wanted);

			assert.deepEqual(plan.add, []);
			assert.deepEqual(plan.keep, wanted);

			applyChanges("claude", settingsPath, plan);

			assert.equal(await readFile(settingsPath, "utf8"), firstBytes);
		},
	},
	{
		name: "approving nothing writes no settings file",
		run: async (base) => {
			const { repoDir } = await freshFixture(base, "claude-nothing");
			const settingsPath = claudeSettingsPath(repoDir);

			applyChanges("claude", settingsPath, planChanges("claude", undefined, []));

			assert.equal(existsSync(settingsPath), false, "settings file must not be created");
		},
	},
	{
		name: "verifyGrants reports missing entries before and after apply",
		run: async (base) => {
			const { repoDir } = await freshFixture(base, "claude-verify");
			const settingsPath = claudeSettingsPath(repoDir);
			const wanted = buildProfile(PROD, READ_AND_PUBLISH);

			assert.deepEqual(verifyGrants("claude", settingsPath, wanted), { granted: [], missing: wanted });

			applyChanges("claude", settingsPath, planChanges("claude", {}, wanted));

			assert.deepEqual(verifyGrants("claude", settingsPath, wanted), { granted: wanted, missing: [] });

			const extra = `mcp__${PROD}__add_artifact_comment`;
			const result = verifyGrants("claude", settingsPath, [...wanted, extra]);

			assert.deepEqual(result.missing, [extra]);
			assert.equal(result.granted.length, wanted.length);
		},
	},
	{
		name: "codex TOML round trip keeps unrelated tables and comments byte-identical",
		run: async (base) => {
			const { homeDir } = await freshFixture(base, "codex-roundtrip");
			const configPath = codexConfigPath(homeDir);
			const original = [
				"# Personal Codex settings",
				'model = "gpt-5"  ',
				"",
				"[profiles.work]",
				"# keep this comment",
				'approval_policy = "on-request"',
				"",
				`[mcp_servers.${PROD}]`,
				"# server entry, do not touch",
				'url = "https://prod.example.test/mcp"',
				"",
			].join("\n");
			await writeText(configPath, original);

			const wanted = buildProfile(PROD, READ_AND_PUBLISH);
			applyChanges("codex", configPath, planChanges("codex", original, wanted));

			const written = await readFile(configPath, "utf8");

			assert.ok(written.startsWith(original), "original bytes must be an unchanged prefix");
			assert.ok(written.includes(`[mcp_servers.${PROD}.tools.search_artifacts]\napproval_mode = "approve"\n`));
			assert.deepEqual(verifyGrants("codex", configPath, wanted), {
				granted: wanted.map((entry) => `${PROD}/${entry.slice(`mcp__${PROD}__`.length)}`),
				missing: [],
			});
		},
	},
	{
		name: "codex never gets a server-wide default",
		run: async (base) => {
			const { homeDir } = await freshFixture(base, "codex-no-default");
			const configPath = codexConfigPath(homeDir);
			const original = `[mcp_servers.${PROD}]\nurl = "https://prod.example.test/mcp"\n`;
			await writeText(configPath, original);

			applyChanges("codex", configPath, planChanges("codex", original, buildProfile(PROD, ALL_CAPABILITIES)));

			const written = await readFile(configPath, "utf8");
			const appended = written.slice(original.length);

			assert.ok(!written.includes("default_tools_approval_mode"));
			assert.equal(written.split(`[mcp_servers.${PROD}]`).length, 2, "server table must not be duplicated");

			const headers = appended.match(/^\[.*\]$/gm) ?? [];

			assert.ok(headers.length > 0);
			assert.ok(headers.every((header) => header.includes(`mcp_servers.${PROD}.tools.`)));
		},
	},
	{
		name: "second codex apply adds nothing and leaves bytes identical",
		run: async (base) => {
			const { homeDir } = await freshFixture(base, "codex-rerun");
			const configPath = codexConfigPath(homeDir);
			const wanted = buildProfile(PROD, READ_AND_PUBLISH);

			applyChanges("codex", configPath, planChanges("codex", "", wanted));

			const firstBytes = await readFile(configPath, "utf8");
			const plan = planChanges("codex", firstBytes, wanted);

			assert.deepEqual(plan.add, []);

			applyChanges("codex", configPath, plan);

			assert.equal(await readFile(configPath, "utf8"), firstBytes);
		},
	},
	{
		name: "codex keeps existing approvals and never duplicates a table with another mode",
		run: async (base) => {
			const { homeDir } = await freshFixture(base, "codex-existing");
			const configPath = codexConfigPath(homeDir);
			const original = [
				`[mcp_servers.${PROD}.tools.search_artifacts]`,
				'approval_mode = "approve"',
				"",
				`[mcp_servers.${PROD}.tools.deploy_artifact]`,
				'approval_mode = "prompt"',
				"",
			].join("\n");
			await writeText(configPath, original);

			const wanted = [
				`${PROD}/search_artifacts`,
				`${PROD}/deploy_artifact`,
				`${PROD}/get_usage`,
			];
			const plan = planChanges("codex", original, wanted);

			assert.deepEqual(plan.keep, [`${PROD}/search_artifacts`]);
			assert.deepEqual(plan.unsupported, [`${PROD}/deploy_artifact`]);
			assert.deepEqual(plan.add, [`${PROD}/get_usage`]);

			applyChanges("codex", configPath, plan);

			const written = await readFile(configPath, "utf8");

			assert.ok(written.startsWith(original));
			assert.equal(written.split(`[mcp_servers.${PROD}.tools.deploy_artifact]`).length, 2);
			assert.deepEqual(verifyGrants("codex", configPath, wanted), {
				granted: [`${PROD}/search_artifacts`, `${PROD}/get_usage`],
				missing: [`${PROD}/deploy_artifact`],
			});
		},
	},
	{
		name: "codex CRLF file stays CRLF on append",
		run: async (base) => {
			const { homeDir } = await freshFixture(base, "codex-crlf");
			const configPath = codexConfigPath(homeDir);
			const original = 'model = "gpt-5"\r\n';
			await writeText(configPath, original);

			applyChanges("codex", configPath, planChanges("codex", original, [`${PROD}/search_artifacts`]));

			const written = await readFile(configPath, "utf8");

			assert.ok(written.startsWith(original));
			assert.doesNotMatch(written, /(^|[^\r])\n/);
		},
	},
	{
		name: "codex config is created in a new directory when absent",
		run: async (base) => {
			const { homeDir } = await freshFixture(base, "codex-fresh");
			const configPath = codexConfigPath(homeDir);

			applyChanges("codex", configPath, planChanges("codex", "", [`${PROD}/search_artifacts`]));

			assert.equal(
				await readFile(configPath, "utf8"),
				`[mcp_servers.${PROD}.tools.search_artifacts]\napproval_mode = "approve"\n`,
			);
		},
	},
	{
		name: "unknown host is rejected",
		run: async (base) => {
			const { repoDir, homeDir } = await freshFixture(base, "unknown-host");

			assert.throws(() => detectServerNames("cursor", repoDir, homeDir), /Unknown host/);
			assert.throws(() => planChanges("cursor", {}, []), /Unknown host/);
		},
	},
];

const base = await mkdtemp(join(tmpdir(), "artfct-permissions-"));
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
