<?php

namespace App\Services\Sharing;

use App\Models\Team;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Pushes an org's sharing settings to the Worker's
 * `POST /v1/internal/org-settings`, authenticated with the same
 * `ARTFCT_LIMITS_WRITE_SECRET` the limits writer uses, and backfills
 * artifact owners through `POST /v1/internal/orgs/{org}/owner-backfill`.
 *
 * A failed push is logged and reported to the caller rather than thrown: for
 * the public-sharing toggle the caller fails closed (leaves the local mirror
 * unchanged), so a dropped push can never make the UI claim public sharing is
 * off while the Worker still serves public links.
 */
final class OrgSettingsWriter
{
    public function __construct(
        private readonly ?string $baseUrl = null,
        private readonly ?string $secret = null,
    ) {}

    public static function default(): self
    {
        return new self(
            config('services.worker.base_url') ?: null,
            config('services.worker.limits_write_secret') ?: null,
        );
    }

    /**
     * Push whether the team allows public sharing. Turning it off downgrades
     * every live public artifact of the org to team in the same request; the
     * returned ids are the ones the Worker moved, so the caller can audit each.
     *
     * @return list<string>|null the downgraded artifact ids, or null when the
     *                           push was not made or was rejected.
     */
    public function pushPublicSharing(Team $team, bool $publicSharingAllowed): ?array
    {
        if (! $this->baseUrl || ! $this->secret) {
            return null;
        }

        try {
            $response = Http::withToken($this->secret)
                ->post(rtrim($this->baseUrl, '/').'/v1/internal/org-settings', [
                    'org' => $team->slug,
                    'public_sharing_allowed' => $publicSharingAllowed,
                ]);
        } catch (Throwable $exception) {
            Log::warning('Org settings push failed.', ['org' => $team->slug, 'error' => $exception->getMessage()]);

            return null;
        }

        if (! $response->successful()) {
            Log::warning('Org settings push rejected.', ['org' => $team->slug, 'status' => $response->status()]);

            return null;
        }

        $downgraded = $response->json('downgraded');

        if (! is_array($downgraded)) {
            return [];
        }

        $ids = [];
        foreach ($downgraded as $id) {
            if (is_string($id)) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * Give every ownerless artifact of the org an owner. Idempotent; returns
     * the number of artifacts the Worker updated (0 when the push was not made
     * or was rejected).
     */
    public function backfillOwners(string $orgSlug, string $ownerUserId): int
    {
        if (! $this->baseUrl || ! $this->secret) {
            return 0;
        }

        try {
            $response = Http::withToken($this->secret)
                ->post(rtrim($this->baseUrl, '/')."/v1/internal/orgs/{$orgSlug}/owner-backfill", [
                    'owner_user_id' => $ownerUserId,
                ]);
        } catch (Throwable $exception) {
            Log::warning('Artifact owner backfill failed.', ['org' => $orgSlug, 'error' => $exception->getMessage()]);

            return 0;
        }

        if (! $response->successful()) {
            Log::warning('Artifact owner backfill rejected.', ['org' => $orgSlug, 'status' => $response->status()]);

            return 0;
        }

        return (int) $response->json('updated');
    }
}
