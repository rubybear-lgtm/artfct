<?php

namespace App\Mcp\Tools;

use App\Mcp\Support\McpArtifactAccess;
use App\Mcp\Support\McpContext;
use App\Mcp\Support\McpErrorResponse;
use App\Mcp\Support\McpTelemetry;
use App\Models\ArtifactComment;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
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

#[Description('Mark a comment thread resolved, or reopen it with resolved set to false. Requires the comments:write scope and access to the artifact.')]
#[Name('resolve_artifact_comment')]
#[IsReadOnly(false)]
#[IsIdempotent(true)]
#[IsDestructive(false)]
#[IsOpenWorld(false)]
final class ResolveArtifactCommentTool extends Tool
{
    /** @var array<string, mixed> */
    protected ?array $meta = [
        'artfct' => [
            'contractVersion' => '1.0.0',
            'toolVersion' => '1.0.0',
            'owner' => 'artfct-mcp',
            'requiredScopes' => ['comments:write'],
            'compatibility' => 'stable',
            'examples' => [[
                'description' => 'Close a question that has been answered.',
                'arguments' => ['id' => 'abc123def4567', 'comment_id' => 12],
            ]],
        ],
    ];

    public function handle(Request $request): Response|ResponseFactory
    {
        $startedAt = hrtime(true);
        McpContext::requireScope('comments:write', 'resolve_artifact_comment');
        $validated = $request->validate([
            'id' => ['required', 'string', 'max:128'],
            'comment_id' => ['required', 'integer', 'min:1'],
            'resolved' => ['nullable', 'boolean'],
        ]);

        if ($denied = McpArtifactAccess::denyUnlessVisible($validated['id'], 'resolve_artifact_comment', $startedAt)) {
            return $denied;
        }

        $comment = ArtifactComment::query()
            ->where('team_id', McpContext::team()->id)
            ->where('artifact_id', $validated['id'])
            ->find($validated['comment_id']);

        if ($comment === null) {
            app(McpTelemetry::class)->record('resolve_artifact_comment', 'not_found', $startedAt);

            return McpErrorResponse::error('That comment was not found on this artifact.', 'comment_not_found');
        }

        // Resolving applies to the whole thread, so replies close with the root.
        $root = $comment->parent_id !== null ? ArtifactComment::query()->where('team_id', McpContext::team()->id)->where('artifact_id', $validated['id'])->find($comment->parent_id) ?? $comment : $comment;
        $resolved = $validated['resolved'] ?? true;
        $root->forceFill([
            'resolved_at' => $resolved ? now() : null,
            'resolved_by' => $resolved ? McpContext::actor() : null,
        ])->save();

        app(McpTelemetry::class)->record('resolve_artifact_comment', 'success', $startedAt, $validated['id']);

        return Response::structured($root->toThreadItem() + ['artifact_id' => $validated['id']]);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->string()->min(1)->max(128)->description('The artifact ID.')->required(),
            'comment_id' => $schema->integer()->min(1)->description('The comment to resolve; a reply resolves its whole thread.')->required(),
            'resolved' => $schema->boolean()->description('true to resolve (default), false to reopen.')->nullable(),
        ];
    }
}
