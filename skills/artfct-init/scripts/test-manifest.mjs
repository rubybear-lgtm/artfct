#!/usr/bin/env node
/**
 * Tests artfct.json handling in skills/artfct-init/scripts/manifest.mjs.
 *
 *   node skills/artfct-init/scripts/test-manifest.mjs
 *
 * Every case runs against a fresh temp directory. Exits non-zero if any case
 * fails.
 */

import assert from "node:assert/strict";
import { existsSync } from "node:fs";
import { mkdir, mkdtemp, readFile, rm, writeFile } from "node:fs/promises";
import { tmpdir } from "node:os";
import { join } from "node:path";
import {
	isResolved,
	readManifest,
	recordDecision,
	writeManifest,
} from "./manifest.mjs";

const AT = "2026-10-18T09:00:00.000Z";
const LATER = "2026-10-18T10:00:00.000Z";

let failed = 0;

async function check(name, fn) {
	try {
		await fn();
		console.log("PASS " + name);
	} catch (error) {
		failed += 1;
		console.log("FAIL " + name);
		console.log("     " + String(error.message).split("\n").join("\n     "));
	}
}

async function main() {
	const dir = await mkdtemp(join(tmpdir(), "artfct-manifest-"));
	const freshDir = async () => {
		const sub = await mkdtemp(join(dir, "case-"));

		return sub;
	};

	try {
		await check("defaults when file is absent, and no file is created", async () => {
			const repo = await freshDir();
			const manifest = readManifest(repo);
			assert.deepEqual(manifest, {
				version: 1,
				decisions: {},
				grants: {},
				imports: [],
				files: { guidelines: null, design: null, user: null },
			});
			assert.equal(existsSync(join(repo, "artfct.json")), false);
		});

		await check("round trip preserves a full manifest", async () => {
			const repo = await freshDir();
			let manifest = readManifest(repo);
			manifest = recordDecision(manifest, "guidelines", { status: "configured", at: AT });
			manifest = recordDecision(manifest, "design", { status: "blocked", at: AT });
			manifest = {
				...manifest,
				grants: { "example.com": ["read", "write"] },
				imports: [{ artifactId: "abc", version: "2", sections: ["intro"], at: AT }],
				files: { guidelines: "GUIDELINES.md", design: null, user: "USER.md" },
			};
			writeManifest(repo, manifest);
			assert.deepEqual(readManifest(repo), manifest);
		});

		await check("written file uses known key order, sorted decisions, trailing newline", async () => {
			const repo = await freshDir();
			let manifest = readManifest(repo);
			manifest = recordDecision(manifest, "zeta", { status: "skipped", at: AT });
			manifest = recordDecision(manifest, "alpha", { status: "inherited", at: AT });
			writeManifest(repo, manifest);
			const text = await readFile(join(repo, "artfct.json"), "utf8");
			assert.ok(text.endsWith("}\n"), "file must end with a closing brace and newline");
			assert.ok(text.startsWith('{\n  "version": 1,\n  "decisions": {\n    "alpha"'));
			const order = ["version", "decisions", "grants", "imports", "files"].map((key) =>
				text.indexOf('"' + key + '"'),
			);
			assert.deepEqual(order, [...order].sort((a, b) => a - b));
			assert.ok(text.indexOf('"alpha"') < text.indexOf('"zeta"'));
		});

		await check("unknown top-level fields survive a round trip", async () => {
			const repo = await freshDir();
			const original = {
				zeta: { nested: true },
				version: 1,
				alpha: [1, 2, 3],
				decisions: {},
				grants: {},
				imports: [],
				files: { guidelines: null, design: null, user: null },
			};
			await writeFile(join(repo, "artfct.json"), JSON.stringify(original));
			const manifest = readManifest(repo);
			assert.deepEqual(manifest.alpha, [1, 2, 3]);
			assert.deepEqual(manifest.zeta, { nested: true });
			writeManifest(repo, manifest);
			const text = await readFile(join(repo, "artfct.json"), "utf8");
			assert.ok(text.indexOf('"files"') < text.indexOf('"alpha"'), "unknown keys must follow known keys");
			assert.ok(text.indexOf('"alpha"') < text.indexOf('"zeta"'), "unknown keys must be sorted");
			assert.deepEqual(JSON.parse(text).zeta, { nested: true });
		});

		await check("isResolved is true for configured, inherited and skipped", async () => {
			let manifest = readManifest(await freshDir());

			for (const status of ["configured", "inherited", "skipped"]) {
				manifest = recordDecision(manifest, status, { status, at: AT });
				assert.equal(isResolved(manifest, status), true, status);
			}
		});

		await check("isResolved is false for blocked and for an unknown key", async () => {
			let manifest = readManifest(await freshDir());
			manifest = recordDecision(manifest, "design", { status: "blocked", at: AT });
			assert.equal(isResolved(manifest, "design"), false);
			assert.equal(isResolved(manifest, "never-recorded"), false);
			assert.equal(isResolved(manifest, "constructor"), false);
		});

		await check("recordDecision with the same status keeps the old at", async () => {
			const manifest = recordDecision(readManifest(await freshDir()), "design", {
				status: "configured",
				at: AT,
			});
			const again = recordDecision(manifest, "design", { status: "configured", at: LATER });
			assert.equal(again.decisions.design.at, AT);
			assert.equal(again, manifest);
		});

		await check("recordDecision with a changed status updates at", async () => {
			const manifest = recordDecision(readManifest(await freshDir()), "design", {
				status: "blocked",
				at: AT,
			});
			const changed = recordDecision(manifest, "design", { status: "configured", at: LATER });
			assert.deepEqual(changed.decisions.design, { status: "configured", at: LATER });
		});

		await check("recordDecision throws on an invalid status", async () => {
			const manifest = readManifest(await freshDir());
			assert.throws(() => recordDecision(manifest, "design", { status: "pending", at: AT }), /status/);
			assert.throws(() => recordDecision(manifest, "design", { status: undefined, at: AT }), /status/);
		});

		await check("writing twice produces byte-identical output", async () => {
			const repo = await freshDir();
			let manifest = readManifest(repo);
			manifest = recordDecision(manifest, "user", { status: "configured", at: AT });
			writeManifest(repo, manifest);
			const first = await readFile(join(repo, "artfct.json"));
			writeManifest(repo, readManifest(repo));
			const second = await readFile(join(repo, "artfct.json"));
			assert.ok(first.equals(second), "second write changed the bytes");
		});

		await check("recordDecision does not mutate its input", async () => {
			const manifest = readManifest(await freshDir());
			const snapshot = structuredClone(manifest);
			const decisionsRef = manifest.decisions;
			const result = recordDecision(manifest, "design", { status: "skipped", at: AT });
			assert.deepEqual(manifest, snapshot);
			assert.equal(manifest.decisions, decisionsRef);
			assert.notEqual(result, manifest);
		});

		await check("invalid JSON throws an error containing the path", async () => {
			const repo = await freshDir();
			const path = join(repo, "artfct.json");
			await mkdir(repo, { recursive: true });
			await writeFile(path, "{ not json");
			assert.throws(
				() => readManifest(repo),
				(error) => error instanceof Error && error.message.includes(path),
			);
		});
	} finally {
		await rm(dir, { recursive: true, force: true });
	}

	console.log(failed === 0 ? "manifest: all cases passed" : "manifest: " + failed + " failed");
	process.exit(failed === 0 ? 0 : 1);
}

main().catch((error) => {
	console.error(error);
	process.exit(1);
});
