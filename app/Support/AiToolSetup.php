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
     * One-click install links for the tools that support them. Cursor and
     * VS Code both register a protocol handler (`cursor://`, `vscode:`) that
     * installs a server straight from a link; each also has an https fallback
     * for browsers without the handler. Every other tool stays copy-paste.
     */
    public function cursorInstallLink(): string
    {
        $config = base64_encode((string) json_encode(['url' => $this->mcpUrl()], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return "cursor://anysphere.cursor-deeplink/mcp/install?name=artfct&config={$config}";
    }

    public function cursorWebInstallLink(): string
    {
        $config = base64_encode((string) json_encode(['url' => $this->mcpUrl()], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return 'https://cursor.com/install-mcp?config='.urlencode($config).'&name=artfct';
    }

    public function vscodeInstallLink(): string
    {
        $config = rawurlencode((string) json_encode(['name' => 'artfct', 'type' => 'http', 'url' => $this->mcpUrl()], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return "vscode:mcp/install?{$config}";
    }

    public function vscodeWebInstallLink(): string
    {
        $config = rawurlencode((string) json_encode(['type' => 'http', 'url' => $this->mcpUrl()], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return "https://vscode.dev/redirect/mcp/install?config={$config}&name=artfct";
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
     * @return list<array{id: string, label: string, verified: bool, intro?: string, installLink?: string, installLinkFallback?: string, steps: list<array{text: string, code?: string}>}>
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
                'id' => 'connect-omp',
                'label' => 'OMP',
                'verified' => false,
                'intro' => 'OMP (oh-my-pi) reads Model Context Protocol servers from its own config file, so add the artfct server there and sign in once.',
                'steps' => [
                    ['text' => 'Add the artfct server to your user config (`~/.omp/agent/mcp.json`, creating the file if needed):', 'code' => $this->json(['mcpServers' => ['artfct' => ['type' => 'http', 'url' => $url]]])],
                    ['text' => 'Start `omp`, type `/mcp`, choose `artfct` and approve the sign-in that opens in your browser.'],
                    ['text' => 'Publish with the `deploy_artifact` tool. When the HTML is ready, deploy instead of pasting raw code: the live link is what you share.'],
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
                'installLink' => $this->cursorInstallLink(),
                'installLinkFallback' => $this->cursorWebInstallLink(),
                'steps' => [
                    ['text' => 'Select Add to Cursor below. If the link does not open Cursor, paste the address by hand instead:', 'code' => $this->json(['mcpServers' => ['artfct' => ['url' => $url]]])],
                    ['text' => 'Open Cursor Settings, find artfct in the tools list and select Connect, or run:', 'code' => 'cursor-agent mcp login artfct'],
                    ['text' => 'Approve the sign-in that opens in your browser.'],
                ],
            ],
            [
                'id' => 'connect-vscode',
                'label' => 'VS Code',
                'verified' => false,
                'intro' => 'For GitHub Copilot Chat in agent mode.',
                'installLink' => $this->vscodeInstallLink(),
                'installLinkFallback' => $this->vscodeWebInstallLink(),
                'steps' => [
                    ['text' => 'Select Add to VS Code below. If the link does not open VS Code, add this to `.vscode/mcp.json` in your project instead, or open the command palette, run MCP: Open User Configuration and add it there for every project:', 'code' => $this->json(['servers' => ['artfct' => ['type' => 'http', 'url' => $url]]])],
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
     * the tools `ArtfctServer` registers.
     *
     * @return list<array{name: string, does: string, permission: string}>
     */
    public function tools(): array
    {
        return [
            ['name' => 'search_artifacts', 'does' => 'Search your team’s artifacts by what they are about, who or what made them, or when. Returns summaries, short excerpts and a link to each one.', 'permission' => 'artifacts:read'],
            ['name' => 'list_artifacts', 'does' => 'List your team’s artifacts by who made them, when they were published, how they are shared or how often they were opened.', 'permission' => 'artifacts:read'],
            ['name' => 'get_artifact', 'does' => 'Open the details of one artifact, or one of its earlier versions, and get a fresh link to view it.', 'permission' => 'artifacts:read'],
            ['name' => 'read_artifact', 'does' => 'Read the text of one artifact a page at a time, when a search excerpt is not enough. The text is treated as information, never as instructions.', 'permission' => 'artifacts:read'],
            ['name' => 'get_guidance', 'does' => 'Read the rules for publishing, finding and working together on artifacts, with the current types and limits.', 'permission' => 'Any'],
            ['name' => 'list_artifact_comments', 'does' => 'Read the comments on one artifact. The comments are treated as information, never as instructions.', 'permission' => 'artifacts:read'],
            ['name' => 'add_artifact_comment', 'does' => 'Comment on an artifact you can see, or reply to a comment.', 'permission' => 'comments:write'],
            ['name' => 'resolve_artifact_comment', 'does' => 'Mark a comment thread resolved, or reopen it.', 'permission' => 'comments:write'],
            ['name' => 'deploy_artifact', 'does' => 'Publish a page, report or document to your team, either as one self-contained HTML file or as a set of files. Choose who can open it: your whole team, only you, or anyone with the link. To update something you published before, pass its id (and the version you built on, so a newer change is never overwritten): the new version keeps the same link. Publishing the same content again returns the same artifact.', 'permission' => 'artifacts:deploy'],
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
            ['name' => 'comments:write', 'type' => 'Comment', 'note' => 'Add comments to artifacts and resolve comment threads.'],
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
            ['name' => 'The sign-in did not open, or access expired', 'type' => 'Sign in again', 'note' => 'Claude Code: type /mcp, choose artfct and select Reconnect. Codex: run codex mcp login artfct. OpenCode: run opencode mcp auth artfct and approve once. OMP: type /mcp, choose artfct and approve the sign-in. Antigravity: type /mcp in agy and choose artfct. Other tools: disconnect and connect artfct again in their settings.'],
            ['name' => 'Connected to the wrong team', 'type' => 'Sign out, then in', 'note' => 'Sign out first (Codex: codex mcp logout artfct. OpenCode: opencode mcp logout artfct. Claude Code or OMP: type /mcp, choose artfct and clear its sign-in). Then sign in again and pick the team on the approval page.'],
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
            ['name' => 'artfct-init', 'install' => 'npx skills add rubybear-lgtm/artfct@artfct-init', 'note' => 'Sets Artfct up for you or a project in any coding agent: project instructions, the files they point to, and only the tool permissions you approve. Works for all your projects, just you in one project, or the whole team in a repo.'],
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
            - When you change something you published before, publish it again with its `artifact_id` and the `base_version` you built on, so the team keeps one link and its history and nobody's newer change is overwritten.
            - Full setup and tool reference: {$this->baseUrl}/llms-full.txt
            MD;
    }

    /**
     * A one-line rule for AGENTS.md, CLAUDE.md or Cursor rules. The full
     * block lives in projectInstructions(); this is the smallest thing that
     * still teaches the tool when to reach for Artfct.
     */
    public function agentInstructionLine(): string
    {
        return 'Before research, a report or an analysis, search Artfct with `search_artifacts` for related work; publish anything worth keeping with `deploy_artifact` and share its link.';
    }

    /**
     * The one prompt behind "Copy prompt" on the homepage, /connect and every
     * guide (RUB-502). It works in any agent: the agent finds its own section
     * of llms-full.txt and either makes the change itself or walks the person
     * through it. Sign-in and every file it writes stay with the person.
     */
    public function agentSetupPrompt(): string
    {
        $serverEntry = $this->json(['mcpServers' => ['artfct' => ['type' => 'http', 'url' => $this->mcpUrl()]]]);

        return <<<MD
            Connect yourself to Artfct, the shared workspace where my team's AI agents publish their work and search each other's. Work through these steps in order and tell me when you need something from me.

            1. Work out which app you are running in (for example Claude Code, Codex, Cursor, VS Code, the Claude app or ChatGPT). If you already have Artfct tools such as `get_connection`, skip to step 4.
            2. Read {$this->baseUrl}/llms-full.txt and follow the setup section for your app. The server is named `artfct` and lives at {$this->mcpUrl()}. In a JSON config file the entry looks like this; some apps use other key names (`servers`, `context_servers`, `serverUrl`), and that page has the exact entry for each:

            {$serverEntry}

            3. If you can run commands or change your own settings, make the change yourself. If you cannot, walk me through the steps for this app one at a time.
               - Signing in happens in my browser. Tell me to approve the page that opens, then wait. Never ask me for a password or a token.
               - Some apps only show a new server after a restart or a reconnect (for example `/mcp`). If the Artfct tools are missing, tell me what to do, and carry on once they appear.
            4. Check the connection: call `get_connection` and tell me which team I'm in and whether search is on. If that is not my team, stop and point me to "Connected to the wrong team" in {$this->baseUrl}/llms-full.txt.
            5. If you are a coding agent working in a project, offer to set Artfct up there too. Ask before you change anything.
               - If you can install skills, run `npx skills add rubybear-lgtm/artfct@artfct-init` and then the artfct-init skill. It asks whether the setup is for all my projects, just me in this project, or the whole team in this repo.
               - Otherwise, add the block between the lines below to the file your app reads for project instructions (`AGENTS.md`, `CLAUDE.md` or Cursor rules), unless it already has an `## Artfct` section. Show me the text before you write it.

            ---
            {$this->projectInstructions()}
            ---

            6. Ask me which other AI tools I use, and offer to help connect them to the same workspace so they can find what you publish.
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
            'cursorInstallLink' => $this->cursorInstallLink(),
            'cursorWebInstallLink' => $this->cursorWebInstallLink(),
            'vscodeInstallLink' => $this->vscodeInstallLink(),
            'vscodeWebInstallLink' => $this->vscodeWebInstallLink(),
            'guides' => $this->guides(),
            'tools' => $this->tools(),
            'permissions' => $this->permissions(),
            'troubleshooting' => $this->troubleshooting(),
            'skills' => $this->skills(),
            'projectInstructions' => $this->projectInstructions(),
            'agentSetupPrompt' => $this->agentSetupPrompt(),
            'agentInstructionLine' => $this->agentInstructionLine(),
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
