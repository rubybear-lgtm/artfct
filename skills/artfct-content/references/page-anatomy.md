# Page anatomy

Measured across 1,682 glean.com pages (2.14M words of body copy). Percentages are the
share of pages carrying that block; they are targets, not laws — but a page that
ignores all of them will read as an outlier.

## The block order

1. **Answer-first lead.** One paragraph, 40–70 words, that states what the thing is or
   what the reader gets. No throat-clearing, no "in this article."
   - 27.4% of pages open with a definitional copula ("X is a…").
   - Only 0.4% (7 of 1,645) open with "in this article." This is the clearest single
     stylistic marker of the register: the reference corpus has essentially eliminated
     the meta-announcement.
2. **`Last updated <date>` line.** Carried by 43–46% of pages. Cheap, and it is the
   strongest freshness signal the corpus uses.
3. **Table of contents.** 47% of pages. Only worth it past roughly 1,200 words; below
   that it costs more attention than it saves.
4. **5–9 question-shaped H2s.** Mean 5.9. Phrase them as the questions a buyer would
   actually type, not as noun phrases. "How does artfct handle access control?" beats
   "Access control."
5. **FAQ block.** 12% of blog posts, **53% of perspectives**. The FAQ is what separates
   the two engines — it is the highest-value block to include when the page is making an
   argument rather than announcing a release.
6. **Author card.** 53% of pages carry no byline at all, 27% carry "The Glean Team", and
   ~17% are named. An absent byline is normal in this corpus and not a defect.
7. **Repeat CTA.** The close restates the single next action.

## Length

| Surface | Median words | Note |
|---|---|---|
| `/blog` (340 posts) | 1,071 | Shorter, release- and concept-shaped |
| `/perspectives` (447 posts) | 2,642 | Longer, argument-shaped, FAQ-heavy |
| Prompt library (177 pages) | 143 | Programmatic, near-zero prose |
| Comparison pages | 1,202–2,468 | Scales with the number of axes compared |

## Sentence and link discipline

- Median sentence 18 words; mean 19.8. If a paragraph's mean crosses ~25, split it.
- Median 7 in-body links. The 105-link navigation signature is excluded — do not read
  it as a linking target.

## The two-engine finding

The corpus is not one thing. `/blog` and `/perspectives` differ in length (1,071 vs
2,642 median words) and in FAQ rate (12% vs 53%), and `/perspectives` never uses the
word "blog" about itself. Deciding which engine a page belongs to before drafting is
worth more than any sentence-level edit.

## Cadence

779 pages carry a literal "Last updated" date spanning 62 months, peaking at 116 pieces
in a single month (July 2026). The corpus is a publishing operation, not a set of
one-off pages — which is why the programmatic surfaces below matter.
