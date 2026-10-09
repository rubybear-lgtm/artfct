<!DOCTYPE html>
<html
    lang="{{ str_replace('_', '-', app()->getLocale()) }}"
    @class([
        'app-theme' => ! in_array($page['component'] ?? null, ['welcome', 'landing'], true),
        'dark' => ($appearance ?? 'system') == 'dark',
    ])
>
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
        <meta property="og:image" content="{{ asset('og-image.png') }}" />
        <meta property="og:image:width" content="1200" />
        <meta property="og:image:height" content="630" />
        <meta name="twitter:card" content="summary_large_image" />
        <meta name="twitter:image" content="{{ asset('og-image.png') }}" />

        {{-- canonical --}}
        <link rel="canonical" href="{{ url()->current() }}" />

        @php
            $meta = $page['props']['meta'] ?? [];
            $pageTitle = $meta['title'] ?? 'artfct';
            $pageDescription = $meta['description'] ?? 'Share self-contained HTML files instantly. Drop a file, get a link. No sign-up required.';
            $blogPost = ($page['component'] ?? null) === 'blog-show' ? ($page['props']['post'] ?? null) : null;
        @endphp

        <x-inertia::head>
            <title>{{ $pageTitle }}</title>
            <meta name="description" content="{{ $pageDescription }}" />
            <meta property="og:title" content="{{ $pageTitle }}" />
            <meta property="og:description" content="{{ $pageDescription }}" />
            <meta name="twitter:title" content="{{ $pageTitle }}" />
            <meta name="twitter:description" content="{{ $pageDescription }}" />
        </x-inertia::head>
        <meta property="og:url" content="{{ url()->current() }}" />
        <meta property="og:type" content="{{ $blogPost ? 'article' : 'website' }}" />

        {{-- structured data --}}
        <script type="application/ld+json">
        {
            "@@context": "https://schema.org",
            "@type": "WebApplication",
            "name": "artfct",
            "url": "https://artfct.dev",
            "description": "{{ $pageDescription }}",
            "applicationCategory": "BusinessApplication",
            "operatingSystem": "Web"
        }
        </script>

        @if ($blogPost)
            @php
                $blogPostingJson = json_encode([
                    '@context' => 'https://schema.org',
                    '@type' => 'BlogPosting',
                    'headline' => $blogPost['title'],
                    'description' => $blogPost['description'],
                    'datePublished' => $blogPost['date'],
                    'image' => asset('og-image.png'),
                    'mainEntityOfPage' => url()->current(),
                    'author' => ['@type' => 'Organization', 'name' => 'artfct', 'url' => 'https://artfct.dev'],
                    'publisher' => ['@type' => 'Organization', 'name' => 'artfct', 'url' => 'https://artfct.dev'],
                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG);
            @endphp
            <script type="application/ld+json">{!! $blogPostingJson !!}</script>
        @endif

        @fonts

        @viteReactRefresh
        @vite(['resources/css/app.css', 'resources/js/app.tsx', "resources/js/pages/{$page['component']}.tsx"])
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
                @case('landing')
                    <h1>Your AI makes things. Artfct remembers them.</h1>
                    <p>{{ $pageDescription }}</p>
                    <p>Share the reports, tables, documents and mockups your AI makes. Everything shared is stored and indexed for your team, and every AI tool on the team can read it and cite its sources. You choose what to share. Your artifacts are never used to train AI, and you can export everything any time.</p>
                    <p>Set up once: sign up, invite your team and add Artfct to your AI tool. Works with Claude, ChatGPT, Copilot, Cursor and other major AI tools. Start with Free, try Team free, or talk about Enterprise.</p>
                    @break

                @case('welcome')
                    <h1>{{ $fallbackTitle }}</h1>
                    <p>{{ $pageDescription }}</p>
                    <p>artfct turns a self-contained HTML or Markdown file into a private, shareable link in seconds. No sign-up, no accounts. Every free link is encrypted in your browser before upload, and the preview is blurred by default, so only someone with the full link can read it. Links expire 5 days after the last visit, and you can change that from Recent deployments.</p>
                    <p>Perfect for sharing UI prototypes, dashboard previews, AI-generated visual outputs, HTML demos, slide decks, markdown documents, Mermaid diagrams, JSON tables, API diffs, env-diffs, regex testers, and any other self-contained web content. No accounts required. Works in the browser, and with AI tools that support adding a remote connection.</p>
                    @break

                @case('docs')
                    <h1>{{ $fallbackTitle }}</h1>
                    <p>{{ $pageDescription }}</p>
                    <p>Full REST API reference for creating, serving, listing, and managing HTML artifacts programmatically. Includes setup guides for connecting your AI tool and installing the artfct skill.</p>
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
