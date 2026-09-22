<?php

use App\Enums\TeamRole;
use App\Models\Team;
use Illuminate\Support\Facades\Http;

/**
 * RUB-365, the link half: the console's own `Location`, followed to a response.
 *
 * Two existing tests prove the ends. `ConsoleTest` asserts the redirect the
 * console emits and that its token verifies under an independent re-derivation
 * of the Worker's wire format;
 * `storage_integration.rs::signed_link_opens_the_artifact_on_its_isolated_origin`
 * asserts the Worker's isolated-origin predicate against a live Worker, but
 * with a token it mints itself. Neither follows the link the console actually
 * emitted to a 200, which is the gap the re-audit named.
 *
 * This drives the real console route for a real team, takes the `Location` it
 * emits, and uses that URL's host and token unchanged against the live Worker.
 * The per-artifact CSP is asserted as well as the bytes, because a public
 * artifact also answers 200 on the shared origin: a broken isolated check
 * would still return the bytes, only under the shared-origin policy.
 *
 * Only runs inside `scripts/mcp-e2e-stack.sh link`, which points this process
 * at the stack's Worker and Postgres; skipped everywhere else, like the Rust
 * integration tests it complements.
 */
test('the console link opens the artifact on its isolated origin', function () {
    $worker = rtrim((string) getenv('ARTFCT_INTEGRATION_BASE_URL'), '/');
    $org = (string) getenv('ARTFCT_INTEGRATION_ORG');
    $token = (string) getenv('ARTFCT_INTEGRATION_TOKEN');
    $secret = (string) getenv('ARTFCT_ARTIFACT_TOKEN_SECRET');

    if ($worker === '' || $org === '' || $token === '' || $secret === '') {
        $this->markTestSkipped('Requires the mcp-e2e stack: scripts/mcp-e2e-stack.sh link');
    }

    // The same refusal the Rust integration test makes: never drive this
    // against a hosted Worker by accident.
    expect(strtolower($worker))->not->toContain('artfct.dev');

    // Create the artifact through the live Worker, the way a deploy would, so
    // the console's link has something real to open.
    $bytes = '<!doctype html><html><body><h1>rub-365-'.bin2hex(random_bytes(8)).'</h1></body></html>';
    $hash = hash('sha256', $bytes);

    $created = Http::withToken($token)->post("{$worker}/v1/artifacts", [
        'mode' => 'permanent',
        'tier' => 'public',
        'title' => 'RUB-365 console link',
        'description' => 'RUB-365 console link',
        'thumbnail' => 'https://example.com/thumbnail.png',
        'preview_blurred' => false,
        'manifest' => [
            'entrypoint' => 'index.html',
            'external_origins' => [],
            'files' => [[
                'path' => 'index.html',
                'content_type' => 'text/html; charset=utf-8',
                'size_bytes' => strlen($bytes),
                'sha256' => $hash,
            ]],
        ],
        'provenance' => ['agent' => 'pest-console-link'],
    ]);

    expect($created->status())->toBe(201, 'artifact create failed: '.$created->body());

    $artifactId = (string) $created->json('id');
    expect($artifactId)->toMatch('/^[0-9a-f]{32}$/');

    $upload = Http::withToken($token)
        ->withBody($bytes, 'text/html; charset=utf-8')
        ->put("{$worker}/v1/artifacts/{$artifactId}/files/{$hash}");

    expect($upload->status())->toBe(204, 'file upload failed: '.$upload->body());

    $team = Team::factory()->create(['slug' => $org]);
    $admin = memberOfTeam($team, TeamRole::Admin);

    // The app's own path: the real route, the real controller, the real mint.
    $response = $this->actingAs($admin)->get(
        "/settings/teams/{$team->slug}/console/artifacts/{$artifactId}/open"
    );

    $response->assertRedirect();

    $location = (string) $response->headers->get('Location');
    $url = parse_url($location);
    expect($url)->toBeArray();

    expect($url['scheme'] ?? null)->toBe('https')
        ->and($url['host'] ?? null)->toBe("{$org}--{$artifactId}".config('services.artifact_access.origin_suffix'))
        ->and($url['path'] ?? null)->toBe("/p/{$artifactId}")
        ->and($url['query'] ?? '')->toStartWith('token=');

    // The isolated hostname resolves nowhere and terminates no TLS from this
    // machine, so the Location is consumed verbatim instead of followed by a
    // client: the same path, the same query token, and the same `Host` header,
    // aimed at the local Worker's address. The Worker decides on those values
    // alone, so nothing the console emitted is substituted here.
    $opened = Http::withHeaders(['Host' => $url['host']])
        ->get($worker.$url['path'].'?'.$url['query']);

    expect($opened->status())->toBe(200, "the console link did not open: {$location}")
        ->and($opened->body())->toBe($bytes)
        ->and($opened->header('Content-Security-Policy'))->toStartWith("default-src 'self';");
});
