<?php

namespace App\Http\Controllers;

use App\Support\AiToolSetup;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\File;

/**
 * Serves the docs in the llms.txt format (https://llmstxt.org) so an AI tool
 * can read them without rendering the page: `/llms.txt` is a short index and
 * `/llms-full.txt` is the whole connection guide plus the REST API summary.
 */
class LlmsTextController extends Controller
{
    public function index(): Response
    {
        $setup = AiToolSetup::forCurrentEnvironment();
        $base = $setup->baseUrl;

        $lines = [
            '# Artfct',
            '',
            '> Artfct keeps what your AI tools make (reports, dashboards, tables, documents and mockups), shares it with your team, and lets every connected AI tool search and read it, with sources.',
            '',
            "Connect an AI tool by adding the hosted server address {$setup->mcpUrl()} and approving the sign-in in your browser. There is nothing to install and no key to copy.",
            '',
            '## Docs',
            '',
            "- [Full documentation for AI tools]({$base}/llms-full.txt): every setup guide, the tools, permissions, troubleshooting and the REST API in one file",
            "- [Connect your AI tool]({$base}/docs#mcp): setup steps for Claude Code, Codex, OpenCode, Antigravity, the Claude app, ChatGPT, Cursor, VS Code, Windsurf and Zed",
            "- [What your AI tool can do]({$base}/docs#connect-tools): the tools a connected AI tool can call",
            "- [Skills]({$base}/docs#skills): optional guidance packs for AI tools",
            "- [REST API reference]({$base}/docs#rest-api): for your own code",
            '',
            '## Optional',
            '',
            "- [Terms]({$base}/terms)",
            "- [Privacy policy]({$base}/privacy)",
        ];

        return $this->plainText($lines);
    }

    public function full(): Response
    {
        $setup = AiToolSetup::forCurrentEnvironment();

        $lines = [
            '# Artfct documentation',
            '',
            '> Artfct keeps what your AI tools make, shares it with your team, and lets every connected AI tool search and read it, with sources.',
            '',
            '## Connect your AI tool',
            '',
            'Artfct runs as a hosted server. Add this address to your AI tool and approve the sign-in that opens in your browser. Use the same address on every tool:',
            '',
            ...$this->codeBlock($setup->mcpUrl()),
            '',
            '### Set up several tools at once',
            '',
            'This command finds the AI tools on your computer (Claude Code, Codex, Cursor, OpenCode, VS Code, Windsurf, Zed, Antigravity and more) and adds Artfct to each one you pick. Each tool still asks you to sign in the first time you use it.',
            '',
            ...$this->codeBlock($setup->quickInstall()),
            '',
            'Antigravity also needs `"oauth": {}` added to its entry; see its steps below.',
            '',
        ];

        foreach ($setup->guides() as $guide) {
            $lines[] = "### {$guide['label']}".($guide['verified'] ? '' : ' (not yet tested by Artfct)');
            $lines[] = '';

            if (isset($guide['intro'])) {
                $lines[] = $guide['intro'];
                $lines[] = '';
            }

            foreach ($guide['steps'] as $number => $step) {
                $lines[] = ($number + 1).'. '.$step['text'];

                if (isset($step['code'])) {
                    $lines[] = '';
                    array_push($lines, ...array_map(
                        fn (string $line): string => "   {$line}",
                        $this->codeBlock($step['code']),
                    ));
                    $lines[] = '';
                }
            }

            $lines[] = '';
        }

        $lines = [
            ...$lines,
            '### Check that it works',
            '',
            'Ask your AI tool: "Which Artfct workspace am I connected to?" It should answer with your team’s name.',
            '',
            '## What your AI tool can do',
            '',
            '| Tool | What it does | Permission |',
            '| --- | --- | --- |',
            ...array_map(
                fn (array $tool): string => "| `{$tool['name']}` | {$tool['does']} | {$tool['permission']} |",
                $setup->tools(),
            ),
            '',
            '## Permissions',
            '',
            'During sign-in your tool asks for the permissions it needs and you approve them. Each connection only reaches the team you signed in to.',
            '',
            '| Permission | Allows | Details |',
            '| --- | --- | --- |',
            ...array_map(
                fn (array $permission): string => "| `{$permission['name']}` | {$permission['type']} | {$permission['note']} |",
                $setup->permissions(),
            ),
            '',
            '## Troubleshooting',
            '',
            ...array_map(
                fn (array $problem): string => "- **{$problem['name']}** ({$problem['type']}): {$problem['note']}",
                $setup->troubleshooting(),
            ),
            '',
            '## Limits and data kept',
            '',
            'Each team can make 120 requests per minute, and so can each connection. Past that, your tool is asked to wait (status 429, with a Retry-After header). Records of what connected tools did are kept for 90 days by default.',
            '',
            '## Project instructions',
            '',
            'Paste this into AGENTS.md, CLAUDE.md or your Cursor rules so your AI tool knows when to use Artfct:',
            '',
            ...$this->codeBlock($setup->projectInstructions(), 'md'),
            '',
            '## Skills',
            '',
            ...array_map(
                fn (array $skill): string => "- **{$skill['name']}**: {$skill['note']} Install with `{$skill['install']}`.",
                $setup->skills(),
            ),
            '',
            ...$this->restApiSummary($setup->baseUrl),
        ];

        return $this->plainText($lines);
    }

    /**
     * @return list<string>
     */
    private function restApiSummary(string $baseUrl): array
    {
        $contract = json_decode(
            File::get(base_path('openapi/artfct.yaml')),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        $lines = [
            '## REST API',
            '',
            "For your own code. Base URL {$baseUrl}. Send an organization API token, created in team settings, as a bearer token. Connecting an AI tool never needs a token. Full reference: {$baseUrl}/docs#rest-api",
            '',
        ];

        foreach ($contract['paths'] as $path => $item) {
            foreach (['get', 'post', 'put', 'patch', 'delete'] as $method) {
                if (! isset($item[$method])) {
                    continue;
                }

                $summary = $item[$method]['summary'] ?? '';
                $lines[] = '- `'.strtoupper($method)." {$path}`".($summary !== '' ? ": {$summary}" : '');
            }
        }

        return $lines;
    }

    /**
     * @return list<string>
     */
    private function codeBlock(string $code, string $language = ''): array
    {
        return ["```{$language}", ...explode("\n", $code), '```'];
    }

    /**
     * @param  list<string>  $lines
     */
    private function plainText(array $lines): Response
    {
        return response(implode("\n", $lines)."\n")
            ->header('Content-Type', 'text/plain; charset=UTF-8');
    }
}
