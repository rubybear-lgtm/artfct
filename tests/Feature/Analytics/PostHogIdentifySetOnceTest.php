<?php

use App\Services\Analytics\PostHogAnalytics;

test('S2 identify sends $set_once as a top-level sibling of $set (not nested under $set)', function () {
    /** @var list<array<string, mixed>> $captured */
    $captured = [];

    config([
        'analytics.enabled' => true,
        'analytics.api_key' => 'phc_test_set_once',
        'analytics.host' => 'https://us.i.posthog.com',
        'analytics.client_options' => [
            'consumer' => 'noop',
            'before_send' => function (array $message) use (&$captured): ?array {
                $captured[] = $message;

                return null;
            },
        ],
    ]);

    $analytics = new PostHogAnalytics;
    $analytics->identify(
        '42',
        [
            'is_bot' => false,
            'is_internal' => false,
            'environment' => 'local',
        ],
        [
            'initial_utm_source' => 'linkedin',
            'initial_utm_medium' => 'social',
            'initial_landing_path' => '/',
        ],
    );

    expect($captured)->toHaveCount(1);

    $message = $captured[0];
    expect($message['event'])->toBe('$identify')
        ->and($message['distinct_id'])->toBe('42')
        ->and($message['$set_once']['initial_utm_source'])->toBe('linkedin')
        ->and($message['$set_once']['initial_utm_medium'])->toBe('social')
        ->and($message['$set'])->toMatchArray([
            'is_bot' => false,
            'is_internal' => false,
            'environment' => 'local',
        ])
        ->and($message['$set'])->not->toHaveKey('$set_once');
});
