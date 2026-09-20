<?php

use App\Jobs\QueueProbeJob;
use Illuminate\Support\Facades\Log;

test('the_probe_job_logs_its_marker_when_handled', function () {
    Log::spy();

    (new QueueProbeJob('abc'))->handle();

    Log::shouldHaveReceived('info')->withArgs(fn (string $message) => str_contains($message, 'QUEUE_PROBE abc processed'))->once();
});
