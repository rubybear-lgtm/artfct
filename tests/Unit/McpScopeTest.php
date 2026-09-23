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
