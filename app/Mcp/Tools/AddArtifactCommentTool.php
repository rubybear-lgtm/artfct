<?php

namespace App\Mcp\Tools;

use App\Mcp\Support\McpArtifactAccess;
use App\Mcp\Support\McpContext;
use App\Mcp\Support\McpErrorResponse;
use App\Mcp\Support\McpTelemetry;
use App\Models\ArtifactComment;
use App\Models\McpConnection;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Add a comment to an artifact the caller can see, or reply to an existing comment with parent_id. Requires the comments:write scope. Plain text only, up to 4000 characters.')]
#[Name('add_artifact_comment')]
#[Title('Comment on artifact')]
#[IsReadOnly(false)]
#[IsIdempotent(false)]
#[IsDestructive(false)]
#[IsOpenWorld(false)]
final class AddArtifactCommentTool extends Tool
{
    /** Comments one member (or the tool acting for them) may add per minute. */
    private const PER_MINUTE = 30;

    /** @var array<string, mixed> */
    protected ?array $meta = [
        'artfct' => [
            'contractVersion' => '1.0.0',
            'toolVersion' => '1.0.0',
            'owner' => 'artfct-mcp',
            'requiredScopes' => ['comments:write'],
            'compatibility' => 'stable',
            'examples' => [[
                'description' => 'Ask a question on a shared report.',
                'arguments' => ['id' => 'abc123def4567', 'body' => 'Does this include the October refunds?'],
            ]],
        ],
    ];

    public function handle(Request $request): Response|ResponseFactory
    {
        $startedAt = hrtime(true);
        McpContext::requireScope('comments:write', 'add_artifact_comment');
        $validated = $request->validate([
            'id' => ['required', 'string', 'max:128'],
            'body' => ['required', 'string', 'min:1', 'max:'.ArtifactComment::MAX_BODY_LENGTH],
            'parent_id' => ['nullable', 'integer', 'min:1'],
        ]);

        if ($denied = McpArtifactAccess::denyUnlessVisible($validated['id'], 'add_artifact_comment', $startedAt)) {
            return $denied;
        }

        $team = McpContext::team();
        $key = 'artifact-comment:'.$team->id.':'.McpContext::actor();
        if (RateLimiter::tooManyAttempts($key, self::PER_MINUTE)) {
            app(McpTelemetry::class)->record('add_artifact_comment', 'rate_limited', $startedAt);

            return McpErrorResponse::error('You are commenting too quickly. Retry shortly.', 'rate_limited', true);
        }

        $parentId = $validated['parent_id'] ?? null;
        if ($parentId !== null) {
            $parent = ArtifactComment::query()
                ->where('team_id', $team->id)
                ->where('artifact_id', $validated['id'])
                ->find($parentId);

            if ($parent === null) {
                return McpErrorResponse::error('That comment was not found on this artifact.', 'comment_not_found');
            }

            // Threads are one level deep: a reply to a reply joins the root thread.
            $parentId = $parent->parent_id ?? $parent->id;
        }

        RateLimiter::hit($key, 60);

        $user = User::query()->find((int) McpContext::actor());
        $connection = request()->attributes->get('mcp_connection');
        $comment = ArtifactComment::query()->create([
            'team_id' => $team->id,
            'artifact_id' => $validated['id'],
            'parent_id' => $parentId,
            'author_user_id' => McpContext::actor(),
            'author_name' => $user?->name,
            'author_agent' => $connection instanceof McpConnection ? $connection->client_name : null,
            'body' => $validated['body'],
        ]);

        app(McpTelemetry::class)->record('add_artifact_comment', 'success', $startedAt, $validated['id']);

        return Response::structured($comment->toThreadItem() + ['artifact_id' => $validated['id']]);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->string()->min(1)->max(128)->description('The artifact ID.')->required(),
            'body' => $schema->string()->min(1)->max(ArtifactComment::MAX_BODY_LENGTH)->description('The comment, as plain text.')->required(),
            'parent_id' => $schema->integer()->min(1)->description('The comment to reply to; omit to start a new thread.')->nullable(),
        ];
    }
}
