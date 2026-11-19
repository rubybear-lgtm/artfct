<?php

/*
 * Test fixture only: serves the deck starter bundle the way the Worker serves an
 * isolated artifact page. The CSP mirrors backend/src/artifact_origin.rs, with
 * frame-ancestors opened to the parent fixture origin given by
 * DECK_STARTER_PARENT_ORIGIN. ?variant=noscale renders the same index.html with
 * the noscale attribute on the stage, so the no-scale path is exercised.
 */

$parentOrigin = getenv('DECK_STARTER_PARENT_ORIGIN') ?: 'http://127.0.0.1';
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';
$file = realpath(getenv('DECK_STARTER_DIR').$path);
$root = realpath(getenv('DECK_STARTER_DIR'));

if ($file === false || $root === false || ! str_starts_with($file, $root.DIRECTORY_SEPARATOR) || ! is_file($file)) {
    http_response_code(404);

    return;
}

$types = ['html' => 'text/html; charset=utf-8', 'css' => 'text/css; charset=utf-8', 'js' => 'text/javascript; charset=utf-8'];
$type = $types[strtolower(pathinfo($file, PATHINFO_EXTENSION))] ?? 'application/octet-stream';

header('Content-Type: '.$type);
header("Content-Security-Policy: default-src 'none'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self'; font-src 'self'; base-uri 'none'; form-action 'none'; frame-ancestors {$parentOrigin};");

$body = (string) file_get_contents($file);

if (($_GET['variant'] ?? null) === 'noscale' && str_ends_with($file, 'index.html')) {
    $body = str_replace('<main class="deck-stage"', '<main class="deck-stage" noscale', $body);
}

echo $body;
