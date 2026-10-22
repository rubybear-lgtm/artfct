<?php

namespace App\Mcp\Tools;

use App\Mcp\Support\McpContext;
use App\Mcp\Support\McpTelemetry;
use App\Models\User;
use App\Services\Teams\BotAvatarStore;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
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

#[Description('Set the avatar image for the authenticated bot teammate. Pass a base64-encoded PNG, JPEG or WebP image (optionally as a data URL). Only bot credentials can call this; humans upload avatars in team settings.')]
#[Name('set_avatar')]
#[Title('Set avatar')]
#[IsReadOnly(false)]
#[IsIdempotent(true)]
#[IsDestructive(false)]
#[IsOpenWorld(false)]
final class SetAvatarTool extends Tool
{
    /** @var array<string, mixed>|null */
    protected ?array $meta = null;

    public function __construct()
    {
        $this->meta = [
            'artfct' => [
                'contractVersion' => '1.0.0',
                'toolVersion' => '1.0.0',
                'owner' => 'artfct-mcp',
                'requiredScopes' => [],
                'compatibility' => 'stable',
                'examples' => [[
                    'description' => 'Upload a small PNG avatar for this bot.',
                    'arguments' => ['image_base64' => 'iVBORw0KGgo...'],
                ]],
            ],
        ];
    }

    public function handle(Request $request): ResponseFactory
    {
        $startedAt = hrtime(true);
        $validated = $request->validate([
            'image_base64' => ['required', 'string', 'min:32', 'max:3000000'],
            'content_type' => ['sometimes', 'nullable', 'string', 'max:64'],
        ]);

        $actor = User::query()->find((int) McpContext::actor());
        if ($actor === null || ! $actor->is_bot) {
            app(McpTelemetry::class)->record('set_avatar', 'denied', $startedAt);

            return Response::error('Only a bot teammate can set its avatar over MCP. Humans upload avatars in team settings.');
        }

        [$bytes, $extension] = $this->decodeImage(
            $validated['image_base64'],
            $validated['content_type'] ?? null,
        );

        if ($bytes === null) {
            app(McpTelemetry::class)->record('set_avatar', 'error', $startedAt);

            return Response::error('image_base64 must be a PNG, JPEG or WebP image as base64 or a data URL.');
        }

        if (strlen($bytes) > 2 * 1024 * 1024) {
            return Response::error('Avatar image must be 2 MB or smaller.');
        }

        $store = app(BotAvatarStore::class);
        $url = $store->storeBytes($bytes, $extension);
        $store->deleteIfManaged($actor->avatar);
        $actor->forceFill(['avatar' => $url])->save();

        app(McpTelemetry::class)->record('set_avatar', 'success', $startedAt);

        return Response::structured([
            'avatar_url' => $url,
            'bot' => [
                'id' => (string) $actor->id,
                'name' => $actor->name,
                'bot' => true,
            ],
        ]);
    }

    /**
     * @return array{0: ?string, 1: string}
     */
    private function decodeImage(string $payload, ?string $contentType): array
    {
        $extension = match (true) {
            is_string($contentType) && str_contains($contentType, 'jpeg') => 'jpg',
            is_string($contentType) && str_contains($contentType, 'jpg') => 'jpg',
            is_string($contentType) && str_contains($contentType, 'webp') => 'webp',
            default => 'png',
        };

        if (preg_match('#^data:(image/(?:png|jpeg|jpg|webp));base64,(.+)$#i', $payload, $matches) === 1) {
            $extension = match (strtolower($matches[1])) {
                'image/jpeg', 'image/jpg' => 'jpg',
                'image/webp' => 'webp',
                default => 'png',
            };
            $payload = $matches[2];
        }

        $bytes = base64_decode($payload, true);
        if ($bytes === false || $bytes === '') {
            return [null, $extension];
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->buffer($bytes);
        if (! in_array($mime, ['image/png', 'image/jpeg', 'image/webp'], true)) {
            return [null, $extension];
        }

        $extension = match ($mime) {
            'image/jpeg' => 'jpg',
            'image/webp' => 'webp',
            default => 'png',
        };

        return [$bytes, $extension];
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'image_base64' => $schema->string()->min(32)->max(3000000)->description('Base64 image bytes, or a data:image/...;base64,... URL.')->required(),
            'content_type' => $schema->string()->max(64)->description('Optional MIME type when the payload is raw base64 (image/png, image/jpeg, image/webp).')->nullable(),
        ];
    }
}
