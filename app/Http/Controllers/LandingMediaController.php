<?php

namespace App\Http\Controllers;

use App\Support\LandingMedia;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Serves the landing animation files. They are named by content hash, so they
 * are cached for a year and never revalidated, and the response answers byte
 * range requests, which Safari needs before it will play a video.
 */
class LandingMediaController extends Controller
{
    public function __invoke(string $file): BinaryFileResponse
    {
        abort_unless(preg_match(LandingMedia::FILE_PATTERN, $file) === 1, 404);

        $path = LandingMedia::directory().'/'.$file;

        abort_unless(is_file($path), 404);

        return response()->file($path, [
            'Content-Type' => str_ends_with($file, '.mp4') ? 'video/mp4' : 'image/jpeg',
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ]);
    }
}
