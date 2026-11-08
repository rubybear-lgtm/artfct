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
     * doesn't. Raw markdown is never a hydration shell.
     */
    public static function needsRender(string $html): bool
    {
        if (self::looksLikeMarkdown($html)) {
            return false;
        }

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

    /**
     * True when `$content` looks like markdown rather than HTML: headings or
     * pipe tables, and no HTML document/structure tags. Used to index markdown
     * without `#` / `|---|` markup and to refuse markdown passed as `html`.
     */
    public static function looksLikeMarkdown(string $content): bool
    {
        $trimmed = ltrim($content);
        if ($trimmed === '') {
            return false;
        }

        if (preg_match('/<(?:!doctype|html|head|body|div|p|h[1-6]|table|section|article|span|a|ul|ol|li|img|script|style)\b/i', $trimmed) === 1) {
            return false;
        }

        if (preg_match('/^#{1,6}\s+\S/m', $trimmed) === 1) {
            return true;
        }

        return preg_match('/^\|.+\|[ \t]*$/m', $trimmed) === 1
            && preg_match('/^\|?[\s:|-]*-{3,}[\s:|-]*\|?\s*$/m', $trimmed) === 1;
    }

    /**
     * Plain text from markdown without heading markers or table rules.
     */
    public static function plainTextFromMarkdown(string $markdown): string
    {
        $lines = preg_split('/\R/', $markdown) ?: [];
        $out = [];

        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '') {
                continue;
            }
            if (preg_match('/^\|?[\s:|-]*-{3,}[\s:|-]*\|?\s*$/', $trimmed) === 1) {
                continue;
            }
            if (preg_match('/^#{1,6}\s+(.*)$/', $trimmed, $heading) === 1) {
                $out[] = trim($heading[1]);

                continue;
            }
            if (str_starts_with($trimmed, '|')) {
                $cells = array_values(array_filter(array_map('trim', explode('|', trim($trimmed, '|'))), fn (string $cell): bool => $cell !== ''));
                if ($cells !== []) {
                    $out[] = implode(' ', $cells);
                }

                continue;
            }
            $out[] = preg_replace('/\*{1,2}|_{1,2}|`+/', '', $trimmed) ?? $trimmed;
        }

        return trim(preg_replace('/\s+/', ' ', implode(' ', $out)) ?? implode(' ', $out));
    }

    /** @return list<string> */
    public static function headingsFromHtml(string $html): array
    {
        if (preg_match_all('/<h([12])\b[^>]*>(.*?)<\/h\1>/is', $html, $matches) === 0) {
            return [];
        }

        $headings = [];
        foreach ($matches[2] as $inner) {
            $text = trim(html_entity_decode(strip_tags($inner), ENT_QUOTES | ENT_HTML5));
            if ($text !== '') {
                $headings[] = $text;
            }
        }

        return $headings;
    }

    /** @return list<string> `#` and `##` headings only. */
    public static function headingsFromMarkdown(string $markdown): array
    {
        $headings = [];
        foreach (preg_split('/\R/', $markdown) ?: [] as $line) {
            if (preg_match('/^#{1,2}\s+(.+)$/', trim($line), $match) === 1) {
                $text = trim($match[1]);
                if ($text !== '') {
                    $headings[] = $text;
                }
            }
        }

        return $headings;
    }

    public static function titleFromHtml(string $html): ?string
    {
        if (preg_match('/<title\b[^>]*>(.*?)<\/title>/is', $html, $match) === 1) {
            $title = trim(html_entity_decode(strip_tags($match[1]), ENT_QUOTES | ENT_HTML5));
            if ($title !== '') {
                return $title;
            }
        }

        $headings = self::headingsFromHtml($html);

        return $headings[0] ?? null;
    }

    public static function titleFromMarkdown(string $markdown): ?string
    {
        $headings = self::headingsFromMarkdown($markdown);

        return $headings[0] ?? null;
    }

    /**
     * Drop a leading title that would otherwise duplicate the separate title
     * field returned by read_artifact.
     */
    public static function withoutLeadingTitle(string $text, ?string $title): string
    {
        if ($title === null || $title === '') {
            return $text;
        }

        $trimmed = ltrim($text);
        if (str_starts_with($trimmed, $title)) {
            return trim(mb_substr($trimmed, mb_strlen($title)));
        }

        return $text;
    }
}
