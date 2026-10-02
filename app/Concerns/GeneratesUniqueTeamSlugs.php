<?php

namespace App\Concerns;

use App\Rules\TeamSlug;
use Illuminate\Support\Str;

trait GeneratesUniqueTeamSlugs
{
    /**
     * Generate a unique slug for the team, honoring {@see TeamSlug}'s
     * constraints. The base is truncated *before* a uniqueness suffix is
     * appended, so `<24-char base>-2` never exceeds the 24-char DNS-label
     * limit, and non-ASCII names (e.g. "José") are transliterated by
     * `Str::slug()` rather than rejected.
     */
    protected static function generateUniqueTeamSlug(string $name, ?int $excludeId = null): string
    {
        $base = Str::slug($name);
        $base = $base === '' ? 'team' : $base;
        $base = rtrim(mb_substr($base, 0, TeamSlug::MAX_LENGTH - 3), '-');
        $base = $base === '' ? 'team' : $base;

        $query = static::withTrashed()
            ->where(function ($query) use ($base) {
                $query->where('slug', $base)
                    ->orWhere('slug', 'like', $base.'-%');
            });

        if ($excludeId) {
            $query->where('id', '!=', $excludeId);
        }

        $existingSlugs = $query->pluck('slug');

        $maxSuffix = $existingSlugs
            ->map(function (string $slug) use ($base): ?int {
                if ($slug === $base) {
                    return 0;
                } elseif (preg_match('/^'.preg_quote($base, '/').'-(\d+)$/', $slug, $matches)) {
                    return (int) $matches[1];
                }

                return null;
            })
            ->filter(fn (?int $suffix) => $suffix !== null)
            ->max() ?? 0;

        return $existingSlugs->isEmpty()
            ? $base
            : $base.'-'.($maxSuffix + 1);
    }
}
