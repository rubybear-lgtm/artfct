# Voice register

The reference corpus writes in a **formal enterprise register**. It is consistent enough
to specify, and specific enough that a paragraph can be checked against it.

## Register is a named parameter

Two registers are defined. Pick one per page and state which you used.

**`glean-formal`** — the default. The measured enterprise voice below.

**`artfct-terse`** — artfct's own landing copy. Shorter, second person, no abstraction.
Use when the page sits beside the landing page rather than beside the blog.
> Your AI makes things. Artfct
> Set it up once. Then just share.

These are not interchangeable within a page. A feature showcase written in
`glean-formal` and closed in `artfct-terse` reads as two authors.

## What `glean-formal` actually sounds like

Real leads from the corpus:

> Organizations across industries are racing to implement AI assistants, drawn by
> promises of enhanced productivity and streamlined operations. Yet many discover that
> the path from pilot to production is littered with unexpected obstacles that can
> derail even the most well-funded initiatives.

> While data forms the foundation, people determine whether an AI assistant thrives or
> withers.

> Perhaps no factor derails AI implementations faster than misaligned expectations.

## The markers

**Abstract plural subjects.** "Organizations", "Teams", "Enterprises". The second-person
*subject* is rare in this register and absent from the leads above.

**No contractions.** "do not", "cannot", "it is". This is the single easiest mechanical
check and it holds across the corpus. The linter enforces it as `W14`.

**Em-dash asides for the qualifier.** "the infrastructure requirements for enterprise
deployment — including computing power, storage, and ongoing maintenance — can escalate
quickly." The dashes carry the parenthetical that would otherwise become a second
sentence.

**Hedge with precision, not vagueness.** "many discover", "frequently underestimate",
"rarely offer" — quantified-sounding hedges rather than "some people think".

**Concede before asserting.** "While data forms the foundation, people determine whether…"
The corpus establishes the opposing factor first, then lands its point. A paragraph that
opens with its conclusion reads as marketing; one that opens with the tension reads as
analysis.

**Named enumeration in prose.** "Multi-system coordination: Determining which system to
query…" — the label then the explanation, in a sentence rather than a list.

**Sentence length: median 18 words, mean 19.8.** Past ~25, split.

## What to avoid

- **"In this article".** 7 of 1,645 pages open this way (0.4%). The register has
  eliminated the meta-announcement. Start with the substance.
- **Superlatives without a measurement.** "the most powerful" is absent; "the highest
  measured accuracy on {benchmark}" is the shape.
- **Second-person imperatives in body copy.** "You should…" belongs to `artfct-terse`.
  `glean-formal` states what organizations do.
- **Rhetorical questions as paragraph openers.** Questions belong in H2s, not in prose.
- **Exclamation marks.** None in the corpus body copy.

## Checking a draft

1. Search the paragraph for contractions. Any hit is a defect in `glean-formal`.
2. Count the sentences; compute the mean. Over 25 words, split.
3. Find the first sentence. Does it assert the conclusion, or set up the tension?
4. Search for "in this article". If present, delete it and start with the next sentence.
5. Search for "you". The possessive is allowed — "your CISO", "your team" appears in
   the corpus, including in titles like "How to get your CISO's green light". The
   *imperative* is not: "You should publish…" belongs to `artfct-terse`. The linter
   enforces this as `W15`.
