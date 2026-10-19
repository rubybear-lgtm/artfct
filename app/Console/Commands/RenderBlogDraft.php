<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Blog\BlogContent;
use App\Services\Blog\BlogPost;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

use function Laravel\Prompts\error;

/**
 * Render a draft as the review document a person reads in a browser and the
 * linter scores. Without `--out` it goes to stdout, so a single draft can be
 * linted with a redirect; with `--out` it lands on disk, because the lint path
 * takes a file argument rather than stdin.
 */
class RenderBlogDraft extends Command
{
    protected $signature = 'blog:render
                            {draft? : A draft path, or a slug}
                            {--all : Render every draft, published or held back}
                            {--out= : Write to this file, or to this directory with --all}';

    protected $description = 'Render a blog draft Markdown file as the review document the linter and the publish gate read';

    public function handle(BlogContent $content): int
    {
        $posts = $this->resolve($content);

        if ($posts === null) {
            error('No draft matched. Pass a path to a .md draft, or a slug that exists.');

            return self::FAILURE;
        }

        $out = $this->option('out');
        $out = is_string($out) && $out !== '' ? $out : null;

        foreach ($posts as $post) {
            $document = $content->standalone($post);

            if ($out === null) {
                $this->output->write($document);

                continue;
            }

            $target = $this->target($out, $post, count($posts) > 1);

            File::ensureDirectoryExists(dirname($target));
            File::put($target, $document);

            $this->info('Wrote '.$target);
        }

        return self::SUCCESS;
    }

    /**
     * @return list<BlogPost>|null
     */
    private function resolve(BlogContent $content): ?array
    {
        if ($this->option('all')) {
            return $content->every();
        }

        $draft = $this->argument('draft');

        if (! is_string($draft) || $draft === '') {
            return null;
        }

        if (is_file($draft)) {
            return [$content->fromPath($draft)];
        }

        foreach ($content->every() as $post) {
            if ($post->slug === $draft) {
                return [$post];
            }
        }

        return null;
    }

    private function target(string $out, BlogPost $post, bool $many): string
    {
        if ($many || str_ends_with($out, '/') || is_dir($out)) {
            return rtrim($out, '/').'/'.$post->slug.'.html';
        }

        return $out;
    }
}
