<?php

declare(strict_types=1);

namespace App\Services\Blog;

/**
 * One draft file, parsed. The file is the only copy of any of this.
 */
final readonly class BlogPost
{
    /**
     * @param  list<BlogSection>  $sections
     */
    public function __construct(
        public string $slug,
        public string $title,
        public string $description,
        public string $date,
        public string $tag,
        public bool $published,
        public string $surface,
        public string $register,
        public string $target,
        public bool $lintSkip,
        public string $markdown,
        public string $lead,
        public array $sections,
        public string $path,
    ) {}

    /**
     * The `<title>` and the search result both use the headline with the brand
     * appended, which is what keeps `E12`'s 45–60 character band satisfiable.
     */
    public function metaTitle(): string
    {
        return $this->title.' — Artfct';
    }

    public function kicker(): string
    {
        return ucfirst($this->tag);
    }

    /**
     * The index, the sitemap and the structured data all read these five fields.
     *
     * @return array{slug: string, date: string, title: string, tag: string, description: string}
     */
    public function toIndexArray(): array
    {
        return [
            'slug' => $this->slug,
            'date' => $this->date,
            'title' => $this->title,
            'tag' => $this->tag,
            'description' => $this->description,
        ];
    }
}
