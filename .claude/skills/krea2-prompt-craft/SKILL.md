---
name: krea2-prompt-craft
description: Use when writing or adapting an image prompt for Krea 2 Turbo, selecting `frame_backend: krea2`, regenerating a Krea frame, or creating a Krea style-reference image. Produces detailed natural-language prompts that preserve subjects, composition, materials, lighting, palette, and quoted rendered text.
---

# Krea 2 Prompt Craft

Krea 2 Turbo accepts **natural-language** image prompts. It can render up to 2K images. Detailed prompts generally yield the strongest results, but a concise, concrete prompt is valid when the intended image is simple.

The pipeline stores prompts as structured JSON for editing and provenance. The PHP `Krea2PromptRenderer` converts that structure into one natural-language paragraph before submitting `workflows/krea2.json`. Do not put JSON syntax or `bbox` coordinates in Krea descriptions.

## Authoring rules

1. Start with the main subject and setting.
2. Add visual information in a readable progression: composition and relative placement, foreground/background, material and texture, perspective or camera framing, lighting, color, medium, and atmosphere.
3. Be concrete. Prefer `immense rocket launch exhaust seen from extremely close up` to abstract quality labels.
4. Preserve deliberate style vocabulary. Krea handles photographic, 3D, painterly, graphic, anime, and cel-animation work; name the specific medium and visual traits rather than applying FLUX-specific caveats.
5. Treat palette entries as metadata. Translate them into natural color descriptions in subjects, materials, light, and atmosphere—never include a palette label, swatches, or raw hex codes in the rendered prompt. Only an element's `text` value is intended to become visible image text.
6. Include exact rendered text in quotation marks. For a hook, retain the literal string in its `text` field and describe its high-contrast upper-frame treatment in `desc`.
7. Never use Ideogram `bbox` values. Express placement in prose: `upper right`, `center foreground`, `behind the subject`, `high-angle wide composition`.
8. Keep the storyboard's 9:16 framing, scene meaning, lighting, and composition intact when translating from another backend.

## High-detail prompt pattern

Use this order when the scene needs precision:

```text
[subject and setting], [pose/action and composition], [foreground/background placement],
[materials and fine visual details], [camera angle / framing / depth], [lighting],
[color palette], [medium and rendering traits], [atmosphere]
```

Example shape:

```text
Close-up anime portrait of a young woman with large amber-brown eyes and dark blue hair,
index finger delicately touching a subtle smile, tilted framing, bright high-key lighting with
cool blue luminous shadows, detailed digital painting, shallow depth of field on the hand.
```

## Text rendering

Put exact words in quotes:

```text
A bold cream headline reading "YOUR BRAIN EDITS REALITY" in the upper third, on a dark
style-matched plate; a surprised figure remains unobstructed below it.
```

The quote must be verbatim. Do not treat the hook as a later video caption.

## Pipeline requirements

<!-- laravel-ai:instructions:start section=image-authoring -->
Author a Krea 2 prompt that preserves the scene, composition, mood, active style branch, and every rendered text string. Use long, detailed natural-language descriptions with explicit positional prose; never use JSON syntax or bbox coordinates. Place the primary subject and setting first, then composition, foreground/background, material, texture, perspective, lighting, palette, and medium. Treat `color_palette` as metadata only — translate colors into natural descriptions of subjects, materials, light, and atmosphere; never render a palette label, swatches, or raw hex codes as visible image text. Put exact rendered words in quotation marks and preserve their spelling and placement intent. If `hook_text` is present, preserve it verbatim as one quoted `text` element, 3-8 words and 45 characters or fewer, in the upper 10-30% of the 9:16 frame with a high-contrast, style-matched treatment — it is baked into scene 1's frame, never a later caption or video overlay. Keep the photo/art-style branch coherent, but do not apply FLUX-specific no-negative or hex-binding rules to Krea. Return the structured response expected by the application; PHP performs rendering and validation.
<!-- laravel-ai:instructions:end section=image-authoring -->

- Select `krea2` in `data/backends.yaml`; it uses raw prompt binding and Turbo mode.
- `Krea2PromptWriter` is the authoritative PHP agent. Its `instructions()` must remain aligned with this skill.
- `Krea2PromptRenderer` is the sole structured-JSON-to-natural-language formatter. Do not add per-backend formatting branches elsewhere.
- Test only offline workflow mutation. Never submit Comfy Cloud jobs merely to test prompts or integration changes.
