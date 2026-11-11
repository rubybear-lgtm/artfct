<?php

use App\Services\Billing\BillingService;

/**
 * Per-seat quantity sync was retired with flat Pro/Team pricing (RUB-529).
 * seatChangeNeeded stays as a no-op predicate so the scheduled command can
 * keep calling it until the command itself is removed.
 */
test('seat_change_is_never_needed_under_flat_pricing', function () {
    expect(BillingService::seatChangeNeeded(3, 5))->toBeFalse()
        ->and(BillingService::seatChangeNeeded(5, 3))->toBeFalse()
        ->and(BillingService::seatChangeNeeded(4, 4))->toBeFalse()
        ->and(BillingService::seatChangeNeeded(null, 1))->toBeFalse();
});
