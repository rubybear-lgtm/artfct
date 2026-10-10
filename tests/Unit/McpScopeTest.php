<?php

use App\Enums\McpScope;
use App\Enums\McpScopeRisk;

/**
 * The risk level is a `match` over every case, so a scope added without one
 * throws here rather than reaching the consent screen unmarked.
 */
test('every supported MCP scope declares a label and a risk level', function () {
    foreach (McpScope::cases() as $scope) {
        expect($scope->label())->not->toBeEmpty()
            ->and($scope->risk())->toBeInstanceOf(McpScopeRisk::class);
    }
});

test('scope labels name the capability in plain team language', function () {
    expect(McpScope::ArtifactsRead->label())->toBe("Read and search your team's shared work")
        ->and(McpScope::ArtifactsDeploy->label())->toBe('Share new work with your team')
        ->and(McpScope::ArtifactsDelete->label())->toBe('Delete shared work')
        ->and(McpScope::CollectionsRead->label())->toBe("See your team's collections")
        ->and(McpScope::CollectionsWrite->label())->toBe('Create collections and add work to them')
        ->and(McpScope::UsageRead->label())->toBe('See usage and plan limits');
});

test('scope labels avoid workspace and deploy wording', function () {
    foreach (McpScope::cases() as $scope) {
        $label = $scope->label();

        expect(str_contains($label, 'workspace'))->toBeFalse()
            ->and(str_contains($label, 'Deploy'))->toBeFalse();
    }
});
