<?php

/*
 * Test fixture only: serves the PDF export parent page from this folder, and
 * the vendored writer and runner from public/vendor/pdf-export, the way the app
 * serves them.
 */

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';
$root = str_starts_with($path, '/vendor/pdf-export/')
    ? realpath(__DIR__.'/../../../public')
    : realpath(__DIR__);
$file = realpath($root.'/'.ltrim($path, '/'));

if ($file === false || ! str_starts_with($file, $root.DIRECTORY_SEPARATOR) || ! is_file($file)) {
    http_response_code(404);

    return;
}

$types = ['html' => 'text/html; charset=utf-8', 'js' => 'text/javascript; charset=utf-8', 'woff' => 'font/woff'];
header('Content-Type: '.($types[strtolower(pathinfo($file, PATHINFO_EXTENSION))] ?? 'application/octet-stream'));
readfile($file);
