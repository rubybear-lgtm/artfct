<?php

namespace App\Services\Slack;

use RuntimeException;

/**
 * DoD: "Exceeding the per-channel post rate limit is refused, not
 * queued" — this is thrown synchronously, there is no retry/backoff path
 * that would eventually deliver the message anyway.
 */
final class SlackRateLimitExceededException extends RuntimeException
{
    public function __construct(public readonly string $channel)
    {
        parent::__construct("Channel [{$channel}] has exceeded its post rate limit.");
    }
}
