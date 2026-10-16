<?php

namespace App\Services\Indexing;

/**
 * Post-hydration extraction result: "visible text, plus title, headings,
 * table headers, and chart accessible labels where present."
 */
final readonly class RenderResult
{
    /**
     * @param  array<int, string>  $headings
     */
    public function __construct(
        public string $text,
        public ?string $title,
        public array $headings,
    ) {}
}
