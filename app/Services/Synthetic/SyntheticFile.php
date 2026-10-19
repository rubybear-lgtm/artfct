<?php

namespace App\Services\Synthetic;

/**
 * Materialises a corpus file's bytes: literal content as-is, generated files
 * as deterministic pseudo-content of the declared size.
 */
final class SyntheticFile
{
    /**
     * @param  array{path: string, content_type: string, content: string|null, size: int, seed: string}  $file
     */
    public static function content(array $file): string
    {
        if ($file['content'] !== null) {
            return $file['content'];
        }

        $block = hash('sha256', $file['seed'].$file['path'], true);
        $repeated = str_repeat($block, intdiv($file['size'], 32) + 1);

        return substr($repeated, 0, $file['size']);
    }
}
