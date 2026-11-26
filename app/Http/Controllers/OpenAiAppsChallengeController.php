<?php

namespace App\Http\Controllers;

use Illuminate\Http\Response;

/**
 * `GET /.well-known/openai-apps-challenge`: domain verification for the
 * OpenAI plugin portal. The value is issued by the portal at submission
 * time and wired through config — never invented here.
 */
final class OpenAiAppsChallengeController extends Controller
{
    public function __invoke(): Response
    {
        $challenge = trim((string) config('services.openai.apps_challenge'));

        if ($challenge === '') {
            abort(404);
        }

        return response($challenge, 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Cache-Control' => 'no-store',
        ]);
    }
}
