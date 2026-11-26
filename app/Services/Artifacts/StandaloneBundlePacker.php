<?php

namespace App\Services\Artifacts;

use App\Contracts\ArtifactDirectory;
use DOMComment;
use DOMDocument;
use DOMElement;
use DOMXPath;

/**
 * Packs one published bundle into a single HTML file that opens offline
 * (RUB-563, spec 40).
 *
 * The bundle is the input and must pass `PublishContractValidator`, the one
 * shared checker. The output is a download format: stylesheets and scripts are
 * inlined, images and fonts become `data:` URIs, and the file is not meant to
 * be re-published. Anything that cannot be inlined is refused with the
 * reasons, never silently dropped, so a packed file never loads a missing asset.
 *
 * Packing rules, in short: `<link rel=stylesheet>` becomes `<style>` and its
 * `url()` values resolve against the stylesheet's own path; `<script src>`
 * becomes `<script>` where it stood, or moves to the end of `<body>` when it is
 * deferred or in `<head>`; `<img>`, `<source>`, `<video poster>` and icon links
 * become `data:` URIs; string literals in scripts that name a bundle asset
 * become `data:` URIs; a literal that names no file is a refusal.
 */
final class StandaloneBundlePacker
{
    /** Extensions a script string literal may name as a bundle asset. */
    private const ASSET_EXTENSIONS = ['png', 'jpg', 'jpeg', 'gif', 'webp', 'avif', 'svg', 'woff', 'woff2', 'ttf', 'otf', 'json', 'mp4', 'webm', 'mp3', 'wav', 'ogg'];

    /** @var array<string, string> bundle path => bytes, for the pack in progress */
    private array $bundle = [];

    /** @var array<string, string> bundle path => manifest content type */
    private array $contentTypes = [];

    /** @var list<string> refusals found while packing */
    private array $refusals = [];

    public function __construct(
        private readonly ArtifactDirectory $directory,
        private readonly PublishContractValidator $validator,
    ) {}

    /**
     * @param  array{html: string, entrypoint: ?string, files: ?list<array{path: string, sha256: string, content_type: string, size_bytes: int}>}  $entry
     *
     * @throws StandalonePackException when the bundle cannot be packed
     */
    public function pack(string $orgSlug, string $artifactId, array $entry): string
    {
        $where = "Artifact [{$artifactId}]";
        $entrypoint = $entry['entrypoint'];
        $files = $entry['files'];

        if ($entrypoint === null || $files === null) {
            throw new StandalonePackException(503, ['The Worker did not list the files of this artifact, so it cannot be packed into one HTML file yet.']);
        }

        $this->bundle = [];
        $this->contentTypes = [];
        $this->refusals = [];

        foreach ($files as $file) {
            $this->contentTypes[$file['path']] = $file['content_type'];
        }

        if (! array_key_exists($entrypoint, $this->contentTypes)) {
            throw new StandalonePackException(503, ["{$where} entrypoint [{$entrypoint}] is not in the list of files the Worker returned."]);
        }

        $this->bundle = $this->readBundle($orgSlug, $where, $entrypoint, $entry['html'], $files);

        $errors = $this->validator->validate($this->bundle, $where, $entrypoint);
        if ($errors !== []) {
            throw new StandalonePackException(422, $errors);
        }

        return $this->packDocument($entrypoint, $this->bundle[$entrypoint]);
    }

    /**
     * Reads the listed files in path order, once per sha256. Reading stops as
     * soon as the listed sizes pass the validator's file or byte limit, so the
     * validator reports the limit without the packer fetching the whole bundle.
     *
     * @param  list<array{path: string, sha256: string, content_type: string, size_bytes: int}>  $files
     * @return array<string, string>
     */
    private function readBundle(string $orgSlug, string $where, string $entrypoint, string $html, array $files): array
    {
        usort($files, fn (array $left, array $right): int => strcmp($left['path'], $right['path']));

        $bundle = [];
        $declaredBytes = 0;

        foreach ($files as $file) {
            $declaredBytes += $file['size_bytes'];

            if (count($bundle) >= PublishContractValidator::MAX_BUNDLE_FILES || $declaredBytes > PublishContractValidator::MAX_BUNDLE_BYTES) {
                break;
            }

            if ($file['path'] === $entrypoint) {
                $bundle[$file['path']] = $html;

                continue;
            }

            $bytes = $this->directory->fetchBlob($orgSlug, $file['sha256']);

            if ($bytes === null) {
                $this->refusals[] = "{$where} file [{$file['path']}] could not be read from the Worker.";

                continue;
            }

            if (strlen($bytes) !== $file['size_bytes']) {
                $this->refusals[] = "{$where} file [{$file['path']}] does not match its listed size.";

                continue;
            }

            $bundle[$file['path']] = $bytes;
        }

        if ($this->refusals !== []) {
            throw new StandalonePackException(422, $this->refusals);
        }

        return $bundle;
    }

    private function packDocument(string $entrypoint, string $html): string
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        // The encoding hint libxml needs for UTF-8 comes back as a comment; drop it.
        foreach (iterator_to_array($document->childNodes) as $node) {
            if ($node instanceof DOMComment && str_starts_with($node->nodeValue, '?xml')) {
                $document->removeChild($node);
            }
        }

        $baseDirectory = $this->directoryOf($entrypoint);
        $deferred = [];

        foreach ($this->elements($document, 'link') as $link) {
            $this->packLink($document, $link, $baseDirectory);
        }

        foreach ($this->elements($document, 'style') as $style) {
            $css = $this->inlineCss($style->textContent, $baseDirectory);
            $style->textContent = $this->escapeClosingTag($css, 'style');
        }

        foreach ($this->elements($document, 'script') as $script) {
            $this->packScript($document, $script, $baseDirectory, $deferred);
        }

        $xpath = new DOMXPath($document);
        foreach ($xpath->query('//*[@style]') ?: [] as $styled) {
            if ($styled instanceof DOMElement) {
                $styled->setAttribute('style', $this->inlineCss($styled->getAttribute('style'), $baseDirectory));
            }
        }

        $body = $document->getElementsByTagName('body')->item(0) ?? $document->documentElement;
        foreach ($deferred as $script) {
            $body->appendChild($script);
        }

        $this->packMedia($document, $baseDirectory);
        $this->refuseRemainingReferences($document);

        if ($this->refusals !== []) {
            throw new StandalonePackException(422, $this->refusals);
        }

        return $document->saveHTML();
    }

    private function packLink(DOMDocument $document, DOMElement $link, string $baseDirectory): void
    {
        $rel = strtolower(trim($link->getAttribute('rel')));
        $href = $link->getAttribute('href');

        if ($rel === '' || ! $link->hasAttribute('href') || ! $this->isLocal($href)) {
            return;
        }

        $path = $this->resolve($baseDirectory, $href);

        if ($path === null) {
            $this->refusals[] = "link [{$href}] is not a file in files/.";

            return;
        }

        if (str_contains($rel, 'stylesheet')) {
            $style = $document->createElement('style');
            if ($link->hasAttribute('media')) {
                $style->setAttribute('media', $link->getAttribute('media'));
            }
            $style->appendChild($document->createTextNode($this->escapeClosingTag($this->inlineCss($this->bundle[$path], $this->directoryOf($path)), 'style')));
            $link->parentNode?->replaceChild($style, $link);

            return;
        }

        if (preg_match('/\bicon\b/', $rel) === 1) {
            $link->setAttribute('href', $this->dataUri($path));

            return;
        }

        $this->refusals[] = "link [rel={$rel}] [{$href}] cannot be packed.";
    }

    /**
     * @param  list<DOMElement>  $deferred  moved to the end of the body, in document order
     */
    private function packScript(DOMDocument $document, DOMElement $script, string $baseDirectory, array &$deferred): void
    {
        if (! $script->hasAttribute('src')) {
            return;
        }

        $src = $script->getAttribute('src');
        $type = strtolower(trim($script->getAttribute('type')));

        if ($type === 'module') {
            $this->refusals[] = "script [{$src}] is a module script, which cannot be packed.";

            return;
        }

        $path = $this->resolve($baseDirectory, $src);

        if ($path === null) {
            $this->refusals[] = "script [{$src}] is not a file in files/.";

            return;
        }

        $inline = $document->createElement('script');
        foreach ($script->attributes as $attribute) {
            if (! in_array($attribute->nodeName, ['src', 'defer', 'async'], true)) {
                $inline->setAttribute($attribute->nodeName, $attribute->nodeValue);
            }
        }
        $inline->appendChild($document->createTextNode($this->escapeClosingTag($this->inlineScript($this->bundle[$path], $baseDirectory), 'script')));

        // A deferred script, or one in <head>, runs after the body exists, so it
        // moves to the end of the body. Any other script stays where it stood.
        if ($script->hasAttribute('defer') || $script->hasAttribute('async') || $this->isInHead($script)) {
            $script->parentNode?->removeChild($script);
            $deferred[] = $inline;

            return;
        }

        $script->parentNode?->replaceChild($inline, $script);
    }

    /**
     * @return list<DOMElement>
     */
    private function elements(DOMDocument $document, string $tag): array
    {
        return iterator_to_array($document->getElementsByTagName($tag));
    }

    private function isInHead(DOMElement $element): bool
    {
        for ($node = $element->parentNode; $node !== null; $node = $node->parentNode) {
            if ($node instanceof DOMElement && strtolower($node->tagName) === 'head') {
                return true;
            }
        }

        return false;
    }

    private function packMedia(DOMDocument $document, string $baseDirectory): void
    {
        foreach (['img', 'source', 'video', 'audio', 'track'] as $tag) {
            foreach ($this->elements($document, $tag) as $element) {
                $this->packAttribute($element, 'src', $baseDirectory);
                $this->packAttribute($element, 'poster', $baseDirectory);

                if ($element->hasAttribute('srcset')) {
                    $candidates = array_map('trim', explode(',', $element->getAttribute('srcset')));
                    $packed = [];
                    foreach ($candidates as $candidate) {
                        [$url, $descriptor] = array_pad(preg_split('/\s+/', $candidate, 2) ?: [], 2, '');
                        if ($url === '') {
                            continue;
                        }
                        $path = $this->isLocal($url) ? $this->resolve($baseDirectory, $url) : null;
                        if ($this->isLocal($url) && $path === null) {
                            $this->refusals[] = "srcset candidate [{$url}] is not a file in files/.";

                            continue;
                        }
                        $packed[] = trim(($path !== null ? $this->dataUri($path) : $url).' '.$descriptor);
                    }
                    $element->setAttribute('srcset', implode(', ', $packed));
                }
            }
        }
    }

    private function packAttribute(DOMElement $element, string $attribute, string $baseDirectory): void
    {
        if (! $element->hasAttribute($attribute)) {
            return;
        }

        $value = $element->getAttribute($attribute);

        if (! $this->isLocal($value)) {
            return;
        }

        $path = $this->resolve($baseDirectory, $value);

        if ($path === null) {
            $this->refusals[] = "{$element->tagName} [{$attribute}] [{$value}] is not a file in files/.";

            return;
        }

        $element->setAttribute($attribute, $this->dataUri($path));
    }

    /**
     * Any relative reference left after packing is one the packer cannot inline,
     * such as a link to another page or an iframe of a bundle file.
     */
    private function refuseRemainingReferences(DOMDocument $document): void
    {
        foreach (['a', 'area', 'iframe', 'embed', 'object', 'use', 'image', 'form', 'base'] as $tag) {
            foreach ($this->elements($document, $tag) as $element) {
                foreach (['href', 'src', 'data', 'action', 'xlink:href'] as $attribute) {
                    if ($element->hasAttribute($attribute) && $this->isLocal($element->getAttribute($attribute))) {
                        $this->refusals[] = "{$element->tagName} [{$attribute}] [{$element->getAttribute($attribute)}] cannot be packed; one file cannot hold another page.";
                    }
                }
            }
        }
    }

    private function inlineCss(string $css, string $baseDirectory): string
    {
        if (preg_match_all('/@import\s+(?:url\(\s*)?["\']?([^"\')\s;]+)/i', $css, $imports) !== false) {
            foreach ($imports[1] as $import) {
                if ($this->isLocal($import)) {
                    $this->refusals[] = "stylesheet @import [{$import}] cannot be packed.";
                }
            }
        }

        return (string) preg_replace_callback(
            '/url\(\s*(["\']?)([^"\')]*?)\1\s*\)/i',
            function (array $match) use ($baseDirectory): string {
                $reference = trim($match[2]);

                if (! $this->isLocal($reference)) {
                    return $match[0];
                }

                $path = $this->resolve($baseDirectory, $reference);

                if ($path === null) {
                    $this->refusals[] = "url() [{$reference}] is not a file in files/.";

                    return $match[0];
                }

                return 'url("'.$this->dataUri($path).'")';
            },
            $css,
        );
    }

    private function inlineScript(string $script, string $baseDirectory): string
    {
        return (string) preg_replace_callback(
            '/(["\'`])([^"\'`\\\\\r\n]{1,255})\1/',
            function (array $match) use ($baseDirectory): string {
                $literal = $match[2];

                if (! $this->isLocal($literal) || ! $this->namesAsset($literal)) {
                    return $match[0];
                }

                $path = $this->resolve($baseDirectory, $literal);

                if ($path === null) {
                    $this->refusals[] = "script references [{$literal}], which is not a file in files/.";

                    return $match[0];
                }

                return $match[1].$this->dataUri($path).$match[1];
            },
            $script,
        );
    }

    private function namesAsset(string $literal): bool
    {
        $extension = strtolower(pathinfo(explode('?', explode('#', $literal, 2)[0], 2)[0], PATHINFO_EXTENSION));

        return in_array($extension, self::ASSET_EXTENSIONS, true);
    }

    private function escapeClosingTag(string $text, string $tag): string
    {
        return (string) preg_replace('#</('.$tag.')#i', '<\\/$1', $text);
    }

    /**
     * A reference the bundle can name: relative, with no scheme, no leading
     * slash and not a fragment or a data URI.
     */
    private function isLocal(string $reference): bool
    {
        $reference = trim($reference);

        if ($reference === '' || str_starts_with($reference, '#') || str_starts_with($reference, '/')) {
            return false;
        }

        return preg_match('/^[a-z][a-z0-9+.\-]*:/i', $reference) !== 1;
    }

    /**
     * The bundle path a relative reference names, or null when no file has it.
     */
    private function resolve(string $baseDirectory, string $reference): ?string
    {
        $reference = explode('?', explode('#', trim($reference), 2)[0], 2)[0];
        $segments = $baseDirectory === '' ? [] : explode('/', $baseDirectory);

        foreach (explode('/', $reference) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                if ($segments === []) {
                    return null;
                }
                array_pop($segments);

                continue;
            }

            $segments[] = $segment;
        }

        $path = implode('/', $segments);

        return array_key_exists($path, $this->bundle) ? $path : null;
    }

    private function directoryOf(string $path): string
    {
        $directory = dirname($path);

        return $directory === '.' ? '' : $directory;
    }

    private function dataUri(string $path): string
    {
        return 'data:'.($this->contentTypes[$path] ?? 'application/octet-stream').';base64,'.base64_encode($this->bundle[$path]);
    }
}
