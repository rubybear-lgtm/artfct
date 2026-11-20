<?php

use App\Services\Artifacts\PublishContractValidator;

/*
 * RUB-578 / spec 44: the CSS-only demo frames (browser, phone, desktop). The
 * sample bundle shows each frame around an image slot. Every check goes
 * through PublishContractValidator, and the chrome and alt rules are read from
 * the sample's DOM.
 */

const FRAMES_DIR = __DIR__.'/../../../skills/presentation/assets/frames';

/** @return array<string, string> */
function framesSampleFiles(array $overrides = []): array
{
    $files = [];
    foreach (['index.html', 'browser.css', 'phone.css', 'desktop.css', 'sample-shot.svg'] as $name) {
        $files[$name] = (string) file_get_contents(FRAMES_DIR.'/'.$name);
    }

    return array_merge($files, $overrides);
}

function framesSampleXPath(): DOMXPath
{
    $document = new DOMDocument;
    libxml_use_internal_errors(true);
    $document->loadHTML(framesSampleFiles()['index.html'], LIBXML_NOERROR | LIBXML_NOWARNING);
    libxml_clear_errors();

    return new DOMXPath($document);
}

test('the frame files exist and the sample shows each frame around an image slot', function () {
    foreach (['browser.css', 'phone.css', 'desktop.css', 'index.html', 'sample-shot.svg'] as $name) {
        expect(FRAMES_DIR.'/'.$name)->toBeFile();
    }

    $xpath = framesSampleXPath();

    foreach (['frame-browser', 'frame-phone', 'frame-desktop'] as $frameClass) {
        $frames = $xpath->query("//*[contains(concat(' ', normalize-space(@class), ' '), ' {$frameClass} ')]//figure[contains(concat(' ', normalize-space(@class), ' '), ' frame-slot ')]//img");
        expect($frames->length)->toBe(1, "{$frameClass} must wrap one image slot");
    }
});

test('the sample bundle passes the publish contract', function () {
    $errors = (new PublishContractValidator)->validate(framesSampleFiles(), 'Frames sample', 'index.html');

    expect($errors)->toBe([]);
});

test('a sample with an inline script fails the publish contract', function () {
    $html = framesSampleFiles()['index.html'];
    $broken = str_replace('</body>', '<script>document.title = "x";</script></body>', $html);

    $errors = (new PublishContractValidator)->validate(framesSampleFiles(['index.html' => $broken]), 'Frames sample', 'index.html');

    expect($errors)->not->toBe([])
        ->and(implode("\n", $errors))->toContain('inline `<script>`');
});

test('a sample with an on-handler fails the publish contract', function () {
    $html = framesSampleFiles()['index.html'];
    $broken = str_replace('<section', '<section onclick="alert(1)"', $html);

    $errors = (new PublishContractValidator)->validate(framesSampleFiles(['index.html' => $broken]), 'Frames sample', 'index.html');

    expect($errors)->not->toBe([])
        ->and(implode("\n", $errors))->toContain('on*=');
});

test('a sample with a data image fails the publish contract', function () {
    $html = framesSampleFiles()['index.html'];
    $broken = str_replace('src="sample-shot.svg"', 'src="data:image/png;base64,iVBORw0KGgo="', $html);

    $errors = (new PublishContractValidator)->validate(framesSampleFiles(['index.html' => $broken]), 'Frames sample', 'index.html');

    expect($errors)->not->toBe([])
        ->and(implode("\n", $errors))->toContain('data:');
});

test('a sample with an external origin fails the publish contract', function () {
    $html = framesSampleFiles()['index.html'];
    $broken = str_replace('src="sample-shot.svg"', 'src="https://example.com/shot.png"', $html);

    $errors = (new PublishContractValidator)->validate(framesSampleFiles(['index.html' => $broken]), 'Frames sample', 'index.html');

    expect($errors)->not->toBe([])
        ->and(implode("\n", $errors))->toContain('external origin');
});

test('every frame chrome element is aria-hidden', function () {
    $chrome = framesSampleXPath()->query("//*[contains(concat(' ', normalize-space(@class), ' '), ' frame-chrome ')]");

    expect($chrome->length)->toBeGreaterThanOrEqual(3);

    foreach ($chrome as $element) {
        expect($element->getAttribute('aria-hidden'))->toBe('true');
    }
});

test('every slide image has a non-empty alt', function () {
    $images = framesSampleXPath()->query('//img');

    expect($images->length)->toBeGreaterThanOrEqual(3);

    foreach ($images as $image) {
        expect(trim($image->getAttribute('alt')))->not->toBe('');
    }
});

test('each frame root rule uses border-box so padding and borders stay inside its width', function () {
    foreach (['desktop' => 'desktop.css', 'browser' => 'browser.css', 'phone' => 'phone.css'] as $frame => $file) {
        $css = (string) file_get_contents(FRAMES_DIR.'/'.$file);

        expect(preg_match('/\.frame-'.$frame.'\s*\{([^}]*)\}/', $css, $matches))->toBe(1, "{$file} must have a .frame-{$frame} root rule")
            ->and($matches[1])->toMatch('/box-sizing\s*:\s*border-box/', "{$file} root rule must set box-sizing: border-box");
    }
});
