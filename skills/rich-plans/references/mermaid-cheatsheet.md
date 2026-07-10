# Mermaid Diagram Cheatsheet for Plans

Common Mermaid diagram patterns for architecture and implementation plans. All
work in the plan template by wrapping in `<pre class="mermaid">` and loading
the Mermaid ESM module via CDN.

## Setup

Add before `</body>` in the plan template:

```html
<script type="module">
  import mermaid from 'https://cdn.jsdelivr.net/npm/mermaid@11/dist/mermaid.esm.min.mjs';
  mermaid.initialize({ startOnLoad: true, theme: 'neutral' });
</script>
```

## Flowchart (Architecture)

```
graph TD
    A[Browser] --> B[Laravel App]
    B --> C[MySQL]
    B --> D[Redis Cache]
    B --> E[Cloudflare Worker]
    E --> F[KV Store]
```

**Shapes:** `[rectangle]`, `(rounded)`, `[(database)]`, `((circle))`, `{diamond}`, `>arrow]`

**Arrows:** `-->` (solid), `-.->` (dashed), `==>` (thick), `-- text -->` (labeled)

## Sequence Diagram (API Flow)

```
sequenceDiagram
    participant C as Client
    participant A as API
    participant D as Database
    C->>A: POST /v1/artifacts
    A->>D: INSERT artifact
    D-->>A: row
    A-->>C: 201 { id, url }
```

## Class Diagram (Data Models)

```
classDiagram
    class User {
        +String name
        +String email
        +hasMany() posts
    }
    class Post {
        +String title
        +Text body
        +belongsTo() user
    }
    User "1" --> "*" Post
```

## State Diagram

```
stateDiagram-v2
    [*] --> Draft
    Draft --> Review: submit
    Review --> Approved: approve
    Review --> Draft: request changes
    Approved --> [*]
```

## Entity Relationship

```
erDiagram
    USER ||--o{ POST : writes
    POST ||--o{ COMMENT : has
    USER ||--o{ COMMENT : writes
```

## Gantt (Timeline / Roadmap)

```
gantt
    title Project Timeline
    dateFormat YYYY-MM-DD
    section Foundation
    Database schema    :done,  db1, 2026-01-05, 3d
    API scaffolding    :done,  api1, after db1, 2d
    section Features
    User auth          :active, auth1, 2026-01-12, 4d
    Dashboard          :       dash1, after auth1, 3d
    section Polish
    Tests              :       test1, 2026-01-20, 3d
```

## Pie Chart (Effort / Breakdown)

```
pie title Effort Distribution
    "Backend" : 45
    "Frontend" : 30
    "Testing" : 15
    "Documentation" : 10
```

## Styling

Add CSS overrides in the `<style>` block:

```css
pre.mermaid {
  background: #fff;
  border: 1px solid var(--border);
  border-radius: 6px;
  padding: 1.5rem;
  text-align: center;
}
```

The `theme: 'neutral'` option works well with the Solarized palette. Alternatives:
`'default'`, `'forest'`, `'dark'`, `'base'`.
