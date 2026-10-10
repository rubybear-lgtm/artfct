<?php

use App\Services\Artifacts\ArtifactIdShape;

/*
 * RUB-437: the artifact-id shape rule lives in one class so the MCP tools, the
 * link builders and the isolated-hostname minter cannot disagree about what a
 * permanent id looks like. The two permanent shapes are the 13-character
 * lowercase base36 id every new artifact gets and the 32-character lowercase
 * hex id artifacts used before versioning; the ephemeral shape is the
 * 10-character alphanumeric id of an anonymous expiring preview.
 */

test('stable ids are exactly 13 lowercase base36 characters', function () {
    expect(ArtifactIdShape::isStable('abc123def4567'))->toBeTrue()
        ->and(ArtifactIdShape::isStable('0000000000000'))->toBeTrue()
        ->and(ArtifactIdShape::isStable('zzzzzzzzzzzzz'))->toBeTrue()
        ->and(ArtifactIdShape::isStable('ABC123DEF4567'))->toBeFalse()
        ->and(ArtifactIdShape::isStable('abc123def456'))->toBeFalse()
        ->and(ArtifactIdShape::isStable('abc123def45678'))->toBeFalse()
        ->and(ArtifactIdShape::isStable('abc123def456-'))->toBeFalse();
});

test('legacy ids are exactly 32 lowercase hex characters', function () {
    expect(ArtifactIdShape::isLegacy('0123456789abcdef0123456789abcdef'))->toBeTrue()
        ->and(ArtifactIdShape::isLegacy('0123456789abcdef0123456789abcde'))->toBeFalse()
        ->and(ArtifactIdShape::isLegacy('0123456789abcdef0123456789abcdefa'))->toBeFalse()
        ->and(ArtifactIdShape::isLegacy('0123456789ABCDEF0123456789ABCDEF'))->toBeFalse()
        ->and(ArtifactIdShape::isLegacy('0123456789abcdef0123456789abcdeg'))->toBeFalse();
});

test('permanent ids accept either shape and reject everything else', function () {
    expect(ArtifactIdShape::isPermanent('abc123def4567'))->toBeTrue()
        ->and(ArtifactIdShape::isPermanent('0123456789abcdef0123456789abcdef'))->toBeTrue()
        ->and(ArtifactIdShape::isPermanent('abcdefghij'))->toBeFalse()
        ->and(ArtifactIdShape::isPermanent(''))->toBeFalse()
        ->and(ArtifactIdShape::isPermanent('not-a-valid-id'))->toBeFalse();
});

test('ephemeral ids are exactly 10 alphanumeric characters', function () {
    expect(ArtifactIdShape::isEphemeral('abcdefghij'))->toBeTrue()
        ->and(ArtifactIdShape::isEphemeral('AbC123xYz9'))->toBeTrue()
        ->and(ArtifactIdShape::isEphemeral('abcdefghi'))->toBeFalse()
        ->and(ArtifactIdShape::isEphemeral('abcdefghijk'))->toBeFalse()
        ->and(ArtifactIdShape::isEphemeral('abcdefgh-j'))->toBeFalse()
        // A 13-character stable id is not an ephemeral id, and vice versa.
        ->and(ArtifactIdShape::isEphemeral('abc123def4567'))->toBeFalse();
});

it('rejects an id with a trailing newline', function () {
    expect(ArtifactIdShape::isStable("0123456789abc\n"))->toBeFalse()
        ->and(ArtifactIdShape::isLegacy(str_repeat('a', 32)."\n"))->toBeFalse()
        ->and(ArtifactIdShape::isEphemeral("abcdefghij\n"))->toBeFalse();
});
