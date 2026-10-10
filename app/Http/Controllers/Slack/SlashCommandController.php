<?php

namespace App\Http\Controllers\Slack;

use App\Http\Controllers\Controller;
use App\Services\Slack\SlashCommandService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `/artfct <query>` (spec 15). Inbound authenticity is enforced by the
 * `VerifySlackSignature` middleware on the route. `response_type:
 * ephemeral` is what proves search results never reach the channel.
 */
class SlashCommandController extends Controller
{
    public function handle(Request $request, SlashCommandService $slashCommand): JsonResponse
    {
        $validated = $request->validate([
            'team_id' => ['required', 'string'],
            'user_id' => ['required', 'string'],
            'text' => ['required', 'string'],
        ]);

        $result = $slashCommand->handle($validated['team_id'], $validated['user_id'], $validated['text']);

        if ($result->needsConnect) {
            return response()->json([
                'response_type' => 'ephemeral',
                'text' => 'Connect your artfct account to search: '.rtrim((string) config('app.public_base_url', 'https://artfct.dev'), '/').'/settings/teams',
            ]);
        }

        if ($result->results === []) {
            return response()->json([
                'response_type' => 'ephemeral',
                'text' => 'No matching artifacts found.',
            ]);
        }

        $lines = array_map(fn ($r): string => "• <{$r->url}|{$r->title}> — {$r->snippet}", $result->results);

        return response()->json([
            'response_type' => 'ephemeral',
            'text' => implode("\n", $lines),
        ]);
    }
}
