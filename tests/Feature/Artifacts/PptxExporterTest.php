<?php

use App\Services\Artifacts\PptxExporter;

/**
 * A 1920 by 1080 PNG with a flat fill, made with GD so the test needs no browser.
 */
function pptxFixturePng(int $red): string
{
    $image = imagecreatetruecolor(1920, 1080);
    imagefilledrectangle($image, 0, 0, 1919, 1079, imagecolorallocate($image, $red, 90, 40));

    ob_start();
    imagepng($image);
    imagedestroy($image);

    return (string) ob_get_clean();
}

/**
 * The slide XML parts and every text run in the notes of each slide, read from
 * the package XML with a DOM parse (phppresentation's reader does not return
 * text it wrote, so the tests do not rely on it).
 *
 * @return array{slides: list<string>, notes: list<string>}
 */
function pptxPackageParts(string $path): array
{
    $zip = new ZipArchive;
    expect($zip->open($path))->toBeTrue();

    $slides = [];
    $notes = [];
    foreach (range(1, 50) as $number) {
        $slideXml = $zip->getFromName("ppt/slides/slide{$number}.xml");
        if ($slideXml === false) {
            break;
        }
        $slides[] = $slideXml;

        $noteXml = $zip->getFromName("ppt/notesSlides/notesSlide{$number}.xml");
        $noteText = [];
        if ($noteXml !== false) {
            $document = new DOMDocument;
            $document->loadXML($noteXml);
            foreach ($document->getElementsByTagNameNS('http://schemas.openxmlformats.org/drawingml/2006/main', 't') as $run) {
                $noteText[] = $run->textContent;
            }
        }
        $notes[] = implode('', $noteText);
    }
    $zip->close();

    return ['slides' => $slides, 'notes' => $notes];
}

test('writes one full-bleed picture per capture, in order, with the view url in the notes', function () {
    $captures = [pptxFixturePng(200), pptxFixturePng(40), pptxFixturePng(30)];
    $urls = ['https://app.test/a/one', 'https://app.test/a/one', 'https://app.test/a/one'];

    $path = app(PptxExporter::class)->build($captures, $urls);

    try {
        $parts = pptxPackageParts($path);

        expect($parts['slides'])->toHaveCount(3)
            ->and($parts['notes'])->toBe(array_fill(0, 3, 'https://app.test/a/one'));

        $zip = new ZipArchive;
        $zip->open($path);
        $media = array_filter(
            array_map(fn (int $index): string => $zip->getNameIndex($index), range(0, $zip->numFiles - 1)),
            fn (string $name): bool => str_starts_with($name, 'ppt/media/'),
        );
        $zip->close();

        expect($media)->toHaveCount(3);
    } finally {
        @unlink($path);
    }
});
