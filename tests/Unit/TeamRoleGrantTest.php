<?php

use App\Enums\TeamRole;

test('admin_can_grant_every_role', function () {
    foreach (TeamRole::cases() as $role) {
        expect(TeamRole::Admin->canGrant($role))->toBeTrue();
    }
});

test('member_cannot_grant_admin', function () {
    expect(TeamRole::Member->canGrant(TeamRole::Admin))->toBeFalse()
        ->and(TeamRole::Member->canGrant(TeamRole::Member))->toBeTrue()
        ->and(TeamRole::Member->canGrant(TeamRole::Viewer))->toBeTrue();
});

test('viewer_can_only_grant_viewer', function () {
    expect(TeamRole::Viewer->canGrant(TeamRole::Viewer))->toBeTrue()
        ->and(TeamRole::Viewer->canGrant(TeamRole::Member))->toBeFalse()
        ->and(TeamRole::Viewer->canGrant(TeamRole::Admin))->toBeFalse();
});

test('ranks_are_strictly_ordered', function () {
    expect(TeamRole::Admin->rank())->toBeGreaterThan(TeamRole::Member->rank())
        ->and(TeamRole::Member->rank())->toBeGreaterThan(TeamRole::Viewer->rank());
});
