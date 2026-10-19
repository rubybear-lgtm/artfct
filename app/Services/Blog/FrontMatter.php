<?php

declare(strict_types=1);

namespace App\Services\Blog;

use RuntimeException;

/**
 * Flat `key: value` front matter between `---` fences, the subset the drafts use.
 *
 * Deliberately not YAML: symfony/yaml is present only transitively in
 * composer.lock, and depending on a transitive package is how a `composer update`
 * breaks the blog. Nested structures are not needed, so they get no parser.
 */
final class FrontMatter
{
    /** @var list<string> */
    public const array REQUIRED = [
        'slug',
        'title',
        'description',
        'date',
        'tag',
        'published',
        'surface',
        'register',
        'target',
    ];

    /** @var list<string> */
    public const array OPTIONAL = ['lint'];

    /** @var list<string> */
    public const array SURFACES = ['feature-showcase', 'comparison', 'explainer', 'programmatic'];

    /** @var list<string> */
    public const array REGISTERS = ['glean-formal', 'artfct-terse'];

    /**
     * Split a draft into its front matter and its body.
     *
     * @return array{0: array<string, string>, 1: string}
     */
    public static function split(string $raw, string $path): array
    {
        $normalized = str_replace("\r\n", "\n", $raw);

        if (! str_starts_with($normalized, "---\n")) {
            throw new RuntimeException(self::name($path).' does not start with a front matter block.');
        }

        $end = strpos($normalized, "\n---\n", 4);

        if ($end === false) {
            throw new RuntimeException(self::name($path).' has an unterminated front matter block.');
        }

        $block = substr($normalized, 4, $end - 4);
        $body = ltrim(substr($normalized, $end + 5), "\n");

        return [self::parse($block, $path), $body];
    }

    /**
     * @return array<string, string>
     */
    private static function parse(string $block, string $path): array
    {
        $meta = [];

        foreach (explode("\n", $block) as $index => $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            $colon = strpos($line, ':');

            if ($colon === false) {
                throw new RuntimeException(
                    self::name($path).' front matter line '.($index + 1).' is not `key: value`: '.$line
                );
            }

            $key = trim(substr($line, 0, $colon));
            $value = self::unquote(trim(substr($line, $colon + 1)));

            if (array_key_exists($key, $meta)) {
                throw new RuntimeException(self::name($path).' sets `'.$key.'` twice.');
            }

            $meta[$key] = $value;
        }

        self::validate($meta, $path);

        return $meta;
    }

    /**
     * A typo'd key is the failure that otherwise ships: the page still renders,
     * so nothing catches a `descripton:` until search shows an empty snippet.
     *
     * @param  array<string, string>  $meta
     */
    private static function validate(array $meta, string $path): void
    {
        foreach (array_keys($meta) as $key) {
            if (! in_array($key, [...self::REQUIRED, ...self::OPTIONAL], true)) {
                throw new RuntimeException(self::name($path).' has an unknown front matter key `'.$key.'`.');
            }
        }

        foreach (self::REQUIRED as $key) {
            if (! isset($meta[$key]) || $meta[$key] === '') {
                throw new RuntimeException(self::name($path).' is missing the required `'.$key.'` key.');
            }
        }

        if (preg_match('/^[a-z0-9-]+$/', $meta['slug']) !== 1) {
            throw new RuntimeException(self::name($path).' has a slug that is not `[a-z0-9-]+`: '.$meta['slug']);
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $meta['date']) !== 1) {
            throw new RuntimeException(self::name($path).' has a date that is not `YYYY-MM-DD`: '.$meta['date']);
        }

        if (! in_array($meta['published'], ['true', 'false'], true)) {
            throw new RuntimeException(self::name($path).' has `published` that is not true or false: '.$meta['published']);
        }

        if (! in_array($meta['surface'], self::SURFACES, true)) {
            throw new RuntimeException(
                self::name($path).' has an unknown surface `'.$meta['surface'].'`; expected one of '
                .implode(', ', self::SURFACES).'.'
            );
        }

        if (! in_array($meta['register'], self::REGISTERS, true)) {
            throw new RuntimeException(
                self::name($path).' has an unknown register `'.$meta['register'].'`; expected one of '
                .implode(', ', self::REGISTERS).'.'
            );
        }

        if (isset($meta['lint']) && ! in_array($meta['lint'], ['run', 'skip'], true)) {
            throw new RuntimeException(self::name($path).' has an unknown lint value `'.$meta['lint'].'`; expected run or skip.');
        }
    }

    private static function unquote(string $value): string
    {
        if (strlen($value) >= 2) {
            $first = $value[0];
            $last = $value[strlen($value) - 1];

            if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                return substr($value, 1, -1);
            }
        }

        return $value;
    }

    private static function name(string $path): string
    {
        return 'skills/artfct-content/drafts/'.basename($path);
    }
}
