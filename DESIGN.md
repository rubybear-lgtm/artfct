# Design System: Artfct

**Stitch project:** Artfct Redesign Exploration (`projects/10297977941696855570`)
**Stitch design system:** Oxblood & Bone (`assets/8557773337143321736`)
**Reference implementation:** the animated landing prototype (Artifact "Artfct Landing v2", version 7)

This replaces the earlier Solarized Light / ASCII direction, which suited the free single-file tool. Artfct is now a paid product for whole companies, not a developer tool.

## 1. Visual Theme & Atmosphere

Warm, editorial and calm. Think of a well-set page in a quiet reading room: bone-colored paper, charcoal ink, and a single deep red used sparingly. It should feel trustworthy and unhurried, the way a company's shared library feels, never like a terminal or a dashboard.

- **Audience:** everyone in a company who produces something worth keeping (engineers, designers, analysts, managers). Nothing on the page should require technical knowledge.
- **Density:** generous whitespace, hairline dividers instead of boxed cards, one lifted object per screen (the product demo).
- **Personality:** confident and plain-spoken. Precision comes from restraint, not decoration.
- **Not:** retro, developer-flavored, gradient-heavy, or neon. No ASCII art, no terminals, no code blocks, no monospace-heavy UI.

## 2. Color Palette & Roles

| Name | Hex | Role |
|---|---|---|
| Bone | `#F7F5F2` | Page ground |
| Paper | `#FBFAF8` | Raised surfaces: the demo panel, plan and comparison containers |
| Hairline | `#DEDAD2` | Every divider and border |
| Charcoal Ink | `#262624` | Headlines, body text, secondary buttons |
| Muted Ink | `#66655F` | Supporting body copy |
| Quiet Ink | `#8C8A83` | Captions, labels, metadata |
| Oxblood | `#701A24` | The one accent: primary buttons, the italic word in a headline, selected and matching states, small eyebrow labels |
| Deep Oxblood | `#58141C` | Primary button hover and pressed |
| Oxblood Tint | `#F1E4E5` | Matching rows, the highlighted plan, the "Artfct" side of a comparison |
| Row Neutral | `#EFECE6` / `#EBE8E1` | Icon wells and tags |
| Confirmation Green | `#2F6B4F` | Only for a completed share ("Shared") |

Rules:
- Oxblood is the only accent. It never fills large areas and never appears as a gradient.
- Green appears only as a state color for "Shared". It is not a brand color.
- Contrast: body text uses Charcoal or Muted Ink on Bone or Paper, both above 4.5:1.
- The page is a single light theme.

## 3. Typography Rules

- **Headlines: Newsreader** (editorial serif), weight 400, tight tracking (about -0.02em), balanced wrapping. One word in the main headline may be set in italic Oxblood for emphasis. Subheads use weight 500.
- **Interface and body: Manrope**, weights 400 to 700. Buttons and labels are 600.
- **Eyebrow labels:** small uppercase Manrope, 11 to 12px, wide letter-spacing (about 0.08em), Oxblood or Quiet Ink.
- **Scale:** H1 fluid 40 to 68px (line-height 1.04); H2 fluid 30 to 42px (1.1); H3 25px serif; body 16px (1.55); supporting copy 17 to 18px; captions 13 to 14px.
- Keep running text near 65 characters. Sentence case everywhere, no title-case marketing headings.

Deliberately not used: Inter, Space Grotesk, Geist, Roboto, and monospace for anything user-facing.

## 4. Component Stylings

* **Buttons:** subtly rounded (6px). Primary is Oxblood with white text, and on hover deepens to Deep Oxblood, lifts 1px and gains a soft oxblood shadow. Secondary is an outlined Hairline button with Charcoal text. Text links carry a small arrow that slides right on hover. The primary action is "Try Team free".
* **Cards and containers:** used sparingly. The hero demo is the one lifted object (Paper, 10px corners, a single long soft shadow). Everything else is separated by hairlines. Three-up feature blocks are columns divided by vertical hairlines, not three boxed cards.
* **Rows (artifact lists):** flat Bone rows with a 30px icon well, a semibold title, a quiet metadata line and a small tag. A matching row gets Oxblood Tint and a 2px Oxblood left edge. A newly shared row uses a soft green tint and a green "Shared" tag.
* **Inputs and search:** Bone field, Hairline stroke, 8px corners. While active, the stroke turns Oxblood with a soft Oxblood Tint ring.
* **Chips and tags:** small, 4 to 5px corners, Row Neutral fill. Citation chips turn Oxblood Tint on hover or when highlighted.
* **Comparison block:** two columns, one Paper, one Oxblood Tint (Artfct). The neutral side uses dashes; the Artfct side uses oxblood check marks.
* **Plans:** three columns inside one hairline frame. The Team plan is tinted; its button is primary. Free and Enterprise use outlined buttons.
* **Diagrams:** simple inline drawings with Hairline strokes and small Oxblood accents. Tool boxes are labeled with plain tool names, never logos.

## 5. Layout Principles

- **Container:** 1120px maximum, centered, with a 20px minimum side gutter that holds at phone width.
- **Rhythm:** sections are separated by a hairline and 84px of vertical space. Headline and supporting sentence sit together; the evidence follows.
- **Grid:** three equal columns for points and plans; a 1 to 1.25 split for text beside a diagram; the demo is a 1.05 to 1 two-pane split. Everything collapses to one column under about 800px.
- **Depth:** essentially flat. Depth comes from hairlines and Paper against Bone, with one soft shadow on the hero demo.
- **Corner radius:** 4 to 10px. Nothing is pill-shaped or fully round.

## 6. Motion

Purposeful and small; it should clarify what the product does, not decorate.
- The hero demo loops: a new artifact is offered ("Share with team?") and becomes "Shared"; a search is typed; matching rows highlight; an assistant answer streams in word by word; source chips appear and pulse their rows.
- A drawn underline appears under the italic headline word; the demo card rises in on load; sections settle in on scroll (movement only, never hidden content).
- Diagram dots travel from each AI tool into the shared box.
- The first frame is always the finished state. With reduced motion on, everything is static and fully readable.

## 7. Voice & Content Rules

- Plain language for a non-technical reader. Say "AI tool", never "harness", "agent runtime" or "MCP" in body copy.
- Never show a terminal, CLI command, JSON, DOM selector, file path or code block on a marketing surface.
- Define "artifact" once, plainly: any report, table, document or mockup your AI makes.
- The core promise: what your AI makes is shared to your team and every AI tool on the team can read it, with sources. Sharing is the user's choice, not automatic.
- Say what is true today. No invented customers, testimonials, certifications, prices, counts or percentages. Placeholder names in demos are sample content only.
- Headlines are sentences that make one claim. Buttons say exactly what happens.

## 8. Stitch Prompting Notes

- Put layout, content and structure in prompts; leave color, font and roundness to the Oxblood & Bone design system.
- Keep generation prompts short. Long prompts time out; generation usually still completes, so poll the project rather than retrying.
- Always state the exclusions: no terminals, code, developer jargon, grid backgrounds, announcement chips, customer logos or fake badges.

## 9. App Shell (to define)

The signed-in app (search, collections, connections, team and billing settings, governance) inherits this system: bone ground, hairline structure, one oxblood accent, serif for page titles, Manrope for controls. Open decisions:
- Navigation pattern and density for admin-heavy screens.
- Table and form styling, empty and error states.
- Whether a dark theme is offered in the app.
