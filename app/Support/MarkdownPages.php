<?php

namespace App\Support;

use App\Http\Controllers\LlmsTextController;
use App\Services\Blog\BlogContent;
use App\Services\Blog\BlogPost;

/**
 * Markdown versions of the public pages, for agents that send
 * `Accept: text/markdown` (NegotiateMarkdown). Built from the same sources
 * the HTML renders from (AiToolSetup, BlogContent, llms-full.txt), so the
 * two never drift, and without Cloudflare's paid Markdown for Agents.
 */
class MarkdownPages
{
    public function __construct(private BlogContent $blog) {}

    /**
     * The Markdown for a path, or null when the page has no Markdown version.
     */
    public function forPath(string $path): ?string
    {
        $path = '/'.trim($path, '/');

        return match (true) {
            $path === '/' => $this->home(),
            $path === '/connect' => $this->connectIndex(),
            str_starts_with($path, '/connect/') => $this->connectGuide(substr($path, strlen('/connect/'))),
            $path === '/blog' => $this->blogIndex(),
            str_starts_with($path, '/blog/') => $this->blogPost(substr($path, strlen('/blog/'))),
            $path === '/docs' => (string) app(LlmsTextController::class)->full()->getContent(),
            default => null,
        };
    }

    private function home(): string
    {
        $setup = AiToolSetup::forCurrentEnvironment();
        $base = $setup->baseUrl;

        return $this->join([
            '# Artfct: shared context and AI artifacts for every agent',
            '',
            '> Every agent sees what the others made.',
            '',
            'Artfct indexes every AI artifact your agents publish: reports, specs, tables and mockups. Claude, Cursor, Codex and ChatGPT can search across your team’s work, read the right part and cite where it came from.',
            '',
            '## Connect your agent',
            '',
            "Paste this prompt into any agent. It finds the steps for that app, connects to your team’s workspace and asks you to approve the sign-in. Setup guides for each agent: {$base}/connect",
            '',
            '```',
            $setup->agentSetupPrompt(),
            '```',
            '',
            '## Setup guides',
            '',
            ...array_map(fn (array $guide): string => "- [{$guide['label']}]({$base}/connect/{$guide['id']}): {$guide['summary']}", $setup->guides()),
            '',
            '## Latest posts',
            '',
            ...array_map(fn (BlogPost $post): string => "- [{$post->title}]({$base}/blog/{$post->slug}): {$post->description}", array_slice($this->blog->all(), 0, 3)),
            '',
            "Full documentation for AI tools: {$base}/llms-full.txt",
        ]);
    }

    private function connectIndex(): string
    {
        $setup = AiToolSetup::forCurrentEnvironment();
        $base = $setup->baseUrl;

        return $this->join([
            '# Agent setup: connect every agent to one Artfct workspace',
            '',
            'Connect each AI tool your team uses to the same Artfct workspace. Then what one agent publishes, the others can find.',
            '',
            '## One prompt for any agent',
            '',
            '```',
            $setup->agentSetupPrompt(),
            '```',
            '',
            '## Guides',
            '',
            ...array_map(fn (array $guide): string => "- [{$guide['label']}]({$base}/connect/{$guide['id']}) ({$guide['method']}): {$guide['summary']}", $setup->guides()),
            '',
            '## Set up several agents at once',
            '',
            '```',
            $setup->quickInstall(),
            '```',
        ]);
    }

    private function connectGuide(string $id): ?string
    {
        $setup = AiToolSetup::forCurrentEnvironment();
        $guide = collect($setup->guides())->firstWhere('id', $id);

        if ($guide === null) {
            return null;
        }

        return $this->join([
            "# Connect {$guide['label']} to Artfct",
            '',
            $guide['summary'],
            '',
            $setup->guideMarkdown($guide),
            '',
            '## Check that it works',
            '',
            'Ask your agent: "Which Artfct workspace am I connected to?" It should name your team and say search is on.',
            '',
            "All agents: {$setup->baseUrl}/connect",
        ]);
    }

    private function blogIndex(): string
    {
        $base = AiToolSetup::forCurrentEnvironment()->baseUrl;

        return $this->join([
            '# Artfct blog',
            '',
            ...array_map(fn (BlogPost $post): string => "- [{$post->title}]({$base}/blog/{$post->slug}) ({$post->date}): {$post->description}", $this->blog->all()),
        ]);
    }

    private function blogPost(string $slug): ?string
    {
        $post = $this->blog->find($slug);

        if ($post === null) {
            return null;
        }

        return $this->join(["# {$post->title}", '', "_{$post->date}_", '', trim($post->markdown)]);
    }

    /**
     * @param  list<string>  $lines
     */
    private function join(array $lines): string
    {
        return implode("\n", $lines)."\n";
    }
}
