# Engineering handoff pack skill

Use this when the person asks for a mockup or design that an engineer or a coding agent will build from, or asks for a handoff, a developer spec or a README for a design. The pack is the mockup bundle plus a `HANDOFF.md` that a developer who was not in the conversation can implement from. Anyone can download it as a zip, where `HANDOFF.md` becomes `README.md`.

## Rules

1. Search first with `search_artifacts` for a mockup with the same title. Update it with `artifact_id` and `base_version` instead of publishing a duplicate.
2. Publish the bundle with `deploy_artifact`: `files` holds `index.html` (the entrypoint, `entrypoint: index.html`), any stylesheets, scripts, images and fonts the mockup uses, and `HANDOFF.md`. Set `artifact_type: mockup`. The title is two to four words.
3. Write `HANDOFF.md` with these nine sections as `##` headings, in this order: Overview, About these files, Fidelity, Screens or views, Interactions and behavior, State management, Tokens, Assets, Notes.
4. Measure from the files, not from memory. Read the values in the CSS and the markup, and copy the exact figures into the README.
5. Do not open a pull request or write production code. The handoff describes the design; the engineer builds it.
6. Give the person the view link from `deploy_artifact`. Tell them the zip download is on the artifact page.

## The nine sections

## Overview

What the thing is, the goal the person using it has, and which screens or states the bundle covers. Three to six sentences.

## About these files

State this near the top, in these words: the HTML files in this bundle are design references to recreate in the target codebase's own framework and patterns, not production code to ship. Say which file is the entrypoint and how the files relate to each other.

## Fidelity

Write `High` or `Low`.

- High fidelity: give exact values for every colour (hex), size, font (family, size, weight, line height), radius and spacing (px or rem). A developer should be able to match the design without asking.
- Low fidelity: layout and content only. Say so, and give no measurements.

## Screens or views

One entry per screen or state, in the order a person meets them. Name the file that shows it and what it is for.

## Interactions and behavior

Each click, hover, focus, validation rule, transition and empty or error state, and what happens. Give exact timings and easing for animations.

## State management

For each screen, what data it shows, where that data comes from, what changes it, and what is saved. Say what is not stored.

## Tokens

The colours, type scale, spacing, radii and shadows used, each with its name and value, as a list. Take the values from the CSS.

## Assets

Each image, icon, font and other file in the bundle: its path, what it is for, and whether it is a placeholder.

## Notes

Open questions, known gaps and anything the engineer must confirm with the author before building. If there are none, write "None."
