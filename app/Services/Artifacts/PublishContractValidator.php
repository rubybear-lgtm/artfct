<?php

namespace App\Services\Artifacts;

/**
 * The publish contract's file checks (spec 26, RUB-574). Shared by the
 * template catalog and every starter, packager and export test so one set of
 * rules decides whether a bundle is publishable.
 *
 * Errors name the bundle with `$where` and are returned as strings; an empty
 * list means the bundle obeys the contract.
 */
final class PublishContractValidator
{
    public const MAX_BUNDLE_BYTES = 8 * 1024 * 1024;

    public const MAX_BUNDLE_FILES = 500;

    /**
     * @param  array<string, string>  $files  relative path => file bytes
     * @param  string  $where  prefix for every error, such as `Template [key]`
     * @param  ?string  $entrypoint  when given, must name a file in $files
     * @return list<string> error strings; empty when the bundle is valid
     */
    public function validate(array $files, string $where = 'Bundle', ?string $entrypoint = null): array
    {
        $errors = [];

        if (count($files) > self::MAX_BUNDLE_FILES) {
            $errors[] = "{$where} has ".count($files).' files; the bundle limit is '.self::MAX_BUNDLE_FILES.'.';
        }

        $totalBytes = array_sum(array_map(strlen(...), array_values($files)));
        if ($totalBytes < 1 || $totalBytes > self::MAX_BUNDLE_BYTES) {
            $errors[] = "{$where} totals {$totalBytes} bytes; the bundle limit is ".self::MAX_BUNDLE_BYTES.'.';
        }

        foreach (array_keys($files) as $path) {
            if (! self::isValidPath($path)) {
                $errors[] = "{$where} file [{$path}] is not a valid relative bundle path.";
            }

            if (str_starts_with($path, '__artfct/')) {
                $errors[] = "{$where} file [{$path}] lives under the reserved `__artfct/` prefix.";
            }
        }

        if ($entrypoint !== null && ! array_key_exists($entrypoint, $files)) {
            $errors[] = "{$where} entrypoint [{$entrypoint}] is not one of the files in files/.";
        }

        foreach ($files as $path => $bytes) {
            $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

            if (! in_array($extension, ['html', 'htm', 'css', 'js', 'mjs', 'svg'], true)) {
                continue;
            }

            $isHtml = in_array($extension, ['html', 'htm'], true);
            $isSvg = $extension === 'svg';
            $isJs = in_array($extension, ['js', 'mjs'], true);

            if ($isHtml && self::htmlHasInlineScript($bytes)) {
                $errors[] = "{$where} file [{$path}] has an inline `<script>`; put scripts in their own file and load them with `<script src=\"…\">`.";
            }

            if ($isSvg && preg_match('/<script\b/i', $bytes) === 1) {
                $errors[] = "{$where} file [{$path}] has an SVG `<script>` element; SVG assets must be static.";
            }

            if ($isSvg && preg_match('/<foreignObject\b/i', $bytes) === 1) {
                $errors[] = "{$where} file [{$path}] has an SVG `<foreignObject>` element; SVG assets must be static.";
            }

            if (($isHtml || $isSvg) && preg_match('/(?:^|[\s<])on[a-z]+\s*=|<[a-z][^>]*\/on[a-z]+\s*=/i', $bytes) === 1) {
                $errors[] = "{$where} file [{$path}] has an `on*=` handler; move interactivity into a bundled script file.";
            }

            if (self::hasDataUri($bytes)) {
                $errors[] = "{$where} file [{$path}] has a `data:` URI; put images and fonts in files/ and reference them by relative path.";
            }

            $originType = match (true) {
                $isHtml => 'html',
                $isSvg => 'svg',
                $isJs => 'js',
                default => 'css',
            };
            foreach ($this->externalOrigins($originType, $bytes) as $origin) {
                $errors[] = "{$where} file [{$path}] loads an external origin [{$origin}]; bundle every asset in files/.";
            }
        }

        $errors = array_merge($errors, $this->validateReferences($where, $files));

        return $errors;
    }

    /** True when the HTML has a `<script>` element without an actual `src` attribute. */
    private static function htmlHasInlineScript(string $html): bool
    {
        if (preg_match_all('/<script\b([^>]*)>/i', $html, $matches) === 0) {
            return false;
        }

        foreach ($matches[1] as $attributes) {
            if (preg_match('/(?:^|\s)src\s*=\s*(?:"[^"]+"|\'[^\']+\'|[^\s>"\']+)/i', $attributes) !== 1) {
                return true;
            }
        }

        return false;
    }

    /** True when content contains a `data:` URI (with or without MIME parameters). */
    private static function hasDataUri(string $bytes): bool
    {
        return preg_match('/(?:^|[\s"\'`(=])data:(?:[a-z0-9.+-]+\/[a-z0-9.+-]+(?:;[a-z0-9.=+-]+)*|;[a-z0-9.=+-]+)*,/i', $bytes) === 1;
    }

    private static function isValidPath(string $path): bool
    {
        return $path !== ''
            && strlen($path) <= 255
            && ! str_starts_with($path, '/')
            && ! str_contains($path, '\\')
            && ! str_contains($path, ':')
            && ! in_array('..', explode('/', $path), true)
            && ! in_array('', explode('/', $path), true);
    }

    /** @return list<string> the external origins a file loads, if any */
    private function externalOrigins(string $extension, string $bytes): array
    {
        $patterns = match ($extension) {
            'html' => [
                '/(?:src|href|action|poster|cite|data-[a-z-]+)\s*=\s*["\']\s*((?:https?:)?\/\/[^"\'\s<>]+)/i',
                '/url\(\s*["\']?\s*((?:https?:)?\/\/[^)"\'\s]+)/i',
            ],
            'svg' => [
                '/(?:xlink:href|href|src|action)\s*=\s*["\']\s*((?:https?:)?\/\/[^"\'\s<>]+)/i',
                '/url\(\s*["\']?\s*((?:https?:)?\/\/[^)"\'\s]+)/i',
            ],
            'css' => [
                '/url\(\s*["\']?\s*((?:https?:)?\/\/[^)"\'\s]+)/i',
                '/@import\s+["\']\s*((?:https?:)?\/\/[^"\'\s]+)/i',
            ],
            default => [
                // fetch/XHR/WebSocket/import/getAttribute-free string URLs.
                '/["\'`]((?:https?:)?\/\/[^"\'`\s]+)["\'`]/',
            ],
        };

        $origins = [];
        foreach ($patterns as $pattern) {
            if (preg_match_all($pattern, $bytes, $matches) === false) {
                continue;
            }

            foreach ($matches[1] as $url) {
                $origins[] = $url;
            }
        }

        return array_values(array_unique($origins));
    }

    /**
     * Every relative asset reference must resolve to a file in the bundle,
     * so a published page cannot 404 its own script or style.
     *
     * @param  array<string, string>  $files
     * @return list<string>
     */
    private function validateReferences(string $where, array $files): array
    {
        $errors = [];

        foreach ($files as $path => $bytes) {
            $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            $refs = [];

            if (in_array($extension, ['html', 'htm'], true)) {
                if (preg_match_all('/(?:src|href)\s*=\s*["\']([^"\'#<>]+)(?:#[^"\']*)?["\']/i', $bytes, $matches) !== false) {
                    $refs = array_merge($refs, $matches[1]);
                }

                if (preg_match_all('/url\(\s*["\']?([^)"\'#]+)(?:#[^)"\']*)?["\']?\s*\)/i', $bytes, $matches) !== false) {
                    $refs = array_merge($refs, $matches[1]);
                }
            } elseif ($extension === 'svg') {
                if (preg_match_all('/(?:xlink:href|href|src)\s*=\s*["\']([^"\'#<>]+)(?:#[^"\']*)?["\']/i', $bytes, $matches) !== false) {
                    $refs = array_merge($refs, $matches[1]);
                }

                if (preg_match_all('/url\(\s*["\']?([^)"\'#]+)(?:#[^)"\']*)?["\']?\s*\)/i', $bytes, $matches) !== false) {
                    $refs = array_merge($refs, $matches[1]);
                }
            } elseif ($extension === 'css') {
                if (preg_match_all('/url\(\s*["\']?([^)"\'#]+)(?:#[^)"\']*)?["\']?\s*\)/i', $bytes, $matches) !== false) {
                    $refs = array_merge($refs, $matches[1]);
                }
            }

            foreach ($refs as $ref) {
                $ref = trim($ref);

                if ($ref === '' || str_starts_with($ref, 'data:') || preg_match('#^(?:https?:)?//#', $ref) === 1) {
                    continue;
                }

                if (str_starts_with($ref, '/')) {
                    $errors[] = "{$where} file [{$path}] references absolute path [{$ref}]; use a relative path inside files/.";

                    continue;
                }

                $resolved = $this->resolveRelative(dirname($path) === '.' ? '' : dirname($path), $ref);

                if ($resolved === null || ! array_key_exists($resolved, $files)) {
                    $errors[] = "{$where} file [{$path}] references [{$ref}], which is not in files/.";
                }
            }
        }

        return $errors;
    }

    private function resolveRelative(string $base, string $ref): ?string
    {
        $parts = $base === '' ? [] : explode('/', $base);

        foreach (explode('/', $ref) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                if ($parts === []) {
                    return null;
                }

                array_pop($parts);

                continue;
            }

            $parts[] = $segment;
        }

        return implode('/', $parts);
    }
}
