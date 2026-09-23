# Spec 16 — Collections and usage ranking

**Track:** F (thesis) · **Depends on:** 12, 13 · **Blocks:** nothing

## Scope

**In.** Usage signals that rank a corpus automatically, and lightweight collections for teams that want to declare canonical artifacts. Retrieval ranking that uses both.

**Out.** Approval workflows, review queues, mandatory curation. See below for why.

## The problem this fixes

Specs 12 and 13 index everything and rank by semantic similarity, recency and provenance. **At 100,000 artifacts, most of them one-off junk, that gives agents bad results.** Signal-to-noise is the difference between a corpus worth querying and a corpus that wastes an agent's context.

It is also the retention mechanism. A store that has learned which artifacts matter is an asset the org built; a store that holds files is a bill.

## Decisions implemented

- **07** — provenance, which makes "the billing team's canonical report format" expressible rather than just "something about billing."

## Design

### Automatic first — because curation decays

Every wiki dies of stale pages. **If this requires someone to maintain collections, it is dead within a quarter.** So the primary mechanism is signal derived from use, with manual curation available but never required:

| Signal | What it indicates |
|---|---|
| Repeat views by distinct users | worth looking at more than once |
| Shared into Slack | someone vouched for it to colleagues |
| Retrieved by an agent, then the artifact opened | retrieval was useful, not just a hit |
| Re-deployed as a variant (same repo, similar content hash lineage) | being used as a template |
| Superseded by a newer version | the *lineage* matters; the old one should rank lower |

Signals decay over time so a once-popular artifact does not outrank current work forever.

### Collections, kept deliberately thin

A named set of artifacts, org-scoped, with a description. "Our reporting formats." "Runbooks." Any member can add; admins can pin a collection as canonical.

No approval workflow, no review state, no owner assignment. Every one of those is a chore, and chores are what kill curation. A collection is a bookmark folder that agents can see.

### Ranking

Spec 13's ranking gains two inputs: usage score and collection membership, with **canonical-collection membership as a strong boost**. An artifact a team explicitly marked canonical should beat a semantically closer artifact nobody has opened since it was made.

### Retrieval scoping

`search_artifacts` gains an optional `collection` argument, so an agent can be told to draw only on canonical material — the difference between "find anything about billing" and "use our approved billing report format."

## Definition of done

- [ ] An artifact viewed by five distinct users ranks above an equally-similar artifact viewed once.
- [ ] An artifact shared into Slack ranks above one never shared.
- [ ] An artifact retrieved by an agent and then opened scores higher than one retrieved and ignored.
- [ ] A superseded artifact ranks below the version that superseded it.
- [ ] Usage signals decay: an artifact popular six months ago and unused since ranks below current work.
- [ ] A member creates a collection, adds artifacts, and another member sees it.
- [ ] An admin pins a collection as canonical; its members receive a ranking boost verifiable in results.
- [ ] `search_artifacts` with `collection: "reporting-formats"` returns only that collection's artifacts.
- [ ] A collection in org A is invisible to org B.
- [ ] A `viewer` cannot pin a collection as canonical.
- [ ] Ranking works with **zero collections defined** — automatic signal alone must be useful, since most orgs will never create one.

## Tests

**Pest / `backend`**
- `repeat_views_raise_ranking`
- `slack_share_raises_ranking`
- `retrieved_then_opened_scores_above_retrieved_then_ignored`
- `superseded_artifact_ranks_below_successor`
- `usage_signal_decays_over_time`
- `canonical_collection_boosts_ranking`
- `collection_scoped_search_excludes_others`
- `collection_invisible_across_orgs` *(negative)*
- `viewer_cannot_pin_canonical` *(negative)*
- `ranking_is_useful_with_no_collections`

## Rollback

Reversible. Signals and collections are derived or additive; dropping them returns retrieval to spec 13 behaviour.

## Deferred

- Agent-proposed collection membership — an agent that reused an artifact successfully suggesting it as canonical. Good idea, needs usage data first.
- Cross-org public collections. Interesting, entirely different trust model.
