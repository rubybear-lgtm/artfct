<?php

namespace App\Services\Billing;

use App\Enums\Plan;
use App\Models\Team;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Daily publish caps (RUB-499): new artifacts and new versions count once per
 * UTC day per user and per workspace. Identical content (content-addressed
 * republish) does not increment after the first successful publish of that
 * fingerprint in the workspace.
 */
final class DailyPublishLimiter
{
    public function assertCanPublish(Team $team, string $userId): void
    {
        $day = $this->utcDay();
        $limits = $this->limitsFor($team);
        $userCount = $this->count($this->userKey($team->id, $userId, $day));
        $workspaceCount = $this->count($this->workspaceKey($team->id, $day));

        if ($userCount >= $limits['per_user']) {
            throw $this->exceeded(
                "This account has reached today's publish limit ({$limits['per_user']} per day). Try again after {$this->resetAt()->toIso8601String()}.",
                $this->resetAt(),
                $limits['per_user'],
                'user',
            );
        }

        if ($workspaceCount >= $limits['per_workspace']) {
            throw $this->exceeded(
                "This workspace has reached today's publish limit ({$limits['per_workspace']} per day). Try again after {$this->resetAt()->toIso8601String()}.",
                $this->resetAt(),
                $limits['per_workspace'],
                'workspace',
            );
        }
    }

    /**
     * Record one publish against the daily counters when the Worker actually
     * created a new artifact or version. Identical-content no-ops pass
     * `$counts = false` (or reuse a known fingerprint that already counted).
     *
     * @param  string  $contentFingerprint  sha256 of the published bundle bytes
     */
    public function recordPublish(Team $team, string $userId, string $contentFingerprint, bool $counts = true): void
    {
        if (! $counts) {
            return;
        }

        $fingerprintKey = $this->fingerprintKey($team->id, $contentFingerprint);
        // Fingerprints outlive the daily window: identical content never burns a
        // second slot in the workspace, even across UTC midnights.
        if (! Cache::add($fingerprintKey, 1, now()->addYear())) {
            return;
        }

        $day = $this->utcDay();
        $ttl = $this->ttlUntilReset();
        $this->increment($this->userKey($team->id, $userId, $day), $ttl);
        $this->increment($this->workspaceKey($team->id, $day), $ttl);
    }

    /**
     * @return array{per_user: int, per_workspace: int}
     */
    public function limitsFor(Team $team): array
    {
        $plan = ($team->plan ?? Plan::Free)->value;
        $config = config("billing.plans.{$plan}.daily_publish", []);

        return [
            'per_user' => max(1, (int) ($config['per_user'] ?? config('billing.daily_publish.per_user', 100))),
            'per_workspace' => max(1, (int) ($config['per_workspace'] ?? config('billing.daily_publish.per_workspace', 300))),
        ];
    }

    public function resetAt(): Carbon
    {
        return Carbon::now('UTC')->startOfDay()->addDay();
    }

    private function utcDay(): string
    {
        return Carbon::now('UTC')->toDateString();
    }

    private function ttlUntilReset(): Carbon
    {
        return $this->resetAt()->addHour();
    }

    private function userKey(int $teamId, string $userId, string $day): string
    {
        return "daily-publish:user:{$teamId}:{$userId}:{$day}";
    }

    private function workspaceKey(int $teamId, string $day): string
    {
        return "daily-publish:workspace:{$teamId}:{$day}";
    }

    private function fingerprintKey(int $teamId, string $fingerprint): string
    {
        return "daily-publish:fingerprint:{$teamId}:{$fingerprint}";
    }

    private function count(string $key): int
    {
        return (int) Cache::get($key, 0);
    }

    private function increment(string $key, Carbon $ttl): void
    {
        if (Cache::add($key, 0, $ttl)) {
            // key created
        }
        Cache::increment($key);
    }

    private function exceeded(string $message, Carbon $resetAt, int $limit, string $scope): QuotaExceededException
    {
        return new QuotaExceededException($message, [
            'reset_at' => $resetAt->toIso8601String(),
            'limit' => $limit,
            'scope' => $scope,
        ]);
    }
}
