<?php

namespace App\Services\Indexing;

use App\Services\Artifacts\ArtifactAccessLink;
use RuntimeException;

final class RealRenderer implements RendererContract
{
    public function __construct(private readonly CloudflareIndexingClient $client) {}

    public function render(string $orgId, string $artifactId, string $html): RenderResult
    {
        // Indexing renders private artifacts too, so it mints as the
        // server-side `system` viewer with the private-seeing scope.
        $url = ArtifactAccessLink::default()->forArtifact($orgId, $artifactId, viewer: 'system', viewerSeesPrivate: true);
        if ($url === null) {
            throw new RuntimeException('Artifact access signing must be configured to render artifacts.');
        }

        $requestOriginPattern = $this->requestOriginPattern($url);

        $result = $this->client->post('browser-run/scrape', [
            'url' => $url,
            'allowRequestPattern' => [$requestOriginPattern],
            'gotoOptions' => ['waitUntil' => 'networkidle0', 'timeout' => 15000],
            'elements' => array_map(fn (string $selector): array => ['selector' => $selector], [
                'body', 'title', 'h1, h2, h3, h4, h5, h6, th', '[aria-label]',
            ]),
        ], timeout: 20);

        if (! is_array($result)) {
            throw new RuntimeException('Browser Rendering returned malformed extraction data.');
        }
        $bySelector = [];
        foreach ($result as $group) {
            if (! is_array($group) || ! is_string($group['selector'] ?? null) || ! is_array($group['results'] ?? null)) {
                throw new RuntimeException('Browser Rendering returned malformed extraction data.');
            }
            $bySelector[$group['selector']] = $group['results'];
        }
        if (! is_string($bySelector['body'][0]['text'] ?? null)) {
            throw new RuntimeException('Browser Rendering returned no body text.');
        }
        $text = $bySelector['body'][0]['text'];
        $headings = [];
        foreach ($bySelector['h1, h2, h3, h4, h5, h6, th'] ?? [] as $element) {
            if (($element['height'] ?? 0) > 0 && ($element['width'] ?? 0) > 0 && is_string($element['text'] ?? null)) {
                $headings[] = trim($element['text']);
            }
        }
        foreach ($bySelector['[aria-label]'] ?? [] as $element) {
            if (($element['height'] ?? 0) <= 0 || ($element['width'] ?? 0) <= 0) {
                continue;
            }
            foreach ($element['attributes'] ?? [] as $attribute) {
                if (($attribute['name'] ?? null) === 'aria-label' && is_string($attribute['value'] ?? null)) {
                    $text .= ' '.$attribute['value'];
                }
            }
        }

        return new RenderResult(trim($text), $bySelector['title'][0]['text'] ?? null, $headings);
    }

    private function requestOriginPattern(string $url): string
    {
        $parts = parse_url($url);
        if ($parts === false || strtolower($parts['scheme'] ?? '') !== 'https' || ! is_string($parts['host'] ?? null)
            || isset($parts['user']) || isset($parts['pass'])) {
            throw new RuntimeException('Artifact access URL must use an HTTPS origin without credentials.');
        }

        $port = isset($parts['port']) ? ':'.preg_quote((string) $parts['port'], '~') : '';
        $hostPattern = str_replace('\\-', '-', preg_quote(strtolower($parts['host']), '~'));

        return '^https://'.$hostPattern.$port.'(?:[/?#]|$)';
    }
}
