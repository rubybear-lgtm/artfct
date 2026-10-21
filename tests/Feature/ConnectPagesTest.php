<?php

use App\Support\AiToolSetup;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * The programmatic surface: one page per connectable AI tool.
 *
 * The guides come from AiToolSetup, so these pages cannot drift from the docs
 * page or /llms-full.txt — and this test is what keeps that true as guides are
 * added, since a new guide with no page fails here.
 *
 * The loop is deliberate rather than a Pest dataset: a dataset is resolved at
 * collection time, before the application is booted, so AiToolSetup cannot be
 * read there.
 */
test('every connectable tool renders its own page', function () {
    $guides = AiToolSetup::forCurrentEnvironment()->guides();

    expect($guides)->not->toBeEmpty();

    foreach ($guides as $guide) {
        $response = $this->get(route('connect.show', ['tool' => $guide['id']]));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('connect-show')
            ->where('guide.id', $guide['id'])
            ->where('guide.label', $guide['label'])
            ->where('meta.title', "Connect {$guide['label']} to Artfct")
            ->has('guide.steps')
            ->has('mcpUrl')
            ->has('related')
            ->has('troubleshooting'));
    }
});

test('the family covers every guide exactly once and reaches all of them', function () {
    $guides = collect(AiToolSetup::forCurrentEnvironment()->guides());
    $ids = $guides->pluck('id');

    expect($ids->count())->toBeGreaterThan(5)
        ->and($ids->unique()->count())->toBe($ids->count());

    foreach ($ids as $id) {
        $this->get(route('connect.show', ['tool' => $id]))->assertOk();
    }
});

test('each page names its siblings so the family is navigable', function () {
    $guides = collect(AiToolSetup::forCurrentEnvironment()->guides());
    $first = $guides->first();

    $response = $this->get(route('connect.show', ['tool' => $first['id']]));

    $response->assertInertia(fn (Assert $page) => $page
        ->has('related', $guides->count() - 1)
        ->where('related.0.id', $guides->get(1)['id']));
});

test('an unknown tool is a 404', function () {
    $this->get('/connect/not-a-real-tool')->assertNotFound();
    $this->get('/connect/Connect-Claude')->assertNotFound();
});

test('the sitemap lists every connect page', function () {
    $response = $this->get(route('sitemap'));

    $response->assertOk();

    foreach (AiToolSetup::forCurrentEnvironment()->guides() as $guide) {
        $response->assertSee(
            route('connect.show', ['tool' => $guide['id']]),
            escape: false,
        );
    }
});

test('the hub lists every guide by kind with the one setup prompt', function () {
    $setup = AiToolSetup::forCurrentEnvironment();
    $guides = collect($setup->guides());

    $this->get(route('connect.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('connect-index')
            ->where('meta.title', fn (string $title): bool => str_contains($title, 'Agent setup'))
            ->where('guides', fn ($cards): bool => collect($cards)->pluck('id')->all() === $guides->pluck('id')->all())
            ->where('guides.0.kind', 'terminal')
            ->where('prompt', $setup->agentSetupPrompt())
            ->where('quickInstall', $setup->quickInstall()));

    expect($guides->pluck('kind')->unique()->sort()->values()->all())->toBe(['chat', 'editor', 'other', 'terminal']);
});

test('a guide page carries the setup prompt and only its own troubleshooting sentence', function () {
    $setup = AiToolSetup::forCurrentEnvironment();

    $this->get(route('connect.show', ['tool' => 'connect-codex']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('prompt', $setup->agentSetupPrompt())
            ->where('guide.initHost', 'codex')
            ->where('troubleshooting.0.note', 'Codex: run codex mcp login artfct.')
            ->where('related.0.kind', 'terminal'));

    $this->get(route('connect.show', ['tool' => 'connect-chatgpt']))
        ->assertInertia(fn (Assert $page) => $page
            ->missing('guide.initHost')
            ->where('guide.kind', 'chat'));
});

test('the sitemap lists the hub', function () {
    $this->get(route('sitemap'))->assertSee(route('connect.index'), escape: false);
});
