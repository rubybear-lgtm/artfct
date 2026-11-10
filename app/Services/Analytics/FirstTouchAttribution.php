<?php

namespace App\Services\Analytics;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Session;

/**
 * First-touch UTM / referrer / landing path, stored in the Laravel session
 * (no analytics cookie) so it survives the WorkOS AuthKit round-trip.
 */
final class FirstTouchAttribution
{
    public const SESSION_KEY = 'analytics.first_touch';

    /**
     * @return array{
     *     utm_source: ?string,
     *     utm_medium: ?string,
     *     utm_campaign: ?string,
     *     utm_content: ?string,
     *     referrer: ?string,
     *     landing_path: ?string
     * }|null
     */
    public function get(): ?array
    {
        $value = Session::get(self::SESSION_KEY);

        return is_array($value) ? $this->normalize($value) : null;
    }

    /**
     * Capture first touch once. Later visits do not overwrite.
     */
    public function captureFromRequest(Request $request): void
    {
        if ($this->get() !== null) {
            return;
        }

        $utmSource = $this->stringOrNull($request->query('utm_source'));
        $utmMedium = $this->stringOrNull($request->query('utm_medium'));
        $utmCampaign = $this->stringOrNull($request->query('utm_campaign'));
        $utmContent = $this->stringOrNull($request->query('utm_content'));
        $referrer = $this->stringOrNull($request->headers->get('referer'));
        $landingPath = '/'.ltrim($request->path(), '/');
        if ($landingPath === '//') {
            $landingPath = '/';
        }

        $hasSignal = $utmSource !== null
            || $utmMedium !== null
            || $utmCampaign !== null
            || $utmContent !== null
            || $referrer !== null;

        // Always record a landing path on the first HTML GET so organic
        // visits still get initial_landing_path at signup.
        if (! $hasSignal && ! $request->isMethod('GET')) {
            return;
        }

        Session::put(self::SESSION_KEY, [
            'utm_source' => $utmSource,
            'utm_medium' => $utmMedium,
            'utm_campaign' => $utmCampaign,
            'utm_content' => $utmContent,
            'referrer' => $referrer,
            'landing_path' => $landingPath,
        ]);
    }

    /**
     * Person / team property bag with initial_* keys (nulls omitted).
     *
     * @param  array{
     *     utm_source?: ?string,
     *     utm_medium?: ?string,
     *     utm_campaign?: ?string,
     *     utm_content?: ?string,
     *     referrer?: ?string,
     *     landing_path?: ?string
     * }|null  $touch
     * @return array<string, string>
     */
    public function asInitialProperties(?array $touch = null): array
    {
        $touch ??= $this->get();
        if ($touch === null) {
            return [];
        }

        $map = [
            'initial_utm_source' => $touch['utm_source'] ?? null,
            'initial_utm_medium' => $touch['utm_medium'] ?? null,
            'initial_utm_campaign' => $touch['utm_campaign'] ?? null,
            'initial_utm_content' => $touch['utm_content'] ?? null,
            'initial_referrer' => $touch['referrer'] ?? null,
            'initial_landing_path' => $touch['landing_path'] ?? null,
        ];

        return array_filter(
            $map,
            static fn (mixed $value): bool => is_string($value) && trim($value) !== '',
        );
    }

    /**
     * @param  array<string, mixed>  $value
     * @return array{
     *     utm_source: ?string,
     *     utm_medium: ?string,
     *     utm_campaign: ?string,
     *     utm_content: ?string,
     *     referrer: ?string,
     *     landing_path: ?string
     * }
     */
    private function normalize(array $value): array
    {
        return [
            'utm_source' => $this->stringOrNull($value['utm_source'] ?? null),
            'utm_medium' => $this->stringOrNull($value['utm_medium'] ?? null),
            'utm_campaign' => $this->stringOrNull($value['utm_campaign'] ?? null),
            'utm_content' => $this->stringOrNull($value['utm_content'] ?? null),
            'referrer' => $this->stringOrNull($value['referrer'] ?? null),
            'landing_path' => $this->stringOrNull($value['landing_path'] ?? null),
        ];
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : mb_substr($trimmed, 0, 500);
    }
}
