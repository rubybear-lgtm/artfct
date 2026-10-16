<?php

namespace App\Services\Slack;

interface SlackPostContract
{
    public function postMessage(string $channel, string $text, string $url): void;
}
