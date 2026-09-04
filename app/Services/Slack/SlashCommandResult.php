<?php

namespace App\Services\Slack;

use App\Services\Search\SearchResult;

/**
 * The result of a `/artfct <query>` invocation — always rendered as an
 * ephemeral message (spec 15 DoD: "Search results are not visible to
 * other channel members").
 */
final readonly class SlashCommandResult
{
    /**
     * @param  array<int, SearchResult>  $results
     */
    private function __construct(
        public bool $needsConnect,
        public array $results,
    ) {}

    public static function connectPrompt(): self
    {
        return new self(needsConnect: true, results: []);
    }

    /**
     * @param  array<int, SearchResult>  $results
     */
    public static function results(array $results): self
    {
        return new self(needsConnect: false, results: $results);
    }
}
