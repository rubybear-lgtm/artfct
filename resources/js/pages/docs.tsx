import { Head, Link } from '@inertiajs/react';
import {
    useCallback,
    useEffect,
    useRef,
    useState,
    useSyncExternalStore,
} from 'react';
import type { KeyboardEvent } from 'react';

import { GITHUB, SitePage } from '@/components/site-chrome';
import { Button } from '@/components/ui/button';
import { login, privacy, terms } from '@/routes';

// ── AI tool setup (from App\\Support\\AiToolSetup) ─────────────────────────
type SetupStep = { text: string; code?: string };

type ConnectionGuide = {
    id: string;
    label: string;
    verified: boolean;
    intro?: string;
    steps: SetupStep[];
};

type AiToolSetup = {
    mcpUrl: string;
    llmsUrl: string;
    llmsFullUrl: string;
    quickInstall: string;
    guides: ConnectionGuide[];
    tools: Array<{ name: string; does: string; permission: string }>;
    permissions: Array<{ name: string; type: string; note: string }>;
    troubleshooting: Array<{ name: string; type: string; note: string }>;
    skills: Array<{ name: string; install: string; note: string }>;
    projectInstructions: string;
};

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
    setup: AiToolSetup;
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

function SubHeading({
    id,
    children,
}: {
    id?: string;
    children: React.ReactNode;
}) {
    return (
        <h3
            id={id}
            className="mt-4 scroll-mt-8 font-serif text-xl font-medium tracking-tight"
        >
            {children}
        </h3>
    );
}

function Prose({ children }: { children: React.ReactNode }) {
    return (
        <p className="max-w-[65ch] leading-relaxed break-words text-muted-foreground">
            {children}
        </p>
    );
}

function Code({ children }: { children: React.ReactNode }) {
    return (
        <code className="rounded bg-muted px-1.5 py-0.5 font-mono text-[0.85em] break-words text-foreground">
            {children}
        </code>
    );
}

function Tag({ children }: { children: React.ReactNode }) {
    return (
        <span className="max-w-full min-w-0 rounded-[5px] bg-muted px-2 py-0.5 text-xs font-medium break-words text-muted-foreground">
            {children}
        </span>
    );
}

function Label({ children }: { children: React.ReactNode }) {
    return (
        <p className="text-[11px] font-semibold tracking-[0.08em] text-muted-foreground uppercase">
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
    headings = ['Name', 'Type', 'Description'],
}: {
    fields: ReadonlyArray<{
        name: string;
        type: string;
        req?: boolean;
        note: string;
    }>;
    headings?: [string, string, string];
}) {
    return (
        <div className="overflow-hidden rounded-[10px] border border-border bg-paper">
            <div className="hidden grid-cols-[11rem_9rem_1fr] gap-4 border-b border-border px-4 py-2.5 text-[11px] font-semibold tracking-[0.08em] text-muted-foreground uppercase sm:grid">
                {headings.map((heading) => (
                    <span key={heading}>{heading}</span>
                ))}
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
                        <span className="break-words text-muted-foreground">
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
                    <p className="mb-3 text-[11px] font-bold tracking-[0.08em] text-muted-foreground uppercase">
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

// ── connecting an AI tool ────────────────────────────────────────────────────
function Steps({ children }: { children: React.ReactNode }) {
    return (
        <ol className="flex list-decimal flex-col gap-4 pl-5 marker:font-semibold marker:text-primary">
            {children}
        </ol>
    );
}

function Step({ children }: { children: React.ReactNode }) {
    return (
        <li className="pl-1 leading-relaxed text-muted-foreground">
            <div className="flex flex-col gap-3">{children}</div>
        </li>
    );
}

/** Renders `backticked` spans in setup text as inline code. */
function Inline({ text }: { text: string }) {
    return (
        <>
            {text
                .split(/(`[^`]+`)/)
                .map((part, index) =>
                    part.startsWith('`') && part.endsWith('`') ? (
                        <Code key={index}>{part.slice(1, -1)}</Code>
                    ) : (
                        part
                    ),
                )}
        </>
    );
}

function GuideSteps({ guide }: { guide: ConnectionGuide }) {
    return (
        <div className="flex flex-col gap-4">
            {!guide.verified && (
                <div className="flex flex-wrap gap-2">
                    <Tag>Not yet tested by us</Tag>
                </div>
            )}
            {guide.intro && (
                <Prose>
                    <Inline text={guide.intro} />
                </Prose>
            )}
            <Steps>
                {guide.steps.map((step) => (
                    <Step key={step.text}>
                        <span>
                            <Inline text={step.text} />
                        </span>
                        {step.code && <CodeBlock code={step.code} />}
                    </Step>
                ))}
            </Steps>
            {!guide.verified && (
                <Prose>
                    If these steps do not match what you see, or the tool does
                    not connect,{' '}
                    <a
                        href={`${GITHUB}/issues`}
                        target="_blank"
                        rel="noreferrer"
                    >
                        tell us
                    </a>
                    .
                </Prose>
            )}
        </div>
    );
}

/** Lets other pages link straight to a tool, e.g. /docs#connect-codex. */
function subscribeToHash(onChange: () => void): () => void {
    window.addEventListener('hashchange', onChange);

    return () => window.removeEventListener('hashchange', onChange);
}

function readHash(): string {
    return window.location.hash.slice(1);
}

function ConnectionTabs({ guides }: { guides: ConnectionGuide[] }) {
    const hash = useSyncExternalStore(subscribeToHash, readHash, () => '');
    const [selected, setSelected] = useState<string | null>(null);
    const tablist = useRef<HTMLDivElement>(null);
    const current =
        guides.find((guide) => guide.id === (selected ?? hash)) ?? guides[0];

    /**
     * Keeps the URL hash on the visible tab so a copied link opens the same
     * tool. replaceState does not fire hashchange, so `selected` still wins.
     */
    const select = (id: string) => {
        setSelected(id);
        window.history.replaceState(null, '', `#${id}`);
    };

    /** Roving tabIndex: focus the newly selected tab, wrapping at both ends. */
    const onKeyDown = (event: KeyboardEvent<HTMLDivElement>) => {
        const index = guides.findIndex((guide) => guide.id === current.id);
        const last = guides.length - 1;
        let next: number;

        switch (event.key) {
            case 'ArrowRight':
                next = index === last ? 0 : index + 1;
                break;
            case 'ArrowLeft':
                next = index <= 0 ? last : index - 1;
                break;
            case 'Home':
                next = 0;
                break;
            case 'End':
                next = last;
                break;
            default:
                return;
        }

        event.preventDefault();
        select(guides[next].id);
        tablist.current
            ?.querySelectorAll<HTMLButtonElement>('[role="tab"]')
            [next]?.focus();
    };

    return (
        <div className="flex flex-col gap-6">
            <div
                ref={tablist}
                role="tablist"
                aria-label="AI tool"
                onKeyDown={onKeyDown}
                className="flex flex-wrap gap-x-6 gap-y-2 border-b border-border"
            >
                {guides.map((guide) => (
                    <button
                        key={guide.id}
                        id={guide.id}
                        type="button"
                        role="tab"
                        aria-selected={guide.id === current.id}
                        aria-controls={`${guide.id}-panel`}
                        tabIndex={guide.id === current.id ? 0 : -1}
                        onClick={() => select(guide.id)}
                        className={`-mb-px scroll-mt-8 border-b-2 pb-2.5 text-sm font-semibold transition-colors ${
                            guide.id === current.id
                                ? 'border-primary text-foreground'
                                : 'border-transparent text-muted-foreground hover:text-foreground'
                        }`}
                    >
                        {guide.label}
                    </button>
                ))}
            </div>
            <div
                id={`${current.id}-panel`}
                role="tabpanel"
                aria-labelledby={current.id}
            >
                <GuideSteps guide={current} />
            </div>
        </div>
    );
}

// ── page ─────────────────────────────────────────────────────────────────────
export default function Docs({ contract, setup }: DocsProps) {
    const groups: SidebarGroup[] = [
        {
            title: 'Get started',
            items: [
                { id: 'mcp', label: 'Connect your AI tool' },
                { id: 'connect-quick', label: 'Set up several tools' },
                { id: 'connect-check', label: 'Check that it works' },
                { id: 'connect-tools', label: 'What your AI tool can do' },
                { id: 'connect-troubleshooting', label: 'Troubleshooting' },
            ],
        },
        {
            title: 'Go further',
            items: [
                { id: 'instructions', label: 'Project instructions' },
                { id: 'skills', label: 'Skills' },
                { id: 'llms', label: 'Docs for AI tools' },
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
                    content="Connect Claude, ChatGPT, Cursor, Codex and other AI tools to Artfct in about a minute, then use skills and the REST API."
                />
                <link
                    rel="alternate"
                    type="text/plain"
                    title="Artfct docs for AI tools"
                    href={setup.llmsFullUrl}
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
                            Connect your AI tool so it can publish to your team
                            and read what is already there, then go further with
                            skills and the REST API.
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
                        id="mcp"
                        eyebrow="Get started"
                        title="Connect your AI tool"
                    >
                        <Prose>
                            Connect once and your AI tool can publish to your
                            team and find what is already there, with sources.
                            Artfct runs online, so there is no key to copy: you
                            add one address and approve a sign-in in your
                            browser. It takes about a minute.
                        </Prose>

                        <SubHeading>1. Copy your Artfct address</SubHeading>
                        <CodeBlock code={setup.mcpUrl} />
                        <Prose>
                            This address belongs to the site you are reading
                            now, so use this one on every tool you connect.
                        </Prose>

                        <SubHeading>2. Add it to your AI tool</SubHeading>
                        <Prose>
                            Choose your tool. We have tested the first four from
                            sign-in to a finished request; the rest follow each
                            tool’s own instructions.
                        </Prose>
                        <ConnectionTabs guides={setup.guides} />

                        <SubHeading id="connect-quick">
                            Or set up several tools at once
                        </SubHeading>
                        <Prose>
                            If you use AI tools on your computer, this command
                            finds the ones you have (Claude Code, Codex, Cursor,
                            OpenCode, VS Code, Windsurf, Zed, Antigravity and
                            more) and adds Artfct to each one you pick. It needs{' '}
                            <a
                                href="https://nodejs.org"
                                target="_blank"
                                rel="noreferrer"
                            >
                                Node.js
                            </a>{' '}
                            and uses the open-source{' '}
                            <a
                                href="https://add-mcp.com"
                                target="_blank"
                                rel="noreferrer"
                            >
                                add-mcp
                            </a>{' '}
                            tool.
                        </Prose>
                        <CodeBlock code={setup.quickInstall} />
                        <Prose>
                            Each tool still asks you to sign in the first time
                            you use it; follow the last steps for your tool
                            above. Antigravity also needs{' '}
                            <Code>"oauth": {'{}'}</Code> added to its entry. The
                            Claude app and ChatGPT are set up in their own
                            settings, not with this command.
                        </Prose>

                        <SubHeading id="connect-check">
                            3. Check that it works
                        </SubHeading>
                        <Prose>
                            Ask your AI tool:{' '}
                            <em>“Which Artfct workspace am I connected to?”</em>{' '}
                            It should answer with your team’s name. From then
                            on, ask it to publish what it makes to Artfct, or to
                            search Artfct for something your team already
                            shared.
                        </Prose>
                    </Section>

                    <Section
                        id="connect-tools"
                        eyebrow="Get started"
                        title="What your AI tool can do"
                    >
                        <Prose>
                            Once connected, your AI tool can use these actions.
                            You do not call them yourself: ask in your own words
                            and the tool picks the right one.
                        </Prose>
                        <FieldTable
                            headings={['Action', 'Needs', 'What it does']}
                            fields={setup.tools.map((tool) => ({
                                name: tool.name,
                                type: tool.permission,
                                note: tool.does,
                            }))}
                        />

                        <SubHeading>Permissions</SubHeading>
                        <Prose>
                            During sign-in, your tool asks for the permissions
                            it needs and you approve them. Each connection only
                            reaches the team you signed in to.
                        </Prose>
                        <FieldTable
                            headings={['Permission', 'Allows', 'Details']}
                            fields={setup.permissions}
                        />

                        <SubHeading>Limits and data kept</SubHeading>
                        <Prose>
                            Each team can make 120 requests per minute, and so
                            can each connection. Past that, your tool is asked
                            to wait (status <Code>429</Code>, with a{' '}
                            <Code>Retry-After</Code> header saying how long).
                            Records of what connected tools did are kept for 90
                            days by default and then removed automatically.
                        </Prose>

                        <SubHeading>Teams and sign-in</SubHeading>
                        <Prose>
                            In the web app your organization is called a team;
                            your AI tool may also call it a workspace. Both mean
                            the same group of people, artifacts and collections.
                            Sign in on the{' '}
                            <Link href={login.url()}>sign-in page</Link>. If you
                            have not joined a team yet, Artfct asks you to
                            create your first one; to join an existing team, ask
                            its administrator to invite the email address you
                            sign in with. Administrators can see and remove
                            connected tools in team settings under{' '}
                            <strong>AI tool connections</strong>. Connecting an
                            AI tool never needs an API token; tokens are only
                            for your own code calling the REST API below, which
                            sends one as a bearer token. The{' '}
                            <Link href={terms.url()}>terms</Link> and{' '}
                            <Link href={privacy.url()}>privacy policy</Link>{' '}
                            explain how your data is handled.
                        </Prose>
                    </Section>

                    <Section
                        id="connect-troubleshooting"
                        eyebrow="Get started"
                        title="If something goes wrong"
                    >
                        <FieldTable
                            headings={['Problem', 'Fix', 'How']}
                            fields={setup.troubleshooting}
                        />
                        <Prose>
                            If you used the old Artfct command-line app, remove
                            its <Code>artfct</Code> entry before adding the
                            address above, for example with{' '}
                            <Code>claude mcp remove artfct</Code> or{' '}
                            <Code>codex mcp remove artfct</Code>. The
                            command-line app has been retired.
                        </Prose>
                    </Section>

                    <Section
                        id="instructions"
                        eyebrow="Go further"
                        title="Tell your AI tool when to use Artfct"
                    >
                        <Prose>
                            Many AI tools read a project instructions file
                            before they start: <Code>AGENTS.md</Code>,{' '}
                            <Code>CLAUDE.md</Code> or Cursor rules. Paste this
                            in so your tool checks what your team already made
                            and saves what is worth keeping, without being asked
                            each time.
                        </Prose>
                        <CodeBlock code={setup.projectInstructions} />
                    </Section>

                    <Section
                        id="skills"
                        eyebrow="Go further"
                        title="Skills for your AI tools"
                    >
                        <Prose>
                            Skills give an AI tool that supports them (such as
                            Claude Code, Codex or OpenCode) built-in guidance
                            for a kind of task. The Artfct skill teaches your
                            tool when and how to publish:
                        </Prose>
                        {setup.skills.map((skill) => (
                            <div
                                key={skill.name}
                                className="flex flex-col gap-2"
                            >
                                <p className="text-sm">
                                    <strong>{skill.name}</strong>{' '}
                                    <span className="text-muted-foreground">
                                        {skill.note}
                                    </span>
                                </p>
                                <CodeBlock code={skill.install} />
                            </div>
                        ))}
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

                    <Section
                        id="llms"
                        eyebrow="Go further"
                        title="Docs your AI tool can read"
                    >
                        <Prose>
                            These docs are also published as plain text, so you
                            can hand them to an AI tool and ask it to set up
                            Artfct or explain a step.
                        </Prose>
                        <FieldTable
                            headings={['Address', 'Contains', 'Use it for']}
                            fields={[
                                {
                                    name: setup.llmsUrl,
                                    type: 'A short index',
                                    note: 'A quick overview with links to each part of the docs.',
                                },
                                {
                                    name: setup.llmsFullUrl,
                                    type: 'Everything',
                                    note: 'Every setup guide, action, permission and troubleshooting step, plus a summary of the REST API, in one file.',
                                },
                            ]}
                        />
                    </Section>

                    {/* Generated directly from openapi/artfct.yaml. */}
                    <OpenApiReference contract={contract} />
                </main>
            </div>
        </SitePage>
    );
}
