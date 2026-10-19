<?php

namespace App\Services\Slack;

use App\Models\OrgToken;
use App\Services\Collections\UsageEventLogger;
use Illuminate\Support\Facades\RateLimiter;

/**
 * "An agent that deployed an artifact can post it to a channel, given an
 * org-scoped token with a channel allowlist. Rate-limited per channel —
 * an agent in a loop must not be able to flood a channel."
 */
final class SlackPostService
{
    private const MAX_POSTS_PER_MINUTE = 5;

    public function __construct(
        private readonly SlackPostContract $slack,
        private readonly ?UsageEventLogger $usage = null,
    ) {}

    /**
     * @throws SlackChannelNotAllowedException when `$channel` isn't in
     *                                         `$token`'s allowlist.
     * @throws SlackRateLimitExceededException when the channel's post
     *                                         rate limit is exceeded.
     */
    public function postArtifact(OrgToken $token, string $channel, string $artifactUrl, string $title, ?string $artifactId = null): void
    {
        $allowlist = $token->slack_channels ?? [];
        if (! in_array($channel, $allowlist, strict: true)) {
            throw new SlackChannelNotAllowedException($channel);
        }

        $key = "slack-post:{$channel}";
        if (RateLimiter::tooManyAttempts($key, self::MAX_POSTS_PER_MINUTE)) {
            throw new SlackRateLimitExceededException($channel);
        }
        RateLimiter::hit($key, decaySeconds: 60);

        $this->slack->postMessage($channel, $title, $artifactUrl);

        // Spec 16: "Shared into Slack — someone vouched for it to
        // colleagues" is a ranking signal in its own right.
        if ($artifactId !== null) {
            $this->usage?->recordSlackShare($token->team, $artifactId, $token->user_id);
        }
    }
}
