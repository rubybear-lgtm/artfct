<?php

namespace App\Services\Slack;

/**
 * What a Slack unfurl renders for everyone in the channel — richness
 * scales with sharing level (spec 15). `bareCard` is the org-private
 * shape: no title, no thumbnail, no description, ever.
 */
final readonly class UnfurlResult
{
    private function __construct(
        public bool $bareCard,
        public bool $signInPrompt,
        public ?string $title,
        public ?string $description,
        public ?string $thumbnail,
        public ?string $provenanceSummary,
    ) {}

    public static function full(string $title, ?string $description, ?string $thumbnail, ?string $provenanceSummary): self
    {
        return new self(bareCard: false, signInPrompt: false, title: $title, description: $description, thumbnail: $thumbnail, provenanceSummary: $provenanceSummary);
    }

    public static function titleOnly(string $title): self
    {
        return new self(bareCard: false, signInPrompt: true, title: $title, description: null, thumbnail: null, provenanceSummary: null);
    }

    public static function bare(): self
    {
        return new self(bareCard: true, signInPrompt: true, title: null, description: null, thumbnail: null, provenanceSummary: null);
    }
}
