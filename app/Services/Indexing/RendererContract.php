<?php

namespace App\Services\Indexing;

/**
 * Headless render of an artifact's HTML — spec 12: "Real content only
 * exists after hydration, so extraction requires Cloudflare Browser
 * Rendering driving a headless browser, on a queue, never in the request
 * path." Only called when {@see needs_render()} says the raw HTML has no
 * usable body text on its own.
 */
interface RendererContract
{
    /**
     * @throws RenderTimeoutException on a render timeout — the caller
     *                                retries with backoff, then dead-letters.
     */
    public function render(string $html): RenderResult;
}
