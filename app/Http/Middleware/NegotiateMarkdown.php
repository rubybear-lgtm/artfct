<?php

namespace App\Http\Middleware;

use App\Support\MarkdownPages;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Markdown for agents without Cloudflare's paid feature: a GET or HEAD that
 * prefers `text/markdown` over `text/html` gets the page's Markdown version
 * (MarkdownPages), with an approximate `x-markdown-tokens` count. Browsers
 * keep getting HTML. Pages with a Markdown version always send
 * `Vary: Accept` so a cache never hands one representation to the other.
 */
class NegotiateMarkdown
{
    public function __construct(private MarkdownPages $pages) {}

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->isMethod('GET') && ! $request->isMethod('HEAD')) {
            return $next($request);
        }

        $markdown = $this->pages->forPath($request->path());

        if ($markdown === null) {
            return $next($request);
        }

        if ($this->wantsMarkdown($request)) {
            return response($markdown, 200, [
                'Content-Type' => 'text/markdown; charset=UTF-8',
                'Vary' => 'Accept',
                'x-markdown-tokens' => (string) $this->approximateTokens($markdown),
            ]);
        }

        $response = $next($request);
        $response->setVary(array_values(array_unique([...$response->getVary(), 'Accept'])));

        return $response;
    }

    private function wantsMarkdown(Request $request): bool
    {
        $accept = (string) $request->header('Accept', '');

        if (! str_contains($accept, 'text/markdown')) {
            return false;
        }

        return $request->prefers(['text/html', 'text/markdown']) === 'text/markdown';
    }

    /**
     * Roughly four characters per token, the usual rule of thumb for English.
     */
    private function approximateTokens(string $markdown): int
    {
        return (int) ceil(mb_strlen($markdown) / 4);
    }
}
