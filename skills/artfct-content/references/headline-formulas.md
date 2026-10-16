# Headline formulas

Every formula below was verified by querying the `pages` table for real titles, not
recalled. Two shapes I had recorded from the teardown did not survive that check and are
listed at the end as unverified — treat them as unavailable until a query finds them.

## Verified formulas

**`What is {term}?`** — the definitional entry point. Most common single shape.
> What is an AI agent? A Complete Guide
> What is Vector Search?
> What is Intelligent Search and why does it matter?

**`{N} key benefits of {topic}`** — listicle with a stated count. The count must be the
real number of items in the body; the corpus never fudges it.
> 7 key benefits of effective enterprise knowledge management
> 7 key benefits of mobile messaging integrations for AI support

**`{N} common pitfalls in {topic}`** — the negative twin of the above, and the higher
intent of the two.
> 5 common pitfalls in AI assistant implementation and how to overcome them
> How to avoid common pitfalls in AI investments

**`{A} vs {B}: what works better`** — the comparison shape. Note that the corpus rarely
declares a winner in the title; it promises a decision procedure.
> AI assistants vs AI agents: What works better?
> Knowledge graph vs vector database: how to choose your AI foundation
> Structured vs unstructured data: differences and examples

**`How to {outcome}`** — outcome-phrased, never process-phrased. "How to get your CISO's
green light" is an outcome; "How to write a security review" is a process. The corpus
always picks the outcome.
> How to get your CISO's green light on AI agents
> How to build agents that actually work: A practical guide to evaluating AI
> How to optimize token efficiency in agentic systems

**`The {N} best {category} in {year}`** — dated, and the year is load-bearing: it is the
reason the page gets rewritten rather than abandoned.
> The 10 best AI voice assistants in 2026: A comprehensive guide

**`Why {thing} needs {unexpected thing}`** — the contrarian shape. The second noun is the
whole point and must genuinely be the non-obvious answer.
> Why agents need sandboxes, not larger context windows
> Why the AI stack for modern engineering teams requires both coding and context

**`{Metric} benchmark: key metrics to evaluate {thing}`** — the measurement shape.
> 2025 search tool benchmark: key metrics to evaluate accuracy and speed

## Rules the corpus keeps

- **Question or promise, never a noun phrase.** "Access control" is not a headline in
  this corpus. "How does artfct handle access control?" is.
- **The subtitle does work.** A colon and a second clause is common and carries the
  qualifier ("A Complete Guide", "how to choose your AI foundation").
- **No year unless the page will be maintained.** `in {year}` is a commitment to
  rewrite.
- **The count is honest.** A "7 key benefits" page has seven.

## Unverified

These shapes appear in the corpus body text but I could not find them in titles. Do not
use them as headline formulas without re-checking:

- `How to {outcome} without {fear}` — zero title matches for `How to % without`.
- `The future of {function}` — not confirmed as a title pattern.
