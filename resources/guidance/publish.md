# Publishing to Artfct

Follow these rules whenever you call `deploy_artifact`, even if you did not load this guidance first.

## The contract

- Give one self-contained HTML document (up to 1 MB) in `html`, or a bundle in `files` (up to 500 files, 8 MB). Text in the page is what gets indexed and found, so keep the real content in the HTML, not in images.
- New artifacts are shared with the team by default. Do not widen `sharing` to `public` unless the person asked for it.
- Never put secrets, keys, tokens or personal data in an artifact.

## Title and description

- The title is a name, two to four words, specific to this artifact ("Q3 churn report"). It is not a summary and not "Name: explainer". Keep it the same across versions.
- The description is one sentence that says what the artifact is for. Search shows both, and people choose from them.

## Type

- Set `artifact_type` to one of: report, table, document, mockup, slide_deck, dashboard, other. If you leave it out it is inferred. A type you declared is kept on later versions.

## Updating

- To update something you published before, pass its `artifact_id` and the `base_version` you built on (from `get_artifact` or `read_artifact`).
- If you get `stale_base`, someone changed it since. Read the live version with `read_artifact`, merge your change into it, and publish again with the new `base_version`. Do not retry the same publish.
- Publishing the same content again returns the same artifact and creates no new version.

## What indexes well

- Clear headings, one idea per artifact, plain sentences. Put the answer to the question the artifact exists for near the top.

## After publishing

- Run one `search_artifacts` for the artifact's own title to confirm it can be found, report the result in one line, and stop. Do not loop.

## Do not publish

- Pages that pretend to be a real person, company or record, fabricated records presented as genuine, or content aimed at a private individual. Leave anything that could mislead if shared further for the person to decide.
