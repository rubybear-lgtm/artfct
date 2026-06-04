import { Head, Link } from '@inertiajs/react';
import { useCallback, useState } from 'react';

// ── Solarized Light ──────────────────────────────────────────────────────────
const S = {
    base3:   '#FDF6E3',
    base2:   '#EEE8D5',
    base1:   '#93A1A1',
    base0:   '#657B83',
    base00:  '#586E75',
    yellow:  '#B58900',
    orange:  '#CB4B16',
    red:     '#DC322F',
    magenta: '#D33682',
    violet:  '#6C71C4',
    blue:    '#268BD2',
    cyan:    '#2AA198',
    green:   '#859900',
} as const;

const MONO = 'ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace';
const SANS = "'Instrument Sans', ui-sans-serif, system-ui, sans-serif";

// ── content ──────────────────────────────────────────────────────────────────
const POST_REQUEST_FIELDS = [
    { name: 'html',        type: 'string',  req: true,  note: 'The complete HTML to host. Max 1 MB.' },
    { name: 'tier',        type: 'enum',    req: true,  note: 'public · secure · ephemeral' },
    { name: 'ttl_minutes', type: 'integer', req: false, note: 'Minutes until expiry. Default: 60. Max: 1440.' },
] as const;

const POST_RESPONSE_FIELDS = [
    { name: 'id',         type: 'string', note: '32-character token.' },
    { name: 'url',        type: 'string', note: 'Direct link to the artifact.' },
    { name: 'tier',       type: 'string', note: 'The tier used.' },
    { name: 'expires_at', type: 'string', note: 'ISO 8601 expiry timestamp.' },
] as const;

const ERROR_CODES = [
    { code: '400', meaning: 'Malformed JSON body.' },
    { code: '413', meaning: 'Payload exceeds 1 MB.' },
    { code: '422', meaning: 'Missing or invalid field.' },
    { code: '429', meaning: 'Rate limit exceeded.' },
    { code: '404', meaning: 'Artifact not found or expired.' },
] as const;

const GITHUB = 'https://github.com/rubybear-lgtm/artfct';
const RELEASES = `${GITHUB}/releases/latest/download`;
const EXAMPLE_ID = '4fa8gx9z3k7m2n5p8q1r6s0t7u2v4w9x';

// ── CLI content ───────────────────────────────────────────────────────────────
const CLI_INSTALL_MAC_ARM =
`curl -sSfL ${RELEASES}/artfct-aarch64-apple-darwin.tar.gz \\
  | tar -xz -C /usr/local/bin`;

const CLI_INSTALL_MAC_INTEL =
`curl -sSfL ${RELEASES}/artfct-x86_64-apple-darwin.tar.gz \\
  | tar -xz -C /usr/local/bin`;

const CLI_INSTALL_LINUX_X64 =
`curl -sSfL ${RELEASES}/artfct-x86_64-unknown-linux-gnu.tar.gz \\
  | tar -xz -C /usr/local/bin`;

const CLI_INSTALL_LINUX_ARM =
`curl -sSfL ${RELEASES}/artfct-aarch64-unknown-linux-gnu.tar.gz \\
  | tar -xz -C /usr/local/bin`;

const CLI_USAGE =
`# deploy a file — prints the URL
artfct deploy page.html

# deploy from stdin
cat page.html | artfct deploy --stdin
echo '<h1>hello</h1>' | artfct deploy --stdin

# check connectivity
artfct doctor`;

const CLI_MCP =
`artfct mcp serve`;

const CLI_DEPLOY_FLAGS = [
    { name: 'FILE',            type: 'path',    req: false, note: 'Path to the HTML file to deploy.' },
    { name: '--stdin',         type: 'flag',    req: false, note: 'Read HTML from stdin instead of a file.' },
    { name: '--tier',          type: 'string',  req: false, note: 'public · secure · ephemeral  (default: ephemeral)' },
    { name: '--ttl-minutes',   type: 'integer', req: false, note: 'Minutes until expiry. Default: 60. Max: 1440.' },
] as const;

const CODE_POST_REQUEST =
`curl -X POST https://artfct.dev/v1/artifacts \\
  -H "Content-Type: application/json" \\
  -d '{
    "html": "<h1>hello world</h1>",
    "tier": "ephemeral"
  }'`;

const CODE_POST_RESPONSE =
`{
  "id": "${EXAMPLE_ID}",
  "url": "https://artfct.dev/p/${EXAMPLE_ID}",
  "tier": "ephemeral",
  "expires_at": "2026-06-03T15:30:00Z"
}`;

const CODE_DELETE_REQUEST =
`curl -X DELETE https://artfct.dev/v1/artifacts/${EXAMPLE_ID}`;

const CODE_ERROR_RESPONSE =
`{
  "message": "The html field is required."
}`;

// ── subcomponents ─────────────────────────────────────────────────────────────
function SectionDivider({ id, label }: { id: string; label: string }) {
    return (
        <div
            id={id}
            style={{
                display: 'flex',
                alignItems: 'center',
                gap: '0.6rem',
                paddingTop: '2.5rem',
                marginBottom: '1.5rem',
            }}
        >
            <span style={{ fontFamily: MONO, fontSize: '11px', color: S.base1, whiteSpace: 'nowrap' }}>
                ── {label}
            </span>
            <div style={{ flex: 1, height: '1px', backgroundColor: S.base2 }} />
        </div>
    );
}

function CodeBlock({ code }: { code: string }) {
    const [copied, setCopied] = useState(false);

    const copy = useCallback(async () => {
        await navigator.clipboard.writeText(code);
        setCopied(true);
        setTimeout(() => setCopied(false), 2000);
    }, [code]);

    return (
        <div style={{ position: 'relative', marginBottom: '1.25rem' }}>
            <pre
                style={{
                    fontFamily: MONO,
                    fontSize: '12px',
                    lineHeight: 1.65,
                    color: S.base00,
                    backgroundColor: S.base2,
                    padding: '1rem 1rem 1rem 1.1rem',
                    margin: 0,
                    overflowX: 'auto',
                    whiteSpace: 'pre',
                }}
            >
                {code}
            </pre>
            <button
                onClick={copy}
                style={{
                    position: 'absolute',
                    top: '0.5rem',
                    right: '0.5rem',
                    fontFamily: MONO,
                    fontSize: '10px',
                    padding: '0.2rem 0.45rem',
                    backgroundColor: copied ? S.green : S.base3,
                    color: copied ? S.base3 : S.base1,
                    border: `1px solid ${copied ? S.green : S.base1}`,
                    cursor: 'pointer',
                    transition: 'all 0.15s ease',
                    letterSpacing: '0.03em',
                }}
            >
                {copied ? '✓' : '⎘ copy'}
            </button>
        </div>
    );
}

function FieldTable({
    fields,
}: {
    fields: ReadonlyArray<{ name: string; type: string; req?: boolean; note: string }>;
}) {
    return (
        <div style={{ marginBottom: '1.25rem' }}>
            {fields.map((f, i) => (
                <div
                    key={f.name}
                    style={{
                        display: 'grid',
                        gridTemplateColumns: '9rem 5.5rem 1fr',
                        gap: '0.5rem',
                        padding: '0.45rem 0',
                        borderTop: i === 0 ? `1px solid ${S.base2}` : undefined,
                        borderBottom: `1px solid ${S.base2}`,
                        alignItems: 'baseline',
                    }}
                >
                    <span style={{ fontFamily: MONO, fontSize: '12px', color: S.base00 }}>
                        {f.name}
                    </span>
                    <span style={{ fontFamily: MONO, fontSize: '11px', color: S.base1 }}>
                        {f.type}
                        {'req' in f && (
                            <span
                                style={{
                                    marginLeft: '0.4rem',
                                    color: f.req ? S.orange : S.base1,
                                    fontSize: '10px',
                                }}
                            >
                                {f.req ? 'required' : 'optional'}
                            </span>
                        )}
                    </span>
                    <span style={{ fontFamily: SANS, fontSize: '13px', color: S.base0 }}>
                        {f.note}
                    </span>
                </div>
            ))}
        </div>
    );
}

function Label({ children }: { children: React.ReactNode }) {
    return (
        <h3
            style={{
                fontFamily: MONO,
                fontSize: '13px',
                fontWeight: 400,
                color: S.base00,
                margin: '0 0 0.6rem',
                letterSpacing: '0.02em',
            }}
        >
            {children}
        </h3>
    );
}

function Prose({ children }: { children: React.ReactNode }) {
    return (
        <p
            style={{
                fontFamily: SANS,
                fontSize: '14px',
                lineHeight: 1.65,
                color: S.base0,
                margin: '0 0 1.1rem',
            }}
        >
            {children}
        </p>
    );
}

function Chip({ children }: { children: React.ReactNode }) {
    return (
        <span
            style={{
                fontFamily: MONO,
                fontSize: '11px',
                color: S.base0,
                backgroundColor: S.base2,
                padding: '0.2rem 0.5rem',
                whiteSpace: 'nowrap',
            }}
        >
            {children}
        </span>
    );
}

// ── page ─────────────────────────────────────────────────────────────────────
export default function Docs() {
    const NAV_LINKS = [
        { href: '#cli',      label: 'cli' },
        { href: '#overview', label: 'rest api' },
        { href: '#create',   label: 'create' },
        { href: '#delete',   label: 'delete' },
        { href: '#errors',   label: 'errors' },
        { href: '#limits',   label: 'rate limits' },
    ];

    return (
        <>
            <Head title="api reference — artfct" />
            <div
                style={{
                    minHeight: '100dvh',
                    backgroundColor: S.base3,
                    fontFamily: SANS,
                    color: S.base0,
                    boxSizing: 'border-box',
                }}
            >
                {/* ── top nav ──────────────────────────────────────────────── */}
                <nav
                    style={{
                        borderBottom: `1px solid ${S.base2}`,
                        padding: '0.85rem 1.5rem',
                        display: 'flex',
                        justifyContent: 'space-between',
                        alignItems: 'center',
                    }}
                >
                    <Link
                        href="/"
                        style={{
                            fontFamily: MONO,
                            fontSize: '13px',
                            color: S.base00,
                            textDecoration: 'none',
                            letterSpacing: '0.04em',
                        }}
                    >
                        artfct
                    </Link>
                    <Link
                        href="/"
                        style={{
                            fontFamily: MONO,
                            fontSize: '11px',
                            color: S.base1,
                            textDecoration: 'none',
                        }}
                    >
                        ← deploy
                    </Link>
                </nav>

                {/* ── main content ─────────────────────────────────────────── */}
                <div
                    style={{
                        maxWidth: '680px',
                        margin: '0 auto',
                        padding: '2.5rem 1.5rem 4rem',
                    }}
                >
                    {/* page title */}
                    <h1
                        style={{
                            fontFamily: MONO,
                            fontSize: '14px',
                            fontWeight: 400,
                            color: S.base00,
                            letterSpacing: '0.04em',
                            margin: '0 0 0.5rem',
                        }}
                    >
                        api reference
                    </h1>
                    <p
                        style={{
                            fontFamily: SANS,
                            fontSize: '13px',
                            color: S.base1,
                            margin: '0 0 2rem',
                        }}
                    >
                        REST API for creating and managing HTML artifacts.
                    </p>

                    {/* in-page nav */}
                    <div
                        style={{
                            display: 'flex',
                            flexWrap: 'wrap',
                            gap: '0.1rem 0',
                            marginBottom: '0.5rem',
                            fontFamily: MONO,
                            fontSize: '11px',
                        }}
                    >
                        {NAV_LINKS.map((link, i) => (
                            <span key={link.href}>
                                <a
                                    href={link.href}
                                    style={{ color: S.blue, textDecoration: 'none' }}
                                >
                                    {link.label}
                                </a>
                                {i < NAV_LINKS.length - 1 && (
                                    <span style={{ color: S.base2, margin: '0 0.5rem' }}>·</span>
                                )}
                            </span>
                        ))}
                    </div>

                    {/* ── cli ─────────────────────────────────────────────── */}
                    <SectionDivider id="cli" label="cli" />

                    <Prose>
                        The <code style={{ fontFamily: MONO, fontSize: '12px', color: S.base00 }}>artfct</code> CLI
                        deploys HTML files directly from your terminal and pipes. Pre-built binaries
                        are available for macOS and Linux — no runtime required.
                    </Prose>

                    <Label>install</Label>

                    <div style={{ display: 'flex', flexDirection: 'column', gap: '0.5rem', marginBottom: '1.25rem' }}>
                        {[
                            { label: 'macOS · Apple Silicon', code: CLI_INSTALL_MAC_ARM },
                            { label: 'macOS · Intel',         code: CLI_INSTALL_MAC_INTEL },
                            { label: 'Linux · x86_64',        code: CLI_INSTALL_LINUX_X64 },
                            { label: 'Linux · ARM64',         code: CLI_INSTALL_LINUX_ARM },
                        ].map(({ label, code }) => (
                            <div key={label}>
                                <div style={{ fontFamily: MONO, fontSize: '11px', color: S.base1, marginBottom: '0.3rem' }}>
                                    {label}
                                </div>
                                <CodeBlock code={code} />
                            </div>
                        ))}
                    </div>

                    <Label>usage</Label>
                    <CodeBlock code={CLI_USAGE} />

                    <Label>deploy flags</Label>
                    <FieldTable fields={CLI_DEPLOY_FLAGS} />

                    <Label>mcp server</Label>
                    <Prose>
                        Start artfct as a local MCP server over stdio. Supports the{' '}
                        <code style={{ fontFamily: MONO, fontSize: '12px', color: S.base00 }}>deploy_to_canvas</code>{' '}
                        tool — Claude Code, Cursor, and other MCP-compatible agents can call it to publish
                        HTML directly without leaving the session.
                    </Prose>
                    <CodeBlock code={CLI_MCP} />

                    <Prose>
                        To wire it up in Claude Code, add this to your{' '}
                        <code style={{ fontFamily: MONO, fontSize: '12px', color: S.base00 }}>.mcp.json</code>:
                    </Prose>
                    <CodeBlock code={`{
  "mcpServers": {
    "artfct": {
      "command": "artfct",
      "args": ["mcp", "serve"]
    }
  }
}`} />

                    {/* ── overview ─────────────────────────────────────────── */}
                    <SectionDivider id="overview" label="rest api" />

                    <div style={{ display: 'flex', flexWrap: 'wrap', gap: '0.5rem', marginBottom: '1.5rem' }}>
                        <Chip>base url: https://artfct.dev</Chip>
                        <Chip>content-type: application/json</Chip>
                        <Chip>max payload: 1 mb</Chip>
                    </div>

                    <Prose>
                        No authentication required. Requests are rate-limited per IP at the
                        Cloudflare edge — see{' '}
                        <a href="#limits" style={{ color: S.blue, textDecoration: 'none' }}>
                            rate limits
                        </a>
                        .
                    </Prose>

                    <Prose>
                        Artifacts are served at{' '}
                        <code style={{ fontFamily: MONO, fontSize: '12px', color: S.base00 }}>
                            /p/:id
                        </code>{' '}
                        as rendered HTML pages — that endpoint is browser-facing and not part of
                        this API.
                    </Prose>

                    {/* ── create ───────────────────────────────────────────── */}
                    <SectionDivider id="create" label="POST /v1/artifacts" />

                    <Prose>Creates a new artifact and returns a shareable URL.</Prose>

                    <Label>request body</Label>
                    <FieldTable fields={POST_REQUEST_FIELDS} />

                    <div style={{ marginBottom: '1.5rem' }}>
                        <div
                            style={{
                                fontFamily: MONO,
                                fontSize: '11px',
                                color: S.base1,
                                marginBottom: '0.75rem',
                            }}
                        >
                            <strong style={{ color: S.base00 }}>tier values</strong>
                            <span style={{ margin: '0 0.4rem', color: S.base2 }}>·</span>
                            <span style={{ color: S.cyan }}>public</span>
                            {' '}open link
                            <span style={{ margin: '0 0.5rem', color: S.base2 }}>·</span>
                            <span style={{ color: S.cyan }}>secure</span>
                            {' '}high-entropy URL
                            <span style={{ margin: '0 0.5rem', color: S.base2 }}>·</span>
                            <span style={{ color: S.cyan }}>ephemeral</span>
                            {' '}auto-expires after TTL
                        </div>
                    </div>

                    <Label>example request</Label>
                    <CodeBlock code={CODE_POST_REQUEST} />

                    <Label>response — 201 created</Label>
                    <FieldTable fields={POST_RESPONSE_FIELDS} />
                    <CodeBlock code={CODE_POST_RESPONSE} />

                    {/* ── delete ───────────────────────────────────────────── */}
                    <SectionDivider id="delete" label="DELETE /v1/artifacts/:id" />

                    <Prose>
                        Immediately evicts an artifact from the store. Returns{' '}
                        <code style={{ fontFamily: MONO, fontSize: '12px', color: S.base00 }}>
                            204 No Content
                        </code>{' '}
                        on success. Use the{' '}
                        <code style={{ fontFamily: MONO, fontSize: '12px', color: S.base00 }}>
                            id
                        </code>{' '}
                        from the create response.
                    </Prose>

                    <Label>example request</Label>
                    <CodeBlock code={CODE_DELETE_REQUEST} />

                    {/* ── errors ───────────────────────────────────────────── */}
                    <SectionDivider id="errors" label="errors" />

                    <Prose>
                        All errors return a JSON body with a single{' '}
                        <code style={{ fontFamily: MONO, fontSize: '12px', color: S.base00 }}>
                            message
                        </code>{' '}
                        field.
                    </Prose>

                    <CodeBlock code={CODE_ERROR_RESPONSE} />

                    <FieldTable
                        fields={ERROR_CODES.map((e) => ({
                            name: e.code,
                            type: '',
                            note: e.meaning,
                        }))}
                    />

                    {/* ── rate limits ──────────────────────────────────────── */}
                    <SectionDivider id="limits" label="rate limits" />

                    <Prose>
                        Rate limits are enforced at the Cloudflare WAF before requests reach the
                        worker. Exceeding a limit returns{' '}
                        <code style={{ fontFamily: MONO, fontSize: '12px', color: S.base00 }}>
                            429
                        </code>
                        .
                    </Prose>

                    <FieldTable
                        fields={[
                            { name: 'POST /v1/artifacts', type: '', note: '60 requests / minute per IP' },
                            { name: 'GET /p/:id',         type: '', note: '200 requests / minute per IP' },
                        ]}
                    />
                </div>

                {/* ── footer ───────────────────────────────────────────────── */}
                <footer
                    style={{
                        borderTop: `1px solid ${S.base2}`,
                        padding: '1rem 1.5rem',
                        maxWidth: '680px',
                        margin: '0 auto',
                        display: 'flex',
                        justifyContent: 'space-between',
                        alignItems: 'center',
                        fontFamily: MONO,
                        fontSize: '11px',
                    }}
                >
                    <div style={{ display: 'flex', gap: '1.25rem' }}>
                        <Link href="/" style={{ color: S.base1, textDecoration: 'none' }}>
                            home
                        </Link>
                        <a
                            href={GITHUB}
                            target="_blank"
                            rel="noreferrer"
                            style={{ color: S.base1, textDecoration: 'none' }}
                        >
                            github
                        </a>
                    </div>
                    <span style={{ color: S.base2 }}>ephemeral · secure · 60min</span>
                </footer>
            </div>
        </>
    );
}
