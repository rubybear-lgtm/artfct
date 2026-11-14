<?php

namespace App\Mcp\Tools;

use App\Mcp\Support\ArtifactDbTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Delete one document from an artifact\'s database. Pass the last `version` you read as `if_version` so a concurrent change fails with `version_conflict` instead of deleting unseen work.')]
#[Name('db_delete')]
#[Title('Delete database document')]
#[IsReadOnly(false)]
#[IsIdempotent(true)]
#[IsDestructive(true)]
#[IsOpenWorld(false)]
final class DbDeleteTool extends ArtifactDbTool
{
    /** @var array<string, mixed> */
    protected ?array $meta = [
        'artfct' => [
            'contractVersion' => '1.0.0',
            'toolVersion' => '1.0.0',
            'owner' => 'artfct-mcp',
            'requiredScopes' => ['data:write'],
            'compatibility' => 'stable',
            'examples' => [[
                'description' => 'Remove a finished card.',
                'arguments' => ['artifact' => 'abc123def4567', 'collection' => 'cards', 'doc_id' => 'card-1'],
            ]],
        ],
    ];

    public function handle(Request $request): ResponseFactory
    {
        $startedAt = hrtime(true);
        if ($disabled = $this->featureDisabled($startedAt)) {
            return $disabled;
        }
        if ($denied = $this->scopeDenied('data:write', 'db_delete')) {
            return $denied;
        }
        $validated = $request->validate([
            'artifact' => ['required', 'string', 'max:128'],
            'collection' => ['required', 'string', 'regex:/\A[A-Za-z0-9._-]{1,64}\z/'],
            'doc_id' => ['required', 'string', 'regex:/\A[A-Za-z0-9._-]{1,128}\z/'],
            'if_version' => ['nullable', 'integer', 'min:1'],
        ]);

        $artifactId = $validated['artifact'];
        if ($refused = $this->invalidId($artifactId, 'db_delete', $startedAt)) {
            return $refused;
        }

        $origin = $this->dbOrigin($artifactId, 'db_delete', $startedAt);
        if ($origin instanceof ResponseFactory) {
            return $origin;
        }
        // Laravel's `delete($url, $params)` does not reliably carry a query
        // string, and the Worker reads `if_version` from the query — build
        // it explicitly like the other single-write verbs.
        $url = $origin['base'].'/'.$validated['collection'].'/'.$validated['doc_id'];
        if (isset($validated['if_version'])) {
            $url .= '?'.http_build_query(['if_version' => (int) $validated['if_version']]);
        }

        try {
            $response = $this->dbRequest($origin['token'], $origin['origin'])->delete($url);
        } catch (\Throwable $exception) {
            return $this->unavailable('db_delete', $startedAt, $artifactId, $exception);
        }

        if ($failure = $this->dbFailure($response, 'db_delete', $startedAt, $artifactId)) {
            return $failure;
        }

        return $this->ok([
            'artifact' => $artifactId,
            'collection' => $validated['collection'],
            'doc_id' => $validated['doc_id'],
            'deleted' => true,
        ], 'db_delete', $startedAt, $artifactId);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'artifact' => $schema->string()->max(128)->description('The permanent artifact id.')->required(),
            'collection' => $schema->string()->max(64)->description('The declared collection holding the document.')->required(),
            'doc_id' => $schema->string()->max(128)->description('The document id to delete.')->required(),
            'if_version' => $schema->integer()->min(1)->description('Optional: the version your change is built on. The delete fails with version_conflict when the document has moved on.')->nullable(),
        ];
    }
}
