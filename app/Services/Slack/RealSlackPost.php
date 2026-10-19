<?php

namespace App\Services\Slack;

use RuntimeException;

final class RealSlackPost implements SlackPostContract
{
    public function postMessage(string $channel, string $text, string $url): void
    {
        if (! config('services.slack.bot_token')) {
            throw new RuntimeException('services.slack.bot_token must be configured to post to Slack.');
        }

        throw new RuntimeException('RealSlackPost::postMessage is not implemented — no live Slack app in this environment.');
    }
}
