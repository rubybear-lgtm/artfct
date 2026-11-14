<?php

namespace App\Mcp\Tools;

use App\Enums\AuditEventType;
use App\Enums\PreferenceKey;
use App\Enums\PreferenceScope;
use App\Enums\TeamPermission;
use App\Mcp\Support\McpContext;
use App\Mcp\Support\McpTelemetry;
use App\Models\User;
use App\Services\Governance\AuditLogger;
use App\Services\Preferences\PreferenceStore;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use InvalidArgumentException;
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

#[Description('Set or clear one preference. The key `save_to_artfct` says whether you save documents and reports you make to the team\'s Artfct library: `always`, `ask` (check with the person first) or `never`. A preference changes how you behave, never what you are allowed to do: it does not change who can see an artifact or override team policy. Scope `user` (the default) is the signed-in person\'s own choice and follows them across their teams. Scope `team` sets the default for members who have not chosen and needs a team admin signed in as a person, not a bot. Pass `clear: true` to remove a value. Read the current values from `get_connection` first, and only set one the person has told you.')]
#[Name('set_preference')]
#[Title('Set preference')]
#[IsReadOnly(false)]
#[IsIdempotent(true)]
#[IsDestructive(false)]
#[IsOpenWorld(false)]
final class SetPreferenceTool extends Tool
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
                    'description' => 'Record that the person wants their documents saved to Artfct without being asked each time.',
                    'arguments' => ['key' => 'save_to_artfct', 'value' => 'always'],
                ]],
            ],
        ];
    }

    public function handle(Request $request, PreferenceStore $preferences, AuditLogger $audit): Response|ResponseFactory
    {
        $startedAt = hrtime(true);
        $validated = $request->validate([
            'key' => ['required', 'string', 'max:64'],
            'value' => ['sometimes', 'nullable', 'string', 'max:64'],
            'scope' => ['sometimes', 'string', 'max:16'],
            'clear' => ['sometimes', 'boolean'],
        ]);

        $key = PreferenceKey::tryFrom($validated['key']);
        $scope = PreferenceScope::tryFrom($validated['scope'] ?? PreferenceScope::User->value);
        $clear = (bool) ($validated['clear'] ?? false);
        $value = $validated['value'] ?? null;

        if ($key === null) {
            return $this->fail('error', $startedAt, 'Unknown preference. Allowed keys: '.implode(', ', PreferenceKey::names()).'.');
        }

        if ($scope === null) {
            return $this->fail('error', $startedAt, 'scope must be one of: '.implode(', ', PreferenceScope::values()).'.');
        }

        if (! $key->allows($scope)) {
            return $this->fail('error', $startedAt, "{$key->value} cannot be set at the {$scope->value} level.");
        }

        if (! $clear && $value === null) {
            return $this->fail('error', $startedAt, "Pass value (one of: {$this->allowed($key)}) or clear: true.");
        }

        if (! $clear && ! $key->accepts((string) $value)) {
            return $this->fail('error', $startedAt, "{$key->value} accepts: {$this->allowed($key)}.");
        }

        $actorId = McpContext::actor();
        $actor = ctype_digit($actorId) ? User::query()->find((int) $actorId) : null;

        if ($actor === null) {
            return $this->fail('denied', $startedAt, 'This connection is not signed in as a person or a bot teammate, so it has no preferences to change.');
        }

        $team = McpContext::team();

        // The team default changes how every member's agent behaves, so it
        // takes an admin signed in as a person. A bot teammate or agent token
        // can set its own preference but never the team's.
        if ($scope === PreferenceScope::Team
            && ($actor->is_bot || ! $actor->hasTeamPermission($team, TeamPermission::UpdateTeam))) {
            return $this->fail('denied', $startedAt, 'Only a team admin signed in as a person can set the team default. Ask a team admin to set it with set_preference from their own AI tool, or set your own preference instead.');
        }

        try {
            $clear
                ? $preferences->clear($key, $scope, $actor, $team)
                : $preferences->set($key, $scope, (string) $value, $actor, $team);
        } catch (InvalidArgumentException $exception) {
            return $this->fail('error', $startedAt, $exception->getMessage());
        }

        if ($scope === PreferenceScope::Team) {
            $audit->recordForRequest(
                McpContext::httpRequest(),
                AuditEventType::TeamPreferenceChanged,
                $team,
                (string) $actor->id,
                $clear ? "preference:{$key->value} cleared" : "preference:{$key->value} set to {$value}",
            );
        }

        app(McpTelemetry::class)->record('set_preference', 'success', $startedAt);

        return Response::structured([
            'key' => $key->value,
            'scope' => $scope->value,
            'value' => $clear ? null : $value,
            'effective' => $preferences->effective($actor, $team)[$key->value],
        ]);
    }

    private function fail(string $outcome, int|float $startedAt, string $message): Response
    {
        app(McpTelemetry::class)->record('set_preference', $outcome, (int) $startedAt);

        return Response::error($message);
    }

    private function allowed(PreferenceKey $key): string
    {
        return implode(', ', $key->values());
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'key' => $schema->string()->enum(PreferenceKey::names())->description('The preference to change. `save_to_artfct` takes `always`, `ask` or `never`.')->required(),
            'value' => $schema->string()->max(64)->description('The new value. Required unless `clear` is true.')->nullable(),
            'scope' => $schema->string()->enum(PreferenceScope::values())->description('`user` (default) for the signed-in person\'s own choice, or `team` for the team default (team admins only).')->nullable(),
            'clear' => $schema->boolean()->description('Set true to remove the value at this scope instead of setting one.')->nullable(),
        ];
    }
}
