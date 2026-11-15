<?php

namespace App\Services\Templates;

/**
 * One validated template on disk (spec 21). Loaded from
 * `resources/templates/<key>/`; the puller publishes `files` plus the
 * `db` manifest and `seed` through the Worker create payload.
 */
final class TemplateDefinition
{
    /**
     * @param  array<string, string>  $files  path under `files/` => raw bytes
     * @param  list<string>  $requires  feature keys released before a pull
     * @param  array{collections: array<string, array<string, mixed>>, seed: list<array{collection: string, doc_id: string, body: array<string, mixed>}>}|null  $db
     */
    public function __construct(
        public readonly string $key,
        public readonly int $version,
        public readonly string $title,
        public readonly string $description,
        public readonly string $artifactType,
        public readonly string $entrypoint,
        public readonly string $sharing,
        public readonly string $editAccess,
        public readonly array $files,
        public readonly array $requires = [],
        public readonly ?array $db = null,
        public readonly ?string $skill = null,
    ) {}
}
