import { Head, Link, router } from '@inertiajs/react';
import {
    ArrowLeft,
    ExternalLink,
    Maximize,
    PanelRightClose,
    PanelRightOpen,
} from 'lucide-react';
import { useEffect, useRef, useState, useSyncExternalStore } from 'react';

import ArtifactComments from '@/components/artifact-comments';
import type { ArtifactComment } from '@/components/artifact-comments';
import ArtifactShareControl from '@/components/artifact-share-control';
import type {
    ArtifactEditAccess,
    ArtifactSharing,
} from '@/components/artifact-share-control';
import { Button } from '@/components/ui/button';
import ViewerLayout from '@/layouts/viewer-layout';
import { useLocalDate } from '@/lib/local-date';
import { home, login } from '@/routes';
import artifacts from '@/routes/artifacts';
import consoleRoutes from '@/routes/console';

interface VersionRow {
    number: number;
    created_at: string | null;
    current: boolean;
}

interface Props {
    /** True when the page is the Worker's public read, not a signed-in member's own artifact. */
    publicView: boolean;
    /** True when a signed-out visitor may ask to sign in and edit a public artifact. */
    canSignInToEdit: boolean;
    team: { slug: string; name: string; publicSharingAllowed: boolean };
    artifact: {
        id: string;
        title: string | null;
        description: string | null;
        sharing: ArtifactSharing | null;
        editAccess: ArtifactEditAccess | null;
        canEdit: boolean;
        canChangeSharing: boolean;
        ownerName: string | null;
        updatedAt: string | null;
        version: number;
        versionCount: number;
    };
    selectedVersion: number | null;
    versions?: VersionRow[];
    frameUrl: string;
    viewerUrl: string;
    openUrl: string;
    /** Null when no download is offered — the public viewer. */
    downloadUrl: string | null;
    /** True for a slide deck, which gets a Present (full screen) action. */
    isSlideDeck: boolean;
    /** A signed-in member's discussion; absent in the public viewer. */
    comments?: ArtifactComment[];
}

/** Nothing to subscribe to: whether the browser offers full screen does not change while the page is open. */
function subscribeToNothing(): () => void {
    return () => undefined;
}

/** False on the server and on browsers without the Fullscreen API, so the first render matches the server's. */
function browserCanPresent(): boolean {
    return (
        document.fullscreenEnabled === true &&
        typeof HTMLElement.prototype.requestFullscreen === 'function'
    );
}

/** The name a person reads: the title, or a short id when it has none. */
function artifactName(title: string | null, id: string): string {
    return title && title !== '' ? title : id.slice(0, 8);
}

function versionLabel(version: number, count: number): string {
    return count > 1 ? `Version ${version} of ${count}` : `Version ${version}`;
}

/** The freshness poll verdict: what the viewer compares against its props. */
interface Freshness {
    version: number | null;
    sharing: ArtifactSharing | null;
    revoked: boolean;
}

/** The only fragments forwarded to the artifact frame: a plain anchor id. */
const FRAME_FRAGMENT = /^#[A-Za-z0-9_.-]{1,128}$/;

/** 45s between polls, ±10s so a fleet of open tabs does not poll in lockstep. */
const FRESHNESS_INTERVAL_MS = 45_000;
const FRESHNESS_JITTER_MS = 10_000;
export default function ArtifactShow({
    publicView,
    canSignInToEdit,
    team,
    artifact,
    selectedVersion,
    versions,
    frameUrl,
    viewerUrl,
    openUrl,
    downloadUrl,
    isSlideDeck,
    comments,
}: Props) {
    const name = artifactName(artifact.title, artifact.id);
    const tooltip = artifact.description
        ? `${name} — ${artifact.description}`
        : name;
    const shownVersion = selectedVersion ?? artifact.version;
    const updated = useLocalDate(artifact.updatedAt);
    const meta = [
        versionLabel(shownVersion, artifact.versionCount),
        artifact.ownerName,
        updated ? `Updated ${updated}` : null,
    ].filter((part): part is string => part !== null);

    const selectVersion = (version: number) => {
        router.get(
            artifacts.version.url({
                artifactId: artifact.id,
                version,
            }),
            {},
            { preserveScroll: true },
        );
    };

    /*
     * The freshness poll: every 45s (±10s jitter) while the tab is visible,
     * plus once on return from hidden/background, the shell asks the server
     * for the current (version, sharing) and compares against these props.
     * A change on the live view reloads through the server — never by
     * resetting the iframe src, whose ?token= may have expired — while a
     * pinned older version only gets a banner, and a revoked artifact
     * unmounts the frame and stops polling. The poll is ~50 bytes of JSON
     * against one D1 row; the reload it avoids refetches the whole bundle.
     */
    const [revokedView, setRevokedView] = useState(false);
    const [newerAvailable, setNewerAvailable] = useState(false);
    const knownRef = useRef(false);

    /*
     * A link to one part of an artifact is the viewer URL plus a fragment,
     * for example `/a/{id}#option-b`. The fragment never reaches the artifact
     * on its own, because the page runs in this sandboxed frame on another
     * origin, so it is read once here and added to the frame's src. It is read
     * after mount, not during render: the server has no `window`, and a
     * different value on the first client render would not match the server
     * markup, so the src is set on the element directly. It runs again only
     * when the frame URL changes, never on a hash change: resetting the src
     * later could reload the page on an expired ?token=.
     *
     * Anyone can put a fragment in a link, and the artifact page can read it
     * (and reaches its own data with the viewer's token), so only a plain
     * anchor id is passed on: letters, digits and `_ . -`, at most 128.
     * Anything else is ignored and the artifact opens at the top, as before.
     */
    const frameRef = useRef<HTMLIFrameElement>(null);

    useEffect(() => {
        const hash = window.location.hash;

        if (frameRef.current !== null && FRAME_FRAGMENT.test(hash)) {
            frameRef.current.src = `${frameUrl.split('#')[0]}${hash}`;
        }
    }, [frameUrl]);

    /*
     * An artifact-to-artifact link followed inside the sandboxed frame loads
     * the next viewer URL as a subframe (RUB-628). The server answers that
     * framed navigation with a shim that posts its URL here, and the top
     * window navigates itself, so one viewer replaces the other: one header,
     * the next title, and Back returning here. The frame has no top-navigation
     * permission and stays that way; only a message from this frame, on this
     * origin, naming an http(s) URL on this origin, is honored.
     *
     * Two history edge cases (review-6092991795):
     * - Back from B restores A with the frame still on the shim, which
     *   re-posts and bounces straight back to B. On a back_forward load the
     *   message is from restored history, so the frame is reset to the clean
     *   artifact URL instead of navigating the top window.
     * - A same-artifact fragment link (e.g. /a/A -> /a/A#sec) assigns a URL
     *   that differs only by its hash, so the browser skips the reload and
     *   the frame stays on the shim. That case forces a server round trip.
     */
    useEffect(() => {
        const onMessage = (event: MessageEvent) => {
            if (event.origin !== window.location.origin) {
                return;
            }

            if (event.source !== frameRef.current?.contentWindow) {
                return;
            }

            const data = event.data as { type?: unknown; url?: unknown } | null;

            if (data === null || typeof data !== 'object') {
                return;
            }

            if (
                data.type !== 'artfct:navigate' ||
                typeof data.url !== 'string'
            ) {
                return;
            }

            let target: URL;

            try {
                target = new URL(data.url, window.location.origin);
            } catch {
                return;
            }

            if (target.origin !== window.location.origin) {
                return;
            }

            if (target.protocol !== 'http:' && target.protocol !== 'https:') {
                return;
            }

            // Same viewer apart from its hash (RUB-577 table of contents,
            // e.g. /a/A -> /a/A#sec): assign() alone is a fragment navigation
            // with no reload, so the frame would stay on the shim. Force a
            // server round trip; the fragment effect then rebuilds the frame
            // with the hash. The frame src is never reset here: its ?token=
            // may have expired.
            const current = new URL(window.location.href);

            if (
                target.pathname === current.pathname &&
                target.search === current.search
            ) {
                window.location.assign(target.href);
                window.location.reload();

                return;
            }

            // Back/forward-restored shim: the frame's navigation to the shim
            // created a joint-history entry [A, A(shim=B), B], so Back reloads
            // A with the frame restored to the shim URL, which re-posts and
            // bounces the top window straight back to B. Undo the restored
            // frame instead of navigating. The shim is same-origin, so the
            // parent may replace it; the replacement is the clean artifact
            // URL, never a viewer URL.
            try {
                const entries = performance.getEntriesByType(
                    'navigation',
                ) as PerformanceNavigationTiming[];

                if (entries[0]?.type === 'back_forward') {
                    const frame = frameRef.current;

                    if (frame?.contentWindow) {
                        frame.contentWindow.location.replace(frameUrl);

                        return;
                    }
                }
            } catch {
                // Fall through to the top-level navigation.
            }

            window.location.assign(target.href);
        };

        window.addEventListener('message', onMessage);

        return () => {
            window.removeEventListener('message', onMessage);
        };
    }, [frameUrl]);

    useEffect(() => {
        // The rendered props are the baseline: a verdict that differs from
        // them means the artifact changed since this page was served.
        const baseline = {
            version: artifact.version,
            sharing: artifact.sharing,
        };
        // Scoped to this effect lifecycle, not the component: a reload
        // re-runs the effect with fresh props, and the new poll starts
        // unstopped instead of inheriting the old run's stop.
        let stopped = false;
        const poll = async () => {
            if (stopped || document.visibilityState !== 'visible') {
                return;
            }

            const headers: Record<string, string> = {
                Accept: 'application/json',
            };

            if (knownRef.current) {
                headers['X-Freshness-Known'] = '1';
            }

            let response: Response;

            try {
                response = await fetch(
                    artifacts.freshness.url({ artifactId: artifact.id }),
                    {
                        headers,
                        credentials: 'same-origin',
                    },
                );
            } catch {
                return;
            }

            // The session went away: stop polling silently and let the next
            // navigation handle re-auth rather than looping against 401s.
            if (response.status === 401 || response.status === 419) {
                stopped = true;

                return;
            }

            if (!response.ok) {
                return;
            }

            let verdict: Freshness;

            try {
                verdict = (await response.json()) as Freshness;
            } catch {
                return;
            }

            if (verdict.revoked) {
                stopped = true;
                setRevokedView(true);

                return;
            }

            knownRef.current = true;

            if (
                verdict.version !== baseline.version ||
                verdict.sharing !== baseline.sharing
            ) {
                if (selectedVersion !== null) {
                    setNewerAvailable(true);

                    return;
                }

                stopped = true;
                router.reload();
            }
        };

        const schedule = () => {
            const jitter = (Math.random() * 2 - 1) * FRESHNESS_JITTER_MS;

            return window.setTimeout(() => {
                void poll().finally(() => {
                    if (!stopped) {
                        timer = schedule();
                    }
                });
            }, FRESHNESS_INTERVAL_MS + jitter);
        };

        let timer = schedule();

        const onReturn = () => {
            if (document.visibilityState === 'visible') {
                void poll();
            }
        };

        document.addEventListener('visibilitychange', onReturn);
        window.addEventListener('focus', onReturn);

        return () => {
            stopped = true;
            window.clearTimeout(timer);
            document.removeEventListener('visibilitychange', onReturn);
            window.removeEventListener('focus', onReturn);
        };
        // The baseline closes over the rendered props, so a server reload —
        // which remounts with fresh props — restarts the poll from the new
        // baseline, and nothing else needs to.
    }, [artifact.id, artifact.version, artifact.sharing, selectedVersion]);

    const canPresent =
        useSyncExternalStore(
            subscribeToNothing,
            browserCanPresent,
            () => false,
        ) && isSlideDeck;
    const [panelOpen, setPanelOpen] = useState(false);
    const commentCount = comments?.length ?? 0;
    const backHref = publicView
        ? home.url()
        : consoleRoutes.index.url({ team: team.slug });

    /**
     * Full screen for the artifact frame itself, so it works for every deck,
     * including ones that predate the starter's own button. The frame is focused
     * afterwards so the deck's arrow keys work at once; Esc leaves full screen.
     * A browser may refuse the request, and the page is fine either way.
     */
    function presentDeck(): void {
        const frame = frameRef.current;

        if (frame === null || !browserCanPresent()) {
            return;
        }

        try {
            void frame
                .requestFullscreen()
                .then(() => frame.focus())
                .catch(() => undefined);
        } catch {
            // A browser that throws instead of rejecting is treated like a refusal.
        }
    }

    return (
        <>
            <Head title={name} />

            {/*
                The layout is a full-viewport column: a thin header, then the
                artifact fills everything left. The details panel slides in
                beside the frame (over it below lg) instead of sitting under it.
            */}
            <header className="shrink-0 border-b border-border bg-background">
                <div className="flex min-h-12 flex-wrap items-center gap-x-3 gap-y-1 px-3 py-1.5 md:flex-nowrap">
                    <Link
                        href={backHref}
                        aria-label={
                            publicView ? 'Artfct home' : 'Back to artifacts'
                        }
                        className="inline-flex size-8 shrink-0 items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-muted hover:text-foreground max-md:size-11"
                    >
                        <ArrowLeft className="size-4" />
                    </Link>

                    <div className="flex min-w-0 flex-1 items-baseline gap-3">
                        <h1
                            className="truncate text-base! leading-6 font-medium"
                            title={tooltip}
                        >
                            {name}
                        </h1>
                        <p className="hidden min-w-0 items-center gap-x-2 truncate text-xs text-muted-foreground md:flex">
                            {meta.map((part, index) => (
                                <span
                                    key={part}
                                    className="flex items-center gap-x-2"
                                >
                                    {index > 0 && (
                                        <span aria-hidden="true">·</span>
                                    )}
                                    {part}
                                </span>
                            ))}
                        </p>
                    </div>

                    <div className="flex flex-wrap items-center gap-2 max-md:order-last max-md:w-full">
                        {versions && versions.length > 1 && (
                            <label className="flex items-center gap-2 text-sm">
                                <span className="sr-only">Version</span>
                                <select
                                    value={shownVersion}
                                    onChange={(event) =>
                                        selectVersion(
                                            Number(event.target.value),
                                        )
                                    }
                                    data-testid="version-picker"
                                    className="h-8 rounded-md border border-border bg-transparent px-2 text-sm max-md:min-h-[44px] max-md:text-base"
                                >
                                    {versions.map((version) => (
                                        <option
                                            key={version.number}
                                            value={version.number}
                                        >
                                            Version {version.number}
                                            {version.current
                                                ? ' (current)'
                                                : ''}
                                        </option>
                                    ))}
                                </select>
                            </label>
                        )}

                        {!publicView && (
                            <ArtifactShareControl
                                artifactId={artifact.id}
                                sharing={artifact.sharing}
                                editAccess={artifact.editAccess}
                                publicSharingAllowed={team.publicSharingAllowed}
                                canChangeSharing={artifact.canChangeSharing}
                                viewerUrl={viewerUrl}
                            />
                        )}

                        <Button asChild variant="outline" size="sm">
                            <a
                                href={openUrl}
                                target="_blank"
                                rel="noopener noreferrer"
                                data-testid="open-artifact"
                            >
                                <ExternalLink className="size-3.5" /> Open
                            </a>
                        </Button>

                        {downloadUrl && (
                            <Button asChild variant="outline" size="sm">
                                <a
                                    href={downloadUrl}
                                    data-testid="download-artifact"
                                >
                                    Download
                                </a>
                            </Button>
                        )}

                        {canPresent && (
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                onClick={presentDeck}
                                data-testid="present-artifact"
                            >
                                <Maximize className="size-3.5" /> Present
                            </Button>
                        )}

                        {canSignInToEdit && (
                            <a
                                href={login.url()}
                                className="text-sm text-muted-foreground underline-offset-4 hover:text-foreground hover:underline"
                                data-testid="sign-in-to-edit"
                            >
                                Sign in to edit
                            </a>
                        )}
                    </div>

                    <button
                        type="button"
                        onClick={() => setPanelOpen((open) => !open)}
                        aria-expanded={panelOpen}
                        aria-controls="artifact-panel"
                        aria-label={
                            panelOpen ? 'Close details' : 'Open details'
                        }
                        data-testid="artifact-panel-toggle"
                        className="relative inline-flex size-8 shrink-0 items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-muted hover:text-foreground max-md:size-11"
                    >
                        {panelOpen ? (
                            <PanelRightClose className="size-4" />
                        ) : (
                            <PanelRightOpen className="size-4" />
                        )}
                        {commentCount > 0 && !panelOpen && (
                            <span className="absolute top-0.5 right-0.5 min-w-4 rounded-full bg-[var(--accent)] px-1 text-center text-[10px] leading-4 text-white">
                                {commentCount}
                            </span>
                        )}
                    </button>
                </div>
            </header>

            {newerAvailable && selectedVersion !== null && (
                <div
                    role="status"
                    data-testid="newer-version-banner"
                    className="shrink-0 border-b border-border bg-muted px-3 py-1.5 text-sm"
                >
                    A newer version is available.{' '}
                    <Link
                        href={artifacts.show.url({ artifactId: artifact.id })}
                        className="underline underline-offset-4"
                    >
                        View the latest version
                    </Link>
                </div>
            )}

            <div className="relative flex min-h-0 flex-1">
                {revokedView ? (
                    <div
                        role="status"
                        data-testid="artifact-revoked"
                        className="flex flex-1 flex-col items-center justify-center gap-2 p-8 text-center"
                    >
                        <p className="text-base font-medium">
                            This artifact is no longer shared.
                        </p>
                        <p className="max-w-md text-sm text-muted-foreground">
                            It was revoked or deleted, so there is nothing to
                            show here.
                        </p>
                    </div>
                ) : (
                    <iframe
                        ref={frameRef}
                        src={frameUrl}
                        title={name}
                        sandbox="allow-scripts allow-same-origin allow-popups allow-forms"
                        allow={isSlideDeck ? 'fullscreen' : undefined}
                        referrerPolicy="no-referrer"
                        data-testid="artifact-frame"
                        className="h-full min-w-0 flex-1 border-0 bg-paper"
                    />
                )}

                {panelOpen && (
                    <aside
                        id="artifact-panel"
                        aria-label="Artifact details"
                        data-testid="artifact-panel"
                        className="absolute inset-y-0 right-0 z-10 flex w-full max-w-[380px] flex-col overflow-y-auto border-l border-border bg-background p-5 shadow-xl lg:static lg:z-auto lg:w-[380px] lg:shrink-0 lg:shadow-none"
                    >
                        <h2 className="text-lg font-normal">{name}</h2>
                        {artifact.description && (
                            <p className="mt-2 text-sm text-muted-foreground">
                                {artifact.description}
                            </p>
                        )}
                        <p className="mt-3 text-xs text-muted-foreground">
                            {meta.join(' · ')}
                        </p>

                        {!publicView && comments !== undefined && (
                            <ArtifactComments
                                artifactId={artifact.id}
                                comments={comments}
                                className="max-w-none"
                            />
                        )}
                    </aside>
                )}
            </div>
        </>
    );
}

ArtifactShow.layout = (page: React.ReactNode) => (
    <ViewerLayout>{page}</ViewerLayout>
);
