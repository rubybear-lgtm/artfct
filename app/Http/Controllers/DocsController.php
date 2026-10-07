<?php

namespace App\Http\Controllers;

use App\Support\AiToolSetup;
use Illuminate\Support\Facades\File;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Renders the API reference from the OpenAPI contract. A controller rather
 * than a `Route::inertia()` closure prop, because closures in route props
 * cannot be serialized by `route:cache` (run by the deploy build).
 */
class DocsController extends Controller
{
    public function __invoke(): Response
    {
        return Inertia::render('docs', [
            'meta' => [
                'title' => 'Documentation — Artfct',
                'description' => 'Connect Claude, ChatGPT, Cursor, Codex and other AI tools to Artfct in about a minute, then use skills and the REST API.',
            ],
            'setup' => fn (): array => AiToolSetup::forCurrentEnvironment()->toArray(),
            'contract' => function (): array {
                $contract = json_decode(
                    File::get(base_path('openapi/artfct.yaml')),
                    true,
                    flags: JSON_THROW_ON_ERROR,
                );

                $contract['servers'] = [[
                    'url' => rtrim((string) config('app.url'), '/'),
                ]];

                return $contract;
            },
        ]);
    }
}
