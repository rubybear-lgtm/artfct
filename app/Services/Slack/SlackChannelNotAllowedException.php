<?php

namespace App\Services\Slack;

use RuntimeException;

final class SlackChannelNotAllowedException extends RuntimeException
{
    public function __construct(public readonly string $channel)
    {
        parent::__construct("Channel [{$channel}] is not in this token's Slack channel allowlist.");
    }
}
