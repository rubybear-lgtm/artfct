<?php

declare(strict_types=1);

namespace App\Services\Blog;

/**
 * One `##` section of a draft, with the markdown body that follows it.
 */
final readonly class BlogSection
{
    public function __construct(
        public string $heading,
        public string $id,
        public string $body,
        public bool $isLimits,
        public bool $isFaq,
    ) {}
}
