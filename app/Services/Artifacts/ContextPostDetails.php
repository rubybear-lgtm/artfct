<?php

namespace App\Services\Artifacts;

/**
 * The field rules for a context post's `post/details` document (RUB-559,
 * spec 29). The db_* MCP tools call this before any Worker request, so an
 * invalid kind, status or replaced_by never reaches the artifact database.
 * The Worker keeps no per-field schema (spec 20 declares only an index), so
 * this is the one place these values are defined.
 */
final class ContextPostDetails
{
    public const COLLECTION = 'post';

    public const DOCUMENT = 'details';

    /** @var list<string> */
    public const KINDS = ['decision', 'announcement', 'topic', 'policy', 'options', 'brand'];

    /** @var list<string> */
    public const STATUSES = ['draft', 'current', 'superseded'];

    /** @var list<string> */
    public const FIELDS = ['kind', 'owner', 'status', 'replaced_by'];

    public static function appliesTo(string $collection, string $docId): bool
    {
        return $collection === self::COLLECTION && $docId === self::DOCUMENT;
    }

    /**
     * Returns the refusal message, or null when the write may be sent.
     *
     * @param  array<string, mixed>  $data  the fields the caller sends
     * @param  bool  $complete  true for a replacement, which must carry every field
     */
    public static function refusal(array $data, bool $complete): ?string
    {
        $unknown = array_diff(array_keys($data), self::FIELDS);
        if ($unknown !== []) {
            return 'A post has no field ['.implode(', ', $unknown).']; use kind, owner, status and replaced_by.';
        }

        if ($complete) {
            $missing = array_diff(self::FIELDS, array_keys($data));
            if ($missing !== []) {
                return 'Replacing a post needs every field; missing ['.implode(', ', $missing).'].';
            }
        }

        if (array_key_exists('kind', $data) && ! in_array($data['kind'], self::KINDS, true)) {
            return 'kind must be one of: '.implode(', ', self::KINDS).'.';
        }

        if (array_key_exists('status', $data) && ! in_array($data['status'], self::STATUSES, true)) {
            return 'status must be one of: '.implode(', ', self::STATUSES).'.';
        }

        if (array_key_exists('replaced_by', $data) && $data['replaced_by'] !== null && (! is_string($data['replaced_by']) || ! ArtifactIdShape::isPermanent($data['replaced_by']))) {
            return 'replaced_by must be null or a permanent artifact id.';
        }

        if (array_key_exists('owner', $data) && ! is_string($data['owner'])) {
            return 'owner must be a string.';
        }

        return null;
    }
}
