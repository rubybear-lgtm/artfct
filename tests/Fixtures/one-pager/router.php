<?php

/*
 * RUB-560 / spec 39 test fixture only. Serves one-pager bundles the way the
 * Worker serves an isolated artifact page, and answers the db endpoints the
 * starter calls. The first path segment picks the bundle:
 *   /starter/...       the shipped starter (ONE_PAGER_BUNDLE_DIR)
 *   /four-fields/...   the starter plus a fourth declared field
 *   /types/...         a declaration with a colour, a toggle and a range
 *   /no-declaration/...  the starter with tweaks.json removed
 * Session role, stored document and the save count live in ONE_PAGER_STATE.
 */

$statePath = getenv('ONE_PAGER_STATE');
$bundleDir = getenv('ONE_PAGER_BUNDLE_DIR');
$fixtureDir = getenv('ONE_PAGER_FIXTURE_DIR');
$state = json_decode((string) @file_get_contents($statePath), true) ?: [];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';

$saveState = function (array $next) use ($statePath): void {
    file_put_contents($statePath, json_encode($next));
};

$json = function (array $body, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($body);
};

if ($path === '/__artfct/db/session') {
    $role = $state['role'] ?? 'writer';
    $json(['token' => $role.'-token', 'role' => $role, 'sharing' => 'team', 'is_owner' => false, 'expires_at' => '2030-01-01T00:00:00Z']);

    return;
}

if ($path === '/__artfct/db/tweaks/fields') {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        if (! str_starts_with($_SERVER['HTTP_AUTHORIZATION'] ?? '', 'Bearer ')) {
            $json(['error' => 'unauthorized'], 401);

            return;
        }
        if (isset($state['get_status'])) {
            $json(['error' => 'forced'], (int) $state['get_status']);

            return;
        }
        if (! isset($state['doc'])) {
            $json(['error' => 'not_found'], 404);

            return;
        }
        $json(['collection' => 'tweaks', 'doc_id' => 'fields', 'version' => 1, 'data' => $state['doc']]);

        return;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'PUT') {
        if (($_SERVER['HTTP_AUTHORIZATION'] ?? '') !== 'Bearer writer-token') {
            $json(['error' => 'unauthorized'], 401);

            return;
        }
        $body = json_decode((string) file_get_contents('php://input'), true);
        $state['doc'] = $body;
        $state['puts'] = ($state['puts'] ?? 0) + 1;
        $saveState($state);
        $json(['collection' => 'tweaks', 'doc_id' => 'fields', 'version' => $state['puts']]);

        return;
    }
}

$segments = explode('/', ltrim($path, '/'), 2);
$bundle = $segments[0];
$file = $segments[1] ?? 'index.html';

if (! in_array($bundle, ['starter', 'four-fields', 'types', 'no-declaration'], true) || $file === '' || str_contains($file, '..')) {
    http_response_code(404);

    return;
}

if ($bundle === 'no-declaration' && $file === 'tweaks.json') {
    http_response_code(404);

    return;
}

$candidates = [];
if ($bundle === 'four-fields') {
    $candidates[] = $fixtureDir.'/one-pager-four-fields/'.$file;
}
if ($bundle === 'types') {
    $candidates[] = $fixtureDir.'/one-pager-types/'.$file;
}
$candidates[] = $bundleDir.'/'.$file;

$found = null;
foreach ($candidates as $candidate) {
    $real = realpath($candidate);
    if ($real !== false && is_file($real)) {
        $found = $real;
        break;
    }
}

if ($found === null) {
    http_response_code(404);

    return;
}

$types = ['html' => 'text/html; charset=utf-8', 'css' => 'text/css; charset=utf-8', 'js' => 'text/javascript; charset=utf-8', 'json' => 'application/json', 'svg' => 'image/svg+xml'];
header('Content-Type: '.($types[strtolower(pathinfo($found, PATHINFO_EXTENSION))] ?? 'application/octet-stream'));
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self'; connect-src 'self'; base-uri 'none'; form-action 'none'; frame-ancestors 'none';");
readfile($found);
