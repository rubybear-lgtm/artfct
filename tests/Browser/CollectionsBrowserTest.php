<?php

use App\Actions\Teams\CreateTeam;
use App\Contracts\ArtifactDirectory;
use App\Models\Collection;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * The collections page as document stacks, exercised in a browser.
 *
 * What only a browser can prove here: the stack toggle expands an inline
 * panel that spans the collection grid's full width directly beneath its own
 * row (responsive 1/2/3 columns), multiple panels stay open together, a second
 * tap collapses again, keyboard Enter toggles, and POST/PATCH/DELETE visits
 * keep the open panels open. Toast/redirect coverage for those mutations lives
 * in `Feature/Teams/CollectionPageTest.php`; this file proves the client-side
 * half — expansion state surviving the Inertia visit.
 *
 * Preview iframes need the `previewUrls` the backend serves from the team's
 * session-auth preview route, which is not in this worktree until that
 * integration merges — so this file covers expansion, layout, keyboard,
 * mutations, and Open semantics only. Actual-preview assertions (lazy mount,
 * sandbox, scaling) are deferred to the manager after the backend lands; no
 * local route stubs are registered here.
 */
function stacksTeam(string $teamName = 'Stacks Users', string $email = 'stacks-user@example.com'): array
{
    $owner = User::factory()->create(['name' => 'Stacks User', 'email' => $email]);
    $team = app(CreateTeam::class)->handle($owner, $teamName);

    return [$team, $owner];
}

function seedStackArtifact(Team $team, string $id, string $title): void
{
    /** @var ArtifactDirectory $directory */
    $directory = app(ArtifactDirectory::class);
    $directory->seedArtifact([
        'id' => $id, 'org_id' => $team->slug, 'user_id' => 1, 'title' => $title,
        'description' => 'Seeded for the collection stack tests.',
        'content_hash' => md5($id),
        'created_at' => now()->subDay()->toIso8601String(), 'revoked_at' => null,
        'provenance' => ['agent' => 'cursor', 'repo_url' => null, 'commit_sha' => null],
    ]);
}

function seedStackCollection(Team $team, int $userId, string $name, array $artifactIds, bool $canonical = false): Collection
{
    $collection = Collection::create([
        'team_id' => $team->id, 'name' => $name,
        'created_by_user_id' => $userId, 'canonical' => $canonical,
    ]);

    foreach ($artifactIds as $artifactId) {
        $collection->artifacts()->create(['artifact_id' => $artifactId, 'added_at' => now()]);
    }

    return $collection;
}

function stacksUrl(Team $team): string
{
    return '/settings/teams/'.$team->slug.'/collections';
}

test('collection_stacks_show_names_counts_and_canonical_badges', function () {
    [$team, $owner] = stacksTeam();
    seedStackArtifact($team, 'stack-report-1', 'Stack report');
    seedStackArtifact($team, 'stack-report-2', 'Second stack report');

    $populated = seedStackCollection($team, $owner->id, 'Reporting', ['stack-report-1', 'stack-report-2']);
    $empty = seedStackCollection($team, $owner->id, 'Empty set', [], canonical: true);
    test()->actingAs($owner);

    visit(stacksUrl($team))
        ->assertNoJavaScriptErrors()
        ->assertSee('Reporting')
        ->assertSee('Empty set')
        ->assertSee('2 artifacts')
        ->assertSee('No artifacts yet')
        ->assertSee('canonical')
        ->assertAttribute("@collection-toggle-{$populated->id}", 'aria-expanded', 'false')
        ->assertAttribute("@collection-toggle-{$populated->id}", 'aria-label', 'Show artifacts in Reporting, 2 artifacts')
        ->assertAttribute("@collection-toggle-{$empty->id}", 'aria-expanded', 'false')
        ->assertAttribute("@collection-toggle-{$populated->id}", 'aria-controls', "collection-panel-{$populated->id}")
        ->assertMissing("@collection-panel-{$populated->id}")
        ->assertMissing("@collection-panel-{$empty->id}")
        ->assertNoJavaScriptErrors();
});

test('a_stack_expands_inline_and_collapses_on_a_second_tap', function () {
    [$team, $owner] = stacksTeam('Tap Users', 'tap-user@example.com');
    seedStackArtifact($team, 'tap-report-1', 'Tap report');

    // 'tap-unknown-1' is deliberately absent from the directory: the panel
    // must still list it by short id, the way the page has always fallen back.
    $collection = seedStackCollection($team, $owner->id, 'Tappable', ['tap-report-1', 'tap-unknown-1']);
    test()->actingAs($owner);

    $page = visit(stacksUrl($team))->assertNoJavaScriptErrors();

    $page->click("@collection-toggle-{$collection->id}")
        ->wait(0.5)
        ->assertAttribute("@collection-toggle-{$collection->id}", 'aria-expanded', 'true')
        ->assertPresent("@collection-panel-{$collection->id}")
        ->assertSee('Tap report')
        ->assertSee('tap-unkn')
        ->assertNoJavaScriptErrors();

    $page->click("@collection-toggle-{$collection->id}")
        ->wait(0.5)
        ->assertAttribute("@collection-toggle-{$collection->id}", 'aria-expanded', 'false')
        ->assertMissing("@collection-panel-{$collection->id}")
        ->assertNoJavaScriptErrors();
});

test('multiple_stacks_stay_expanded_with_full_width_panels_under_their_rows', function () {
    [$team, $owner] = stacksTeam('Wide Users', 'wide-user@example.com');
    seedStackArtifact($team, 'wide-report-1', 'Wide report one');
    seedStackArtifact($team, 'wide-report-2', 'Wide report two');

    $first = seedStackCollection($team, $owner->id, 'First', ['wide-report-1']);
    $second = seedStackCollection($team, $owner->id, 'Second', ['wide-report-2']);
    seedStackCollection($team, $owner->id, 'Third', []);
    seedStackCollection($team, $owner->id, 'Fourth', []);
    test()->actingAs($owner);

    $page = visit(stacksUrl($team))->assertNoJavaScriptErrors();

    $page->click("@collection-toggle-{$first->id}")
        ->wait(0.5)
        ->click("@collection-toggle-{$second->id}")
        ->wait(0.5)
        ->assertAttribute("@collection-toggle-{$first->id}", 'aria-expanded', 'true')
        ->assertAttribute("@collection-toggle-{$second->id}", 'aria-expanded', 'true')
        ->assertPresent("@collection-panel-{$first->id}")
        ->assertPresent("@collection-panel-{$second->id}")
        ->assertSee('Wide report one')
        ->assertSee('Wide report two');

    // Desktop is three columns; each panel spans its row's full grid width
    // and sits below its own card, not wedged between cards.
    $page->assertScript(<<<JS
        (() => {
            const row = document.querySelector('[data-testid="collection-row-0"]');
            const card = document.querySelector('[data-testid="collection-card-{$first->id}"]');
            const panel = document.querySelector('[data-testid="collection-panel-{$first->id}"]');

            if (! row || ! card || ! panel) {
                return false;
            }

            const columns = getComputedStyle(row).gridTemplateColumns.split(' ').length;
            const rowRect = row.getBoundingClientRect();
            const cardRect = card.getBoundingClientRect();
            const panelRect = panel.getBoundingClientRect();

            return columns === 3
                && Math.abs(panelRect.width - rowRect.width) <= 2
                && panelRect.top >= cardRect.bottom - 2;
        })()
        JS, true);

    // A tablet viewport regroups into two columns; the panels still span.
    $page->resize(800, 900)->wait(0.5);
    foreach ([$first, $second] as $collection) {
        $page->assertAttribute("@collection-toggle-{$collection->id}", 'aria-expanded', 'true')
            ->assertPresent("@collection-panel-{$collection->id}");
    }

    $page->assertScript(<<<'JS'
        (() => {
            const row = document.querySelector('[data-testid="collection-row-0"]');
            const panel = document.querySelector('[data-testid^="collection-panel-"]');

            if (! row || ! panel) {
                return false;
            }

            const columns = getComputedStyle(row).gridTemplateColumns.split(' ').length;
            const rowRect = row.getBoundingClientRect();
            const panelRect = panel.getBoundingClientRect();

            return columns === 2
                && Math.abs(panelRect.width - rowRect.width) <= 2
                && panelRect.top >= rowRect.bottom - 2;
        })()
        JS, true);

    $page->resize(1280, 900)->wait(0.2);
    foreach ([$first, $second] as $collection) {
        $page->assertAttribute("@collection-toggle-{$collection->id}", 'aria-expanded', 'true')
            ->assertPresent("@collection-panel-{$collection->id}");
    }
    $page->assertNoJavaScriptErrors();
});

test('collection_stacks_fit_a_phone_viewport_and_toggle_by_tap', function () {
    [$team, $owner] = stacksTeam('Phone Users', 'phone-user@example.com');
    seedStackArtifact($team, 'phone-report-1', 'Phone report');

    $collection = seedStackCollection(
        $team, $owner->id,
        'A collection with a very long name that must wrap instead of overflowing its card on a narrow phone screen',
        ['phone-report-1'],
    );
    test()->actingAs($owner);

    $page = visit(stacksUrl($team))->on()->mobile();

    $page->assertNoJavaScriptErrors()
        ->assertScript(<<<'JS'
            (() => {
                const row = document.querySelector('[data-testid="collection-row-0"]');

                if (! row) {
                    return false;
                }

                const columns = getComputedStyle(row).gridTemplateColumns.split(' ').length;
                const fits = document.documentElement.scrollWidth <= window.innerWidth + 1;

                return columns === 1 && fits;
            })()
            JS, true);

    $page->click("@collection-toggle-{$collection->id}")
        ->wait(0.5)
        ->assertAttribute("@collection-toggle-{$collection->id}", 'aria-expanded', 'true')
        ->assertPresent("@collection-panel-{$collection->id}")
        ->assertSee('Phone report');

    // Every control on the card is a phone-sized target; long names wrap
    // inside their cards instead of pushing the page sideways.
    $page->assertScript(<<<JS
        (() => {
            const toggle = document.querySelector('[data-testid="collection-toggle-{$collection->id}"]');
            const card = document.querySelector('[data-testid="collection-card-{$collection->id}"]');

            if (! toggle || ! card) {
                return false;
            }

            const toggleRect = toggle.getBoundingClientRect();
            const fits = document.documentElement.scrollWidth <= window.innerWidth + 1;

            return toggleRect.height >= 44
                && card.scrollWidth <= card.clientWidth + 1
                && fits;
        })()
        JS, true);

    $page->assertNoJavaScriptErrors();
});

test('the_stack_toggle_works_from_the_keyboard', function () {
    [$team, $owner] = stacksTeam('Keyboard Users', 'keyboard-user@example.com');
    seedStackArtifact($team, 'keys-report-1', 'Keyboard report');

    $collection = seedStackCollection($team, $owner->id, 'Keyed', ['keys-report-1']);
    test()->actingAs($owner);

    $page = visit(stacksUrl($team))->assertNoJavaScriptErrors();

    $page->keys("@collection-toggle-{$collection->id}", 'Enter')
        ->wait(0.5)
        ->assertAttribute("@collection-toggle-{$collection->id}", 'aria-expanded', 'true')
        ->assertPresent("@collection-panel-{$collection->id}")
        ->assertSee('Keyboard report');

    $page->keys("@collection-toggle-{$collection->id}", 'Enter')
        ->wait(0.5)
        ->assertAttribute("@collection-toggle-{$collection->id}", 'aria-expanded', 'false')
        ->assertMissing("@collection-panel-{$collection->id}")
        ->assertNoJavaScriptErrors();
});

test('rename_add_and_remove_keep_the_stack_expanded', function () {
    [$team, $owner] = stacksTeam('Mutation Users', 'mutation-user@example.com');
    seedStackArtifact($team, 'mut-report-1', 'Keep me report');
    seedStackArtifact($team, 'mut-report-2', 'Add me report');

    $collection = seedStackCollection($team, $owner->id, 'Mutable', ['mut-report-1']);
    test()->actingAs($owner);

    $page = visit(stacksUrl($team))->assertNoJavaScriptErrors();

    $page->click("@collection-toggle-{$collection->id}")
        ->wait(0.5)
        ->assertPresent("@collection-panel-{$collection->id}");

    // PATCH: renaming through the dialog keeps the panel open.
    $page->click('Rename')
        ->fill("#rename-{$collection->id}", 'Mutable renamed')
        ->click('Save name')
        ->waitForText('Collection renamed.')
        ->assertAttribute("@collection-toggle-{$collection->id}", 'aria-expanded', 'true')
        ->assertPresent("@collection-panel-{$collection->id}")
        ->assertSee('Mutable renamed')
        ->assertSee('Keep me report');

    // DELETE: removing the only artifact keeps the (now empty) panel open.
    $page->click('Remove')
        ->click('Remove from collection')
        ->waitForText('Removed from Mutable renamed.')
        ->assertAttribute("@collection-toggle-{$collection->id}", 'aria-expanded', 'true')
        ->assertPresent("@collection-panel-{$collection->id}")
        ->assertSee('No artifacts yet')
        ->assertMissing("@collection-artifact-{$collection->id}-mut-report-1");

    // POST: adding an artifact keeps the panel open on the new row.
    $page->select("#add-artifact-{$collection->id}", 'Add me report')
        ->click('Add')
        ->waitForText('Added to Mutable renamed.')
        ->assertAttribute("@collection-toggle-{$collection->id}", 'aria-expanded', 'true')
        ->assertPresent("@collection-panel-{$collection->id}")
        ->assertPresent("@collection-artifact-{$collection->id}-mut-report-2")
        ->assertNoJavaScriptErrors();
});

test('collection_open_links_use_the_apps_open_route_in_a_new_tab', function () {
    config(['services.artifact_access.token_secret' => 'browser-artifact-secret']);

    [$team, $owner] = stacksTeam('Stack Open Users', 'stack-open-user@example.com');

    $collection = seedStackCollection($team, $owner->id, 'Openable', [ARTIFACT_LINK_ID]);
    test()->actingAs($owner);

    $openTestId = 'collection-open-'.$collection->id.'-'.ARTIFACT_LINK_ID;
    $openTitleTestId = 'collection-open-title-'.$collection->id.'-'.ARTIFACT_LINK_ID;

    $page = visit(stacksUrl($team))->assertNoJavaScriptErrors();

    $page->click("@collection-toggle-{$collection->id}")
        ->wait(0.5)
        ->assertPresent("@collection-panel-{$collection->id}")
        // The title links through the open action, and the row offers an
        // explicit Open control with the same target: both point at the open
        // action, never at a token.
        ->assertAttribute("@{$openTitleTestId}", 'target', '_blank')
        ->assertAttributeContains("@{$openTitleTestId}", 'href', '/open')
        ->assertAttributeDoesntContain("@{$openTitleTestId}", 'href', 'token=')
        ->assertAttribute("@{$openTestId}", 'target', '_blank')
        ->assertAttribute("@{$openTestId}", 'rel', 'noreferrer')
        ->assertAttributeContains("@{$openTestId}", 'href', '/open')
        ->assertAttributeDoesntContain("@{$openTestId}", 'href', 'token=')
        ->assertScript(<<<JS
            (() => {
                const open = document.querySelector('[data-testid="{$openTestId}"]');

                return open !== null && open.getBoundingClientRect().height >= 43.5;
            })()
            JS, true)
        ->assertNoJavaScriptErrors();
});

test('renaming a collection across grid rows preserves its expansion', function () {
    [$team, $owner] = stacksTeam('Reorder Users', 'reorder-user@example.com');
    foreach (['Alpha', 'Bravo', 'Charlie'] as $name) {
        seedStackCollection($team, $owner->id, $name, []);
    }
    $collection = seedStackCollection($team, $owner->id, 'Zebra', []);
    test()->actingAs($owner);

    $page = visit(stacksUrl($team))->resize(1280, 850);
    $page->click("@collection-toggle-{$collection->id}")
        ->assertScript("document.querySelector('[data-testid=collection-row-1] [data-testid=collection-toggle-{$collection->id}]') !== null")
        ->click("[data-testid=collection-card-{$collection->id}] button:text-is(\"Rename\")")
        ->fill("#rename-{$collection->id}", '0 renamed')
        ->click('Save name')->waitForText('Collection renamed.')
        ->assertAttribute("@collection-toggle-{$collection->id}", 'aria-expanded', 'true')
        ->assertPresent("@collection-panel-{$collection->id}")
        ->assertScript("document.querySelector('[data-testid=collection-row-0] [data-testid=collection-toggle-{$collection->id}]') !== null")
        ->assertNoJavaScriptErrors();
});
