<?php

namespace App\Services\Indexing;

/**
 * In-memory stand-in for Cloudflare Browser Rendering, bound in `testing`.
 * Simulates hydration: given `<div id="app"></div>`-shaped shells, returns
 * canned post-hydration text keyed by a hash of the input HTML, so a test
 * fixture "renders" into whatever text the test seeded for it.
 */
final class FakeRenderer implements RendererContract
{
    /** @var array<string, RenderResult> */
    private array $seeded = [];

    /** @var array<string, true> */
    private array $timeoutOn = [];

    public int $callCount = 0;

    public function seedRender(string $html, RenderResult $result): void
    {
        $this->seeded[$this->key($html)] = $result;
    }

    public function timeoutOnNextRender(string $html): void
    {
        $this->timeoutOn[$this->key($html)] = true;
    }

    public function render(string $html): RenderResult
    {
        $this->callCount++;
        $key = $this->key($html);

        if (isset($this->timeoutOn[$key])) {
            unset($this->timeoutOn[$key]);

            throw new RenderTimeoutException("Render timed out for fixture hash {$key}.");
        }

        return $this->seeded[$key] ?? new RenderResult(text: '', title: null, headings: []);
    }

    private function key(string $html): string
    {
        return md5($html);
    }
}
