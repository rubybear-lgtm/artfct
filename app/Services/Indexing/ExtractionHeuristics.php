<?php

namespace App\Services\Indexing;

/**
 * Pure, `Env`-free heuristics — no rendering, no I/O — so the "skip the
 * render pass when it isn't needed" decision (spec 12 DoD: "A static HTML
 * artifact indexes without a render pass where the text is already
 * present") is unit-testable on its own.
 */
final class ExtractionHeuristics
{
    /** Below this many visible characters, raw HTML is treated as an empty shell. */
    private const MIN_VISIBLE_TEXT_LENGTH = 40;

    /**
     * Whether `$html`'s raw source has enough visible text to skip a
     * render pass. A hydration shell (`<div id="root"></div>` plus a
     * script tag) strips down to almost nothing; real static content
     * doesn't.
     */
    public static function needsRender(string $html): bool
    {
        return mb_strlen(self::visibleText($html)) < self::MIN_VISIBLE_TEXT_LENGTH;
    }

    /**
     * Strips `<script>`/`<style>` blocks, then every remaining tag,
     * collapsing whitespace — the same "visible text" extraction spec 12
     * describes for the post-render path, reused here to judge the raw
     * source before deciding whether to render at all.
     */
    public static function visibleText(string $html): string
    {
        $withoutScripts = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', ' ', $html) ?? $html;
        $withoutTags = preg_replace('#<[^>]+>#', ' ', $withoutScripts) ?? $withoutScripts;
        $decoded = html_entity_decode($withoutTags, ENT_QUOTES | ENT_HTML5);

        return trim(preg_replace('/\s+/', ' ', $decoded) ?? $decoded);
    }
}
