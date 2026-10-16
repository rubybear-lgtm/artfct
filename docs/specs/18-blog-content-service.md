# Spec 17 — Blog content as Markdown files read through a service

**Track:** content (outside the enterprise-transition tracks 0–16) · **Depends on:** `blog/seo-artifact-cluster` merged · **Blocks:** nothing

## Scope

**In.** Post bodies and their metadata move out of two compiled literals and into Markdown files with front matter, read and rendered by an application service at request time. The four hand-synced copies of that metadata collapse to one. The content pipeline in `skills/artfct-content/` emits Markdown instead of HTML, and `draft-to-post.mjs` is deleted.

**Out.** A CMS, a database table, an authoring UI, RSS, tag index pages, pagination, draft preview inside the app, and per-post images (see Deferred). This spec changes where content lives and how it reaches the page; it does not add product surface.

## The problem this fixes

A post's slug, title, description, date and tag exist in four places, and nothing keeps three of them honest:

| Copy | Location | Kept in sync by |
|---|---|---|
| Metadata rows | `routes/web.php:17` — 8 rows, read again at `routes/web.php:149`, `routes/web.php:153`, `routes/web.php:180` | hand-pasting, per `skills/artfct-content/SKILL.md:63` |
| Bodies plus the same five fields | `resources/js/lib/posts.tsx` — 2,583 lines in the working tree, 8 entries | `draft-to-post.mjs` |
| Metadata, again | `tests/Feature/BlogFeatureStoriesTest.php:12` — `blogPosts()`, 8 rows | hand-editing |
| Slug, title, one sentence | `tests/Browser/BlogArticleBrowserTest.php` dataset | hand-editing |

Three consequences are already real:

1. **The parity check runs in one direction.** `tests/Feature/BlogFeatureStoriesTest.php:185` walks the published drafts and asserts each has a row; it never asserts each row has a draft. `stop-ai-tools-contradicting-each-other` therefore sits in both literals with no file in `skills/artfct-content/drafts/` and nothing notices. It is also the only post using `BreakFigure` and `LoopFigure` (`resources/js/lib/posts.tsx:2445`, `:2527`), which the generator cannot express — most likely why it was never drafted.
2. **The documentation has drifted from the code.** `skills/artfct-content/SKILL.md:66` tells the next writer to update a `workflowPosts()` map that no longer exists.
3. **Every post body ships to every reader.** `resources/js/pages/blog.tsx:4` imports `POSTS` for descriptions alone, so all eight bodies are in the client bundle of the index page.

The SEO pass currently uncommitted on `blog/seo-artifact-cluster` is the same problem at scale: retitling seven posts touches the route literal, `posts.tsx`, both test fixtures and all 18 drafts. That branch should land first; converting the format underneath an in-flight copy change multiplies the conflict surface across 20+ files.

## Decisions implemented

The [decision record](../architecture-decisions.md) has no decision to cite: it scopes the enterprise transition, and this is the marketing site. The specification this implements is the repo's own standing invariant — *registries, matrices and runbooks are claim sets, and drift like prose* (`AGENTS.md`). Four copies of the same metadata with one one-directional test is that invariant failing.

## Design

### One directory, one file per page

Files stay in `skills/artfct-content/drafts/`, one per page, exactly where the drafts, the linter and the parity tests already look (`tests/Feature/BlogFeatureStoriesTest.php:159` globs that directory today). No rename: `skills/artfct-content/SKILL.md`, `.pi/extensions/artfct-content.ts` and the tests all reference the path, and a rename buys nothing but churn.

`published: true|false` in front matter replaces today's `data-published="false"` attribute. A held-back file is written, linted and reviewed but is absent from the index, the sitemap and its own permalink. That behaviour is already specified and tested; only its carrier changes.

Nothing excludes `skills/` from the deploy — `.gitignore` has no entry for it, and the app builds from the repo source — so the marketing site's content now depends on a whole-repo checkout. That dependency gets an assertion of its own rather than a warning path: an empty `/blog` behind a green suite is the exact failure this spec exists to prevent.

### Front matter

Flat `key: value` between `---` fences. **No new dependency:** `symfony/yaml` is present only transitively (`composer.lock` v8.1.0), and depending on a transitive package is how a `composer update` breaks the blog. A ~30-line parser reads the flat subset; nested YAML is not needed and does not get a parser.

| Key | Required | Notes |
|---|---|---|
| `slug` | yes | the permalink segment; must match `[a-z0-9-]+` |
| `title` | yes | the bare headline, matching today's `<h1>`; the renderer appends ` — artfct` for `<title>` and only there — the structured-data `headline` stays bare — which is what keeps `E12`'s 45–60 character band satisfiable |
| `description` | yes | meta description; `lint-content.py` holds it to 120–160 characters |
| `date` | yes | `YYYY-MM-DD`, the `datePublished` value |
| `tag` | yes | the index badge (`search`, `governance`, `workflows`, `explainers`, …) |
| `published` | yes | `true` or `false` |
| `surface` | yes | one of the four values in `skills/artfct-content/scripts/lint-content.py:25` |
| `register` | yes | `glean-formal` or `artfct-terse` |
| `target` | yes | the query the page answers; today's `data-target` |
| `lint` | no | `run` (default) or `skip`; the front-matter form of the gate's `data-lint="skip"` override, which is otherwise unreachable once the source is Markdown |

Unknown keys are an error naming the file, not a warning. A typo'd `descripton:` is the failure mode that otherwise ships silently, because the page still renders.

The three `data-*` markers become front matter because they are already the gate's contract: `.pi/extensions/artfct-content.ts:302` refuses to lint a page that does not carry `data-surface`, and `:306` honours `data-lint="skip"`. The standalone rendering emits them as attributes, so the gate keeps working on the rendered document — with the one addition above, since a draft needing the override has no HTML to carry it until it is rendered. The gate itself lives in `.pi/`, which `.gitignore:55` ignores: it is not a versioned artifact, and CI cannot exercise it.

### Body grammar

Plain CommonMark with tables, plus one reserved fence. No directive parser.

| Markdown | Renders as |
|---|---|
| first paragraph | the lead |
| `## Heading` | section heading |
| `### Heading` | subheading and FAQ question |
| `- item` | bulleted list |
| 1. item | ordered list |
| `**bold**`, `*italic*`, `` `code` `` | inline |
| `[text](href)` | link |
| `> text` | pull quote |
| ```` ```code ```` | code block |
| pipe table | table |
| ```` ```figure ```` + `loop` or `break` | a figure block |

Two sections are recognised by their heading text, which the template already uses: `## What this does not do` and `## Questions`. Under `## Questions`, each `###` becomes a `<details class="q">` in the standalone rendering, and on the site the section heading renders as **Frequently asked questions** — that string is hardcoded by the generator today (`draft-to-post.mjs:294`) and asserted for every post by `tests/Browser/BlogArticleBrowserTest.php:57`, so the renderer owes exactly that text. Heading-text matching rather than a fence is deliberate: a fence would mean a block-level directive parser for one styling difference, and the headings are part of the template the linter already enforces.

**The figure fence** is the one place the body is not pure prose:

````
```figure
loop
```
````

This rides on CommonMark's fenced block rather than a new block type: a custom renderer for `FencedCodeBlock` emits a marker when the info string is exactly `figure`, and delegates to the default renderer otherwise. The service splits the rendered fragment on a sentinel-wrapped marker into ordered blocks — the same technique `draft-to-post.mjs:51` already uses for inline markup, and for the same reason: a string that cannot occur in content.

The fence body is a figure name, and the names are a closed set:

| Name | Component |
|---|---|
| `break` | `BreakFigure` from `resources/js/components/contradicting-figures.tsx` |
| `loop` | `LoopFigure`, same module |

An unknown name is an error naming the value rather than a silently dropped block. The renderer is a fidelity contract here, and the DoD item that says no post is missing a block type it contains has no teeth if an unrecognised name renders as nothing.

### The service

`app/Services/Blog/BlogContent.php`, alongside `MarkdownRenderer`, with two renderings because there are two readers:

| Method | Returns | Read by |
|---|---|---|
| `all(): array` | published post metadata, newest first | `/blog`, the sitemap |
| `find(string $slug): ?Post` | one post, or null | `/blog/{slug}` |
| `blocks(BlogPost $post): array` | ordered blocks for the article | `blog-show` |
| `standalone(BlogPost $post): string` | the review document — shell, TOC, byline, `details.q` FAQ, embedded CSS, `data-surface` | the author, and `deploy_artifact` |

`standalone()` is the review copy that gets published for a browser read before the page becomes code, which `skills/artfct-content/SKILL.md:50` describes and which the linter and the gate both operate on. It keeps the draft shell's shape — the masthead, the header, the table of contents, the limits and FAQ sections, the byline and the embedded stylesheet — and three things about it differ from the hand-written drafts it replaces, none of which the linter reads: the kicker is the post's `tag` rather than the draft's freer label, the read time is computed from the word count rather than written by hand, and the TOC anchors are slugified headings rather than `#s1…#s8`. The CTA block and the footer are not carried over, because the site rendering never had them either.

The renderer emits these classes and nothing else. This table is the fidelity contract, and it is what the renderer test asserts — today's component classes, except the `h3` row, which is where the change below deliberately lands:

| Block | Class list |
|---|---|
| paragraph | `mb-5 leading-[1.75] text-muted-foreground` |
| h2 | `mt-12 mb-4 font-serif text-2xl leading-tight font-medium tracking-tight text-foreground` |
| h3 | `mt-8 mb-2 font-serif text-lg leading-snug font-medium text-foreground` — changed from today's `text-2xl`; see below |
| strong | `text-foreground` |
| code | `rounded bg-muted px-1.5 py-0.5 font-mono text-[0.85em] text-foreground` |
| pre | `mb-5 overflow-x-auto rounded border border-border bg-muted p-4 font-mono text-sm leading-relaxed text-foreground` |
| link | `font-semibold text-primary underline-offset-4 hover:underline`; external adds `target="_blank" rel="noreferrer"` |
| list | `mb-5 list-disc space-y-2 pl-6 leading-[1.75] text-muted-foreground` |
| ordered list | the same classes with `list-decimal` in place of `list-disc`; no current draft uses one, so the renderer test is the only thing asserting it |
| quote | `mb-5 border-l-2 border-border pl-4 leading-[1.75] text-muted-foreground italic` |
| table | the `Table` component's wrapper, `thead`, `tbody`, cell classes |

**One intentional visual delta.** `H3` at `resources/js/lib/posts.tsx:38` is byte-identical to `H2` — `text-2xl` — while `FaqItem`'s inner heading at `:132` is `text-lg`. Markdown has one `###`, so one of them wins: the FAQ size, because the smaller heading is what an FAQ should look like and a subheading is the rarer case. Every `###` in every published post changes size, which is why the DoD asks for a browser pass per post; the alternative — a fence to select heading style — was rejected as parser surface for a styling difference.

### Failure behaviour

Two different failures, deliberately different:

- A malformed or incomplete file throws, naming the file: front matter that is missing a required key or carries an unknown one, a body with no `##` section, and a body with no lead paragraph. Content is reviewed code, and a post that renders half of itself on a live marketing site is worse than a failed request that a deploy test catches. This is the posture `draft-to-post.mjs:92` already takes with markup it cannot read, and it is enforced in `structure()` and `read()`.
- A duplicate slug across two files throws, naming both files, because the alternative is a duplicate index row, a duplicate sitemap entry, and a permalink that serves whichever file the glob returned first.
- A missing or empty drafts directory logs a warning and yields an empty collection, so a deploy that excludes `skills/` degrades to an empty index rather than a 500 on `/blog`. The warning is the fallback, not the guard: a test asserts the directory resolves and is non-empty, because a silently empty blog with a green suite is the failure this section exists to prevent.

### Routes and controllers

The three closures at `routes/web.php:142`, `routes/web.php:152` and `routes/web.php:170` become `BlogController@index`, `BlogController@show`, and a `SitemapController`. The reason is the service, not `route:cache`: all three need the same metadata, and so does `standalone()`. `app/Http/Controllers/DocsController.php:13` justifies its own conversion with `route:cache`, but that reason does not hold — Laravel 13 wraps closure actions (`Illuminate/Routing/Route.php`, `SerializableClosure::unsigned()`), this app already ships closure routes at `routes/web.php:76`, `:88` and `:101`, and the deploy runs `php artisan optimize` without complaint. The controllers are housekeeping and one place for metadata; they are not a deploy requirement.

Route names stay `blog` and `blog.show`, so the generated `@/routes/blog` helpers and the Wayfinder route check that `scripts/check-wayfinder-routes.mjs` performs are unaffected by any of this.

The sitemap keeps its existing shape, including the `/connect/{tool}` entries from `App\Support\AiToolSetup::forCurrentEnvironment()->guides()` at `routes/web.php:188`.

### The Inertia and SEO contracts

Props change as little as possible, because two things outside the page depend on them:

- `post.{title,description,date,tag,slug}` keeps its shape and names. `resources/views/app.blade.php:73` builds the `BlogPosting` JSON-LD from `post.date` and `post.description`, and `tests/Feature/BlogPermalinksTest.php` asserts the result.
- The article body arrives as `post.blocks` — an ordered list of `{type: "html", html}` and `{type: "figure", name}` — rendered by `blog-show` at `resources/js/pages/blog-show.tsx:85`, with figures mounting `BreakFigure`/`LoopFigure` from the block's name.
- The index passes `description` per post so `resources/js/pages/blog.tsx` stops reaching into the removed `POSTS` import for it.
- `resources/views/app.blade.php:127` reads `props.postSlug`, which nothing sets, and the fallback switch has no `blog-show` case, so articles fall to `@default` and emit the title alone. Fix or delete it here.

### Caching

None in the first cut. Eight files, one Markdown render each; measure before adding a cache, because a mtime-keyed cache with a stampede on the marketing page is a worse bug than the milliseconds it saves.

### What gets deleted

- The 8 bodies in `resources/js/lib/posts.tsx`, and the file itself; its two importers move to props.
- The `$blogPosts` literal at `routes/web.php:17`.
- The third and fourth copies of the metadata: `blogPosts()` at `tests/Feature/BlogFeatureStoriesTest.php:12`, and the 8-row dataset in `tests/Browser/BlogArticleBrowserTest.php`. Both exist to restate what the literals say; both shrink to whatever the file-derived tests need. `tests/Feature/BlogPermalinksTest.php` keeps one hardcoded specimen slug, which is not a copy of the metadata: it asserts the 404s, the `og:type` and the `BlogPosting` date for one named post.
- `skills/artfct-content/scripts/draft-to-post.mjs` (304 lines at `HEAD`, 467 in the working tree) and `assets/article-template.html` (replaced by a Markdown template).
- The `image` branch at `resources/js/pages/blog-show.tsx:63`: no post sets `image`, and it has been dead since it was written. Re-add it with a front-matter key when a post has an image.
- Every place `skills/artfct-content/SKILL.md` describes the old flow: the publish step at `:50`, the generator command at `:58`, the `$blogPosts` paste at `:63`, the long-dead `workflowPosts()` at `:66`, `drafts/` described as the generator's input at `:71` and `:93`, and the generator's row in the scripts table at `:127`. In its place: write the Markdown file, render it with `php artisan blog:render`, publish that for review, set `published: true`.

### The content pipeline

Markdown becomes the authoring format, so the skill changes with it:

- `assets/article-template.md` replaces `assets/article-template.html`; the front matter is the template's job to get right, since it is what the gate and the linter read.
- `scripts/lint-content.py` keeps parsing HTML — it runs against the *rendered* standalone document, not the Markdown. That keeps `HTMLParser` and the `data-surface` gate intact, and its HTML-only rules are already gated on `doc.is_html` (`lint-content.py:414`).
- `scripts/seo-audit.py` moves the same way, and the spec has to say so: it reads `.html` files off disk, `--all` globs `*.html` at `:154`, and it takes `<title>` and the meta description out of the raw file. Untouched, `--all` finds nothing after the conversion and exits 2.
- Because `E12`/`E13` read `<title>` and the meta description straight out of the rendered document, the renderer has to emit both from front matter: the bare `title` with ` — artfct` appended, and `description` verbatim. A renderer test asserts both, since the linter fails the whole run without them.
- `php artisan blog:render <file>` is new, with `--out <path>`, because the lint path takes a file argument (`lint-content.py`'s positional `DRAFT`) rather than stdin: it writes the standalone document so the linter, the reviewer and `deploy_artifact` all see the same bytes they see today.
- All 18 files convert. Seven publish, eleven stay held back. `stop-ai-tools-contradicting-each-other` gets a draft written from its JSX body, figures included, which also closes the missing-file gap.
- `references/*.md` and the three prompts update where they name the HTML template or the generator.

## Definition of done

- [ ] `GET /blog` lists 8 posts, newest first, each showing the title, description and tag from its own file's front matter.
- [ ] A new file with `published: true` appears at `/blog`, at `/blog/<slug>`, and in `/sitemap.xml` with no code change anywhere else.
- [ ] A file with `published: false` is absent from all three and 404s at its permalink.
- [ ] `GET /blog/<slug>` returns the article's headings, lists, table, code block, quote and FAQ, and no post is missing a block type its source contains.
- [ ] The FAQ section heading renders as **Frequently asked questions**, the string every browser case asserts.
- [ ] `stop-ai-tools-contradicting-each-other` renders both figures in a browser, in the position the file puts them.
- [ ] No post body appears in the built client bundle: after `npm run build`, a body sentence from a published post appears in no file under the build output.
- [ ] `resources/js/lib/posts.tsx` does not exist and `routes/web.php` contains no `$blogPosts`.
- [ ] `curl -s /blog/<slug>` shows `<meta property="og:type" content="article">` and a `BlogPosting` `datePublished` equal to the file's `date`.
- [ ] `php artisan blog:render skills/artfct-content/drafts/<file>.md` writes a document carrying `data-surface` and a `details.q` FAQ, and `deploy_artifact` on that output still arms the gate.
- [ ] That same document carries a 45–60 character `<title>` and a 120–160 character description, so `E12` and `E13` pass on it.
- [ ] The drafts directory resolves and is non-empty in a built app — asserted by a test, not observed through the warning path.
- [ ] A file with a missing `description` fails with the filename and key in the message; a `surface` value outside the four is refused the same way.
- [ ] Every published post has been read in a browser at least once, specifically for the `###` size change.
- [ ] `skills/artfct-content/SKILL.md` describes the flow that exists: no `workflowPosts()`, no `draft-to-post.mjs`, no `$blogPosts` paste step.

## Tests

**Pest — `tests/Feature/`**
- `blog_index_lists_every_published_file` — index count and order come from the files
- `held_back_file_absent_from_index_sitemap_and_permalink` *(negative)*
- `unknown_permalink_returns_404` *(negative)*
- `post_metadata_comes_from_its_own_front_matter` — title, description, date, tag per file, not from a fixture
- `renderer_emits_the_documented_classes_for_every_block_type` — the fidelity contract above, one case per row
- `figure_fence_yields_a_figure_block_in_position` — and that the ordering against prose blocks holds
- `unknown_fence_passes_through_as_a_code_block` *(negative)* — the delegation path is unbroken
- `unknown_figure_name_fails_naming_the_value` *(negative)*
- `faq_section_heading_renders_as_frequently_asked_questions` — the string `tests/Browser/BlogArticleBrowserTest.php:57` asserts
- `ordered_list_uses_the_list_classes`
- `drafts_directory_is_present_and_non_empty` — the guard behind the warning path
- `missing_front_matter_key_fails_naming_the_file` *(negative)*
- `unknown_front_matter_key_fails_naming_the_file` *(negative)*
- `absent_drafts_directory_yields_an_empty_index` *(negative)* — no 500
- `no_post_metadata_literal_remains` — `posts.tsx` gone, `$blogPosts` gone, so the migration cannot silently regress
- `a_new_published_file_is_enough_for_the_index_the_permalink_and_the_sitemap` — the headline claim, over a directory of its own so the real content directory is never written to
- `every_block_a_published_file_carries_reaches_the_rendered_article` — the per-file block parity the deleted generator test used to provide: h2, h3, list, table, code and quote are each asserted present when the file uses them
- `a_file_with_no_section_fails_instead_of_rendering_half_a_page` *(negative)*
- `a_file_with_no_lead_fails_instead_of_rendering_a_bare_heading` *(negative)*
- `two_files_declaring_one_slug_fail_naming_both` *(negative)*
- `an_empty_drafts_directory_is_reported_rather_than_served_silently` *(negative)*
- `the_faq_heading_renders_as_the_site_calls_it` and `a_figure_block_sits_where_the_file_puts_it` — figure order is asserted, not just presence
- `each_published_permalink_renders_its_own_front_matter_seo_meta_and_title` — including `assertSee(e($title).' — artfct')`, the served `<title>`, which is the string `E12` measures

**Pest — `tests/Unit/` and the review document (`tests/Feature/BlogReviewDocumentTest.php`)**
- `front_matter_parser_reads_the_flat_subset` — quotes, colons inside a value, CRLF
- `the_review_document_carries_what_the_linter_and_the_gate_read` — `data-surface`, the meta description, `<p class="lead">`, a `details.q` FAQ
- `every_draft_renders_a_title_and_a_description_inside_the_band_E12_and_E13_measure` — every file, decoded, so an out-of-band page cannot ship through the documented flow
- `the_lint_opt_out_reaches_the_document_as_the_gate_reads_it` — `lint: skip` becomes `data-lint="skip"`
- `the_review_document_renders_every_figure_as_a_placeholder_rather_than_dropping_it`
- `blog:render` — writes to `--out`, fails on an unknown draft, and `--all` writes one document per draft

**Pest 4 browser — `tests/Browser/`**
- one case per published post asserting a sentence from its body renders, which is the only check that sees a rendered article rather than a prop
- `held_back_draft_is_not_reachable`

**Sets that must keep passing unchanged** — `tests/Browser/SmokeAllPagesTest.php:38` routes `blog.show` coverage to `BlogPermalinksTest`, and `tests/Browser/PublicResponsiveBrowserTest.php` covers `/blog` for layout and JavaScript errors. One thing to fix while in here: that file's `/blog/developer-tools` path at `tests/Browser/PublicResponsiveBrowserTest.php:46` names a slug that exists in no draft and in neither literal, so it asserts layout on a 404 today. Point it at a real post or drop it.

**Red-check, required before the spec closes, and done:** with one class string in `BlogRenderer` broken, `BlogRendererTest` fails naming that class; with the review loop's `— artfct` suffix pushed past 60 characters, `every_draft_renders_a_title_and_a_description_inside_the_band` fails with the file's name and its length. Both were run and reverted. A green suite that has never been seen to fail on this migration proves nothing — the failure mode is a renderer that quietly drops blocks while every props assertion still passes.

## Rollback

Fully reversible, and reversible by revert alone. No stored data, no issued URL and no external contract changes: every permalink in the sitemap keeps its slug, so the rollback is `git revert` of the merge commit, which restores the two literals. The one-way edge is the draft format — the HTML drafts are deleted when the Markdown ones land, so their only remaining copy is git history; the conversion should be a single commit that both adds and deletes, so history is the whole recovery story. Reverting leaves the drafts back in HTML and `draft-to-post.mjs` restored.

## Deferred

- **Markdown content negotiation** — serving `text/markdown` on `Accept`, and folding posts into `/llms-full.txt`, which `app/Http/Controllers/LlmsTextController.php` already builds from other sources. Cheap once the Markdown is the source, and a different decision about what crawlers get.
- **Per-post images** — a front-matter key plus the branch deleted above. No post needs one today.
- **RSS, tag index pages, pagination** — all become trivial once metadata is queryable, none are needed for eight posts.
- **Draft preview inside the app** — the standalone rendering could be served at a signed URL instead of published as an artifact. The artifact route already does this job.
- **The publish gate is not a versioned artifact.** `.gitignore:55` ignores `/.pi`, so the extension that gates publishing exists only on a machine, and CI cannot exercise it. Making it reviewable is its own change; this spec only has to stop depending on an override the new format cannot reach, which is what the `lint` key does.
- **A cache**, if and when a measurement asks for one.
