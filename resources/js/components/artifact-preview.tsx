import { useEffect, useRef, useState } from 'react';

import { cn } from '@/lib/utils';

/** The fixed document width every preview scales down from. */
export const ARTIFACT_PREVIEW_DOCUMENT_WIDTH = 1024;

/** The fixed document height every preview scales down from (8:5). */
export const ARTIFACT_PREVIEW_DOCUMENT_HEIGHT = 640;

interface ArtifactPreviewProps {
    /** A session-authenticated preview URL from the page props. Never built here. */
    previewUrl: string;
    /** Describes the preview to assistive tech when it is meaningful content. */
    title: string;
    /** Hides the preview from assistive tech when it is pure decoration. */
    decorative?: boolean;
    className?: string;
}

/**
 * A lazy, non-interactive live preview of an artifact's actual HTML.
 *
 * The frame only mounts while the preview is near the viewport
 * (IntersectionObserver with a ~200px margin) and scales a fixed 1024px-wide
 * document down to the container width measured with a ResizeObserver,
 * clipped to an 8:5 window. Sandboxed with no permissions, no referrer, out of
 * the tab order, and pointer-transparent, so embedded content can never capture
 * input or navigate the page.
 */
export function ArtifactPreview({
    previewUrl,
    title,
    decorative = false,
    className,
}: ArtifactPreviewProps) {
    const containerRef = useRef<HTMLDivElement | null>(null);
    const [nearViewport, setNearViewport] = useState(
        () => typeof IntersectionObserver === 'undefined',
    );
    const [scale, setScale] = useState(1);

    useEffect(() => {
        const node = containerRef.current;

        if (!node) {
            return;
        }

        if (typeof IntersectionObserver === 'undefined') {
            return;
        }

        const observer = new IntersectionObserver(
            (entries) => {
                for (const entry of entries) {
                    if (entry.target === node) {
                        setNearViewport(entry.isIntersecting);
                    }
                }
            },
            { rootMargin: '200px' },
        );

        observer.observe(node);

        return () => observer.disconnect();
    }, []);

    useEffect(() => {
        const node = containerRef.current;

        if (!node) {
            return;
        }

        const measure = () => {
            setScale(node.clientWidth / ARTIFACT_PREVIEW_DOCUMENT_WIDTH);
        };

        measure();

        if (typeof ResizeObserver === 'undefined') {
            return;
        }

        const observer = new ResizeObserver(measure);

        observer.observe(node);

        return () => observer.disconnect();
    }, []);

    const height = Math.max(
        1,
        Math.round(ARTIFACT_PREVIEW_DOCUMENT_HEIGHT * scale),
    );

    return (
        <div
            ref={containerRef}
            className={cn('w-full overflow-hidden bg-muted', className)}
            style={{ height }}
        >
            {nearViewport ? (
                <div
                    aria-hidden={decorative ? true : undefined}
                    className="pointer-events-none origin-top-left"
                    style={{
                        width: ARTIFACT_PREVIEW_DOCUMENT_WIDTH,
                        height: ARTIFACT_PREVIEW_DOCUMENT_HEIGHT,
                        transform: `scale(${scale})`,
                    }}
                >
                    <iframe
                        src={previewUrl}
                        title={title}
                        loading="lazy"
                        sandbox=""
                        referrerPolicy="no-referrer"
                        tabIndex={-1}
                        aria-hidden={decorative ? true : undefined}
                        className="pointer-events-none h-full w-full border-0"
                    />
                </div>
            ) : (
                <div
                    aria-hidden="true"
                    className="h-full w-full animate-pulse bg-muted motion-reduce:animate-none"
                />
            )}
        </div>
    );
}
