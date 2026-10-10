<?php

namespace App\Support;

/**
 * The landing animation as published by `npm run video:landing`: one file per
 * quality tier for each cut, named by a hash of its contents so a changed
 * render can never be served from a stale cache.
 */
class LandingMedia
{
    /** File names the media route will serve, and nothing else. */
    public const FILE_PATTERN = '/^landing-flow-(wide|phone)-(high|medium|low|poster)\.[0-9a-f]{10}\.(mp4|jpg)$/';

    public static function directory(): string
    {
        return resource_path('video');
    }

    /**
     * The tiers the page can pick from, for each cut, as URLs.
     *
     * @return array<string, array{poster: string, tiers: list<array{name: string, src: string, width: int, bytes: int}>}>
     */
    public static function forPage(): array
    {
        $manifest = self::manifest();
        $cuts = [];

        foreach (['wide', 'phone'] as $cut) {
            $entry = $manifest[$cut] ?? null;

            if (! is_array($entry) || ! isset($entry['poster']['file'])) {
                continue;
            }

            $tiers = [];

            foreach (['high', 'medium', 'low'] as $name) {
                if (isset($entry[$name]['file'])) {
                    $tiers[] = [
                        'name' => $name,
                        'src' => route('landing.media', ['file' => $entry[$name]['file']], false),
                        'width' => (int) ($entry[$name]['width'] ?? 0),
                        'bytes' => (int) ($entry[$name]['bytes'] ?? 0),
                    ];
                }
            }

            $cuts[$cut] = [
                'poster' => route('landing.media', ['file' => $entry['poster']['file']], false),
                'tiers' => $tiers,
            ];
        }

        return $cuts;
    }

    /**
     * @return array<string, array<string, array<string, mixed>>>
     */
    private static function manifest(): array
    {
        $path = self::directory().'/landing-flow.json';

        if (! is_file($path)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : [];
    }
}
