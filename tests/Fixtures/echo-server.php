<?php

// Router script for `php -S`: fake KeyCDN endpoint that echoes the request it received.

declare(strict_types=1);

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

if ($path === '/empty') {
    http_response_code(204);
    return;
}

header('Content-Type: application/json');

if ($path === '/unauthorized.json') {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'description' => 'Unauthorized']);
    return;
}

$headers = array_change_key_case(getallheaders(), CASE_LOWER);

echo json_encode([
    'method' => $_SERVER['REQUEST_METHOD'],
    'path' => $path,
    'query' => $_GET,
    'body' => file_get_contents('php://input'),
    'content_type' => $headers['content-type'] ?? null,
    'authorization' => $headers['authorization'] ?? null,
]);
