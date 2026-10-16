<?php

namespace App\Services\Indexing;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class CloudflareIndexingClient
{
    /** @param array<string, mixed> $body */
    public function post(string $path, array $body, int $timeout = 30): mixed
    {
        $accountId = config('services.cloudflare.account_id');
        $token = config('services.cloudflare.api_token');
        if (! $accountId || ! $token) {
            throw new RuntimeException('Cloudflare indexing credentials are not configured.');
        }

        try {
            $response = Http::withToken($token)->acceptJson()
                ->connectTimeout(5)->timeout($timeout)
                ->post("https://api.cloudflare.com/client/v4/accounts/{$accountId}/{$path}", $body);
        } catch (ConnectionException) {
            // Provider exceptions can contain the signed URL or a response body.
            if (str_starts_with($path, 'browser-run/')) {
                throw new RenderTimeoutException('Browser Rendering timed out or could not connect.');
            }

            throw new RuntimeException('Workers AI request timed out or could not connect.');
        }

        if (! $response->successful() || $response->json('success') !== true) {
            $errors = $response->json('errors', []);
            $timedOut = in_array($response->status(), [408, 504], true)
                || preg_match('/timeout|timed out/i', json_encode($errors)) === 1;
            if ($timedOut && str_starts_with($path, 'browser-run/')) {
                throw new RenderTimeoutException('Browser Rendering exceeded its render timeout.');
            }

            throw new RuntimeException('Cloudflare indexing request failed (HTTP '.$response->status().').');
        }

        return $response->json('result');
    }
}
