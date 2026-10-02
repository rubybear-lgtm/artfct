<?php

use App\Services\Billing\BillingService;

test('change_needed_when_quantity_differs', function () {
    expect(BillingService::seatChangeNeeded(3, 5))->toBeTrue()
        ->and(BillingService::seatChangeNeeded(5, 3))->toBeTrue();
});

test('no_change_when_quantity_matches', function () {
    expect(BillingService::seatChangeNeeded(4, 4))->toBeFalse();
});

test('change_needed_when_never_billed', function () {
    expect(BillingService::seatChangeNeeded(null, 1))->toBeTrue();
});
