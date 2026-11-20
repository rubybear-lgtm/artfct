<?php

/*
 * RUB-560 / spec 39: the one-pager tweak panel, proven in a browser against a
 * fixture server. The fixture serves the starter (and two declaration fixtures)
 * under the artifact CSP and answers the db endpoints from a state file, so a
 * test can choose the viewer's role, seed a stored document and count saves.
 */

use Symfony\Component\Process\Process;

const ONE_PAGER_FIXTURES = __DIR__.'/../Fixtures/one-pager';
const ONE_PAGER_BUNDLE = __DIR__.'/../../skills/artfct-team-doc/assets/one-pager-starter';

/** @var ?Process */
$onePagerServer = null;

/** @return string the state file the fixture server and the tests share */
function onePagerStatePath(): string
{
    return sys_get_temp_dir().'/artfct-rub-560-one-pager-state.json';
}

/** @return string base URL of the fixture server */
function onePagerBaseUrl(): string
{
    static $base = null;

    if ($base !== null) {
        return $base;
    }

    $socket = stream_socket_server('tcp://127.0.0.1:0');
    $port = (int) parse_url(stream_socket_get_name($socket, false), PHP_URL_PORT);
    fclose($socket);

    $base = "http://127.0.0.1:{$port}";

    $server = new Process(
        ['php', '-S', "127.0.0.1:{$port}", ONE_PAGER_FIXTURES.'/router.php'],
        null,
        [
            'ONE_PAGER_STATE' => onePagerStatePath(),
            'ONE_PAGER_BUNDLE_DIR' => ONE_PAGER_BUNDLE,
            'ONE_PAGER_FIXTURE_DIR' => dirname(ONE_PAGER_FIXTURES),
        ],
    );
    $server->start();

    $deadline = microtime(true) + 5;
    while (@fsockopen('127.0.0.1', $port) === false) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException("One-pager fixture server {$base} did not start.");
        }
        usleep(50_000);
    }

    register_shutdown_function(fn () => $server->stop());

    return $base;
}

/** @param array<string, mixed> $state */
function onePagerSetState(array $state): void
{
    file_put_contents(onePagerStatePath(), json_encode(array_merge(['role' => 'writer', 'puts' => 0, 'doc' => null], $state)));
}

/** @return array<string, mixed> */
function onePagerState(): array
{
    return json_decode((string) file_get_contents(onePagerStatePath()), true, flags: JSON_THROW_ON_ERROR);
}

function onePagerSaveCount(): int
{
    return (int) (onePagerState()['puts'] ?? 0);
}

function onePagerPage(string $bundle = 'starter'): string
{
    return onePagerBaseUrl()."/{$bundle}/index.html";
}

test('the edit panel is shown to a writer', function () {
    onePagerSetState(['role' => 'writer']);

    visit(onePagerPage())
        ->waitForText('Edit fields')
        ->assertScript('document.querySelector("[data-tweak-panel]") !== null', true)
        ->assertNoJavaScriptErrors();
});

test('the edit panel is absent for a reader', function () {
    onePagerSetState(['role' => 'reader']);

    visit(onePagerPage())
        ->waitForText('Customer name')
        ->assertScript('document.querySelector("[data-tweak-panel]") === null', true)
        ->assertNoJavaScriptErrors();
});

test('a reader sees the stored values and cannot save', function () {
    onePagerSetState(['role' => 'reader', 'doc' => ['customer_name' => 'Stored Co', 'tier' => 'Tier two', 'logo_path' => '']]);

    visit(onePagerPage())
        ->waitForText('Stored Co')
        ->assertScript('document.querySelector("[data-tweak=\"tier\"]").textContent', 'Tier two');
});

test('saving the tweaks writes the declared fields and a reload shows them', function () {
    onePagerSetState(['role' => 'writer']);

    $page = visit(onePagerPage())
        ->waitForText('Edit fields')
        ->fill('#tweak-customer_name', 'Acme Ltd');
    $page->script("const tier = document.querySelector('#tweak-tier'); tier.value = 'Tier two'; tier.dispatchEvent(new Event('change'))");
    $page->click('Save')
        ->waitForText('Saved.')
        ->assertNoJavaScriptErrors();

    expect(onePagerSaveCount())->toBe(1)
        ->and(onePagerState()['doc'])->toBe(['customer_name' => 'Acme Ltd', 'tier' => 'Tier two', 'logo_path' => '']);

    visit(onePagerPage())
        ->waitForText('Acme Ltd')
        ->assertScript('document.querySelector("[data-tweak=\"tier\"]").textContent', 'Tier two');
});

test('a reload after a save shows the saved values to the writer', function () {
    onePagerSetState(['role' => 'writer', 'doc' => ['customer_name' => 'Reload Co', 'tier' => 'Tier three', 'logo_path' => '']]);

    visit(onePagerPage())
        ->waitForText('Edit fields')
        ->assertScript('document.querySelector("[data-tweak=\"customer_name\"]").textContent', 'Reload Co')
        ->assertScript('document.querySelector("[data-tweak=\"tier\"]").textContent', 'Tier three')
        ->assertScript('document.querySelector("#tweak-customer_name").value', 'Reload Co')
        ->assertNoJavaScriptErrors();
});

test('a writer whose stored read is refused does not save defaults over the stored document', function () {
    onePagerSetState(['role' => 'writer', 'get_status' => 503, 'doc' => ['customer_name' => 'Keep Me', 'tier' => 'Tier two', 'logo_path' => '']]);

    visit(onePagerPage())
        ->waitForText('Edit fields')
        ->click('Save')
        ->waitForText('nothing was saved');

    expect(onePagerSaveCount())->toBe(0)
        ->and(onePagerState()['doc'])->toBe(['customer_name' => 'Keep Me', 'tier' => 'Tier two', 'logo_path' => '']);
});

test('a bundle without tweaks.json shows a visible panel error', function () {
    onePagerSetState(['role' => 'writer']);

    visit(onePagerPage('no-declaration'))
        ->waitForText('tweaks.json is missing from this bundle')
        ->assertScript('document.querySelector("[data-tweak-error]") !== null', true)
        ->assertScript('document.querySelector("[data-tweak-panel]") === null', true);
});

test('a fourth declared field renders and saves with no change to tweaks.js', function () {
    onePagerSetState(['role' => 'writer']);

    visit(onePagerPage('four-fields'))
        ->waitForText('Edit fields')
        ->assertScript('document.querySelector("#tweak-accent") !== null', true)
        ->fill('#tweak-accent', '#123456')
        ->click('Save')
        ->waitForText('Saved.');

    expect(onePagerState()['doc']['accent'] ?? null)->toBe('#123456');
});

test('tweaks.js refuses text over its max', function () {
    onePagerSetState(['role' => 'writer']);

    visit(onePagerPage())
        ->waitForText('Edit fields')
        ->fill('#tweak-customer_name', str_repeat('a', 81))
        ->click('Save')
        ->waitForText('Fix the highlighted fields before saving.')
        ->assertScript('document.querySelector("[data-tweak-error-for=\"customer_name\"]").textContent', 'Use at most 80 characters.');

    expect(onePagerSaveCount())->toBe(0);
});

test('tweaks.js refuses a choice outside its choices', function () {
    onePagerSetState(['role' => 'writer', 'doc' => ['customer_name' => 'Acme', 'tier' => 'Tier nine', 'logo_path' => '']]);

    visit(onePagerPage())
        ->waitForText('Edit fields')
        ->assertScript('document.querySelector("[data-tweak-error-for=\"tier\"]").textContent', 'Choose one of the listed choices.')
        ->click('Save')
        ->waitForText('Fix the highlighted fields before saving.');

    expect(onePagerSaveCount())->toBe(0);
});

test('tweaks.js refuses a colour that is not hex', function () {
    onePagerSetState(['role' => 'writer']);

    visit(onePagerPage('four-fields'))
        ->waitForText('Edit fields')
        ->fill('#tweak-accent', 'red')
        ->click('Save')
        ->waitForText('Fix the highlighted fields before saving.')
        ->assertScript('document.querySelector("[data-tweak-error-for=\"accent\"]").textContent', 'Use a hex colour such as #701a24.');

    expect(onePagerSaveCount())->toBe(0);
});

test('tweaks.js refuses a range outside its min and max', function () {
    onePagerSetState(['role' => 'writer', 'doc' => ['accent' => '#701a24', 'show_terms' => true, 'copies' => 9]]);

    visit(onePagerPage('types'))
        ->waitForText('Edit fields')
        ->assertScript('document.querySelector("[data-tweak-error-for=\"copies\"]").textContent', 'Use a number from 1 to 5.')
        ->click('Save')
        ->waitForText('Fix the highlighted fields before saving.');

    expect(onePagerSaveCount())->toBe(0);
});

test('tweaks.js refuses a toggle that is not boolean', function () {
    onePagerSetState(['role' => 'writer', 'doc' => ['accent' => '#701a24', 'show_terms' => 'yes', 'copies' => 1]]);

    visit(onePagerPage('types'))
        ->waitForText('Edit fields')
        ->assertScript('document.querySelector("[data-tweak-error-for=\"show_terms\"]").textContent', 'Use on or off.')
        ->click('Save')
        ->waitForText('Fix the highlighted fields before saving.');

    expect(onePagerSaveCount())->toBe(0);
});

test('tweaks.js refuses an image path that is not a file in the bundle', function () {
    onePagerSetState(['role' => 'writer']);

    visit(onePagerPage('four-fields'))
        ->waitForText('Edit fields')
        ->fill('#tweak-logo_path', 'missing.png')
        ->click('Save')
        ->waitForText('Fix the highlighted fields before saving.')
        ->assertScript('document.querySelector("[data-tweak-error-for=\"logo_path\"]").textContent', 'That file is not in this bundle.');

    expect(onePagerSaveCount())->toBe(0);
});

test('tweaks.js refuses an image path that escapes the bundle without a request', function () {
    onePagerSetState(['role' => 'writer']);

    visit(onePagerPage('four-fields'))
        ->waitForText('Edit fields')
        ->fill('#tweak-logo_path', '../secret.png')
        ->click('Save')
        ->waitForText('Fix the highlighted fields before saving.')
        ->assertScript('document.querySelector("[data-tweak-error-for=\"logo_path\"]").textContent', 'Use a file in this bundle, such as logo.png, or leave it empty.');

    expect(onePagerSaveCount())->toBe(0);
});

test('an image path that is a file in the bundle is saved', function () {
    onePagerSetState(['role' => 'writer']);

    visit(onePagerPage('four-fields'))
        ->waitForText('Edit fields')
        ->fill('#tweak-logo_path', 'logo.svg')
        ->click('Save')
        ->waitForText('Saved.');

    expect(onePagerState()['doc']['logo_path'] ?? null)->toBe('logo.svg');
});
