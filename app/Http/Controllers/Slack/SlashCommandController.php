<?php

namespace App\Http\Controllers\Slack;

use App\Http\Controllers\Controller;
use App\Services\Slack\SlashCommandService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `/artfct <query>` (spec 15). **Not wired in this environment**: Slack's
 * request-signature verification (HMAC over the raw body + timestamp,
 * against a live app's signing secret) is not implemented here — no live
 * Slack app exists to verify a real signature against, same fail-closed
 * gap as every other external-service Real* implementation this run. The
 * response shape below (`response_type: ephemeral`) is what actually
 * proves search results never reach the channel — that's real and
 * tested; the inbound authenticity check is the deferred half.
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
