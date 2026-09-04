<?php

namespace App\Services\Indexing;

use RuntimeException;

/**
 * Real Cloudflare Browser Rendering. Fails closed — same pattern as
 * `App\Services\Tenancy\RealTenantProvisioner` — since this environment has
 * no live Browser Rendering binding to render against or verify with.
 */
final class RealRenderer implements RendererContract
{
    public function render(string $html): RenderResult
    {
        if (! config('services.cloudflare.browser_rendering_endpoint')) {
            throw new RuntimeException('services.cloudflare.browser_rendering_endpoint must be configured to render artifacts.');
        }

        throw new RuntimeException('RealRenderer::render is not implemented — no live Cloudflare Browser Rendering account in this environment.');
    }
}
