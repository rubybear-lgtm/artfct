<?php

namespace App\Services\Artifacts;

/**
 * Checks the slide captures a PowerPoint export posts (RUB-562, spec 46): at
 * most 30 slides, a body of at most 40 MB, and each capture a base64 PNG of
 * exactly 1920 by 1080. Each refusal carries its own code.
 */
final class PptxCaptureValidator
{
    public const MAX_SLIDES = 30;

    public const MAX_BODY_BYTES = 40 * 1024 * 1024;

    public const WIDTH = 1920;

    public const HEIGHT = 1080;

    private const PNG_SIGNATURE = "\x89PNG\r\n\x1a\n";

    /** The IHDR chunk name at bytes 12-15, then width and height at 16-23. */
    private const IHDR_CHUNK_NAME = 'IHDR';

    private const IHDR_END_OFFSET = 24;

    /**
     * @param  mixed  $captures  The request's `captures` value: a list of base64 PNG strings.
     * @param  int  $bodyBytes  The size of the request body in bytes.
     * @return list<string> The decoded PNG bytes, in slide order.
     *
     * @throws PptxExportException
     */
    public function decode(mixed $captures, int $bodyBytes): array
    {
        if (! is_array($captures) || $captures === [] || ! array_is_list($captures)) {
            throw $this->refusal('invalid_captures', 'Send one base64 PNG per slide in the captures list.');
        }

        if (count($captures) > self::MAX_SLIDES) {
            throw $this->refusal('too_many_slides', 'A deck export takes at most '.self::MAX_SLIDES.' slides.');
        }

        if ($bodyBytes > self::MAX_BODY_BYTES) {
            throw new PptxExportException(422, 'payload_too_large', 'The export is larger than 40 MB.');
        }

        $pngs = [];

        foreach ($captures as $index => $encoded) {
            $pngs[] = $this->decodeCapture($encoded, $index + 1);
        }

        return $pngs;
    }

    /**
     * @throws PptxExportException
     */
    private function decodeCapture(mixed $encoded, int $slideNumber): string
    {
        $png = is_string($encoded) ? base64_decode($encoded, true) : false;

        if ($png === false || ! str_starts_with($png, self::PNG_SIGNATURE)) {
            throw $this->refusal('invalid_png', "Slide {$slideNumber} is not a PNG.");
        }

        if (strlen($png) < self::IHDR_END_OFFSET || substr($png, 12, 4) !== self::IHDR_CHUNK_NAME) {
            throw $this->refusal('wrong_dimensions', "Slide {$slideNumber} must be 1920 by 1080 pixels.");
        }

        $dimensions = unpack('Nwidth/Nheight', substr($png, 16, 8));

        if ($dimensions === false || $dimensions['width'] !== self::WIDTH || $dimensions['height'] !== self::HEIGHT) {
            throw $this->refusal('wrong_dimensions', "Slide {$slideNumber} must be 1920 by 1080 pixels.");
        }

        return $png;
    }

    private function refusal(string $code, string $message): PptxExportException
    {
        return new PptxExportException(422, $code, $message);
    }
}
