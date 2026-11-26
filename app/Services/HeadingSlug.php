<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Heading id slugs, shared by `MarkdownRenderer` and `Blog\BlogContent`.
 *
 * Shape: lowercase ASCII, every run of non-`[a-z0-9]` becomes one `-`,
 * trimmed; empty falls back to `section`. Dedupe appends `-2`, `-3`, ….
 * `backend/src/markdown.rs` mirrors this byte-for-byte, and
 * `tests/Fixtures/markdown-heading-ids.md` locks both renderers to it.
 */
final class HeadingSlug
{
    public static function slugify(string $text): string
    {
        $slug = preg_replace('/[^a-z0-9]+/', '-', strtolower($text)) ?? '';
        $slug = trim($slug, '-');

        return $slug === '' ? 'section' : $slug;
    }

    /**
     * @param  array<string, true>  $used
     */
    public static function unique(string $base, array &$used): string
    {
        $id = $base;
        $suffix = 2;

        while (isset($used[$id])) {
            $id = $base.'-'.$suffix;
            $suffix++;
        }

        $used[$id] = true;

        return $id;
    }
}
