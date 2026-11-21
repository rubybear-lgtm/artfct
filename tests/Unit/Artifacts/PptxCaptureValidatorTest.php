<?php

use App\Services\Artifacts\PptxCaptureValidator;
use App\Services\Artifacts\PptxExportException;

/*
 * RUB-562 / spec 46: the limits on the captures the export route accepts. The
 * route tests cover the HTTP side; these cover each limit and its code.
 */

/** A PNG of the given size, made with GD. */
function pptxValidatorPng(int $width = 1920, int $height = 1080): string
{
    $image = imagecreatetruecolor($width, $height);

    ob_start();
    imagepng($image);

    return (string) ob_get_clean();
}

/** The refusal code a call raises, or null when it is accepted. */
function pptxValidatorRefusal(callable $call): ?string
{
    try {
        $call();
    } catch (PptxExportException $exception) {
        expect($exception->status)->toBe(422);

        return $exception->errorCode;
    }

    return null;
}

test('decodes each base64 capture back to its PNG bytes in order', function () {
    $first = pptxValidatorPng();
    $second = pptxValidatorPng();

    $decoded = app(PptxCaptureValidator::class)->decode([base64_encode($first), base64_encode($second)], 1024);

    expect($decoded)->toBe([$first, $second]);
});

test('refuses no captures with invalid_captures', function () {
    expect(pptxValidatorRefusal(fn () => app(PptxCaptureValidator::class)->decode([], 10)))->toBe('invalid_captures');
});

test('accepts exactly 30 captures and refuses 31 with too_many_slides', function () {
    $png = base64_encode(pptxValidatorPng());

    expect(pptxValidatorRefusal(fn () => app(PptxCaptureValidator::class)->decode(array_fill(0, 30, $png), 10)))->toBeNull()
        ->and(pptxValidatorRefusal(fn () => app(PptxCaptureValidator::class)->decode(array_fill(0, 31, $png), 10)))->toBe('too_many_slides');
});

test('accepts a body of exactly 40 MB and refuses one byte more with payload_too_large', function () {
    $png = base64_encode(pptxValidatorPng());
    $limit = 40 * 1024 * 1024;

    expect(pptxValidatorRefusal(fn () => app(PptxCaptureValidator::class)->decode([$png], $limit)))->toBeNull()
        ->and(pptxValidatorRefusal(fn () => app(PptxCaptureValidator::class)->decode([$png], $limit + 1)))->toBe('payload_too_large');
});

test('refuses a capture that is not base64 with invalid_png', function () {
    expect(pptxValidatorRefusal(fn () => app(PptxCaptureValidator::class)->decode(['not*base64!'], 10)))->toBe('invalid_png');
});

test('refuses bytes without the PNG signature with invalid_png', function () {
    $jpegLike = base64_encode("\xFF\xD8\xFF\xE0 not a png at all");

    expect(pptxValidatorRefusal(fn () => app(PptxCaptureValidator::class)->decode([$jpegLike], 10)))->toBe('invalid_png');
});

test('refuses a PNG that is not 1920 by 1080 with wrong_dimensions', function () {
    $small = base64_encode(pptxValidatorPng(1280, 720));

    expect(pptxValidatorRefusal(fn () => app(PptxCaptureValidator::class)->decode([$small], 10)))->toBe('wrong_dimensions');
});
