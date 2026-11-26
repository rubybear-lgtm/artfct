<?php

use App\Services\Artifacts\ArtifactIdShape;

/*
 * RUB-437: the artifact-id shape rule lives in one class so the MCP tools, the
 * link builders and the isolated-hostname minter cannot disagree about what a
 * permanent id looks like. The two permanent shapes are the 13-character
 * lowercase base36 id every new artifact gets and the 32-character lowercase
 * hex id artifacts used before versioning. The 10-character ids the removed
 * free tool minted are not artifact ids any more.
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

test('the 10-character ids of the removed free tool are not artifact ids', function () {
    expect(ArtifactIdShape::isStable('abcdefghij'))->toBeFalse()
        ->and(ArtifactIdShape::isLegacy('abcdefghij'))->toBeFalse()
        ->and(ArtifactIdShape::isPermanent('abcdefghij'))->toBeFalse()
        ->and(ArtifactIdShape::isPermanent('AbC123xYz9'))->toBeFalse();
});

it('rejects an id with a trailing newline', function () {
    expect(ArtifactIdShape::isStable("0123456789abc\n"))->toBeFalse()
        ->and(ArtifactIdShape::isLegacy(str_repeat('a', 32)."\n"))->toBeFalse();
});
