## FAQ — additions to docs.tsx

Add this content block after the rate limits section (before the closing `</div>` of the main content area).

### New data constants — add after `ERROR_CODES`:

```typescript
const FAQ_ITEMS = [
    {
        q: 'Can I use artfct without creating an account?',
        a: 'Yes. No sign-up, no API key, nothing. Drop a file or call the API — that\'s it.',
    },
    {
        q: 'How long do artifacts last?',
        a: 'Ephemeral artifacts expire after the TTL you set (default: 60 minutes, max: 1440). Public and secure tiers are permanent until deleted.',
    },
    {
        q: 'Can I delete an artifact early?',
        a: 'Yes — use `artfct delete <id>` from the CLI, or send a `DELETE /v1/artifacts/<id>` request.',
    },
    {
        q: 'What\'s the difference between public, secure, and ephemeral?',
        a: 'Public artifacts are accessible to anyone with the URL. Secure artifacts require authentication. Ephemeral artifacts auto-delete after the TTL. See the tiers section above.',
    },
    {
        q: 'Is there a file size limit?',
        a: 'Yes — 1 MB per artifact. This keeps deploys fast and storage reasonable.',
    },
    {
        q: 'Can I use artfct from CI/CD?',
        a: 'Yes. Install the CLI in your pipeline, then `artfct deploy report.html`. Or use the REST API directly with curl.',
    },
    {
        q: 'Can my AI agent deploy to artfct?',
        a: 'Yes — configure artfct as an MCP server. The `deploy_to_canvas` tool lets agents publish HTML directly. Run `artfct setup --silent` to configure Cursor, Claude Desktop, Gemini, and Codex automatically.',
    },
    {
        q: 'Is artfct open source?',
        a: 'Yes — MIT licensed. The source is at github.com/rubybear-lgtm/artfct.',
    },
    {
        q: 'What about privacy?',
        a: 'Ephemeral artifacts are not indexed. URLs are 32-character random tokens — not guessable. Secure-tier artifacts require authentication to view.',
    },
];
```

### New nav link — add `{ href: '#faq', label: 'faq' }` to the `NAV_LINKS` array:

```typescript
const NAV_LINKS = [
    { href: '#cli', label: 'cli' },
    { href: '#skills', label: 'skills' },
    { href: '#overview', label: 'rest api' },
    { href: '#create', label: 'create' },
    { href: '#delete', label: 'delete' },
    { href: '#errors', label: 'errors' },
    { href: '#limits', label: 'rate limits' },
    { href: '#faq', label: 'faq' },       // <-- add this
];
```

### New FAQ section — add after the rate limits section (before the final `</div></div></>`):

```tsx
                    {/* ── faq ──────────────────────────────────────────────── */}
                    <SectionDivider id="faq" label="faq" />

                    {FAQ_ITEMS.map((item, i) => (
                        <div key={i} style={{ marginBottom: '1.25rem' }}>
                            <p
                                style={{
                                    fontFamily: MONO,
                                    fontSize: '12px',
                                    color: S.base00,
                                    margin: '0 0 0.3rem',
                                    fontWeight: 500,
                                }}
                            >
                                {item.q}
                            </p>
                            <Prose>{item.a}</Prose>
                        </div>
                    ))}
```
