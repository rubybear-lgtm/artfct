<?php

use App\Contracts\ArtifactContentSource;
use App\Contracts\ArtifactDirectory;
use App\Enums\TeamRole;
use App\Models\Team;
use App\Providers\AppServiceProvider;
use App\Services\Artifacts\HttpArtifactContentSource;
use App\Services\Artifacts\HttpArtifactDirectory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * The container must build the real HTTP clients with their configured base
 * URL: a bare `new HttpArtifactDirectory()` has a null URL and quietly lists
 * nothing, which is what an unconfigured binding looked like on staging.
 */
test('real_directory_is_built_with_the_configured_worker_url', function () {
    app()->detectEnvironment(fn () => 'staging');
    app()->forgetInstance(ArtifactDirectory::class);
    (new AppServiceProvider(app()))->register();
    configureSigning(testSigningKey());
    config(['services.worker.base_url' => 'https://worker.test']);
    Http::fake(['worker.test/*' => Http::response(['artifacts' => [['id' => 'a1']], 'next_cursor' => null])]);

    $team = Team::factory()->create(['slug' => 'zz-northwind']);
    test()->actingAs(memberOfTeam($team, TeamRole::Member));

    $directory = app(ArtifactDirectory::class);

    expect($directory)->toBeInstanceOf(HttpArtifactDirectory::class)
        ->and($directory->listArtifacts('zz-northwind')['artifacts'])->toHaveCount(1);
    Http::assertSent(fn (Request $request) => str_starts_with($request->url(), 'https://worker.test/v1/orgs/zz-northwind/artifacts'));
});

test('real_content_source_is_built_with_the_configured_worker_url', function () {
    app()->detectEnvironment(fn () => 'staging');
    app()->forgetInstance(ArtifactContentSource::class);
    (new AppServiceProvider(app()))->register();
    configureSigning(testSigningKey());
    config(['services.worker.base_url' => 'https://worker.test']);
    Http::fake(['worker.test/*' => Http::response(['content' => '<h1>x</h1>', 'provenance' => ['agent' => 'a']])]);

    $source = app(ArtifactContentSource::class);

    expect($source)->toBeInstanceOf(HttpArtifactContentSource::class)
        ->and($source->fetch('zz-northwind', 'abc')['html'])->toBe('<h1>x</h1>');
});
