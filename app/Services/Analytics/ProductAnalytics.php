<?php

namespace App\Services\Analytics;

use App\Contracts\AnalyticsContract;
use App\Enums\HeardAboutSource;
use App\Enums\Plan;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Typed product-analytics events for Artfct. Adds environment, team
 * denormalization, is_internal, and groups on every team-scoped event.
 * Never throws.
 */
final class ProductAnalytics
{
    public const ARTIFACT_SHARED = 'artifact_shared';

    public const ARTIFACT_SEARCHED = 'artifact_searched';

    public const USER_SIGNED_UP = 'user_signed_up';

    public const USER_LOGGED_IN = 'user_logged_in';

    public const TEAM_CREATED = 'team_created';

    public const TEAM_UPGRADED = 'team_upgraded';

    public const INVITE_SENT = 'invite_sent';

    public const MEMBER_JOINED = 'member_joined';

    public const BOT_CONNECTED = 'bot_connected';

    public const ARTIFACT_VIEWED = 'artifact_viewed';

    public const ARTIFACT_DELETED = 'artifact_deleted';

    public function __construct(
        private AnalyticsContract $analytics,
        private FirstTouchAttribution $firstTouch,
    ) {}

    /**
     * @param  array<string, mixed>  $properties
     */
    public function artifactShared(User|string|null $actor, Team $team, string $source, array $properties = []): void
    {
        $this->captureTeamEvent(self::ARTIFACT_SHARED, $actor, $team, $source, $properties);
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    public function artifactSearched(User|string|null $actor, Team $team, string $source, int $queryLength, int $resultCount, array $properties = []): void
    {
        $this->captureTeamEvent(self::ARTIFACT_SEARCHED, $actor, $team, $source, [
            ...$properties,
            'query_length' => $queryLength,
            'result_count' => $resultCount,
        ]);
    }

    public function userSignedUp(User $user): void
    {
        $initial = $this->firstTouch->asInitialProperties();
        if ($user->acquisition === null && $initial !== []) {
            $user->forceFill(['acquisition' => $initial])->save();
        }

        $initial = is_array($user->acquisition) ? $user->acquisition : $initial;
        $isInternal = $this->isInternalUser($user);

        $this->safe(function () use ($user, $initial, $isInternal): void {
            $this->analytics->identify(
                (string) $user->id,
                [
                    'is_bot' => $user->isBot(),
                    'is_internal' => $isInternal,
                    'environment' => $this->environment(),
                ],
                $initial,
            );

            $this->analytics->capture((string) $user->id, self::USER_SIGNED_UP, [
                'environment' => $this->environment(),
                'actor_is_bot' => $user->isBot(),
                'is_internal' => $isInternal,
                'source' => 'web',
                ...$initial,
                '$set_once' => $initial,
            ]);
        });
    }

    public function userLoggedIn(User $user): void
    {
        $isInternal = $this->isInternalUser($user);

        $this->safe(function () use ($user, $isInternal): void {
            $this->analytics->identify((string) $user->id, [
                'is_bot' => $user->isBot(),
                'is_internal' => $isInternal,
                'environment' => $this->environment(),
            ]);

            $this->analytics->capture((string) $user->id, self::USER_LOGGED_IN, [
                'environment' => $this->environment(),
                'actor_is_bot' => $user->isBot(),
                'is_internal' => $isInternal,
                'source' => 'web',
            ]);
        });
    }

    public function teamCreated(User $user, Team $team, string $source = 'web'): void
    {
        $initial = is_array($user->acquisition) ? $user->acquisition : [];
        if ($initial === []) {
            $initial = $this->firstTouch->asInitialProperties();
        }

        if ($team->acquisition === null && $initial !== []) {
            $team->forceFill(['acquisition' => $initial])->save();
        }

        $heard = $this->heardAboutProps($team);
        $groupProps = [
            ...$this->teamProps($team),
            ...$initial,
            ...$heard,
            'name' => $team->name,
            'plan' => ($team->plan ?? Plan::Free)->value,
        ];

        $this->safe(function () use ($user, $team, $source, $initial, $heard, $groupProps): void {
            $this->analytics->groupIdentify('team', $team->slug, $groupProps);
            $this->captureTeamEvent(self::TEAM_CREATED, $user, $team, $source, [
                ...$initial,
                ...$heard,
            ]);
        });
    }

    public function teamUpgraded(User $user, Team $team): void
    {
        $initial = is_array($user->acquisition) ? $user->acquisition : [];
        if ($team->acquisition === null && $initial !== []) {
            $team->forceFill(['acquisition' => $initial])->save();
        }

        $heard = $this->heardAboutProps($team);
        $groupProps = [
            ...$this->teamProps($team),
            ...$initial,
            ...$heard,
            'name' => $team->name,
            'plan' => ($team->plan ?? Plan::Free)->value,
        ];

        $this->safe(function () use ($user, $team, $initial, $heard, $groupProps): void {
            $this->analytics->groupIdentify('team', $team->slug, $groupProps);
            $this->captureTeamEvent(self::TEAM_UPGRADED, $user, $team, 'web', [
                ...$initial,
                ...$heard,
            ]);
        });
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    public function inviteSent(User $actor, Team $team, array $properties = []): void
    {
        unset($properties['email'], $properties['invitee_email'], $properties['invitee']);
        $this->captureTeamEvent(self::INVITE_SENT, $actor, $team, 'web', $properties);
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    public function memberJoined(User $user, Team $team, string $source = 'web', array $properties = []): void
    {
        unset($properties['email'], $properties['invitee_email']);
        $this->captureTeamEvent(self::MEMBER_JOINED, $user, $team, $source, $properties);
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    public function botConnected(User|string|null $actor, Team $team, string $source, array $properties = []): void
    {
        $this->captureTeamEvent(self::BOT_CONNECTED, $actor, $team, $source, [
            'actor_is_bot' => true,
            ...$properties,
        ]);
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    public function artifactViewed(User|string|null $actor, Team $team, array $properties = []): void
    {
        $this->captureTeamEvent(self::ARTIFACT_VIEWED, $actor, $team, 'worker', $properties);
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    public function artifactDeleted(User|string|null $actor, Team $team, array $properties = []): void
    {
        $this->captureTeamEvent(self::ARTIFACT_DELETED, $actor, $team, 'worker', $properties);
    }

    public function isInternalUser(?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        /** @var list<int> $ids */
        $ids = config('analytics.internal.user_ids', []);
        if (in_array($user->id, $ids, true)) {
            return true;
        }

        /** @var list<string> $domains */
        $domains = config('analytics.internal.email_domains', []);
        $email = strtolower((string) $user->email);
        foreach ($domains as $domain) {
            if ($domain !== '' && str_ends_with($email, '@'.$domain)) {
                return true;
            }
        }

        return false;
    }

    public function isInternalTeam(Team $team): bool
    {
        /** @var list<string> $slugs */
        $slugs = config('analytics.internal.team_slugs', []);

        return in_array($team->slug, $slugs, true);
    }

    /**
     * Scrub free text that looks like an email; keep short.
     */
    public function sanitizeHeardAboutOther(?string $other): ?string
    {
        if ($other === null) {
            return null;
        }

        $trimmed = trim($other);
        if ($trimmed === '') {
            return null;
        }

        if (filter_var($trimmed, FILTER_VALIDATE_EMAIL)) {
            return null;
        }

        if (preg_match('/[^\s]+@[^\s]+/', $trimmed) === 1) {
            return null;
        }

        return mb_substr($trimmed, 0, 120);
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    private function captureTeamEvent(string $event, User|string|null $actor, Team $team, string $source, array $properties = []): void
    {
        $this->safe(function () use ($event, $actor, $team, $source, $properties): void {
            $user = $actor instanceof User ? $actor : null;
            $distinctId = $user !== null
                ? (string) $user->id
                : (is_string($actor) && $actor !== '' && $actor !== 'anonymous' ? $actor : 'anonymous');

            $actorIsBot = array_key_exists('actor_is_bot', $properties)
                ? (bool) $properties['actor_is_bot']
                : ($user?->isBot() ?? false);

            $isInternal = $this->isInternalTeam($team) || $this->isInternalUser($user);

            $props = [
                ...$this->teamProps($team),
                'plan' => ($team->plan ?? Plan::Free)->value,
                'environment' => $this->environment(),
                'source' => $source,
                ...$properties,
                // Force after caller props so privacy/identity cannot be overridden.
                'actor_is_bot' => $actorIsBot,
                'is_internal' => $isInternal,
            ];

            // Privacy: never leak content / raw query / emails.
            unset(
                $props['email'],
                $props['invitee_email'],
                $props['invitee'],
                $props['query'],
                $props['html'],
                $props['content'],
                $props['body'],
            );

            $this->analytics->capture($distinctId, $event, $props, [
                'team' => $team->slug,
            ]);
        });
    }

    /**
     * @return array{team_id: int, team_slug: string, is_personal: bool}
     */
    private function teamProps(Team $team): array
    {
        return [
            'team_id' => $team->id,
            'team_slug' => $team->slug,
            'is_personal' => (bool) $team->is_personal,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function heardAboutProps(Team $team): array
    {
        $props = [];
        if (is_string($team->heard_about) && $team->heard_about !== '') {
            $props['heard_about'] = $team->heard_about;
        }

        $other = $this->sanitizeHeardAboutOther($team->heard_about_other);
        if ($other !== null && ($team->heard_about === HeardAboutSource::Other->value || $team->heard_about === null)) {
            $props['heard_about_other'] = $other;
        }

        return $props;
    }

    private function environment(): string
    {
        $environment = config('analytics.environment', 'local');

        return is_string($environment) && $environment !== '' ? $environment : 'local';
    }

    /**
     * @param  callable(): void  $callback
     */
    private function safe(callable $callback): void
    {
        try {
            $callback();
        } catch (Throwable $exception) {
            Log::warning('Product analytics emission failed.', [
                'exception' => $exception->getMessage(),
            ]);
            report($exception);
        }
    }
}
