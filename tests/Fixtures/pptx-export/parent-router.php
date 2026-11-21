<?php

/*
 * Test fixture only: the app-origin side of the PowerPoint export. Serves the
 * parent page, the runner from public/vendor/pptx-export, and a stub export route
 * that counts the captures it receives in a file under the system temp directory.
 */

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';
$runner = dirname(__DIR__, 3).'/public/vendor/pptx-export/export.js';

if ($path === '/export' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = json_decode((string) file_get_contents('php://input'), true);
    $captures = is_array($body['captures'] ?? null) ? $body['captures'] : [];

    file_put_contents(sys_get_temp_dir().'/artfct-pptx-export-fixture-count.txt', (string) count($captures));

    header('Content-Type: application/vnd.openxmlformats-officedocument.presentationml.presentation');
    header('Content-Disposition: attachment; filename="fixture-deck.pptx"');
    echo 'PK';

    return;
}

if ($path === '/parent.html') {
    header('Content-Type: text/html; charset=utf-8');
    readfile(__DIR__.'/parent.html');

    return;
}

if ($path === '/vendor/pptx-export/export.js' && is_file($runner)) {
    header('Content-Type: text/javascript; charset=utf-8');
    readfile($runner);

    return;
}

http_response_code(404);
