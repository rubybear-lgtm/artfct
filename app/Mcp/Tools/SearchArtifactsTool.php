<?php

namespace App\Mcp\Tools;

use App\Mcp\Support\McpContext;
use App\Mcp\Support\McpErrorResponse;
use App\Mcp\Support\McpTelemetry;
use App\Services\Artifacts\ArtifactViewLink;
use App\Services\Search\SearchResult;
use App\Services\Search\SearchService;
use App\Support\ClientIp;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Search the authenticated workspace by meaning, provenance, repository, agent, or date. Returns summaries, snippets and a view_url per result, never full artifact contents. Each view_url is the app\'s own open route, so it works when a member clicks it while signed in and mints a signed link only for a secure artifact.')]
#[Name('search_artifacts')]
#[IsReadOnly]
#[IsIdempotent]
#[IsDestructive(false)]
#[IsOpenWorld(false)]
final class SearchArtifactsTool extends Tool
{
    /** @var array<string, mixed> */
    protected ?array $meta = [
        'artfct' => [
            'contractVersion' => '1.0.0',
            'toolVersion' => '1.0.0',
            'owner' => 'artfct-mcp',
            'requiredScopes' => ['artifacts:read'],
            'compatibility' => 'stable',
            'examples' => [[
                'description' => 'Find an existing dashboard before generating a new one.',
                'arguments' => ['query' => 'billing dashboard', 'limit' => 5],
            ]],
        ],
    ];

    /**
     * Handle the tool request.
     */
    public function handle(Request $request, SearchService $search): Response|ResponseFactory
    {
        $startedAt = hrtime(true);
        McpContext::requireScope('artifacts:read', 'search_artifacts');
        $validated = $request->validate([
            'query' => ['required', 'string', 'min:1', 'max:500'],
            'repo' => ['nullable', 'string', 'max:500'],
            'agent' => ['nullable', 'string', 'max:100'],
            'since' => ['nullable', 'date'],
            'collection' => ['nullable', 'string', 'max:100'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $team = McpContext::team();
        $httpRequest = McpContext::httpRequest();
        try {
            $results = $search->search(
                $team,
                $validated['query'],
                [
                    'repo' => $validated['repo'] ?? null,
                    'agent' => $validated['agent'] ?? null,
                    'since' => $validated['since'] ?? null,
                    'collection' => $validated['collection'] ?? null,
                ],
                (int) ($validated['limit'] ?? 10),
                actor: McpContext::actor(),
                ip: ClientIp::for($httpRequest),
                userAgent: $httpRequest->userAgent() ?? 'mcp',
            );
        } catch (\Throwable $exception) {
            report($exception);
            app(McpTelemetry::class)->record('search_artifacts', 'error', $startedAt);

            return McpErrorResponse::error('The artifact search service is temporarily unavailable.', 'upstream_unavailable', true);
        }

        $response = Response::structured([
            'results' => array_map(fn (SearchResult $result): array => [
                'id' => $result->id,
                'title' => $result->title,
                'description' => $result->description,
                // Search results are workspace artifacts; the index carries no
                // tier, so every one of them links through the app's open
                // route. That route is the link that is correct either way: it
                // authorizes the viewer, then either redirects a public
                // artifact to its credential-less `/p/{id}` URL without
                // minting, or mints a signed link for a secure one.
                'view_url' => ArtifactViewLink::forArtifact($team->slug, $result->id, null),
                'snippet' => $result->snippet,
                'provenance' => [
                    'agent' => $result->agent,
                    'repo_url' => $result->repoUrl,
                    'commit_sha' => $result->commitSha,
                ],
                'score' => $result->score,
            ], $results),
        ]);

        app(McpTelemetry::class)->record('search_artifacts', 'success', $startedAt);

        return $response;
    }

    /**
     * Get the tool's input schema.
     *
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->min(1)->max(500)->description('The natural-language search query.')->required(),
            'repo' => $schema->string()->max(500)->description('Optional exact repository URL.')->nullable(),
            'agent' => $schema->string()->max(100)->description('Optional agent identity filter.')->nullable(),
            'since' => $schema->string()->description('Optional ISO-8601 date/time lower bound.')->nullable(),
            'collection' => $schema->string()->max(100)->description('Optional collection name.')->nullable(),
            'limit' => $schema->integer()->description('Maximum number of results, from 1 to 50.')->nullable(),
        ];
    }
}
