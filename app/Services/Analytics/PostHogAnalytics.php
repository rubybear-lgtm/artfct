<?php

namespace App\Services\Analytics;

use App\Contracts\AnalyticsContract;
use Illuminate\Support\Facades\Log;
use PostHog\PostHog;
use Throwable;

/**
 * PostHog PHP sink. No-ops when disabled or when the API key is missing.
 * Every public method swallows failures so product requests never fail open.
 */
final class PostHogAnalytics implements AnalyticsContract
{
    private bool $initialized = false;

    public function capture(string $distinctId, string $event, array $properties = [], array $groups = []): void
    {
        if (! $this->ensureReady()) {
            return;
        }

        try {
            $payload = [
                'distinctId' => $distinctId,
                'event' => $event,
                'properties' => $properties,
            ];

            if ($groups !== []) {
                $payload['$groups'] = $this->groupKeys($groups);
            }

            PostHog::capture($payload);
        } catch (Throwable $exception) {
            Log::warning('PostHog capture failed.', [
                'event' => $event,
                'exception' => $exception->getMessage(),
            ]);
            report($exception);
        }
    }

    public function identify(string $distinctId, array $properties = [], array $setOnce = []): void
    {
        if (! $this->ensureReady()) {
            return;
        }

        try {
            // posthog-php copies `properties` onto top-level `$set`. `$set_once`
            // must be a sibling of `$set`, not nested inside `properties`, or the
            // SDK folds it into `$set['$set_once']` and first-touch never sticks.
            $payload = [
                'distinctId' => $distinctId,
                'properties' => $properties,
            ];
            if ($setOnce !== []) {
                $payload['$set_once'] = $setOnce;
            }

            PostHog::identify($payload);
        } catch (Throwable $exception) {
            Log::warning('PostHog identify failed.', [
                'exception' => $exception->getMessage(),
            ]);
            report($exception);
        }
    }

    public function groupIdentify(string $groupType, string $groupKey, array $properties = []): void
    {
        if (! $this->ensureReady()) {
            return;
        }

        try {
            PostHog::groupIdentify([
                'groupType' => $groupType,
                'groupKey' => $groupKey,
                'properties' => $properties,
            ]);
        } catch (Throwable $exception) {
            Log::warning('PostHog groupIdentify failed.', [
                'exception' => $exception->getMessage(),
            ]);
            report($exception);
        }
    }

    public function flush(): void
    {
        if (! $this->initialized) {
            return;
        }

        try {
            PostHog::flush();
        } catch (Throwable $exception) {
            Log::warning('PostHog flush failed.', [
                'exception' => $exception->getMessage(),
            ]);
            report($exception);
        }
    }

    public function enabled(): bool
    {
        return (bool) config('analytics.enabled')
            && is_string(config('analytics.api_key'))
            && config('analytics.api_key') !== '';
    }

    private function ensureReady(): bool
    {
        if (! $this->enabled()) {
            return false;
        }

        if ($this->initialized) {
            return true;
        }

        try {
            $clientOptions = config('analytics.client_options', []);
            $initOptions = [
                'host' => (string) config('analytics.host', 'https://us.i.posthog.com'),
            ];
            if (is_array($clientOptions)) {
                $initOptions = [...$initOptions, ...$clientOptions];
            }

            PostHog::init(
                (string) config('analytics.api_key'),
                $initOptions,
            );
            $this->initialized = true;

            return true;
        } catch (Throwable $exception) {
            Log::warning('PostHog init failed.', [
                'exception' => $exception->getMessage(),
            ]);
            report($exception);

            return false;
        }
    }

    /**
     * @param  array<string, array<string, mixed>|string>  $groups
     * @return array<string, string>
     */
    private function groupKeys(array $groups): array
    {
        $keys = [];
        foreach ($groups as $type => $value) {
            $keys[$type] = is_array($value)
                ? (string) ($value['id'] ?? $value['slug'] ?? '')
                : (string) $value;
        }

        return array_filter($keys, static fn (string $key): bool => $key !== '');
    }
}
