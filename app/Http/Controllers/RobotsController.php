<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\Response;

/**
 * Serves robots.txt from the app so the policy lives in the repository
 * instead of Cloudflare's managed block (RUB-487).
 *
 * Search and AI search crawlers may read the public site. AI training
 * crawlers may not, with one exception: Google-Extended may read the
 * marketing pages so Gemini can cite them, but never artifact content.
 * Artifact, API and sign-in paths are disallowed for every crawler
 * (RUB-513). Anything other than production asks every crawler to stay out.
 */
class RobotsController extends Controller
{
    /**
     * AI training crawlers kept out entirely: the list Cloudflare's managed
     * robots.txt carried on 2026-10-20, minus Google-Extended.
     *
     * @var list<string>
     */
    public const TRAINING_CRAWLERS = [
        'Amazonbot', 'Applebot-Extended', 'Bytespider', 'CCBot', 'ClaudeBot',
        'Diffbot', 'GPTBot', 'omgili', 'anthropic-ai', 'Claude-Web', 'cohere-ai',
        'MistralAI-Training', 'meta-externalagent', 'GoogleOther', 'Baiduspider',
        'PetalBot', 'AwarioSmartBot', 'AwarioRssBot', 'Google-CloudVertexBot',
        'QualifiedBot', 'Cotoyogi', 'ICC-Crawler', 'atlassian-bot', 'FishBot',
        'BorderxBot', 'NavuBot', 'SemrushBot-SWA', 'WARDBot', 'magpie-crawler',
        'KimiBot', 'CitibotSiteCrawler',
    ];

    /**
     * Paths that serve what people published. Google-Extended stays out of
     * them so artifacts are never used to train AI.
     *
     * @var list<string>
     */
    public const ARTIFACT_PATHS = ['/a/', '/p/', '/v1/'];

    public function __invoke(): Response
    {
        return response($this->body())
            ->header('Content-Type', 'text/plain; charset=UTF-8');
    }

    private function body(): string
    {
        if (! app()->isProduction()) {
            return "User-agent: *\nDisallow: /\n";
        }

        // Artifact, API and sign-in paths are never marketing pages. Disallow
        // them for every crawler (RUB-513) so Google spends crawl budget on
        // the sitemap instead of shared /a/ links and dead app shells.
        $privateDisallows = array_map(
            fn (string $path): string => "Disallow: {$path}",
            [...self::ARTIFACT_PATHS, '/login'],
        );

        $lines = [
            'User-agent: *',
            'Content-Signal: search=yes,ai-train=no,use=reference',
            'Allow: /',
            ...$privateDisallows,
            '',
            'User-agent: Google-Extended',
            'Allow: /',
            ...$privateDisallows,
            '',
        ];

        foreach (self::TRAINING_CRAWLERS as $crawler) {
            $lines[] = "User-agent: {$crawler}";
        }

        $lines[] = 'Disallow: /';
        $lines[] = '';
        $lines[] = 'Sitemap: '.url('/sitemap.xml');

        return implode("\n", $lines)."\n";
    }
}
