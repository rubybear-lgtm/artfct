<?php

use Illuminate\Support\Facades\Route;

/**
 * Every registered `/ops` route as the isolation matrix keys it, in the same
 * `"{METHOD} {uri-without-leading-slash}"` form as
 * `scripts/isolation/route-classification.json` (HEAD is dropped).
 *
 * @return list<string>
 */
function registeredOpsRouteKeys(): array
{
    $keys = [];

    foreach (Route::getRoutes()->getRoutes() as $route) {
        $uri = ltrim($route->uri(), '/');

        if ($uri !== 'ops' && ! str_starts_with($uri, 'ops/')) {
            continue;
        }

        foreach ($route->methods() as $method) {
            if ($method !== 'HEAD') {
                $keys[] = $method.' '.$uri;
            }
        }
    }

    sort($keys);

    return array_values(array_unique($keys));
}

/**
 * @return array<string, string> route key => class, from the classification file
 */
function isolationClassification(): array
{
    $file = json_decode(
        (string) file_get_contents(base_path('scripts/isolation/route-classification.json')),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    return $file['laravel'];
}

test('ops_routes_are_classified_staff_in_the_isolation_matrix', function () {
    $classification = isolationClassification();
    $registered = registeredOpsRouteKeys();

    expect($registered)->not->toBeEmpty();

    $unclassifiedOrWrong = array_values(array_filter(
        $registered,
        fn (string $key): bool => ($classification[$key] ?? null) !== 'staff',
    ));

    expect($unclassifiedOrWrong)->toBe([], 'every /ops route must be classified staff');

    $staffEntries = array_keys(array_filter($classification, fn (string $class): bool => $class === 'staff'));
    $stale = array_values(array_diff($staffEntries, $registered));

    expect($stale)->toBe([], 'a staff entry has no registered route');

    $opsEntries = array_filter(
        $classification,
        fn (string $key): bool => (bool) preg_match('#^[A-Z]+ ops(/|$)#', $key),
        ARRAY_FILTER_USE_KEY,
    );
    $notStaff = array_keys(array_filter($opsEntries, fn (string $class): bool => $class !== 'staff'));

    expect($notStaff)->toBe([], 'an /ops route is classified as something other than staff');
});
