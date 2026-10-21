<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Blog\BlogContent;
use App\Support\AiToolSetup;
use Illuminate\Http\Response;

/**
 * The sitemap reads the same post list the index does, so a published draft
 * cannot be missing from one of them.
 */
class SitemapController extends Controller
{
    public function __construct(private readonly BlogContent $content) {}

    public function __invoke(): Response
    {
        $urls = [
            ['loc' => url('/'), 'priority' => '1.0', 'changefreq' => 'weekly'],
            ['loc' => url('/free'), 'priority' => '0.8', 'changefreq' => 'weekly'],
            ['loc' => url('/docs'), 'priority' => '0.8', 'changefreq' => 'weekly'],
            ['loc' => url('/blog'), 'priority' => '0.6', 'changefreq' => 'weekly'],
            ['loc' => url('/terms'), 'priority' => '0.3', 'changefreq' => 'yearly'],
            ['loc' => url('/privacy'), 'priority' => '0.3', 'changefreq' => 'yearly'],
        ];

        foreach ($this->content->all() as $post) {
            $urls[] = [
                'loc' => route('blog.show', ['slug' => $post->slug]),
                'priority' => '0.7',
                'changefreq' => 'monthly',
            ];
        }

        $urls[] = ['loc' => route('connect.index'), 'priority' => '0.8', 'changefreq' => 'monthly'];

        foreach (AiToolSetup::forCurrentEnvironment()->guides() as $guide) {
            $urls[] = [
                'loc' => route('connect.show', ['tool' => $guide['id']]),
                'priority' => '0.6',
                'changefreq' => 'monthly',
            ];
        }

        $xml = view('sitemap', ['urls' => $urls])->render();

        return response('<?xml version="1.0" encoding="UTF-8"?>'."\n".$xml)
            ->header('Content-Type', 'text/xml');
    }
}
