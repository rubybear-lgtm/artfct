<?php

namespace App\Mcp\Tools;

use App\Mcp\Support\FeatureFlaggedTool;
use App\Mcp\Support\McpArtifactLink;
use App\Mcp\Support\McpContext;
use App\Mcp\Support\McpErrorResponse;
use App\Mcp\Support\McpTelemetry;
use App\Models\McpConnection;
use App\Services\Templates\PullFailedException;
use App\Services\Templates\TemplateFeatures;
use App\Services\Templates\TemplatePull;
use App\Services\Templates\TemplatePuller;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Use when the person asks to start from a kanban, wiki, announcements board or other official template in their library. Rules in get_guidance (topic templates) apply even if you have not read them. Creates a new artifact you own with seed rows only, never another team\'s live rows. Pass request_id once per dialog so a retry returns the same artifact. A data-backed template needs data:read and data:write on top of artifacts:deploy. Quota and the daily publish limit apply as for deploy_artifact.')]
#[Name('pull_template')]
#[Title('Pull template')]
#[IsReadOnly(false)]
#[IsIdempotent]
#[IsDestructive(false)]
#[IsOpenWorld(true)]
final class PullTemplateTool extends FeatureFlaggedTool
{
    /** @var array<string, mixed> */
    protected ?array $meta = [
        'artfct' => [
            'contractVersion' => '1.0.0',
            'guidanceVersion' => '1.15.0',
            'toolVersion' => '1.1.0',
            'owner' => 'artfct-mcp',
            'requiredScopes' => ['artifacts:deploy'],
            'compatibility' => 'stable',
            'examples' => [[
                'description' => 'Pull the kanban template as a team board.',
                'arguments' => [
                    'template' => 'kanban',
                    'request_id' => '01J0000000000000000000000',
                ],
            ]],
        ],
    ];

    public function shouldRegister(): bool
    {
        try {
            return parent::shouldRegister();
        } catch (AuthorizationException) {
            return false;
        }
    }

    protected function featureKey(): string
    {
        return TemplateFeatures::ARTIFACT_TEMPLATES;
    }

    public function handle(Request $request, TemplatePuller $puller): ResponseFactory
    {
        $startedAt = hrtime(true);

        if (($refused = $this->featureNotEnabled($startedAt)) !== null) {
            return $refused;
        }

        McpContext::requireScope('artifacts:deploy', 'pull_template');

        $validated = $request->validate([
            'template' => ['required', 'string', 'max:64'],
            'title' => ['nullable', 'string', 'max:200'],
            'sharing' => ['nullable', 'string', 'in:private,team'],
            'collection' => ['nullable', 'string', 'max:100'],
            'request_id' => ['nullable', 'string', 'max:64'],
        ]);

        $httpRequest = McpContext::httpRequest();
        [$agent, $agentVersion, $agentSource] = self::publishingAgent();

        try {
            $result = $puller->pull(new TemplatePull(
                team: McpContext::team(),
                actor: McpContext::actor(),
                credentialToken: (string) $httpRequest->bearerToken(),
                templateKey: $validated['template'],
                startedAt: $startedAt,
                scopes: preg_split('/\s+/', trim((string) (McpContext::claims()['scope'] ?? '')), -1, PREG_SPLIT_NO_EMPTY) ?: [],
                title: $validated['title'] ?? null,
                sharing: $validated['sharing'] ?? null,
                collection: $validated['collection'] ?? null,
                requestId: $validated['request_id'] ?? null,
                agent: $agent,
                agentVersion: $agentVersion,
                agentSource: $agentSource,
                sessionId: $request->sessionId(),
                clientName: $this->clientName($httpRequest),
            ));
        } catch (PullFailedException $exception) {
            // Publish-phase failures were already recorded by the publisher;
            // only record the puller's own refusals here.
            if (! $exception->telemetryRecorded) {
                app(McpTelemetry::class)->record('pull_template', $exception->errorCode, $startedAt);
            }

            return McpErrorResponse::error(
                $exception->getMessage(),
                $exception->errorCode,
                $exception->retryable,
                $exception->nextAction,
                $exception->details,
            );
        }

        $link = McpArtifactLink::forArtifact(McpContext::team()->slug, $result['id']);

        if ($link instanceof ResponseFactory) {
            app(McpTelemetry::class)->record('pull_template', McpArtifactLink::ERROR_CODE, $startedAt, $result['id']);

            return $link;
        }

        app(McpTelemetry::class)->record('pull_template', 'success', $startedAt, $result['id']);

        return Response::structured([
            'id' => $result['id'],
            'view_url' => $link,
            'template' => $result['template_key'],
            'template_version' => $result['template_version'],
            'title' => $result['title'],
            'sharing' => $result['sharing'],
            'created' => $result['created'],
            'organization' => McpContext::team()->slug,
            'organization_name' => McpContext::team()->name,
        ]);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'template' => $schema->string()->max(64)->description('The template key from `list_templates`, e.g. `kanban`.')->required(),
            'title' => $schema->string()->max(200)->description('Optional title for the new artifact; defaults to the template title.')->nullable(),
            'sharing' => $schema->string()->enum(['private', 'team'])->description('Who can open the copy: team (default, the template default) or private (only you). Public pulls are not supported.')->nullable(),
            'collection' => $schema->string()->max(100)->description('Optional workspace collection name or id for the new artifact (requires collections:write).')->nullable(),
            'request_id' => $schema->string()->max(64)->description('Optional idempotency key: pulling twice with the same id returns the same artifact. Generate one per dialog so a retry or double click never makes two boards.')->nullable(),
        ];
    }

    /**
     * The agent recorded as the publishing agent. An agent token's name is
     * set by a workspace admin and authenticated, so it wins; the self-reported
     * MCP client identity is only the fallback when the credential names no agent.
     *
     * @return array{0: ?string, 1: ?string, 2: string} agent, version, provenance source
     */
    private static function publishingAgent(): array
    {
        $credentialName = McpContext::agentName();

        if ($credentialName !== null) {
            return [$credentialName, null, 'credential'];
        }

        $http = McpContext::httpRequest();
        $connection = $http->attributes->get('mcp_connection');

        if ($connection instanceof McpConnection) {
            $name = filled($connection->client_name) ? (string) $connection->client_name : null;
            $version = filled($connection->client_version) ? (string) $connection->client_version : null;

            if ($name !== null) {
                return [$name, $version, 'client_info'];
            }
        }

        $headerName = $http->header('MCP-Client-Name');
        $headerVersion = $http->header('MCP-Client-Version');
        $name = is_string($headerName) && trim($headerName) !== '' ? trim($headerName) : null;
        $version = is_string($headerVersion) && trim($headerVersion) !== '' ? trim($headerVersion) : null;

        if ($name !== null) {
            return [$name, $version, 'client_info'];
        }

        return [null, null, 'absent'];
    }

    private function clientName(\Illuminate\Http\Request $httpRequest): ?string
    {
        $header = $httpRequest->header('MCP-Client-Name');

        return is_string($header) && trim($header) !== '' ? trim($header) : null;
    }
}
