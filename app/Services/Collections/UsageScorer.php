<?php

namespace App\Services\Collections;

use App\Enums\UsageEventType;
use App\Models\ArtifactUsageEvent;
use Carbon\CarbonInterface;

/**
 * Pure scoring from a set of usage events (spec 16) — no I/O, so the
 * ranking rules ("repeat views by distinct users", "shared into Slack",
 * "retrieved then opened", decay, supersession) are each independently
 * unit-testable without a database.
 */
final class UsageScorer
{
    private const VIEW_WEIGHT_PER_DISTINCT_USER = 1.0;

    private const SLACK_SHARE_WEIGHT = 2.0;

    private const RETRIEVED_THEN_OPENED_WEIGHT = 3.0;

    /** Score halves every 30 days — six months out is ~1/64th. */
    private const HALF_LIFE_DAYS = 30.0;

    /**
     * @param  array<int, ArtifactUsageEvent>  $events  every usage event
     *                                                  for ONE artifact
     */
    public static function score(array $events, CarbonInterface $now): float
    {
        if (self::isSuperseded($events)) {
            // A superseded artifact never outranks its successor on
            // usage — its own historical popularity doesn't matter once
            // a newer version exists (DoD: "the lineage matters; the old
            // one should rank lower").
            return 0.0;
        }

        $score = 0.0;

        // Distinct viewers, not raw view count — five distinct viewers
        // must outrank one viewer who reloaded five times.
        $latestViewByUser = [];
        foreach ($events as $event) {
            if ($event->event_type !== UsageEventType::Viewed || $event->actor_user_id === null) {
                continue;
            }
            $existing = $latestViewByUser[$event->actor_user_id] ?? null;
            if ($existing === null || $event->occurred_at->gt($existing->occurred_at)) {
                $latestViewByUser[$event->actor_user_id] = $event;
            }
        }
        foreach ($latestViewByUser as $event) {
            $score += self::VIEW_WEIGHT_PER_DISTINCT_USER * self::decay($event->occurred_at, $now);
        }

        foreach ($events as $event) {
            $score += match ($event->event_type) {
                UsageEventType::SlackShared => self::SLACK_SHARE_WEIGHT * self::decay($event->occurred_at, $now),
                UsageEventType::RetrievedThenOpened => self::RETRIEVED_THEN_OPENED_WEIGHT * self::decay($event->occurred_at, $now),
                default => 0.0,
            };
        }

        return $score;
    }

    /**
     * @param  array<int, ArtifactUsageEvent>  $events
     */
    private static function isSuperseded(array $events): bool
    {
        foreach ($events as $event) {
            if ($event->event_type === UsageEventType::Superseded) {
                return true;
            }
        }

        return false;
    }

    private static function decay(CarbonInterface $occurredAt, CarbonInterface $now): float
    {
        $ageDays = max(0.0, $now->diffInSeconds($occurredAt, absolute: true) / 86400);

        return 0.5 ** ($ageDays / self::HALF_LIFE_DAYS);
    }
}
