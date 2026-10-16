<?php

use Illuminate\Support\Facades\File;

it('uses the framework health route for Railway checks without starting a session', function () {
    $railway = File::get(base_path('.railway/railway.ts'));

    expect($railway)->toContain('healthcheck: "/up"');

    $this->get('/up')
        ->assertSuccessful()
        ->assertCookieMissing(config('session.cookie'));
});
