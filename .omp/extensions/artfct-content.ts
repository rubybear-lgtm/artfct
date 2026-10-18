/**
 * artfct-content — corpus retrieval, draft linting, and a publish gate.
 *
 * OMP port of `.pi/extensions/artfct-content.ts`. Same three behaviors:
 *   corpus_search  retrieve how the source corpus writes about a topic
 *   content_lint   validate a draft against the distilled rules
 *   tool_call      block a deploy_artifact whose draft fails the linter
 *
 * The two files share the lint/format/draft helpers by copy (not import):
 * OMP discovers extensions only under `.omp/extensions/`, and a shared
 * module outside that root would not load. Keep them in sync when the
 * gate rules change.
 */

import { existsSync } from "node:fs";
import { mkdtemp, rm, writeFile } from "node:fs/promises";
import { homedir, tmpdir } from "node:os";
import { dirname, join, resolve } from "node:path";
import { fileURLToPath } from "node:url";
import type { ExtensionAPI } from "@oh-my-pi/pi-coding-agent";

const HERE = dirname(fileURLToPath(import.meta.url));
const REPO = resolve(HERE, "..", "..");
const SCRIPTS = join(REPO, "skills", "artfct-content", "scripts");
const INDEX_SCRIPT = join(SCRIPTS, "build-corpus-index.py");
const LINT_SCRIPT = join(SCRIPTS, "lint-content.py");
// Shared with the Pi extension and build-corpus-index.py, which hardcodes
// ~/.pi/agent/artfct-content-corpus. Do not change here alone.
const CORPUS_DB = join(homedir(), ".pi", "agent", "artfct-content-corpus", "glean.db");
const CORPUS_JSONL = join(homedir(), ".pi", "agent", "artfct-content-corpus", "glean-corpus.jsonl");

const SURFACES = ["feature-showcase", "comparison", "explainer", "programmatic"] as const;
const REGISTERS = ["glean-formal", "artfct-terse"] as const;

type Finding = { rule: string; message: string; excerpt: string };
type LintResult = {
	surface: string;
	register: string;
	errors: Finding[];
	warnings: Finding[];
	stats: Record<string, number | boolean>;
};
type CorpusRow = {
	section: string;
	title: string;
	url: string;
	author: string | null;
	date: string | null;
	snippet: string;
};

async function lintFile(
	pi: ExtensionAPI,
	path: string,
	surface?: string,
	register?: string,
): Promise<LintResult | { failure: string }> {
	if (!existsSync(LINT_SCRIPT)) {
		return { failure: "linter not found at " + LINT_SCRIPT };
	}

	const args = [path, "--json"];

	if (surface) {
		args.push("--surface", surface);
	}

	if (register) {
		args.push("--register", register);
	}

	const result = await pi.exec("python3", [LINT_SCRIPT, ...args]);

	if (result.code === 2) {
		return { failure: result.stderr.trim() || "the draft could not be read" };
	}

	try {
		return JSON.parse(result.stdout) as LintResult;
	} catch {
		return { failure: "the linter produced no JSON: " + (result.stderr || result.stdout).slice(0, 300) };
	}
}

function formatFindings(result: LintResult): string {
	const lines: string[] = [];
	lines.push(result.surface + " / " + result.register);
	const s = result.stats;
	lines.push(
		"  " + s.words + " words · " + s.h2 + " H2 · " + s.faq + " FAQ · " +
		s.links + " links · mean " + s.mean_sentence + "w · " + s.read_minutes + " min",
	);

	for (const e of result.errors) {
		lines.push("  ERROR   " + e.rule + "  " + e.message + (e.excerpt ? "\n          > " + e.excerpt : ""));
	}

	for (const w of result.warnings) {
		lines.push("  warning " + w.rule + "  " + w.message + (w.excerpt ? "\n          > " + w.excerpt : ""));
	}

	if (result.errors.length === 0 && result.warnings.length === 0) {
		lines.push("  clean");
	}

	lines.push("  " + result.errors.length + " error(s), " + result.warnings.length + " warning(s)");

	return lines.join("\n");
}

function formatBlock(result: LintResult): string {
	const lines = [
		"artfct-content publish gate: the draft fails " + result.errors.length + " blocking rule(s).",
		"",
	];

	for (const e of result.errors) {
		lines.push("  " + e.rule + "  " + e.message);

		if (e.excerpt) {
			lines.push("      > " + e.excerpt);
		}
	}

	lines.push("");
	lines.push("Fix these, or re-run content_lint for the full picture. Rules live in");
	lines.push("skills/artfct-content/references/feature-inventory.md and page-anatomy.md.");

	return lines.join("\n");
}

/**
 * The HTML a deploy_artifact call would publish, whichever argument carries
 * it. Tolerant of nulls and extra entries: MCP callers commonly send null
 * for omitted fields, and a strict schema would fail open by rejecting the
 * whole input before the marker check runs.
 */
function draftFrom(input: unknown): { html: string; where: string } | undefined {
	if (!input || typeof input !== "object") {
		return undefined;
	}

	if ("html" in input && typeof input.html === "string") {
		return { html: input.html, where: "the html argument" };
	}

	if ("files" in input && Array.isArray(input.files)) {
		const entry = "entrypoint" in input && typeof input.entrypoint === "string" ? input.entrypoint : "index.html";

		for (const file of input.files) {
			if (
				file && typeof file === "object" && "path" in file && "content" in file &&
				file.path === entry && typeof file.content === "string"
			) {
				return { html: file.content, where: entry };
			}
		}
	}

	return undefined;
}

export default function (pi: ExtensionAPI) {
	const z = pi.zod;

	const CorpusSearchParams = z.object({
		query: z.string().describe(
			'Words to search for. Bare words are ANDed and stemmed; "quoted phrases" must be adjacent.',
		),
		limit: z.number().min(1).max(20).optional().describe("Pages to return, 1-20. Defaults to 6."),
		section: z.string().optional().describe("Restrict to one corpus section, for example blog or perspectives."),
	});

	const ContentLintParams = z.object({
		path: z.string().describe("Path to the draft, HTML or Markdown."),
		surface: z.enum(SURFACES).optional(),
		register: z.enum(REGISTERS).optional(),
	});

	pi.registerTool({
		name: "corpus_search",
		label: "Search the source corpus",
		description:
			"Search 62,000 passages of the source corpus (a competitor's published marketing copy, " +
			"1,682 pages) for how a topic is actually written: the lead shape, the heading ladder, the " +
			"phrasing. Use it before drafting to ground structure and register, and to check whether a " +
			"headline formula is real rather than remembered. This is third-party copy kept outside the " +
			"repository: read it for shape, never copy a sentence from it.",
		parameters: CorpusSearchParams,
		approval: "read",
		async execute(_toolCallId, params) {
			const query = params.query;
			const limit = params.limit ?? 6;
			const section = params.section;

			if (!existsSync(CORPUS_DB)) {
				const hint = existsSync(CORPUS_JSONL)
					? "The corpus is present but not indexed. Build it with:\n  python3 " + INDEX_SCRIPT
					: "The corpus is not on this machine. It lives at " + CORPUS_JSONL + " and is deliberately kept out of the repository.";

				throw new Error("corpus_search index missing at " + CORPUS_DB + "\n" + hint);
			}

			const args = ["--query", query, "--json", "--limit", String(limit)];

			if (section) {
				args.push("--section", section);
			}

			const result = await pi.exec("python3", [INDEX_SCRIPT, ...args]);

			if (result.code !== 0) {
				throw new Error("corpus_search failed: " + (result.stderr || result.stdout).slice(0, 400));
			}

			const rows = JSON.parse(result.stdout) as CorpusRow[];

			if (rows.length === 0) {
				return {
					content: [
						{
							type: "text",
							text: 'No passages matched "' + query + '". Try broader words, or drop the section filter.',
						},
					],
					details: { query, results: rows },
				};
			}

			const text = rows
				.map(
					(r) =>
						"[" + r.section + "] " + r.title + "\n  " + r.url + "  (" + (r.date ?? "undated") + ")" +
						(r.author ? "  by " + r.author : "") + "\n  " + r.snippet,
				)
				.join("\n\n");

			return {
				content: [{ type: "text", text }],
				details: { query, results: rows },
			};
		},
	});

	pi.registerTool({
		name: "content_lint",
		label: "Lint a draft",
		description:
			"Validate an artfct-content draft against the distilled rules: unsupportable capability " +
			"claims, retired connection methods, absolute claims, lab disparagement, marketing filler, " +
			"lead shape, heading count, FAQ weight and length for its surface. Run it on every draft " +
			"before publishing. Errors block; warnings do not.",
		parameters: ContentLintParams,
		approval: "read",
		async execute(_toolCallId, params, _signal, _onUpdate, ctx) {
			const result = await lintFile(pi, resolve(ctx.cwd, params.path), params.surface, params.register);

			if (!("errors" in result)) {
				throw new Error("content_lint could not run: " + result.failure);
			}

			return {
				content: [{ type: "text", text: formatFindings(result) }],
				details: result,
				isError: result.errors.length > 0,
			};
		},
	});

	pi.on("tool_call", async (event, ctx) => {
		if (!event.toolName.endsWith("deploy_artifact")) {
			return undefined;
		}

		const draft = draftFrom(event.input);

		if (!draft) {
			return undefined;
		}

		// No marker means this is not an artfct-content page. Dashboards, specs
		// and plans publish freely; the content rules do not describe them.
		if (!draft.html.includes("data-surface")) {
			return undefined;
		}

		if (draft.html.includes('data-lint="skip"')) {
			return undefined;
		}

		const dir = await mkdtemp(join(tmpdir(), "artfct-content-"));
		const file = join(dir, "draft.html");

		try {
			await writeFile(file, draft.html, "utf8");
			const result = await lintFile(pi, file);

			if (!("errors" in result)) {
				return {
					block: true,
					reason:
						"artfct-content publish gate could not lint this draft, and a draft carrying a " +
						"data-surface marker has opted into the gate, so it does not pass by default: " +
						result.failure,
				};
			}

			if (result.errors.length > 0) {
				return { block: true, reason: formatBlock(result) };
			}

			if (ctx.hasUI) {
				ctx.ui.notify(
					"artfct-content: " + draft.where + " passes the gate (" + result.warnings.length + " warning(s))",
					result.warnings.length > 0 ? "warning" : "info",
				);
			}

			return undefined;
		} finally {
			await rm(dir, { recursive: true, force: true });
		}
	});
}
