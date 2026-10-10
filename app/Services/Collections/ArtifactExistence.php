<?php

namespace App\Services\Collections;

use Illuminate\Support\Facades\Http;

/**
 * Asks the Worker whether an artifact is visible to the caller's own
 * credential, so collections can only ever hold artifacts of their own org.
 */
final class ArtifactExistence
{
    public const EXISTS = 'exists';

    public const MISSING = 'missing';

    public const UNAVAILABLE = 'unavailable';

    /**
     * @return self::EXISTS|self::MISSING|self::UNAVAILABLE
     */
    public function check(?string $bearerToken, string $artifactId): string
    {
        $baseUrl = config('services.worker.base_url');

        if (! is_string($baseUrl) || $baseUrl === '' || $bearerToken === null) {
            return self::UNAVAILABLE;
        }

        try {
            $response = Http::withToken($bearerToken)->get(rtrim($baseUrl, '/').'/v1/artifacts/'.$artifactId);
        } catch (\Throwable $exception) {
            report($exception);

            return self::UNAVAILABLE;
        }

        return match (true) {
            $response->successful() => self::EXISTS,
            $response->status() === 404 => self::MISSING,
            default => self::UNAVAILABLE,
        };
    }
}
