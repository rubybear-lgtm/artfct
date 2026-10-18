#!/usr/bin/env node
/**
 * Tests the publish gate in .omp/extensions/artfct-content.ts.
 *
 *   node skills/artfct-content/scripts/test-omp-gate.mjs
 *
 * Mirrors test-gate.mjs (the Pi original) with three differences: the
 * extension is bundled from .omp/extensions, the @oh-my-pi import is
 * stubbed, and `pi.zod` is backed by a minimal schema builder that records
 * shapes and validates the fields the gate relies on (optional strings,
 * bounded numbers, string enums, arrays of file records). The bundle is written into a
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
import { gateCases } from "./test-gate-cases.mjs";

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
				return { stdout: error.stdout ?? "", stderr: error.stderr ?? "", code: typeof error.code === "number" ? error.code : 1 };
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
	let built;
	let broken;

	try {
		built = await build({ withLinter: true });
		broken = await build({ withLinter: false });
		const { out } = built;
		const extension = await import(pathToFileURL(out).href);
		const { handlers, tools, notices, ctx } = harness(extension);

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
			...gateCases(clean),
			["a null entrypoint still lints the bundle", { files: [{ path: "index.html", content: clean }], entrypoint: null }, false],
			["null sibling fields still inspect the html draft", { html: clean.replace("Members install nothing.", "Artfct guarantees nothing is ever lost."), files: null, entrypoint: null }, true],
		]

		for (const [name, input, expectBlock] of cases) {
			const result = await call(input);
			check(name, Boolean(result && result.block) === expectBlock, "expected " + (expectBlock ? "block" : "allow"));
		}

		check("malformed deploy input without extractable html passes untouched", (await call({ html: 123 })) === undefined);
		check("a file entry without content passes untouched", (await call({ files: [{ path: "index.html" }] })) === undefined);

		const corpusParams = tools[0].parameters;
		check("corpus_search rejects a non-string query", !corpusParams.safeParse({ query: 123 }).success);
		check("corpus_search rejects an out-of-range limit", !corpusParams.safeParse({ query: "x", limit: 99 }).success);
		check("corpus_search accepts a bounded limit", corpusParams.safeParse({ query: "x", limit: 5 }).success);

		const lintParams = tools[1].parameters;
		check("content_lint rejects an unknown surface", !lintParams.safeParse({ path: "d.md", surface: "nope" }).success);
		check("content_lint accepts a known surface", lintParams.safeParse({ path: "d.md", surface: "comparison" }).success);

		check("a passing draft notifies", notices.some((n) => n[1].includes("passes the gate")), JSON.stringify(notices));
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
		if (built) {
			await rm(built.base, { recursive: true, force: true });
		}

		if (broken) {
			await rm(broken.base, { recursive: true, force: true });
		}
	}

	process.exit(failed === 0 ? 0 : 1);
}

main().catch((error) => {
	console.error(error);
	process.exit(1);
});
