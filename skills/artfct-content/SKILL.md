---
name: artfct-content
description: Writes artfct marketing, comparison, and documentation content in Glean's enterprise register. Use when writing or reviewing a blog post, feature showcase, comparison page, long-form explainer, or programmatic landing page for artfct.dev; when choosing a headline, page structure, or FAQ block; when matching artfct's voice to a specific register; or when grounding a claim about what artfct does.
---

# artfct content

Produces artfct content that reads like an enterprise vendor wrote it deliberately, not
like a landing page grew by accretion. The structural and voice model is glean.com,
measured across 1,682 pages rather than remembered — see `references/page-anatomy.md`
for the numbers and where they came from.

## The three non-negotiables

**Ground every capability claim.** artfct's real surface is enumerated in
`references/feature-inventory.md` and nowhere else. If a sentence needs a feature that
is not in that file, the sentence is wrong — cut it or rewrite it against what exists.
Invented capabilities are the single most expensive failure mode here, because they
survive review (they read plausibly) and die in front of a customer.

**Retrieve before you write.** `corpus_search` queries 62,161 passages from the mined
glean.com corpus. Use it to check how the reference register handles a specific move —
opening a comparison, handling a pricing objection, introducing a technical concept to a
non-technical reader. Do not paraphrase from memory; the corpus is the source.

**Lint before you publish.** `content_lint` enforces the measurable parts of the
anatomy. The publish gate blocks `deploy_artifact` while a draft fails, so a failing
lint is a stop, not a warning.

## Workflow

1. **Pick the surface.** Each has its own length, intent, and register weight — see
   `references/surfaces.md`. Getting this wrong is the most common structural error: a
   comparison page written as a feature showcase answers a question nobody asked.
2. **Search the corpus** for the move you are about to make. One query per structural
   decision, not one query per article.
3. **Draft against the anatomy**, starting from `assets/article-template.html` so the
   gate applies and the block order is right. `references/page-anatomy.md` for structure,
   `references/headline-formulas.md` for the headline, `references/voice-register.md`
   for sentence-level voice.
4. **Lint.** Run `content_lint` on the draft. Fix and re-run until clean. The linter
   is the floor, not the review: it checks the measurable rules and cannot tell
   whether a sentence describes a capability the product actually has.
5. **Review with a clean agent.** Hand the draft to `artfct-content-reviewer`,
   which is deliberately not the author. It maps every capability noun to an
   inventory row and reports findings with file and line evidence. Expect real
   findings — on the first three pages it caught a provenance claim the hosted
   path does not support, an absolute the product falsifies, and a filter set the
   console does not have. Fix and re-run until it stops finding blockers.
6. **Publish the draft** with `deploy_artifact` at `team` sharing, so the page can be
   read in a browser before it becomes code. Give it a real `title` and a one-sentence
   `description`. Re-publishing after a fix takes `artifact_id` so the link stays
   stable and the review history stays on one artifact.
7. **Emit the page** only after the draft is approved. Generate the post body from the
   draft rather than retyping it:

   ```
   node skills/artfct-content/scripts/draft-to-post.mjs \
       skills/artfct-content/drafts/<slug>.html \
       --slug <slug> --tag <tag> --insert resources/js/lib/posts.tsx
   ```

   Then add the metadata row to `$blogPosts` in `routes/web.php` — the
   `description` must match the draft's meta description, because
   `tests/Feature/BlogFeatureStoriesTest.php` asserts both — and update that test's
   `workflowPosts()` map and its `has('posts', n)` count. Finally run
   `vendor/bin/pint --dirty`, `npx prettier --write resources/js/lib/posts.tsx`,
   `npx eslint --fix`, and the full suite.

Drafts live in `skills/artfct-content/drafts/`, one file per page. That directory is
the input to `draft-to-post.mjs`, which is why the published page and the reviewed
draft cannot disagree.

## Register is a parameter, not a personality

The default register is Glean-formal — the measured enterprise voice described in
`references/voice-register.md`. It is named and switchable so a surface can dial it
without a rewrite. artfct's own landing copy runs terser and more casual than the
default; when a page must sit beside the landing page rather than beside the blog, set
the register explicitly and say which one you used.

## Reference files

| File | Answers |
|---|---|
| `references/page-anatomy.md` | What order do the blocks go in, and how long is each? |
| `references/headline-formulas.md` | What shape should the headline take? |
| `references/voice-register.md` | What does a sentence sound like in this register? |
| `references/feature-inventory.md` | What does artfct actually do? |
| `references/surfaces.md` | Which of the four surfaces is this, and what does it owe the reader? |
| `assets/article-template.html` | The draft shell to fill in before publishing. |
| `assets/example-feature-showcase.html` | A filled draft that passes the linter — the shape to aim for. |
| `drafts/` | One file per page, the input to `draft-to-post.mjs`. |

## The programmatic surface is generated, not written

The long tail does not use the article template. `/connect/{tool}` renders one page per
connectable AI tool from `App\Support\AiToolSetup` — the same source the docs page and
`/llms-full.txt` render from, so a setup step cannot drift between them. Adding a guide
adds a page, and `tests/Feature/ConnectPagesTest.php` fails if a guide has no page.

Programmatic pages carry no FAQ, no table of contents, no author card and no
"Last updated" line. Their shape is one sentence of answer, two to four short sections,
a concrete example, related links and a CTA. Do not generate one for a parameter with no
search demand, and do not let two parameters produce the same page with one word swapped.

## The publish gate is armed by the template

`deploy_artifact` is blocked when the draft fails the linter, but only for a draft
that carries a `data-surface` attribute on `<body>`. That attribute is what the
template sets, and it is what tells the gate "this is an artfct-content page". A
dashboard, a spec, or a plan has no marker and publishes untouched, because these
rules do not describe it.

The practical consequence: **a draft built from scratch instead of from the template
is not gated.** Use the template. If a page genuinely must publish while failing a
rule, `data-lint="skip"` on the body is the documented override — reach for it
deliberately, not to silence a rule you disagree with.

## Bundled scripts

| Script | Does |
|---|---|
| `scripts/build-corpus-index.py` | Rebuilds the corpus index. No arguments to rebuild, `--query "<fts5>"` to search from a shell, `--json` for structured output. |
| `scripts/lint-content.py` | The linter the gate calls. `lint-content.py DRAFT [--surface S] [--register R] [--json]`, exit 1 on any error. |
| `scripts/test_lint.py` | Tests the linter's rules, one case per rule. `python3 scripts/test_lint.py`. |
| `scripts/draft-to-post.mjs` | Turns a draft into the JSX a blog post uses, and splices it into `resources/js/lib/posts.tsx`. The draft stays the source of truth, so the published page cannot drift from the reviewed draft. |
| `scripts/test-gate.mjs` | Tests the publish gate end to end, including that it fails closed. `node scripts/test-gate.mjs`. |

The corpus text is third-party marketing copy and is deliberately kept outside this
repository, at `~/.pi/agent/artfct-content-corpus/`; only the reproducible indexer is
versioned.

The `artfct-content` Pi extension (at `.pi/extensions/artfct-content.ts`) is what
exposes `corpus_search` and `content_lint` and installs the gate. Without it, the
scripts still run from a shell.
