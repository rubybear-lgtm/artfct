<?php

test('the_staging_verification_command_is_a_noop_outside_staging_or_without_the_flag', function () {
    config(['synthetic.verify_usage' => false]);

    test()->artisan('staging:verify-usage')->doesntExpectOutputToContain('USAGE_VERIFY')->assertSuccessful();

    config(['synthetic.verify_usage' => true]);

    test()->artisan('staging:verify-usage')->doesntExpectOutputToContain('USAGE_VERIFY')->assertSuccessful();
});
