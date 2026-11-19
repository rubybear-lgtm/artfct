<?php

/*
 * RUB-555 / spec 33: the presentation skill is the slide deck skill. It starts
 * from the deck starter (spec 31) and deploys a slide_deck bundle. These tests
 * read the skill text and check the paths and tokens it names.
 */

const PRESENTATION_SKILL_DIR = __DIR__.'/../../../skills/presentation';
const PRESENTATION_DECK_CSS = PRESENTATION_SKILL_DIR.'/assets/deck-starter/deck.css';

function presentationSkill(): string
{
    return (string) file_get_contents(PRESENTATION_SKILL_DIR.'/SKILL.md');
}

test('the skill copies the three starter files and fills only index.html', function () {
    $skill = presentationSkill();

    expect($skill)->toContain('assets/deck-starter/index.html')
        ->and($skill)->toContain('assets/deck-starter/deck.css')
        ->and($skill)->toContain('assets/deck-starter/app.js')
        ->and($skill)->toMatch('/only `index\.html`/')
        ->and(strtolower($skill))->not->toContain('move the script body');
});

test('the skill deploys a files bundle with entrypoint index.html and artifact_type slide_deck', function () {
    $skill = presentationSkill();

    expect($skill)->toContain('"files"')
        ->and($skill)->toContain('"entrypoint": "index.html"')
        ->and($skill)->toContain('"artifact_type": "slide_deck"')
        ->and($skill)->toContain('"deck.css":')
        ->and($skill)->toContain('"app.js":');
});

test('the old template is gone and nothing names it', function () {
    expect(file_exists(PRESENTATION_SKILL_DIR.'/assets/presentation-template.html'))->toBeFalse();

    $self = realpath(__FILE__);
    $roots = ['skills', 'resources', 'app', 'tests', 'config', 'routes', 'scripts'];
    $offenders = [];

    foreach ($roots as $root) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__.'/../../../'.$root, FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            if ($file->isDir() || realpath($file->getPathname()) === $self) {
                continue;
            }

            $text = (string) file_get_contents($file->getPathname());

            if (str_contains($text, 'presentation-template.html') || preg_match('/\{\{[A-Z_]+\}\}/', $text) === 1) {
                $offenders[] = $file->getPathname();
            }
        }
    }

    expect($offenders)->toBe([]);
});

test('the skill requires a confirmed outline before building', function () {
    $skill = presentationSkill();

    expect(strtolower($skill))->toContain('outline')
        ->and($skill)->toMatch('/outline.*confirm|confirm.*outline/is')
        ->and($skill)->toContain('slide titles')
        ->and($skill)->toContain('just build');
});

test('the skill asks for a talk track in each notes element and 5 to 15 slides', function () {
    $skill = presentationSkill();

    expect($skill)->toContain('<p class="notes">')
        ->and($skill)->toContain('talk track')
        ->and($skill)->toContain('5 to 15 slides');
});

test('the skill searches for an existing deck before creating one', function () {
    $skill = presentationSkill();

    expect($skill)->toContain('search_artifacts')
        ->and($skill)->toContain('artifact_id')
        ->and($skill)->toContain('base_version')
        ->and($skill)->toMatch('/same title/i');
});

test('the skill runs one findability search after deploying', function () {
    $skill = presentationSkill();
    $deployAt = strpos($skill, 'deploy_artifact');
    $searchAt = strpos($skill, 'one findability search');

    expect($searchAt)->not->toBeFalse()
        ->and($deployAt)->not->toBeFalse()
        ->and($searchAt)->toBeGreaterThan($deployAt)
        ->and($skill)->toMatch('/findability.*search_artifacts|search_artifacts.*findability/is');
});

test('the skill sets the title grammar and bans punchline titles', function () {
    $skill = presentationSkill();

    expect($skill)->toContain('one title grammar')
        ->and($skill)->toContain('punchline')
        ->and($skill)->toContain('one idea per slide');
});

test('the skill sizes type with the --type tokens and converts points', function () {
    $skill = presentationSkill();

    expect($skill)->toContain('--type-title')
        ->and($skill)->toContain('--type-heading')
        ->and($skill)->toContain('--type-body')
        ->and($skill)->toContain('--type-kicker')
        ->and($skill)->toContain('1pt = 1.333px');
});

test('the skill fits photos and diagrams', function () {
    $skill = presentationSkill();

    expect($skill)->toContain('object-fit: cover')
        ->and($skill)->toContain('object-fit: contain');
});

test('the skill keeps parallel slides identical', function () {
    expect(presentationSkill())->toMatch('/identical markup/i');
});

test('the skill requires literal static markup', function () {
    $skill = presentationSkill();

    expect($skill)->toMatch('/literal static/i')
        ->and($skill)->toContain('leaf')
        ->and($skill)->toMatch('/script-generated|script-built/i');
});

test('the skill names none of the old template, its placeholders or Solarized', function () {
    $skill = presentationSkill();

    expect($skill)->not->toContain('presentation-template.html')
        ->and($skill)->not->toContain('{{')
        ->and(strtolower($skill))->not->toContain('solarized')
        ->and($skill)->toContain('--deck-');
});

test('the skill keeps the PDF and PowerPoint refusals unchanged', function () {
    $skill = presentationSkill();

    expect($skill)->toContain('- User wants a PDF — build a print-optimized HTML page with `@media print` styles instead, or note that `Ctrl+P → Save as PDF` works from the deployed URL')
        ->and($skill)->toContain('- User wants Keynote/PowerPoint format — artfct only hosts HTML; suggest they copy the outline into their preferred tool');
});

test('the skill names the deck starter, slide_deck, search_artifacts and base_version', function () {
    $skill = presentationSkill();

    expect($skill)->toContain('deck-starter')
        ->and($skill)->toContain('slide_deck')
        ->and($skill)->toContain('search_artifacts')
        ->and($skill)->toContain('base_version');
});

test('every asset and reference path the skill names exists', function () {
    preg_match_all('#(?:assets|references)/[A-Za-z0-9_./-]+#', presentationSkill(), $matches);
    $paths = array_unique(array_map(fn (string $path): string => rtrim($path, './'), $matches[0]));

    expect($paths)->not->toBeEmpty();

    foreach ($paths as $path) {
        expect(file_exists(PRESENTATION_SKILL_DIR.'/'.$path))->toBeTrue("the skill names [{$path}], which does not exist");
    }
});

test('every --deck and --type token the skill names is declared in deck.css', function () {
    preg_match_all('/--(?:deck|type)-[a-z-]+/', presentationSkill(), $matches);
    $tokens = array_unique($matches[0]);
    $css = (string) file_get_contents(PRESENTATION_DECK_CSS);

    expect($tokens)->not->toBeEmpty();

    foreach ($tokens as $token) {
        expect($css)->toContain($token.':', "the skill names [{$token}], which deck.css does not declare");
    }
});
