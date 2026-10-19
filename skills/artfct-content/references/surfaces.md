# Surfaces

Four page families, borrowed from the corpus because the corpus is unusually disciplined about keeping them apart. Each has its own length, block order, FAQ weight and CTA. A page belongs to exactly one surface; when two seem to fit, the one matching the page's *job* wins, not the one matching its length.

## The four, at a glance

| | **Feature showcase** | **Comparison** | **Long-form explainer** | **Programmatic** |
|---|---|---|---|---|
| Job | Show what artfct does | Help someone choose | Teach a concept | Answer one narrow query |
| Route | `/blog/{slug}` | `/blog/{slug}` (own template later) | `/blog/{slug}` | own route per family |
| Words | 900–1,400 | 1,200–2,500 | 2,000–3,000 | 150–600 |
| H2s | 5–7 | 5–9 | 6–9 | 2–4 |
| FAQ | Usually | Always | Always | Never |
| TOC | Optional | Required | Required | No |
| Register | glean-formal | glean-formal | glean-formal | glean-formal, tightest |
| Corpus anchor | blog engine | `/compare` family | perspectives engine | prompt-library / connector family |

Lengths are ranges, not targets. The corpus medians that produced them: blog 1,071 words, perspectives 2,642, comparison pages 1,202–2,468, prompt-library 143.

---

## 1. Feature showcase

**Job:** a reader arrives with a task — "our agents keep rebuilding the same dashboard" — and leaves knowing artfct does that, and roughly how.

This is the surface with the most room to go wrong, because it is the one where inventing a capability is most tempting. Every claim resolves through `references/feature-inventory.md`.

**Block order**

1. **Answer-first lead** (40–70 words). State the outcome, not the feature name. If the page is "Shared knowledge across AI tools", the lead says what changes for the reader, then names the mechanism.
2. **Last updated** line, if the page will be maintained.
3. **TOC**, if the page runs past ~1,200 words or has more than six H2s.
4. **The problem, concretely** — one short section, with a named scenario. The corpus is consistent here: it establishes the failure before the fix, and it uses a specific situation rather than an abstraction.
5. **5–7 question-shaped H2s.** The middle is the mechanism: what it does, how it is set up, what it looks like in practice, what it does *not* do.
6. **Limits section.** Explicit. The corpus does this and it is the strongest trust signal on the page. Name the constraint — file size, what is out of scope, what requires an admin.
7. **FAQ**, 3–5 questions, question-shaped, answer-first.
8. **Author card.**
9. **CTA.**

**Rules**

- One capability per page. A page that explains publishing *and* search *and* governance is three pages.
- The "how" must be executable: if the page says connect a tool, the setup is a real step from `AiToolSetup`.
- Show the failure mode. "If the tool can only start local programs, it cannot connect" is more useful than another benefit.
- Never lead with the feature name. The corpus almost never does.

**Done when:** every capability sentence traces to the inventory; the page names at least one limit; the FAQ answers what the limits section raises.

---

## 2. Comparison

**Job:** someone is deciding, and has a specific alternative in mind.

**Block order**

1. **Answer-first lead** that states the distinction in one sentence, without disparaging the alternative.
2. **TOC** — required, these are the longest reference pages.
3. **What each is for** — two short, fair sections. The alternative gets its genuine strength stated plainly.
4. **Where they differ**, as H2s: the axes that matter. Not a feature-by-feature table of everything both products do.
5. **A comparison table** — 4–6 rows maximum. If a row exists only to make artfct win, cut it.
6. **Choose X if / choose Y if** — the corpus does this and it converts because it is honest.
7. **FAQ**, 4–6 questions, including at least one that a skeptic would ask.
8. **Author card.**
9. **CTA.**

**Rules**

- **Never claim the competitor lacks controls.** False since 2026 for the major labs, and it is the fastest way to lose a platform lead. The true claim is structural: no lab will index a competitor's output.
- Name the alternative's real advantage. A comparison with no concession reads as marketing and gets discounted.
- Compare *jobs*, not feature counts. "Codex does not rebuild the dashboard Claude already built" is the shape.
- Every competitor statement must be verifiable from `docs/market-analysis.md` or the competitor's own current docs. Do not compare against a remembered version of a product.
- Do not put a roadmap item in the table as though it shipped.

**Done when:** the alternative is described in terms its own team would accept; no unverifiable competitor claim; the "choose X if" section names a real reason to choose the alternative.

---

## 3. Long-form explainer

**Job:** someone is learning the concept, and artfct is the place that explained it well.

This is the perspectives engine: 2,642 median words, 53% carry an FAQ, and it never uses the word "blog".

**Block order**

1. **Definitional lead.** The corpus opens 27.4% of pages with a definitional copula — "X is …". It is the single most common shape and it is worth copying. Only 0.4% of the corpus opens with "in this article"; do not be the exception.
2. **Last updated** line.
3. **TOC** — required at this length.
4. **6–9 question-shaped H2s.** The reader's actual questions, in the order they arise.
5. **A worked example** in the middle. The corpus uses a concrete scenario, not a hypothetical.
6. **What this does not cover** — the boundary, stated once.
7. **FAQ**, 5–8 questions. This surface carries the heaviest FAQ load.
8. **Author card.**
9. **CTA.**

**Rules**

- Teach first. The product appears where it is genuinely the answer, not in every section.
- Define terms on first use. The reader arrived not knowing the concept.
- No product pitch before the first third is done.
- Cite. The corpus links a median of 7 times in body; a claim about how the industry works needs a source.
- Do not manufacture authority with invented statistics. If a number is not from a source you have, the sentence does not have a number.

**Done when:** the page is useful to someone who never adopts artfct; every factual claim is sourced or plainly reasoned; the product appears after the concept is established.

---

## 4. Programmatic long tail

**Job:** answer one narrow, high-intent query — a prompt pattern, a connector, an author, a template — at a scale no one writes by hand.

The corpus runs 562 of these, and the median is **143 words**. That is the whole point: a programmatic page is short, specific, and does not pretend to be an article.

**Block order**

1. **One-sentence answer.** No lead paragraph, no warm-up.
2. **What it is / how to use it** — 2–4 short H2s.
3. **A concrete example** — the actual prompt, config or step.
4. **3–5 related links** to siblings in the same family and to the parent page.
5. **CTA.**

**Rules**

- No FAQ, no TOC, no author card, no "Last updated" — these are template pages and the corpus keeps them lean.
- No unique prose where a parameter will do. The value is in the specificity of the parameter, not the writing.
- Never generate a programmatic page for a parameter with no search demand. Volume without intent is the failure mode.
- Each page must be genuinely distinct from its siblings. If two parameters produce the same page with one word swapped, the family is not ready.
- Route these behind their own template so they never inherit article styling or article metadata.

**Done when:** the page answers its query in the first sentence; it is meaningfully distinct from every sibling; it links to the parent and to at least three siblings.

---

## Register by surface

All four default to **glean-formal**. Only a landing-adjacent or launch page uses **artfct-terse**, and none of the four surfaces above is that. If a draft starts drifting terse, it has picked up the wrong influence — see `references/voice-register.md`.

## CTA

The corpus leans on "take the quiz" (254 occurrences across 71 pages). **That is Glean's funnel and does not transfer.** artfct's equivalents, in order of how often they are the right one:

1. Share a page as a link — the free tier, no account.
2. Sign up and connect a tool.
3. Read the docs at `/docs`.
4. Ask for a demo — rare, and only on enterprise-facing pages.

One CTA per page. The corpus repeats its CTA rather than stacking different ones.

## Where these pages live

Default to the existing pipeline: one Markdown file per page in `skills/artfct-content/drafts/`, whose front matter carries the metadata and whose body carries the article. `/blog`, `/blog/{slug}`, the sitemap and the structured data all read those files at request time, so there is no second copy to update and no route to add. That is what `tests/Feature/BlogPermalinksTest.php` and `BlogFeatureStoriesTest.php` cover.

A surface earns its own route and template only when it has a genuinely different shape — programmatic pages do, comparisons probably will. Until then, one pipeline, and the tests keep it honest. A new post is a new file, nowhere else.

