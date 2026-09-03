import { Head, Link } from '@inertiajs/react';
import { useCallback, useState } from 'react';
import { ThemeToggle } from '@/lib/theme';

// ── Solarized (CSS custom properties — light/dark via prefers-color-scheme) ──
const S = {
    base3: 'var(--sol-base3)',
    base2: 'var(--sol-base2)',
    base1: 'var(--sol-base1)',
    base0: 'var(--sol-base0)',
    base00: 'var(--sol-base00)',
    yellow: 'var(--sol-yellow)',
    orange: 'var(--sol-orange)',
    red: 'var(--sol-red)',
    magenta: 'var(--sol-magenta)',
    violet: 'var(--sol-violet)',
    blue: 'var(--sol-blue)',
    cyan: 'var(--sol-cyan)',
    green: 'var(--sol-green)',
} as const;

const MONO = 'ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace';
const SANS = "'Instrument Sans', ui-sans-serif, system-ui, sans-serif";

const GITHUB = 'https://github.com/rubybear-lgtm/artfct';

// ── Skills content ───────────────────────────────────────────────────────────
const SKILLS_INSTALL = `npx skills add rubybear-lgtm/artfct@artfct`;

// ── CLI content ───────────────────────────────────────────────────────────────
const CLI_INSTALL = `curl -fsSL https://artfct.dev/install.sh | sh`;

const CLI_INSTALL_OPTS = `# install a specific version
ARTFCT_INSTALL_VERSION=v0.1.0 curl -fsSL https://artfct.dev/install.sh | sh

# install to a custom directory
ARTFCT_INSTALL_DIR=/usr/local/bin curl -fsSL https://artfct.dev/install.sh | sh`;

const CLI_USAGE = `# deploy a file — prints the URL
artfct deploy page.html

# deploy from stdin
cat page.html | artfct deploy --stdin
echo '<h1>hello</h1>' | artfct deploy --stdin

# delete an artifact by ID
artfct delete bdf7cd9dd9

# delete an artifact by preview URL
artfct delete https://artfct.dev/p/bdf7cd9dd9

# check connectivity
artfct doctor`;

const CLI_MCP = `artfct mcp serve`;

const CLI_DEPLOY_FLAGS = [
    {
        name: 'FILE',
        type: 'path',
        req: false,
        note: 'Path to the HTML file to deploy.',
    },
    {
        name: '--stdin',
        type: 'flag',
        req: false,
        note: 'Read HTML from stdin instead of a file.',
    },
    {
        name: '--tier',
        type: 'string',
        req: false,
        note: 'public · secure · ephemeral  (default: ephemeral)',
    },
    {
        name: '--ttl-minutes',
        type: 'integer',
        req: false,
        note: 'Minutes until expiry after last access. Default: 7200 (5 days). Max: 525600 (365 days).',
    },
] as const;

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
            <span
                style={{
                    fontFamily: MONO,
                    fontSize: '11px',
                    color: S.base1,
                    whiteSpace: 'nowrap',
                }}
            >
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
    fields: ReadonlyArray<{
        name: string;
        type: string;
        req?: boolean;
        note: string;
    }>;
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
                    <span
                        style={{
                            fontFamily: MONO,
                            fontSize: '12px',
                            color: S.base00,
                        }}
                    >
                        {f.name}
                    </span>
                    <span
                        style={{
                            fontFamily: MONO,
                            fontSize: '11px',
                            color: S.base1,
                        }}
                    >
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
                    <span
                        style={{
                            fontFamily: SANS,
                            fontSize: '13px',
                            color: S.base0,
                        }}
                    >
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

type HttpMethod = 'get' | 'post' | 'patch' | 'delete' | 'put';

type OpenApiSchema = {
    $ref?: string;
    type?: string | string[];
    format?: string;
    description?: string;
    enum?: Array<string | number | boolean>;
    properties?: Record<string, OpenApiSchema>;
    required?: string[];
    oneOf?: OpenApiSchema[];
    items?: OpenApiSchema;
};

type OpenApiMediaType = {
    schema?: OpenApiSchema;
    example?: unknown;
};

type OpenApiOperation = {
    operationId?: string;
    summary?: string;
    description?: string;
    security?: Array<Record<string, string[]>>;
    requestBody?: {
        content?: Record<string, OpenApiMediaType>;
    };
    responses?: Record<
        string,
        {
            description?: string;
            content?: Record<string, OpenApiMediaType>;
        }
    >;
    'x-status'?: string;
};

type OpenApiPath = Partial<Record<HttpMethod, OpenApiOperation>> & {
    'x-status'?: string;
};

type OpenApiDocument = {
    openapi: string;
    info: {
        title: string;
        version: string;
        description?: string;
    };
    servers?: Array<{ url: string }>;
    paths: Record<string, OpenApiPath>;
    components?: {
        schemas?: Record<string, OpenApiSchema>;
    };
};

type DocsProps = {
    contract: OpenApiDocument;
};

const HTTP_METHODS: HttpMethod[] = ['get', 'post', 'patch', 'delete', 'put'];

function schemaName(reference: string): string {
    return reference.split('/').at(-1) ?? reference;
}

function resolveSchema(
    schema: OpenApiSchema,
    contract: OpenApiDocument,
): OpenApiSchema {
    if (!schema.$ref) {
        return schema;
    }

    return contract.components?.schemas?.[schemaName(schema.$ref)] ?? schema;
}

function describeSchema(schema: OpenApiSchema): string {
    if (schema.$ref) {
        return schemaName(schema.$ref);
    }

    if (schema.oneOf) {
        return schema.oneOf.map(describeSchema).join(' | ');
    }

    const type = Array.isArray(schema.type)
        ? schema.type.join(' | ')
        : (schema.type ?? 'object');
    const format = schema.format ? `:${schema.format}` : '';
    const values = schema.enum ? ` (${schema.enum.join(' · ')})` : '';

    return `${type}${format}${values}`;
}

function schemaFields(
    schema: OpenApiSchema | undefined,
    contract: OpenApiDocument,
) {
    if (!schema) {
        return [];
    }

    const resolved = resolveSchema(schema, contract);
    const required = new Set(resolved.required ?? []);

    return Object.entries(resolved.properties ?? {}).map(
        ([name, property]) => ({
            name,
            type: describeSchema(property),
            req: required.has(name),
            note: property.description ?? '',
        }),
    );
}

function SchemaTable({
    schema,
    contract,
}: {
    schema: OpenApiSchema;
    contract: OpenApiDocument;
}) {
    if (schema.oneOf) {
        return (
            <>
                {schema.oneOf.map((variant) => (
                    <div key={describeSchema(variant)}>
                        <Label>{describeSchema(variant)}</Label>
                        <SchemaTable schema={variant} contract={contract} />
                    </div>
                ))}
            </>
        );
    }

    const fields = schemaFields(schema, contract);

    if (fields.length === 0) {
        return <Chip>{describeSchema(schema)}</Chip>;
    }

    return <FieldTable fields={fields} />;
}

function OpenApiReference({ contract }: { contract: OpenApiDocument }) {
    const operations = Object.entries(contract.paths).flatMap(([path, item]) =>
        HTTP_METHODS.flatMap((method) => {
            const operation = item[method];

            return operation ? [{ method, path, item, operation }] : [];
        }),
    );

    return (
        <>
            <SectionDivider id="overview" label="rest api" />

            <div
                style={{
                    display: 'flex',
                    flexWrap: 'wrap',
                    gap: '0.5rem',
                    marginBottom: '1.5rem',
                }}
            >
                <Chip>
                    base url:{' '}
                    {contract.servers?.[0]?.url ?? 'https://artfct.dev'}
                </Chip>
                <Chip>openapi: {contract.openapi}</Chip>
                <Chip>version: {contract.info.version}</Chip>
            </div>

            {contract.info.description && (
                <Prose>{contract.info.description}</Prose>
            )}

            {operations.map(({ method, path, item, operation }) => {
                const request =
                    operation.requestBody?.content?.['application/json'];
                const status = operation['x-status'] ?? item['x-status'];
                const security = (operation.security ?? [])
                    .flatMap((requirement) => Object.keys(requirement))
                    .join(' · ');

                return (
                    <section key={`${method}:${path}`}>
                        <SectionDivider
                            id={operation.operationId ?? `${method}-${path}`}
                            label={`${method.toUpperCase()} ${path}`}
                        />

                        <div
                            style={{
                                display: 'flex',
                                flexWrap: 'wrap',
                                gap: '0.5rem',
                                marginBottom: '1rem',
                            }}
                        >
                            <Chip>{status ?? 'implemented'}</Chip>
                            <Chip>{security || 'anonymous'}</Chip>
                        </div>

                        {operation.summary && (
                            <Label>{operation.summary}</Label>
                        )}
                        {operation.description && (
                            <Prose>{operation.description}</Prose>
                        )}

                        {request?.schema && (
                            <>
                                <Label>request body</Label>
                                <SchemaTable
                                    schema={request.schema}
                                    contract={contract}
                                />
                                {request.example !== undefined && (
                                    <CodeBlock
                                        code={JSON.stringify(
                                            request.example,
                                            null,
                                            2,
                                        )}
                                    />
                                )}
                            </>
                        )}

                        <Label>responses</Label>
                        <FieldTable
                            fields={Object.entries(
                                operation.responses ?? {},
                            ).map(([code, response]) => {
                                const responseMedia =
                                    response.content?.['application/json'] ??
                                    response.content?.['text/html'];

                                return {
                                    name: code,
                                    type: responseMedia?.schema
                                        ? describeSchema(responseMedia.schema)
                                        : 'empty',
                                    note: response.description ?? '',
                                };
                            })}
                        />
                    </section>
                );
            })}
        </>
    );
}

// ── page ─────────────────────────────────────────────────────────────────────
export default function Docs({ contract }: DocsProps) {
    const operationLinks = Object.entries(contract.paths).flatMap(
        ([path, item]) =>
            HTTP_METHODS.flatMap((method) => {
                const operation = item[method];

                return operation
                    ? [
                          {
                              href: `#${operation.operationId ?? `${method}-${path}`}`,
                              label: `${method} ${path}`,
                          },
                      ]
                    : [];
            }),
    );
    const NAV_LINKS = [
        { href: '#cli', label: 'cli' },
        { href: '#skills', label: 'skills' },
        { href: '#overview', label: 'rest api' },
        ...operationLinks,
    ];

    return (
        <>
            <Head title="api reference — artfct">
                <meta
                    name="description"
                    content="REST API reference for creating and managing HTML artifacts on artfct.dev."
                />
            </Head>
            <ThemeToggle />
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
                                    style={{
                                        color: S.blue,
                                        textDecoration: 'none',
                                    }}
                                >
                                    {link.label}
                                </a>
                                {i < NAV_LINKS.length - 1 && (
                                    <span
                                        style={{
                                            color: S.base2,
                                            margin: '0 0.5rem',
                                        }}
                                    >
                                        ·
                                    </span>
                                )}
                            </span>
                        ))}
                    </div>

                    {/* ── cli ─────────────────────────────────────────────── */}
                    <SectionDivider id="cli" label="cli" />

                    <Prose>
                        The{' '}
                        <code
                            style={{
                                fontFamily: MONO,
                                fontSize: '12px',
                                color: S.base00,
                            }}
                        >
                            artfct
                        </code>{' '}
                        CLI deploys HTML files directly from your terminal and
                        pipes. Pre-built binaries are available for macOS and
                        Linux — no runtime required.
                    </Prose>

                    <Label>install</Label>

                    <Prose>
                        Works on macOS (Apple Silicon and Intel) and Linux
                        (x86\_64 and ARM64). Installs to{' '}
                        <code
                            style={{
                                fontFamily: MONO,
                                fontSize: '12px',
                                color: S.base00,
                            }}
                        >
                            ~/.local/bin
                        </code>{' '}
                        by default.
                    </Prose>

                    <CodeBlock code={CLI_INSTALL} />

                    <CodeBlock code={CLI_INSTALL_OPTS} />

                    <Label>usage</Label>
                    <CodeBlock code={CLI_USAGE} />

                    <Label>deploy flags</Label>
                    <FieldTable fields={CLI_DEPLOY_FLAGS} />

                    <Label>mcp server</Label>
                    <Prose>
                        Start artfct as a local MCP server over stdio. Supports
                        the{' '}
                        <code
                            style={{
                                fontFamily: MONO,
                                fontSize: '12px',
                                color: S.base00,
                            }}
                        >
                            deploy_to_canvas
                        </code>{' '}
                        tool — Claude Desktop, Claude Code, Cursor, and other
                        MCP-compatible agents can call it to publish HTML
                        directly without leaving the session.
                    </Prose>
                    <CodeBlock code={CLI_MCP} />

                    <Prose>
                        To configure the MCP server automatically for all
                        detected agents (Cursor, Claude Desktop, Gemini, Codex,
                        etc.), run:
                    </Prose>
                    <CodeBlock code="artfct setup" />
                    <Prose>
                        Pass <code>--silent</code> to execute without prompts,
                        or <code>--list</code> to preview which configuration
                        files will be modified.
                    </Prose>

                    <Prose>
                        Alternatively, for manual setup, add this configuration
                        block directly to your client's settings file:
                    </Prose>
                    <CodeBlock
                        code={`{
  "mcpServers": {
    "artfct": {
      "command": "artfct",
      "args": ["mcp", "serve"]
  }
}`}
                    />
                    <Prose>
                        To uninstall the CLI binary and remove MCP
                        configurations from all supported client configuration
                        files, run:
                    </Prose>
                    <CodeBlock code="artfct uninstall" />
                    <Prose>
                        Pass <code>--silent</code> to run the uninstallation
                        without interactive prompts.
                    </Prose>

                    {/* ── skills ───────────────────────────────────────────── */}
                    <SectionDivider id="skills" label="skills" />

                    <Prose>
                        Install the artfct skill to give any compatible AI agent
                        (such as Claude Code, Codex, or OpenCode) built-in
                        guidance on when and how to deploy artifacts — tier
                        selection, self-contained HTML authoring, SRI pinning,
                        and error handling.
                    </Prose>

                    <Label>install</Label>
                    <CodeBlock code={SKILLS_INSTALL} />

                    <Prose>
                        Once installed, agents automatically call{' '}
                        <code
                            style={{
                                fontFamily: MONO,
                                fontSize: '12px',
                                color: S.base00,
                            }}
                        >
                            deploy_to_canvas
                        </code>{' '}
                        whenever they produce visual HTML output — dashboards,
                        reports, charts, interactive demos — instead of emitting
                        raw code blocks.
                    </Prose>

                    <Prose>
                        Skills follow the{' '}
                        <a
                            href="https://skills.sh"
                            target="_blank"
                            rel="noreferrer"
                            style={{ color: S.blue, textDecoration: 'none' }}
                        >
                            skills.sh
                        </a>{' '}
                        format and are resolved from the{' '}
                        <code
                            style={{
                                fontFamily: MONO,
                                fontSize: '12px',
                                color: S.base00,
                            }}
                        >
                            skills/artfct/
                        </code>{' '}
                        directory in the{' '}
                        <a
                            href={GITHUB}
                            target="_blank"
                            rel="noreferrer"
                            style={{ color: S.blue, textDecoration: 'none' }}
                        >
                            artfct repo
                        </a>
                        .
                    </Prose>

                    {/* Generated directly from openapi/artfct.yaml. */}
                    <OpenApiReference contract={contract} />
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
                        <Link
                            href="/"
                            style={{ color: S.base1, textDecoration: 'none' }}
                        >
                            home
                        </Link>
                        <Link
                            href="/blog"
                            style={{ color: S.base1, textDecoration: 'none' }}
                        >
                            blog
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
                    <span style={{ color: S.base2 }}>
                        public · secure · ephemeral
                    </span>
                    <a
                        href="#"
                        style={{
                            color: S.base1,
                            textDecoration: 'none',
                        }}
                    >
                        ↑ top
                    </a>
                </footer>
            </div>
        </>
    );
}
