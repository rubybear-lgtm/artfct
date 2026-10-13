#!/usr/bin/env node
/**
 * Turn an artfct-content draft into the JSX body a blog post uses.
 *
 *   draft-to-post.mjs DRAFT --slug SLUG --tag TAG [--insert posts.tsx]
 *
 * The draft is the source of truth: it is what a human reviews and what the
 * publish gate lints. Generating the page from it means the published page
 * cannot drift from the reviewed draft. Re-running with the same slug
 * replaces the existing entry rather than adding a second one.
 *
 * The markup it understands is the fixed shell in
 * skills/artfct-content/assets/article-template.html. A draft that has been
 * restructured will produce a warning rather than silently dropping blocks.
 */

import { readFileSync, writeFileSync } from "node:fs";

/**
 * Internal links become Wayfinder calls rather than literal URLs, which is the
 * convention this repo enforces elsewhere and what keeps a staging link from
 * pointing at production. Anything not listed stays a literal href.
 */
const INTERNAL = [
	[/^https?:\/\/artfct\.dev\/blog\/([a-z0-9-]+)\/?$/, (m) => ({ href: "blogRoutes.show.url({ slug: '" + m[1] + "' })", import: "import blogRoutes from '@/routes/blog';" })],
	[/^https?:\/\/artfct\.dev\/blog\/?$/, () => ({ href: "blog().url", import: "import { blog } from '@/routes';" })],
	[/^https?:\/\/artfct\.dev\/docs$/, () => ({ href: "docs().url", import: "import { docs } from '@/routes';" })],
	[/^https?:\/\/artfct\.dev\/free$/, () => ({ href: "free().url", import: "import { free } from '@/routes';" })],
	[/^https?:\/\/artfct\.dev\/llms-full\.txt$/, () => ({ href: "llmsRoutes.full.url()", import: "import llmsRoutes from '@/routes/llms';" })],
];

const needed = new Set();

function link(href, text) {
	for (const [pattern, build] of INTERNAL) {
		const match = href.match(pattern);

		if (match) {
			const built = build(match);
			needed.add(built.import);

			return { open: "<A href={" + built.href + "}>", close: "</A>", text };
		}
	}

	return { open: '<A href="' + href + '">', close: "</A>", text };
}

// A private-use character, not a control character: eslint
// no-control-regex rejects the obvious u0000 sentinel.
const SENTINEL = "\uE000";

const INLINE = [
	[/<a\s+[^>]*href="([^"]*)"[^>]*>([\s\S]*?)<\/a>/g, (_m, href, text) => link(href, text)],
	[/<code>([\s\S]*?)<\/code>/g, (_m, text) => ({ open: "<Mono>", close: "</Mono>", text })],
	[/<strong>([\s\S]*?)<\/strong>/g, (_m, text) => ({ open: '<strong className="text-foreground">', close: "</strong>", text })],
	[/<em>([\s\S]*?)<\/em>/g, (_m, text) => ({ open: "<em>", close: "</em>", text })],
];

/** Escape only the text between tags: JSX reads braces and angle brackets as code. */
function escapeText(text) {
	return text
		.replace(/&nbsp;/g, " ")
		.replace(/\s+/g, " ")
		.replace(/\{/g, "{'{'}")
		.replace(/\}/g, "{'}'}");
}

/**
 * Rewrite inline markup, then escape whatever text remains. Tags are pulled
 * out into placeholders first so the escaping pass cannot touch them.
 */
function inline(html) {
	let out = html;
	const kept = [];

	for (const [pattern, build] of INLINE) {
		out = out.replace(pattern, (_m, ...rest) => {
			const built = build(_m, ...rest.slice(0, -2));
			kept.push(built);

			return SENTINEL + (kept.length - 1) + SENTINEL;
		});
	}

	out = escapeText(out);

	// Any tag left at this point is markup the template does not define.
	const leftovers = out.match(/<\/?[a-zA-Z][^>]*>/g);

	if (leftovers) {
		throw new Error("unhandled markup in draft: " + [...new Set(leftovers)].join(", "));
	}

	return out.replace(new RegExp(SENTINEL + "(\\d+)" + SENTINEL, "g"), (_m, i) => {
		const part = kept[Number(i)];

		return part.open + escapeText(part.text) + part.close;
	}).trim();
}

const PARA = /<p>([\s\S]*?)<\/p>/g;
const ITEM = /<li>([\s\S]*?)<\/li>/g;
const LIST_CLASS =
	'className="mb-5 list-disc space-y-2 pl-6 leading-[1.75] text-muted-foreground"';

function list(block) {
	const items = [...block.matchAll(ITEM)].map((m) => "                        <li>" + inline(m[1]) + "</li>");

	if (items.length === 0) {
		return [];
	}

	return [
		"                <ul",
		"                    " + LIST_CLASS,
		"                >",
		...items,
		"                </ul>,",
	];
}

function paragraphs(block, indent) {
	return [...block.matchAll(PARA)].map((m) => indent + "<P>" + inline(m[1]) + "</P>,");
}

function section(block) {
	const heading = block.match(/<h2>([\s\S]*?)<\/h2>/);
	const out = [];

	if (heading) {
		out.push("                <H2>" + inline(heading[1]) + "</H2>,");
	}

	out.push(...paragraphs(block, "                "), ...list(block));

	return out;
}

function faq(block) {
	const out = ['                <H2>Frequently asked questions</H2>,'];
	const items = [...block.matchAll(/<details class="q">\s*<summary>([\s\S]*?)<\/summary>\s*<p>([\s\S]*?)<\/p>\s*<\/details>/g)];

	if (items.length === 0) {
		throw new Error("the FAQ section has no <details class=\"q\"> items");
	}

	for (const m of items) {
		out.push("                <FaqItem question=\"" + inline(m[1]).replace(/"/g, "&quot;") + "\">");
		out.push("                    " + inline(m[2]));
		out.push("                </FaqItem>,");
	}

	return out;
}

function block(html, cls) {
	const m = html.match(new RegExp("<section class=\"" + cls + "\"[^>]*>([\\s\\S]*?)<\\/section>"));

	if (!m) {
		throw new Error("no <section class=\"" + cls + "\"> in the draft");
	}

	return m[1];
}

/** Add any route import the generated body needs, after the last existing one. */
function ensureImports(source, imports) {
	const modulePath = (line) => line.split("'")[1] ?? line;
const missing = imports
	.filter((line) => !source.includes(line))
	.sort((a, b) => modulePath(a).localeCompare(modulePath(b)));

	if (missing.length === 0) {
		return source;
	}

	const lines = source.split("\n");
	let last = -1;
	lines.forEach((line, i) => {
		if (/^import\b.*;$/.test(line)) {
			last = i;
		}
	});
	lines.splice(last + 1, 0, ...missing);

	return lines.join("\n");
}

function convert(draft, { slug, tag }) {
	needed.clear();
	const title = draft.match(/<h1>([\s\S]*?)<\/h1>/);
	const description = draft.match(/<meta name="description" content="([^"]*)"/);
	const lead = draft.match(/<p class="lead">([\s\S]*?)<\/p>/);
	const updated = draft.match(/Last updated\s+(\d{4}-\d{2}-\d{2})/);

	for (const [name, m] of [["h1", title], ["meta description", description], ["lead", lead], ["Last updated", updated]]) {
		if (!m) {
			throw new Error("the draft has no " + name);
		}
	}

	const sections = [...draft.matchAll(/<section class="s"[^>]*>([\s\S]*?)<\/section>/g)];

	if (sections.length === 0) {
		throw new Error("the draft has no <section class=\"s\"> blocks");
	}

	const body = [
		"            <>",
		"                <P>" + inline(lead[1]) + "</P>,",
		...sections.flatMap((m) => section(m[1])),
		"                <H2>What this does not do</H2>,",
		...list(block(draft, "limits")),
		...faq(block(draft, "faq")),
		"            </>",
	];

	const entry = [
		"    {",
		"        slug: '" + slug + "',",
		"        date: '" + updated[1] + "',",
		"        title: '" + inline(title[1]).replace(/'/g, "\\'") + "',",
		"        tag: '" + tag + "',",
		"        description:",
		"            '" + inline(description[1]).replace(/'/g, "\\'") + "',",
		"        body: (",
		...body,
		"        ),",
		"    },",
	];

	return entry.join("\n");
}

export { ensureImports, needed };

function insert(entry, slug, path) {
	const source = readFileSync(path, "utf8");
	// The array is spelled POSTS_UNSORTED in posts.tsx because the export is a
	// date-sorted copy. Accept either spelling so the literal can be renamed
	// without silently breaking the pipeline.
	const marker = ["const POSTS_UNSORTED: Post[] = [\n", "export const POSTS: Post[] = [\n"].find(
		(candidate) => source.includes(candidate),
	);

	if (marker === undefined) {
		throw new Error("could not find the POSTS array in " + path);
	}

	const at = source.indexOf(marker);

	const existing = new RegExp(
		"    \\{\\n        slug: '" + slug + "',[\\s\\S]*?\\n    \\},\\n",
	);
	let next;

	if (existing.test(source)) {
		next = source.replace(existing, entry + "\n");
	} else {
		next = source.slice(0, at + marker.length) + entry + "\n" + source.slice(at + marker.length);
	}

	writeFileSync(path, ensureImports(next, [...needed]));

	return existing.test(source) ? "replaced" : "inserted";
}

function main() {
	const argv = process.argv.slice(2);
	const draftPath = argv.find((a) => !a.startsWith("--"));

	const flag = (name) => {
		const i = argv.indexOf("--" + name);

		return i === -1 ? undefined : argv[i + 1];
	};

	if (!draftPath) {
		console.error("usage: draft-to-post.mjs DRAFT --slug SLUG --tag TAG [--insert posts.tsx]");
		process.exit(2);
	}

	const slug = flag("slug");
	const tag = flag("tag");

	if (!slug || !tag) {
		console.error("--slug and --tag are required");
		process.exit(2);
	}

	const entry = convert(readFileSync(draftPath, "utf8"), { slug, tag });
	const target = flag("insert");

	if (target) {
		console.log(insert(entry, slug, target) + " " + slug + " in " + target);

		return;
	}

	console.log(entry);
}

main();
