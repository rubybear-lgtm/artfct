<?php

use App\Services\Teams\LastAdminGuard;

test('blocks_when_the_only_admin_would_stop_being_admin', function () {
    expect(LastAdminGuard::leavesNoAdmin(1, true, false))->toBeTrue();
});

test('allows_when_another_admin_remains', function () {
    expect(LastAdminGuard::leavesNoAdmin(2, true, false))->toBeFalse();
});

test('allows_when_the_member_is_not_an_admin', function () {
    expect(LastAdminGuard::leavesNoAdmin(1, false, false))->toBeFalse();
});

test('allows_when_the_admin_stays_admin', function () {
    expect(LastAdminGuard::leavesNoAdmin(1, true, true))->toBeFalse();
});

test('blocks_when_no_admin_count_is_known_to_exist', function () {
    expect(LastAdminGuard::leavesNoAdmin(0, true, false))->toBeTrue();
});
