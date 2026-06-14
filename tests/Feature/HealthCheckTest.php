<?php

use Illuminate\Support\Facades\File;

it('uses the framework health route for Railway checks without starting a session', function () {
    $railway = json_decode(File::get(base_path('railway.json')), associative: true, flags: JSON_THROW_ON_ERROR);

    expect($railway['deploy']['healthcheckPath'])->toBe('/up');

    $this->get('/up')
        ->assertSuccessful()
        ->assertCookieMissing(config('session.cookie'));
});
