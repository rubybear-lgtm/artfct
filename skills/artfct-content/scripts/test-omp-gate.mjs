#!/usr/bin/env node
/**
 * Tests the publish gate in .omp/extensions/artfct-content.ts.
 *
 *   node skills/artfct-content/scripts/test-omp-gate.mjs
 *
 * Mirrors test-gate.mjs (the Pi original) with three differences: the
 * extension is bundled from .omp/extensions, the @oh-my-pi import is
 * stubbed, and `pi.zod` is backed by a minimal schema builder that records
 * shapes and validates the fields the gate relies on (optional/nullable
 * strings, string arrays of records, enums). The bundle is written into a
 * throwaway directory that reproduces the real layout (.omp/extensions
 * beside skills/), because the extension locates the linter relative to
 * its own file. One case deliberately builds a layout with no linter, to
 * prove the gate fails closed rather than open.
 */

import { execFile } from "node:child_process";
import { mkdir, mkdtemp, readFile, rm, symlink } from "node:fs/promises";
import { tmpdir } from "node:os";
import { dirname, join, resolve } from "node:path";
import { fileURLToPath, pathToFileURL } from "node:url";
import { promisify } from "node:util";

const run = promisify(execFile);
const HERE = dirname(fileURLToPath(import.meta.url));
const SKILL = resolve(HERE, "..");
const REPO = resolve(SKILL, "..", "..");
const EXTENSION = join(REPO, ".omp", "extensions", "artfct-content.ts");
const STUBS = join(HERE, "test-omp-gate-stubs");
const ESBUILD = join(REPO, "node_modules", ".bin", "esbuild");
const FIXTURE = join(SKILL, "assets", "example-feature-showcase.html");
const { __zod } = await import("./test-omp-gate-stubs/omp-agent.mjs");

/**
 * Bundle the extension into a throwaway tree laid out as <base>/.omp/extensions
 * beside <base>/skills, which is what the extension expects to find.
 */
async function build({ withLinter }) {
	const base = await mkdtemp(join(tmpdir(), "artfct-omp-gate-"));
	await mkdir(join(base, ".omp", "extensions"), { recursive: true });

	if (withLinter) {
		await symlink(join(REPO, "skills"), join(base, "skills"));
	} else {
		await mkdir(join(base, "skills"), { recursive: true });
	}

	const out = join(base, ".omp", "extensions", "artfct-content.mjs");
	await run(ESBUILD, [
		EXTENSION,
		"--bundle",
		"--format=esm",
		"--platform=node",
		"--outfile=" + out,
		"--alias:@oh-my-pi/pi-coding-agent=" + join(STUBS, "omp-agent.mjs"),
		"--log-level=warning",
	]);

	return { base, out };
}

function harness(extension) {
	const handlers = [];
	const tools = [];
	const notices = [];
	const pi = {
		zod: __zod,
		registerTool: (tool) => tools.push(tool),
		on: (event, handler) => {
			if (event === "tool_call") {
				handlers.push(handler);
			}
		},
		exec: async (cmd, args) => {
			try {
				const { stdout, stderr } = await run(cmd, args, { maxBuffer: 8 * 1024 * 1024 });

				return { stdout, stderr: stderr ?? "", code: 0 };
			} catch (error) {
				return { stdout: error.stdout ?? "", stderr: error.stderr ?? "", code: error.code ?? 1 };
			}
		},
	};
	const ctx = { hasUI: true, cwd: REPO, ui: { notify: (m, k) => notices.push([k, m]) } };
	extension.default(pi);

	return { handlers, tools, notices, ctx };
}

let failed = 0;
const check = (name, ok, detail) => {
	if (!ok) {
		failed++;
	}

	console.log((ok ? "  ok  " : "  FAIL ") + name + (ok || !detail ? "" : "  -- " + detail));
};

async function main() {
	const { base, out } = await build({ withLinter: true });
	const broken = await build({ withLinter: false });

	try {
		const extension = await import(pathToFileURL(out).href);
		const { handlers, tools, ctx } = harness(extension);

		check(
			"registers corpus_search and content_lint",
			tools.map((t) => t.name).join(",") === "corpus_search,content_lint",
			tools.map((t) => t.name).join(","),
		);
		check("registers one tool_call handler", handlers.length === 1, String(handlers.length));

		const gate = handlers[0];
		const call = (input, toolName) =>
			gate({ toolName: toolName ?? "mcp__artfct_staging__deploy_artifact", input }, ctx);

		const clean = await readFile(FIXTURE, "utf8");

		const cases = [
			["a clean content draft is allowed", { html: clean }, false],
			["an artifact without the marker passes untouched", { html: "<html><body>a dashboard</body></html>" }, false],
			["the explicit skip marker is honoured", { html: clean.replace("data-surface=", 'data-lint="skip" data-surface=') }, false],
			["a bundle entrypoint is linted", { files: [{ path: "index.html", content: clean }], entrypoint: "index.html" }, false],
			["a bundle without a matching entrypoint passes", { files: [{ path: "other.html", content: clean }], entrypoint: "index.html" }, false],
			["a non-goal claim is blocked", { html: clean.replace("It does not write", "Artfct is an artifact store. It does not write") }, true],
			["an unfilled token is blocked", { html: clean.replace("Every AI tool on your team starts from the same store", "{{TITLE}}") }, true],
			["a retired connection method is blocked", { html: clean.replace("Members install nothing.", "Members use the CLI.") }, true],
			["an unsupportable absolute is blocked", { html: clean.replace("Members install nothing.", "Artfct guarantees nothing is ever lost.") }, true],
			["a lab disparagement claim is blocked", { html: clean.replace("Members install nothing.", "Claude and Copilot give you no controls.") }, true],
		];

		for (const [name, input, expectBlock] of cases) {
			const result = await call(input);
			check(name, Boolean(result && result.block) === expectBlock, "expected " + (expectBlock ? "block" : "allow"));
		}

		check("unrelated tool calls are ignored", (await call({ command: "rm -rf /" }, "bash")) === undefined);

		// Fail-closed: this layout has no linter at all, so a draft that opted
		// into the gate must block. If the extension fails open, this fails.
		const brokenExtension = await import(pathToFileURL(broken.out).href);
		const brokenHarness = harness(brokenExtension);
		const brokenResult = await brokenHarness.handlers[0](
			{ toolName: "deploy_artifact", input: { html: clean } },
			brokenHarness.ctx,
		);
		check(
			"a missing linter blocks rather than passes",
			Boolean(brokenResult && brokenResult.block),
			"the gate allowed the publish with no linter present: " + JSON.stringify(brokenResult),
		);

		console.log(failed === 0 ? "gate: all cases passed" : "gate: " + failed + " failed");
	} finally {
		await rm(base, { recursive: true, force: true });
		await rm(broken.base, { recursive: true, force: true });
	}

	process.exit(failed === 0 ? 0 : 1);
}

main().catch((error) => {
	console.error(error);
	process.exit(1);
});
