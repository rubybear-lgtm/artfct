## Open Graph / SEO meta tags — for welcome.tsx

In `resources/js/pages/welcome.tsx`, replace the existing `<Head>` line:

```tsx
<Head title="artfct — share html instantly" />
```

With:

```tsx
<Head title="artfct — share html instantly">
    <meta property="og:title" content="artfct — share html. get a link. that's it." />
    <meta property="og:description" content="Drop a self-contained HTML file — via browser, CLI, API, or AI agent — and get back a shareable link. No sign-up required." />
    <meta property="og:url" content="https://artfct.dev" />
    <meta property="og:type" content="website" />
    <meta name="description" content="Drop a self-contained HTML file — via browser, CLI, API, or AI agent — and get back a shareable link. No sign-up required." />
</Head>
```

And in `resources/js/pages/docs.tsx`, replace:

```tsx
<Head title="api reference — artfct" />
```

With:

```tsx
<Head title="api reference — artfct">
    <meta name="description" content="REST API reference for creating and managing HTML artifacts on artfct.dev." />
</Head>
```

### Also add OG image reference to app.blade.php

In `resources/views/app.blade.php`, add these lines inside `<head>` after the apple-touch-icon link:

```blade
        <meta property="og:image" content="{{ asset('og-image.svg') }}" />
        <meta name="twitter:card" content="summary_large_image" />
        <meta name="twitter:title" content="artfct — share html. get a link. that's it." />
        <meta name="twitter:description" content="Drop a self-contained HTML file — via browser, CLI, API, or AI agent — and get back a shareable link. No sign-up required." />
```

And add the `og-image.svg` file from this PR to `public/og-image.svg`.
