<?php

namespace App\Services\Slack;

final class FakeSlackPost implements SlackPostContract
{
    /** @var array<int, array{channel: string, text: string, url: string}> */
    public array $posted = [];

    public function postMessage(string $channel, string $text, string $url): void
    {
        $this->posted[] = ['channel' => $channel, 'text' => $text, 'url' => $url];
    }
}
