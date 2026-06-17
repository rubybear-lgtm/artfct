<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" @class(['dark' => ($appearance ?? 'system') == 'dark'])>
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <script>
            (function () {
                try {
                    var t = localStorage.getItem('artfct-theme');
                    if (t === 'dark' || t === 'light') {
                        document.documentElement.setAttribute('data-theme', t);
                    }
                } catch (e) {}
            })();
        </script>

        <link rel="icon" href="/favicon.ico" sizes="any">
        <link rel="icon" href="/favicon.svg" type="image/svg+xml">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png">

        {{-- OG / social meta --}}
        <meta property="og:site_name" content="artfct" />
        <meta property="og:image" content="{{ asset('og-image.svg') }}" />
        <meta property="og:image:width" content="1200" />
        <meta property="og:image:height" content="630" />
        <meta name="twitter:card" content="summary_large_image" />
        <meta name="twitter:image" content="{{ asset('og-image.svg') }}" />

        {{-- canonical --}}
        <link rel="canonical" href="{{ url()->current() }}" />

        @php
            $meta = $page['props']['meta'] ?? [];
            $pageTitle = $meta['title'] ?? 'artfct';
            $pageDescription = $meta['description'] ?? 'Share self-contained HTML files instantly. Drop a file, get a link. No sign-up required.';
        @endphp

        <title>{{ $pageTitle }}</title>
        <meta name="description" content="{{ $pageDescription }}" />
        <meta property="og:title" content="{{ $pageTitle }}" />
        <meta property="og:description" content="{{ $pageDescription }}" />
        <meta property="og:url" content="{{ url()->current() }}" />
        <meta property="og:type" content="website" />
        <meta name="twitter:title" content="{{ $pageTitle }}" />
        <meta name="twitter:description" content="{{ $pageDescription }}" />

        {{-- structured data --}}
        <script type="application/ld+json">
        {
            "@@context": "https://schema.org",
            "@type": "WebApplication",
            "name": "artfct",
            "url": "https://artfct.dev",
            "description": "{{ $pageDescription }}",
            "applicationCategory": "DeveloperApplication",
            "operatingSystem": "Web, macOS, Linux",
            "offers": {
                "@type": "Offer",
                "price": "0",
                "priceCurrency": "USD"
            }
        }
        </script>

        @fonts

        @viteReactRefresh
        @vite(['resources/css/app.css', 'resources/js/app.tsx', "resources/js/pages/{$page['component']}.tsx"])
        <x-inertia::head />
    </head>
    <body class="font-sans antialiased">
        <x-inertia::app />

        {{-- Server-rendered fallback content for crawlers — visible when SSR or JS unavailable.
             Hidden after Inertia mounts so users see only the React UI. --}}
        <div id="ssr-fallback" style="position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border-width:0">
            @php
                $fallbackMeta = $page['props']['meta'] ?? [];
                $fallbackTitle = $fallbackMeta['title'] ?? 'artfct';
            @endphp

            @switch($page['component'] ?? '')
                @case('welcome')
                    <h1>{{ $fallbackTitle }}</h1>
                    <p>{{ $pageDescription }}</p>
                    <p>artfct is an instant encrypted HTML sharing tool for developers. Drop a self-contained HTML or Markdown file — via browser, CLI, API, or AI agent — and get a shareable link in seconds. No sign-up, no accounts, no configuration. Every artifact is encrypted in the browser with AES-GCM before it ever reaches the server. Choose from three tiers: public (open permanent URLs), secure (high-entropy fragment keys with blurred previews), or ephemeral (auto-expiring content with 1-week to 1-year TTLs).</p>
                    <p>Install the artfct skill in Claude Code, Cursor, Codex, or Gemini and deploy artifacts directly from your agent. The artfct MCP server handles authentication, deployment, and link management automatically — your agent builds, artfct serves. Supports CLI piping from stdin, REST API integration, and one-click drag-and-drop in the browser.</p>
                    <p>Perfect for sharing UI prototypes, dashboard previews, AI-generated visual outputs, HTML demos, slide decks, markdown documents, Mermaid diagrams, JSON tables, API diffs, env-diffs, regex testers, and any other self-contained web content. No accounts required. Ephemeral by default.</p>
                    @break

                @case('docs')
                    <h1>{{ $fallbackTitle }}</h1>
                    <p>{{ $pageDescription }}</p>
                    <p>Full REST API reference for creating, serving, listing, and managing HTML artifacts programmatically. Includes CLI documentation, MCP server setup, and skills installation guides.</p>
                    @break

                @case('blog')
                    @php
                        $fallbackPostSlug = $page['props']['postSlug'] ?? null;
                        $fallbackPosts = $page['props']['posts'] ?? [];
                    @endphp
                    @if ($fallbackPostSlug && !empty($fallbackPosts))
                        @php
                            $matchedPost = collect($fallbackPosts)->firstWhere('slug', $fallbackPostSlug);
                        @endphp
                        @if ($matchedPost)
                            <h1>{{ $matchedPost['title'] }} — artfct</h1>
                            <p>{{ $matchedPost['description'] }}</p>
                        @else
                            <h1>{{ $fallbackTitle }}</h1>
                            <p>{{ $pageDescription }}</p>
                        @endif
                    @else
                        <h1>{{ $fallbackTitle }}</h1>
                        <p>{{ $pageDescription }}</p>
                    @endif
                    @break

                @default
                    <h1>{{ $fallbackTitle }}</h1>
            @endswitch
        </div>

        <script>
            (function () {
                var fallback = document.getElementById('ssr-fallback');
                if (!fallback) return;
                var check = function () {
                    var app = document.getElementById('app');
                    if (app && app.children.length > 0) {
                        fallback.style.display = 'none';
                    } else {
                        requestAnimationFrame(check);
                    }
                };
                requestAnimationFrame(check);
            })();
        </script>
    </body>
</html>
