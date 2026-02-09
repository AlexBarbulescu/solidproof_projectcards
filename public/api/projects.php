<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$projectsJsonPath = $root . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'projects.json';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache');

if (!is_file($projectsJsonPath)) {
    http_response_code(404);
    echo json_encode([
        'error' => 'Missing data/projects.json. Run: php scripts/fetch_projects.php',
        'path' => $projectsJsonPath,
        'data' => [],
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

$raw = file_get_contents($projectsJsonPath);
$decoded = json_decode($raw ?: '', true);
if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(500);
    echo json_encode([
        'error' => 'projects.json is not valid JSON: ' . json_last_error_msg(),
        'data' => [],
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

$data = $decoded['data'] ?? $decoded ?? [];
if (!is_array($data)) {
    $data = [];
}

echo json_encode([
    'data' => array_values($data),
], JSON_UNESCAPED_SLASHES);
