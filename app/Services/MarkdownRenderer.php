<?php

declare(strict_types=1);

namespace App\Services;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\Table\TableExtension;
use League\CommonMark\MarkdownConverter;

final class MarkdownRenderer
{
    public const int MAX_OUTPUT_BYTES = 1_048_576;

    private readonly MarkdownConverter $converter;

    public function __construct()
    {
        $environment = new Environment([
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
        ]);
        $environment->addExtension(new CommonMarkCoreExtension);
        $environment->addExtension(new TableExtension);

        $this->converter = new MarkdownConverter($environment);
    }

    /**
     * Convert markdown to an HTML fragment (no wrapping document).
     */
    public function toFragment(string $markdown): string
    {
        return trim($this->converter->convert($markdown)->getContent());
    }

    /**
     * Convert markdown to a complete self-contained HTML document
     * with embedded styling, ready for hosting.
     */
    public function toFullDocument(string $markdown): string
    {
        $body = $this->toFragment($markdown);

        $css = $this->embeddedCss();

        $html = <<<HTML
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Markdown — artfct</title>
<style>{$css}</style>
</head>
<body>
{$body}
<a class="artfct-badge-link" href="https://artfct.dev" target="_blank">hosted by artfct</a>
</body>
</html>
HTML;

        if (strlen($html) > self::MAX_OUTPUT_BYTES) {
            throw new \RuntimeException('Rendered output exceeds 1 MB limit.');
        }

        return $html;
    }

    /**
     * Return the embedded CSS used for markdown rendering.
     */
    public function embeddedCss(): string
    {
        $mono = 'ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace';
        $sans = "-apple-system, BlinkMacSystemFont, 'Segoe UI', Helvetica, Arial, sans-serif, 'Apple Color Emoji', 'Segoe UI Emoji'";

        return <<<CSS
  @media (prefers-color-scheme: dark) {
    :root {
      --bg: #0d1117;
      --fg: #c9d1d9;
      --fg-muted: #8b949e;
      --border: #30363d;
      --block-bg: #161b22;
      --code-bg: #343941;
      --link: #58a6ff;
      --heading: #f0f6fc;
      --quote-border: #3fb950;
      --quote-fg: #8b949e;
      --table-stripe: #161b22;
      --hr: #21262d;
    }
  }
  @media (prefers-color-scheme: light) {
    :root {
      --bg: #ffffff;
      --fg: #24292f;
      --fg-muted: #57606a;
      --border: #d0d7de;
      --block-bg: #f6f8fa;
      --code-bg: #afb8c133;
      --link: #0969da;
      --heading: #1f2328;
      --quote-border: #2da44e;
      --quote-fg: #656d76;
      --table-stripe: #f6f8fa;
      --hr: #d8dee4;
    }
  }

  * { box-sizing: border-box; }

  body {
    max-width: 820px;
    margin: 0 auto;
    padding: 2rem 1.5rem 4rem;
    font-family: {$sans};
    font-size: 16px;
    line-height: 1.65;
    color: var(--fg);
    background: var(--bg);
    -webkit-text-size-adjust: 100%;
    text-size-adjust: 100%;
  }

  h1, h2, h3, h4, h5, h6 {
    margin-top: 1.75em;
    margin-bottom: 0.5em;
    font-weight: 600;
    line-height: 1.3;
    color: var(--heading);
  }

  h1 { font-size: 1.85em; padding-bottom: 0.35em; border-bottom: 1px solid var(--border); }
  h2 { font-size: 1.45em; padding-bottom: 0.3em; border-bottom: 1px solid var(--border); }
  h3 { font-size: 1.2em; }
  h4 { font-size: 1.05em; }

  p { margin: 0 0 1em; }

  a { color: var(--link); text-decoration: none; }
  a:hover { text-decoration: underline; }

  strong { font-weight: 600; }

  hr {
    height: 1px;
    margin: 1.5em 0;
    background: var(--hr);
    border: 0;
  }

  blockquote {
    margin: 0 0 1em;
    padding: 0.5em 1em;
    color: var(--quote-fg);
    border-left: 4px solid var(--quote-border);
    background: var(--block-bg);
    border-radius: 0 6px 6px 0;
  }
  blockquote > :first-child { margin-top: 0; }
  blockquote > :last-child { margin-bottom: 0; }

  code {
    font-family: {$mono};
    font-size: 0.88em;
    padding: 0.15em 0.4em;
    background: var(--code-bg);
    border-radius: 4px;
  }

  pre {
    margin: 0 0 1em;
    padding: 1rem;
    background: var(--block-bg);
    border: 1px solid var(--border);
    border-radius: 6px;
    overflow-x: auto;
  }
  pre code {
    padding: 0;
    background: none;
    font-size: 0.85em;
    line-height: 1.55;
  }

  ul, ol {
    margin: 0 0 1em;
    padding-left: 2em;
  }
  li + li { margin-top: 0.25em; }
  li > p { margin-bottom: 0; }

  table {
    width: 100%;
    margin: 0 0 1em;
    border-collapse: collapse;
    border-spacing: 0;
  }
  th, td {
    padding: 0.5em 0.85em;
    border: 1px solid var(--border);
    text-align: left;
  }
  th {
    font-weight: 600;
    background: var(--block-bg);
  }
  tr:nth-child(even) td { background: var(--table-stripe); }

  img {
    max-width: 100%;
    height: auto;
    border-radius: 6px;
  }

  .artfct-badge-link {
    display: inline-flex;
    align-items: center;
    gap: 0.35em;
    margin-top: 2.5rem;
    padding: 0.35em 0.75em;
    font-family: {$mono};
    font-size: 0.75em;
    color: var(--fg-muted);
    border: 1px solid var(--border);
    border-radius: 6px;
    text-decoration: none;
    transition: border-color 0.15s ease, color 0.15s ease;
  }
  .artfct-badge-link:hover {
    border-color: var(--link);
    color: var(--link);
    text-decoration: none;
  }
CSS;
    }
}
