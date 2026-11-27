<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Heading id slugs, shared by `MarkdownRenderer` and `Blog\BlogContent`.
 *
 * Shape: lowercase ASCII, every run of non-`[a-z0-9]` becomes one `-`,
 * truncated to `MAX_LENGTH`, trimmed; empty falls back to `section`.
 * Dedupe appends `-2`, `-3`, …. `backend/src/markdown.rs` mirrors this
 * byte-for-byte, and `tests/Fixtures/markdown-heading-ids.md` locks both
 * renderers to it.
 *
 * Slugging each heading through one instance keeps the ids unique across a
 * document: first use keeps the base, repeats take the next suffix.
 */
final class HeadingSlug
{
    /**
     * Longest base slug kept, before any `-N` suffix. The artifact viewer
     * forwards at most 128 characters after `#` (`FRAME_FRAGMENT` in
     * `resources/js/pages/artifacts/show.tsx`), leaving room for the suffix.
     */
    public const int MAX_LENGTH = 120;

    /** @var array<string, true> */
    private array $used = [];

    /** @var array<string, int> */
    private array $nextSuffix = [];

    public static function slugify(string $text): string
    {
        $slug = preg_replace('/[^a-z0-9]+/', '-', strtolower($text)) ?? '';

        if (strlen($slug) > self::MAX_LENGTH) {
            $slug = substr($slug, 0, self::MAX_LENGTH);
        }

        $slug = trim($slug, '-');

        return $slug === '' ? 'section' : $slug;
    }

    /**
     * Slugify one heading and make it unique within this instance's document.
     */
    public function id(string $text): string
    {
        $base = self::slugify($text);

        if (! isset($this->used[$base])) {
            $this->used[$base] = true;

            return $base;
        }

        // Resume from where the previous repeat of this base landed, so N
        // repeats cost N steps instead of N². `used` is still checked because
        // a later heading can literally spell a candidate (`a-2`).
        $suffix = $this->nextSuffix[$base] ?? 2;

        do {
            $id = $base.'-'.$suffix;
            $suffix++;
        } while (isset($this->used[$id]));

        $this->used[$id] = true;
        $this->nextSuffix[$base] = $suffix;

        return $id;
    }
}
