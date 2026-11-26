<?php

/*
 * Test fixture only: serves the doc starter bundle the way the Worker serves an
 * isolated artifact page. The CSP mirrors backend/src/artifact_origin.rs, with
 * frame-ancestors opened to the parent fixture origin given by PARENT_ORIGIN.
 */

$parentOrigin = getenv('DOC_STARTER_PARENT_ORIGIN') ?: 'http://127.0.0.1';
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';
$root = (string) getenv('DOC_STARTER_DIR');
$relative = ltrim($path, '/');

// Fixture cases: /cases/<name>/index.html is the case page; its doc.css and
// export.js always come from the real bundle, so each case runs the shipped chrome.
if (preg_match('#^/cases/([a-z-]+)/index\.html$#', $path, $case)) {
    $root = __DIR__.'/cases/'.$case[1];
    $relative = 'index.html';
} elseif (preg_match('#^/cases/[a-z-]+/(doc\.css|export\.js)$#', $path, $asset)) {
    $relative = $asset[1];
}

$file = realpath($root.'/'.$relative);
$rootReal = realpath($root);

if ($file === false || $rootReal === false || ! str_starts_with($file, $rootReal.DIRECTORY_SEPARATOR) || ! is_file($file)) {
    http_response_code(404);

    return;
}

$types = ['html' => 'text/html; charset=utf-8', 'css' => 'text/css; charset=utf-8', 'js' => 'text/javascript; charset=utf-8'];
$type = $types[strtolower(pathinfo($file, PATHINFO_EXTENSION))] ?? 'application/octet-stream';

header('Content-Type: '.$type);
header("Content-Security-Policy: default-src 'none'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self'; font-src 'self'; base-uri 'none'; form-action 'none'; frame-ancestors {$parentOrigin};");
readfile($file);
