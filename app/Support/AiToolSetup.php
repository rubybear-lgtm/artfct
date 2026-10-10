<?php

namespace App\Support;

/**
 * The single source for connecting an AI tool to Artfct. The docs page renders
 * it as props and `/llms-full.txt` renders it as Markdown, so the two cannot
 * drift apart.
 *
 * Step text may wrap inline code in backticks; the docs page renders those as
 * code and the Markdown view passes them through unchanged.
 *
 * A guide is `verified` only after the tool completed browser sign-in and a
 * tool call against staging (docs/mcp-runbook.md, "Client compatibility").
 */
class AiToolSetup
{
    public function __construct(public string $baseUrl)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    public static function forCurrentEnvironment(): self
    {
        return new self((string) config('app.url'));
    }

    public function mcpUrl(): string
    {
        return "{$this->baseUrl}/mcp";
    }

    /**
     * One command that adds Artfct to every supported AI tool it finds on the
     * machine. Each tool still asks the person to sign in afterwards.
     */
    public function quickInstall(): string
    {
        return "npx add-mcp@latest {$this->mcpUrl()} --name artfct --global";
    }

    /**
     * @return list<array{id: string, label: string, verified: bool, intro?: string, steps: list<array{text: string, code?: string}>}>
     */
    public function guides(): array
    {
        $url = $this->mcpUrl();

        return [
            [
                'id' => 'connect-claude-code',
                'label' => 'Claude Code',
                'verified' => true,
                'steps' => [
                    ['text' => 'Run this in your terminal:', 'code' => "claude mcp add --transport http artfct {$url}"],
                    ['text' => 'Start Claude Code, type `/mcp`, choose `artfct` and select `Authenticate`.'],
                    ['text' => 'Approve the sign-in that opens in your browser.'],
                ],
            ],
            [
                'id' => 'connect-codex',
                'label' => 'Codex',
                'verified' => true,
                'steps' => [
                    ['text' => 'Run this in your terminal:', 'code' => "codex mcp add artfct --url {$url}"],
                    ['text' => 'Sign in, and approve the page that opens in your browser:', 'code' => 'codex mcp login artfct'],
                    ['text' => 'Start Codex. Artfct is ready to use.'],
                ],
            ],
            [
                'id' => 'connect-opencode',
                'label' => 'OpenCode',
                'verified' => true,
                'steps' => [
                    ['text' => 'Run this in your terminal:', 'code' => "opencode mcp add artfct --url {$url}"],
                    ['text' => 'Sign in, and select Approve once. Approving the same page twice makes OpenCode report that the code is invalid or expired; if that happens, run the command again.', 'code' => 'opencode mcp auth artfct'],
                    ['text' => 'Start OpenCode. Artfct is ready to use.'],
                ],
            ],
            [
                'id' => 'connect-antigravity',
                'label' => 'Antigravity',
                'verified' => true,
                'steps' => [
                    ['text' => 'Run this in your terminal:', 'code' => "agy mcp add artfct {$url}"],
                    ['text' => 'Open `~/.gemini/config/mcp_config.json` and add `"oauth": {}` to the `artfct` entry, so it reads:', 'code' => $this->json(['artfct' => ['serverUrl' => $url, 'oauth' => new \stdClass]])],
                    ['text' => 'Start `agy`, type `/mcp`, choose `artfct` to sign in, and approve the page that opens in your browser.'],
                ],
            ],
            [
                'id' => 'connect-claude',
                'label' => 'Claude app',
                'verified' => false,
                'intro' => 'For Claude on the web and the desktop app. A connector added once is available everywhere you use Claude. On Team and Enterprise plans, an owner adds it first: Organization settings, then Connectors, then Add, then Custom, then Web, using the same details. Members then find it under Customize, then Connectors, and select Connect.',
                'steps' => [
                    ['text' => 'In Claude, open Customize, then Connectors. Select Add, then Add custom connector.'],
                    ['text' => 'Name it `artfct`, paste this address and select Continue:', 'code' => $url],
                    ['text' => 'Under Authentication choose Sign in now, and under OAuth client choose Register automatically. Select Add.'],
                    ['text' => 'Approve the sign-in that opens in your browser.'],
                ],
            ],
            [
                'id' => 'connect-chatgpt',
                'label' => 'ChatGPT',
                'verified' => false,
                'intro' => 'ChatGPT adds custom connectors in developer mode, on paid plans. On business plans an administrator may need to allow it first.',
                'steps' => [
                    ['text' => 'In ChatGPT, open Settings, then Apps & Connectors (called Connectors on some plans), then Advanced settings, and turn on Developer mode.'],
                    ['text' => 'Back in Apps & Connectors, select Create. Name it `artfct`, choose OAuth for authentication and paste this address:', 'code' => $url],
                    ['text' => 'Tick the box saying you trust this connector, select Create, then approve the sign-in that opens.'],
                    ['text' => 'In a chat, select the + button, then More, and turn on artfct.'],
                ],
            ],
            [
                'id' => 'connect-cursor',
                'label' => 'Cursor',
                'verified' => false,
                'steps' => [
                    ['text' => 'Add this to `~/.cursor/mcp.json` (or `.cursor/mcp.json` in one project):', 'code' => $this->json(['mcpServers' => ['artfct' => ['url' => $url]]])],
                    ['text' => 'Open Cursor Settings, find artfct in the tools list and select Connect, or run:', 'code' => 'cursor-agent mcp login artfct'],
                    ['text' => 'Approve the sign-in that opens in your browser.'],
                ],
            ],
            [
                'id' => 'connect-vscode',
                'label' => 'VS Code',
                'verified' => false,
                'intro' => 'For GitHub Copilot Chat in agent mode.',
                'steps' => [
                    ['text' => 'Add this to `.vscode/mcp.json` in your project, or open the command palette, run MCP: Open User Configuration and add it there for every project:', 'code' => $this->json(['servers' => ['artfct' => ['type' => 'http', 'url' => $url]]])],
                    ['text' => 'Select Start above the artfct entry and approve the sign-in when VS Code asks.'],
                    ['text' => 'In Copilot Chat, switch to Agent mode. Artfct appears in the tools list.'],
                ],
            ],
            [
                'id' => 'connect-windsurf',
                'label' => 'Windsurf',
                'verified' => false,
                'steps' => [
                    ['text' => 'Add this to `~/.codeium/windsurf/mcp_config.json`:', 'code' => $this->json(['mcpServers' => ['artfct' => ['serverUrl' => $url]]])],
                    ['text' => 'Quit and reopen Windsurf, then approve the sign-in when it asks.'],
                ],
            ],
            [
                'id' => 'connect-zed',
                'label' => 'Zed',
                'verified' => false,
                'steps' => [
                    ['text' => 'Open your Zed settings file and add:', 'code' => $this->json(['context_servers' => ['artfct' => ['url' => $url]]])],
                    ['text' => 'Open the Agent panel settings, find artfct and approve the sign-in when it asks.'],
                ],
            ],
            [
                'id' => 'connect-other',
                'label' => 'Other tools',
                'verified' => false,
                'intro' => 'Most AI tools have a place to add a remote server (sometimes called an HTTP, URL or streamable HTTP server). Artfct only runs as a hosted server, so a tool that can only start programs on your computer cannot connect.',
                'steps' => [
                    ['text' => 'Add a server named `artfct` with this address:', 'code' => $url],
                    ['text' => 'If your tool is set up with a JSON file, the entry usually looks like this:', 'code' => $this->json(['mcpServers' => ['artfct' => ['url' => $url]]])],
                    ['text' => "Approve the sign-in when your browser opens. Tools that need the sign-in details find them automatically at `{$this->baseUrl}/.well-known/oauth-protected-resource`."],
                ],
            ],
        ];
    }

    /**
     * What a connected AI tool can do, in plain language. The names must match
     * the tools `ArtfctServer` registers; the deprecated `deploy_to_canvas` is
     * left out on purpose.
     *
     * @return list<array{name: string, does: string, permission: string}>
     */
    public function tools(): array
    {
        return [
            ['name' => 'search_artifacts', 'does' => 'Search your team’s artifacts by what they are about, who or what made them, or when. Returns summaries, short excerpts and a link to each one.', 'permission' => 'artifacts:read'],
            ['name' => 'list_artifacts', 'does' => 'List your team’s artifacts by who made them, when they were published, how they are shared or how often they were opened.', 'permission' => 'artifacts:read'],
            ['name' => 'get_artifact', 'does' => 'Open the details of one artifact, or one of its earlier versions, and get a fresh link to view it.', 'permission' => 'artifacts:read'],
            ['name' => 'deploy_artifact', 'does' => 'Publish a page, report or document to your team, either as one self-contained HTML file or as a set of files. Choose who can open it: your whole team, only you, or anyone with the link. To update something you published before, pass its id: the new version keeps the same link. Publishing the same content again returns the same artifact.', 'permission' => 'artifacts:deploy'],
            ['name' => 'delete_artifact', 'does' => 'Permanently remove an artifact, unless it is on legal hold.', 'permission' => 'artifacts:delete'],
            ['name' => 'list_collections', 'does' => 'List your team’s collections.', 'permission' => 'collections:read'],
            ['name' => 'create_collection', 'does' => 'Create a collection.', 'permission' => 'collections:write'],
            ['name' => 'add_collection_artifact', 'does' => 'Add an artifact to a collection.', 'permission' => 'collections:write'],
            ['name' => 'get_usage', 'does' => 'Read your team’s storage, limits and indexing status.', 'permission' => 'usage:read'],
            ['name' => 'get_connection', 'does' => 'Show which team and person the connection belongs to, without revealing any credentials.', 'permission' => 'Any'],
        ];
    }

    /**
     * @return list<array{name: string, type: string, note: string}>
     */
    public function permissions(): array
    {
        return [
            ['name' => 'artifacts:read', 'type' => 'Find and read', 'note' => 'Search your team’s artifacts and open their details.'],
            ['name' => 'artifacts:deploy', 'type' => 'Publish', 'note' => 'Save new artifacts to your team.'],
            ['name' => 'artifacts:delete', 'type' => 'Delete', 'note' => 'Remove artifacts, when your team’s rules allow it.'],
            ['name' => 'collections:read', 'type' => 'See collections', 'note' => 'List your team’s collections.'],
            ['name' => 'collections:write', 'type' => 'Organize', 'note' => 'Create collections and add artifacts to them.'],
            ['name' => 'usage:read', 'type' => 'See usage', 'note' => 'Read storage and quota totals for your team.'],
        ];
    }

    /**
     * @return list<array{name: string, type: string, note: string}>
     */
    public function troubleshooting(): array
    {
        return [
            ['name' => 'The sign-in did not open, or access expired', 'type' => 'Sign in again', 'note' => 'Claude Code: type /mcp, choose artfct and select Reconnect. Codex: run codex mcp login artfct. OpenCode: run opencode mcp auth artfct and approve once. Antigravity: type /mcp in agy and choose artfct. Other tools: disconnect and connect artfct again in their settings.'],
            ['name' => 'Connected to the wrong team', 'type' => 'Sign out, then in', 'note' => 'Sign out first (Codex: codex mcp logout artfct. OpenCode: opencode mcp logout artfct. Claude Code: type /mcp, choose artfct and clear its sign-in). Then sign in again and pick the team on the approval page.'],
            ['name' => 'The tool cannot reach Artfct', 'type' => 'Check the address', 'note' => "Make sure the address is exactly {$this->mcpUrl()} and that your tool supports remote (HTTP) servers. Tools that can only start programs on your computer cannot connect."],
            ['name' => 'Your tool says it is being rate limited', 'type' => 'Wait a moment', 'note' => 'Wait as long as your tool reports, then try again.'],
            ['name' => 'An administrator removed or reset the connection', 'type' => 'Sign in again', 'note' => 'Repeat the sign-in step for your tool and approve access again. If you have left the team, ask an administrator to invite you back.'],
        ];
    }

    /**
     * @return list<array{name: string, install: string, note: string}>
     */
    public function skills(): array
    {
        return [
            ['name' => 'artfct', 'install' => 'npx skills add rubybear-lgtm/artfct@artfct', 'note' => 'When and how to publish: writing self-contained HTML, choosing who can see it and handling errors.'],
        ];
    }

    /**
     * A short block to paste into a project's AGENTS.md, CLAUDE.md or Cursor
     * rules so the AI tool knows when to use Artfct.
     */
    public function projectInstructions(): string
    {
        return <<<MD
            ## Artfct

            This team keeps what its AI tools make in Artfct ({$this->baseUrl}).

            - Before starting research, a report or an analysis, search Artfct with `search_artifacts` for related work, and say which artifacts you used.
            - When you make something worth keeping (a report, dashboard, table, document or mockup), publish it with `deploy_artifact` and share the link it returns instead of pasting the raw code.
            - When you change something you published before, publish it again with its `artifact_id` so the team keeps one link and its history.
            - Full setup and tool reference: {$this->baseUrl}/llms-full.txt
            MD;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'mcpUrl' => $this->mcpUrl(),
            'llmsUrl' => "{$this->baseUrl}/llms.txt",
            'llmsFullUrl' => "{$this->baseUrl}/llms-full.txt",
            'quickInstall' => $this->quickInstall(),
            'guides' => $this->guides(),
            'tools' => $this->tools(),
            'permissions' => $this->permissions(),
            'troubleshooting' => $this->troubleshooting(),
            'skills' => $this->skills(),
            'projectInstructions' => $this->projectInstructions(),
        ];
    }

    /**
     * @param  array<string, mixed>  $value
     */
    private function json(array $value): string
    {
        return json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
