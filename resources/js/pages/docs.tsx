import { Head, Link } from '@inertiajs/react';
import { useCallback, useEffect, useState } from 'react';

import { GITHUB, SitePage } from '@/components/site-chrome';
import { Button } from '@/components/ui/button';
import { login, privacy, terms } from '@/routes';

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

const CLI_AUTH = `# browser sign-in with PKCE
artfct login --oauth

# sign in and pin a workspace
artfct login --oauth --organization acme

# inspect available workspaces
artfct organizations

# check credentials, workspace context, and MCP health
artfct doctor

# revoke the remote session and remove local credentials
artfct logout`;

const MCP_SCOPES = `artifacts:read       search and retrieve safe artifact metadata
artifacts:deploy     deploy artifacts to the workspace
artifacts:delete     delete artifacts when policy permits
collections:read     list workspace collections
collections:write    create collections and add artifacts
usage:read           read customer-safe usage and quota totals`;

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
        note: 'public · secure · ephemeral · permanent  (default: ephemeral; permanent needs an organization token)',
    },
    {
        name: '--ttl-minutes',
        type: 'integer',
        req: false,
        note: 'Minutes until expiry after last access. Default: 7200 (5 days). Max: 525600 (365 days).',
    },
] as const;

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
    servers: Array<{ url: string }>;
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

// ── building blocks ──────────────────────────────────────────────────────────
function Eyebrow({ children }: { children: React.ReactNode }) {
    return (
        <p className="mb-3 text-xs font-bold tracking-[0.08em] text-primary uppercase">
            {children}
        </p>
    );
}

function Section({
    id,
    eyebrow,
    title,
    children,
}: {
    id: string;
    eyebrow: string;
    title: string;
    children: React.ReactNode;
}) {
    return (
        <section id={id} className="scroll-mt-8 border-t border-border py-12">
            <Eyebrow>{eyebrow}</Eyebrow>
            <h2 className="mb-6 max-w-[22ch] font-serif text-[clamp(1.75rem,3vw,2.25rem)] leading-[1.1] tracking-tight text-balance">
                {title}
            </h2>
            <div className="flex flex-col gap-4">{children}</div>
        </section>
    );
}

function SubHeading({ children }: { children: React.ReactNode }) {
    return (
        <h3 className="mt-4 font-serif text-xl font-medium tracking-tight">
            {children}
        </h3>
    );
}

function Prose({ children }: { children: React.ReactNode }) {
    return (
        <p className="max-w-[65ch] leading-relaxed text-muted-foreground">
            {children}
        </p>
    );
}

function Code({ children }: { children: React.ReactNode }) {
    return (
        <code className="rounded bg-muted px-1.5 py-0.5 font-mono text-[0.85em] text-foreground">
            {children}
        </code>
    );
}

function Tag({ children }: { children: React.ReactNode }) {
    return (
        <span className="rounded-[5px] bg-muted px-2 py-0.5 text-xs font-medium text-muted-foreground">
            {children}
        </span>
    );
}

function Label({ children }: { children: React.ReactNode }) {
    return (
        <p className="text-[11px] font-semibold tracking-[0.08em] text-[var(--sol-base1)] uppercase">
            {children}
        </p>
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
        <div className="relative">
            <pre className="overflow-x-auto rounded-[10px] border border-border bg-paper p-4 pr-20 font-mono text-[13px] leading-relaxed text-foreground">
                {code}
            </pre>
            <Button
                type="button"
                onClick={copy}
                className="mt-2 ml-auto block rounded-md border border-border bg-background px-2.5 py-1 text-xs font-semibold text-muted-foreground transition-colors hover:border-primary hover:text-primary sm:absolute sm:top-2.5 sm:right-2.5 sm:mt-0 sm:ml-0"
            >
                {copied ? 'Copied' : 'Copy'}
            </Button>
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
        <div className="overflow-hidden rounded-[10px] border border-border bg-paper">
            <div className="hidden grid-cols-[11rem_9rem_1fr] gap-4 border-b border-border px-4 py-2.5 text-[11px] font-semibold tracking-[0.08em] text-[var(--sol-base1)] uppercase sm:grid">
                <span>Name</span>
                <span>Type</span>
                <span>Description</span>
            </div>
            <ul className="divide-y divide-border">
                {fields.map((field) => (
                    <li
                        key={field.name}
                        className="grid gap-x-4 gap-y-1 px-4 py-3 text-sm sm:grid-cols-[11rem_9rem_1fr]"
                    >
                        <span
                            className={`font-semibold break-words ${
                                field.name.startsWith('-')
                                    ? 'font-mono text-[13px]'
                                    : ''
                            }`}
                        >
                            {field.name}
                            {'req' in field && field.req && (
                                <span className="ml-2 text-[11px] font-semibold text-primary">
                                    Required
                                </span>
                            )}
                        </span>
                        <span className="break-words text-muted-foreground">
                            {field.type}
                        </span>
                        <span className="text-muted-foreground">
                            {field.note}
                        </span>
                    </li>
                ))}
            </ul>
        </div>
    );
}

// ── OpenAPI contract → reference ─────────────────────────────────────────────
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
                    <div
                        key={describeSchema(variant)}
                        className="flex flex-col gap-2"
                    >
                        <p className="text-sm font-semibold">
                            {describeSchema(variant)}
                        </p>
                        <SchemaTable schema={variant} contract={contract} />
                    </div>
                ))}
            </>
        );
    }

    const fields = schemaFields(schema, contract);

    if (fields.length === 0) {
        return <Tag>{describeSchema(schema)}</Tag>;
    }

    return <FieldTable fields={fields} />;
}

function operationsOf(contract: OpenApiDocument) {
    return Object.entries(contract.paths).flatMap(([path, item]) =>
        HTTP_METHODS.flatMap((method) => {
            const operation = item[method];

            return operation
                ? [
                      {
                          method,
                          path,
                          item,
                          operation,
                          id: operation.operationId ?? `${method}-${path}`,
                      },
                  ]
                : [];
        }),
    );
}

function MethodTag({ method }: { method: string }) {
    return (
        <span className="rounded-[5px] bg-primary/10 px-2 py-0.5 text-[11px] font-bold tracking-wide text-primary uppercase">
            {method}
        </span>
    );
}

function OpenApiReference({ contract }: { contract: OpenApiDocument }) {
    return (
        <Section id="rest-api" eyebrow="API reference" title="The REST API">
            <div className="flex flex-wrap gap-2">
                <Tag>
                    Base URL{' '}
                    {contract.servers?.[0]?.url ?? 'https://artfct.dev'}
                </Tag>
                <Tag>OpenAPI {contract.openapi}</Tag>
                <Tag>Version {contract.info.version}</Tag>
            </div>

            {contract.info.description && (
                <Prose>{contract.info.description}</Prose>
            )}

            <div className="mt-4 flex flex-col">
                {operationsOf(contract).map(
                    ({ method, path, item, operation, id }) => {
                        const request =
                            operation.requestBody?.content?.[
                                'application/json'
                            ];
                        const status =
                            operation['x-status'] ?? item['x-status'];
                        const security = (operation.security ?? [])
                            .flatMap((requirement) => Object.keys(requirement))
                            .join(' · ');

                        return (
                            <article
                                key={id}
                                id={id}
                                className="flex scroll-mt-8 flex-col gap-4 border-t border-border py-10 first:border-t-0 first:pt-2"
                            >
                                <div className="flex flex-wrap items-center gap-3">
                                    <MethodTag method={method} />
                                    <h3 className="text-base font-semibold break-all">
                                        {path}
                                    </h3>
                                    <span className="ml-auto flex gap-2">
                                        <Tag>{status ?? 'Implemented'}</Tag>
                                        <Tag>
                                            {security || 'No sign-in needed'}
                                        </Tag>
                                    </span>
                                </div>

                                {operation.summary && (
                                    <p className="font-serif text-xl tracking-tight">
                                        {operation.summary}
                                    </p>
                                )}
                                {operation.description && (
                                    <Prose>{operation.description}</Prose>
                                )}

                                {request?.schema && (
                                    <>
                                        <Label>Request body</Label>
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

                                <Label>Responses</Label>
                                <FieldTable
                                    fields={Object.entries(
                                        operation.responses ?? {},
                                    ).map(([code, response]) => {
                                        const responseMedia =
                                            response.content?.[
                                                'application/json'
                                            ] ??
                                            response.content?.['text/html'];

                                        return {
                                            name: code,
                                            type: responseMedia?.schema
                                                ? describeSchema(
                                                      responseMedia.schema,
                                                  )
                                                : 'Empty',
                                            note: response.description ?? '',
                                        };
                                    })}
                                />
                            </article>
                        );
                    },
                )}
            </div>
        </Section>
    );
}

// ── navigation ───────────────────────────────────────────────────────────────
/** Highlights the sidebar entry for the section currently in view. */
function useActiveSection(ids: string[]): string {
    const [active, setActive] = useState(ids[0] ?? '');

    useEffect(() => {
        const observer = new IntersectionObserver(
            (entries) => {
                const visible = entries.find((entry) => entry.isIntersecting);

                if (visible) {
                    setActive(visible.target.id);
                }
            },
            { rootMargin: '-10% 0px -75% 0px' },
        );

        ids.forEach((id) => {
            const element = document.getElementById(id);

            if (element) {
                observer.observe(element);
            }
        });

        return () => observer.disconnect();
    }, [ids]);

    return active;
}

type SidebarGroup = {
    title: string;
    items: Array<{ id: string; label: string; method?: string }>;
};

function Sidebar({
    groups,
    active,
}: {
    groups: SidebarGroup[];
    active: string;
}) {
    return (
        <nav aria-label="On this page" className="flex flex-col gap-8 text-sm">
            {groups.map((group) => (
                <div key={group.title}>
                    <p className="mb-3 text-[11px] font-bold tracking-[0.08em] text-[var(--sol-base1)] uppercase">
                        {group.title}
                    </p>
                    <ul className="flex flex-col border-l border-border">
                        {group.items.map((item) => (
                            <li key={item.id}>
                                <a
                                    href={`#${item.id}`}
                                    className={`-ml-px flex items-baseline gap-2 border-l-2 py-1.5 pl-4 transition-colors hover:text-foreground ${
                                        active === item.id
                                            ? 'border-primary font-semibold text-foreground'
                                            : 'border-transparent text-muted-foreground'
                                    }`}
                                >
                                    {item.method && (
                                        <span className="w-9 shrink-0 text-[10px] font-bold tracking-wide text-primary uppercase">
                                            {item.method}
                                        </span>
                                    )}
                                    <span>{item.label}</span>
                                </a>
                            </li>
                        ))}
                    </ul>
                </div>
            ))}
        </nav>
    );
}

// ── page ─────────────────────────────────────────────────────────────────────
export default function Docs({ contract }: DocsProps) {
    const hostedMcpBaseUrl = contract.servers[0].url;
    const hostedMcpUrl = `${hostedMcpBaseUrl}/mcp`;
    const hostedMcpConfiguration = `MCP endpoint:
${hostedMcpUrl}

OAuth protected-resource metadata:
${hostedMcpBaseUrl}/.well-known/oauth-protected-resource

OAuth authorization-server metadata:
${hostedMcpBaseUrl}/.well-known/oauth-authorization-server`;
    const hostedMcpJsonConfig = JSON.stringify(
        { mcpServers: { artfct: { url: hostedMcpUrl } } },
        null,
        2,
    );
    const groups: SidebarGroup[] = [
        {
            title: 'Get started',
            items: [
                { id: 'cli', label: 'Command line' },
                { id: 'mcp', label: 'MCP server' },
                { id: 'skills', label: 'Skills' },
            ],
        },
        {
            title: 'API reference',
            items: [
                { id: 'rest-api', label: 'Overview' },
                ...operationsOf(contract).map((operation) => ({
                    id: operation.id,
                    label: operation.operation.summary ?? operation.path,
                    method: operation.method,
                })),
            ],
        },
    ];
    const active = useActiveSection(
        groups.flatMap((group) => group.items.map((item) => item.id)),
    );

    return (
        <SitePage active="docs">
            <Head title="Documentation">
                <meta
                    name="description"
                    content="Connect your AI tools to Artfct and build on it: the command line, MCP server, skills and REST API."
                />
            </Head>

            <div className="mx-auto grid max-w-[1120px] gap-14 px-5 py-14 lg:grid-cols-[240px_minmax(0,1fr)]">
                <aside className="hidden lg:block">
                    <div className="sticky top-8 max-h-[calc(100vh-4rem)] overflow-y-auto pr-2">
                        <Sidebar groups={groups} active={active} />
                    </div>
                </aside>

                <main className="min-w-0">
                    <header className="pb-12">
                        <Eyebrow>Documentation</Eyebrow>
                        <h1 className="max-w-[16ch]">
                            Build with <em className="text-primary">Artfct</em>
                        </h1>
                        <p className="mt-5 max-w-[52ch] text-lg text-muted-foreground">
                            Connect your AI tools, and share and read artifacts
                            from your own code: the command line, the MCP
                            server, skills and the REST API.
                        </p>
                        <details className="mt-8 rounded-[10px] border border-border bg-paper px-4 py-3 text-sm lg:hidden">
                            <summary className="cursor-pointer font-semibold">
                                On this page
                            </summary>
                            <div className="pt-4">
                                <Sidebar groups={groups} active={active} />
                            </div>
                        </details>
                    </header>

                    <Section
                        id="cli"
                        eyebrow="Get started"
                        title="The Artfct command line"
                    >
                        <Prose>
                            The <Code>artfct</Code> command deploys HTML files
                            from your terminal and from pipes. Pre-built
                            binaries are available for macOS and Linux, with no
                            runtime required.
                        </Prose>

                        <SubHeading>Install</SubHeading>
                        <Prose>
                            Works on macOS (Apple Silicon and Intel) and Linux
                            (x86_64 and ARM64). Installs to{' '}
                            <Code>~/.local/bin</Code> by default. If your shell
                            cannot find <Code>artfct</Code> afterwards, add that
                            folder to your <Code>PATH</Code> and open a new
                            terminal.
                        </Prose>
                        <CodeBlock code={CLI_INSTALL} />
                        <CodeBlock code={CLI_INSTALL_OPTS} />

                        <SubHeading>Usage</SubHeading>
                        <CodeBlock code={CLI_USAGE} />

                        <SubHeading>Deploy options</SubHeading>
                        <FieldTable fields={CLI_DEPLOY_FLAGS} />
                    </Section>

                    <Section
                        id="mcp"
                        eyebrow="Get started"
                        title="Use Artfct from your AI tool"
                    >
                        <Prose>
                            Start Artfct as a local MCP server over stdio. It
                            publishes to your workspace with the{' '}
                            <Code>deploy_artifact</Code> tool, so Claude
                            Desktop, Claude Code, Cursor and other
                            MCP-compatible tools can publish HTML without
                            leaving the session.
                            <Code>deploy_to_canvas</Code> is deprecated; use{' '}
                            <Code>deploy_artifact</Code> instead.
                        </Prose>
                        <CodeBlock code={CLI_MCP} />

                        <SubHeading>Set it up automatically</SubHeading>
                        <Prose>
                            To configure the MCP server for every detected tool
                            (Cursor, Claude Desktop, Gemini, Codex and more),
                            run:
                        </Prose>
                        <CodeBlock code="artfct setup" />
                        <Prose>
                            Pass <Code>--silent</Code> to skip the prompts, or{' '}
                            <Code>--list</Code> to preview which configuration
                            files will change.
                        </Prose>

                        <SubHeading>Set it up by hand</SubHeading>
                        <Prose>
                            Add this block to your client&apos;s settings file.
                            Use <Code>cursor</Code> or <Code>claude-code</Code>{' '}
                            (or your tool&apos;s name) after <Code>--host</Code>{' '}
                            so Artfct knows which tool is calling:
                        </Prose>
                        <CodeBlock
                            code={`{
  "mcpServers": {
    "artfct": {
      "command": "artfct",
      "args": ["mcp", "serve", "--host", "cursor"]
    }
  }
}`}
                        />

                        <SubHeading>Sign in from the command line</SubHeading>
                        <Prose>
                            Local stdio and hosted Streamable HTTP use the same
                            organization-scoped tool catalog. Authenticate in a
                            browser with OAuth and PKCE. Tokens are stored in
                            the platform credential store when available; the
                            CLI never writes them to agent config.
                        </Prose>
                        <Prose>
                            In the web app, an organization is called a team;
                            the CLI and OAuth protocol also call it a workspace.
                            They mean the same boundary for members, artifacts,
                            collections, and access. Sign in at{' '}
                            <Link href={login.url()}>sign-in page</Link>. If you
                            have not joined a team, Artfct asks you to create
                            your first one. To join an existing team, ask its
                            administrator to invite the email address you use to
                            sign in. Hosted MCP uses browser sign-in, so you do
                            not need to create an API token. For automation, an
                            administrator can create one in team settings under{' '}
                            <Code>API tokens</Code>; pass it as{' '}
                            <Code>ARTFCT_ORG_TOKEN</Code> through your secret
                            manager.
                        </Prose>
                        <CodeBlock code={CLI_AUTH} />
                        <Prose>
                            <Code>doctor</Code> reports the selected
                            organization, available organizations and hosted MCP
                            initialize and tool health without printing
                            credentials. Example of a healthy result (your
                            organization and numbers will differ):
                        </Prose>
                        <CodeBlock
                            code={`Diagnostics
  Saved credential    configured (value hidden)

Organization context
  Selected: acme · admin
  Available organizations: acme

Hosted MCP health
  Connected: artfct · protocol 2025-11-25 · 10 tools`}
                        />
                        <Prose>
                            A saved sign-in that is missing or invalid shows as{' '}
                            <Code>not configured</Code> or <Code>invalid</Code>{' '}
                            with <Code>run `artfct login`</Code> next to it. An
                            expired or revoked sign-in shows{' '}
                            <Code>MCP health check failed</Code> and tells you
                            to run <Code>artfct login --oauth</Code>.
                        </Prose>

                        <SubHeading>Use the hosted server</SubHeading>
                        <Prose>
                            For clients that support OAuth discovery, add the
                            endpoint below. The client opens browser consent and
                            requests only the scopes it needs; no bearer token
                            needs to be copied into configuration. These URLs
                            point to the environment serving this page; keep
                            each client on the same environment. See the runbook
                            for staging verification steps. The public{' '}
                            <Link href={terms.url()}>terms</Link> and{' '}
                            <Link href={privacy.url()}>privacy policy</Link>{' '}
                            explain the service and its data handling.
                        </Prose>
                        <CodeBlock code={hostedMcpConfiguration} />
                        <Prose>
                            In Claude Code, add it with the command below, then
                            run <Code>/mcp</Code> inside Claude Code to sign in.
                            In Cursor, add the same address to{' '}
                            <Code>~/.cursor/mcp.json</Code> (or{' '}
                            <Code>.cursor/mcp.json</Code> in a project) and
                            approve the browser consent when it opens.
                        </Prose>
                        <CodeBlock
                            code={`claude mcp add --transport http artfct ${hostedMcpUrl}`}
                        />
                        <CodeBlock code={hostedMcpJsonConfig} />

                        <SubHeading>Scopes</SubHeading>
                        <CodeBlock code={MCP_SCOPES} />
                        <Prose>
                            If a connection expires or is revoked, sign in again
                            for the intended workspace, then run{' '}
                            <Code>artfct doctor</Code> to verify recovery.
                            Workspace administrators can revoke hosted
                            connections in your workspace settings under{' '}
                            <Code>MCP connections</Code>.{' '}
                            <Code>artfct logout</Code> asks the server to end
                            your saved sign-in and then removes your local
                            credentials; if the server cannot be reached it
                            warns you and still removes them.
                        </Prose>
                        <Prose>
                            Hosted-client sign-in is separate from the local CLI
                            session. In Claude Code, open <Code>/mcp</Code>,
                            choose <Code>artfct</Code>, and select{' '}
                            <Code>Reconnect</Code> to repeat browser sign-in;
                            check the result with{' '}
                            <Code>claude mcp get artfct</Code>. In Cursor, use
                            its MCP panel to reconnect, or run{' '}
                            <Code>cursor-agent mcp login artfct</Code> and{' '}
                            <Code>cursor-agent mcp list</Code> in Cursor CLI.
                            These client steps repair hosted OAuth;{' '}
                            <Code>artfct login</Code> signs in the local CLI.
                            See the{' '}
                            <a
                                href="https://code.claude.com/docs/en/mcp"
                                target="_blank"
                                rel="noreferrer"
                            >
                                Claude Code MCP guide
                            </a>{' '}
                            and{' '}
                            <a
                                href="https://docs.cursor.com/en/cli/reference/parameters"
                                target="_blank"
                                rel="noreferrer"
                            >
                                Cursor CLI reference
                            </a>{' '}
                            for client details.
                        </Prose>

                        <SubHeading>Limits and data kept</SubHeading>
                        <Prose>
                            Hosted requests are limited to 120 per minute for
                            each workspace and, separately, 120 per minute for
                            each connection. A limited request returns status{' '}
                            <Code>429</Code> with a <Code>Retry-After</Code>{' '}
                            header; wait that long before trying again. Records
                            of MCP activity are kept for 90 days by default and
                            then removed automatically. Workspace administrators
                            can remove a connection at any time in workspace
                            settings under <Code>MCP connections</Code>.
                        </Prose>

                        <SubHeading>Remove it</SubHeading>
                        <Prose>
                            To uninstall the binary and remove the MCP
                            configuration from every supported client, run:
                        </Prose>
                        <CodeBlock code="artfct uninstall" />
                        <Prose>
                            Pass <Code>--silent</Code> to skip the prompts.
                        </Prose>
                    </Section>

                    <Section
                        id="skills"
                        eyebrow="Get started"
                        title="Skills for your AI tools"
                    >
                        <Prose>
                            Install the Artfct skill to give any compatible AI
                            tool (such as Claude Code, Codex or OpenCode)
                            built-in guidance on when and how to deploy
                            artifacts: choosing a tier, writing self-contained
                            HTML, pinning scripts and handling errors.
                        </Prose>
                        <CodeBlock code={SKILLS_INSTALL} />
                        <Prose>
                            Once installed, your AI tool calls{' '}
                            <Code>deploy_artifact</Code> whenever it produces
                            visual HTML such as a dashboard, report, chart or
                            interactive demo, instead of printing raw code.
                        </Prose>
                        <Prose>
                            Skills follow the{' '}
                            <a
                                href="https://skills.sh"
                                target="_blank"
                                rel="noreferrer"
                                className="font-semibold text-primary underline-offset-4 hover:underline"
                            >
                                skills.sh
                            </a>{' '}
                            format and are resolved from the{' '}
                            <Code>skills/artfct/</Code> directory in the{' '}
                            <a
                                href={GITHUB}
                                target="_blank"
                                rel="noreferrer"
                                className="font-semibold text-primary underline-offset-4 hover:underline"
                            >
                                Artfct repository
                            </a>
                            .
                        </Prose>
                    </Section>

                    {/* Generated directly from openapi/artfct.yaml. */}
                    <OpenApiReference contract={contract} />
                </main>
            </div>
        </SitePage>
    );
}
