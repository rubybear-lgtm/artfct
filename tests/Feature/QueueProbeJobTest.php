<?php

use App\Jobs\QueueProbeJob;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;

test('the_probe_job_logs_its_marker_when_handled', function () {
    Log::spy();

    (new QueueProbeJob('abc'))->handle();

    Log::shouldHaveReceived('info')->withArgs(fn (string $message) => str_contains($message, 'QUEUE_PROBE abc processed'))->once();
});

test('the_probe_job_reports_the_origin_it_would_link_to', function () {
    // `configureDefaults()` pins the scheme only when APP_URL is https, and
    // CI's .env is copied from .env.example (http://localhost). Pin it here
    // too, or the logged scheme follows the ambient config and this assertion
    // depends on the developer's .env.
    URL::forceScheme('https');
    URL::forceRootUrl('https://public.example');
    Log::spy();

    (new QueueProbeJob('abc'))->handle();

    Log::shouldHaveReceived('info')->withArgs(fn (string $message) => str_contains($message, 'app_url https://public.example'))->once();
});
