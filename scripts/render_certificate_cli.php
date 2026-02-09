<?php

declare(strict_types=1);

// CLI wrapper for the existing GD/Intervention renderer.
//
// Usage:
//   php scripts/render_certificate_cli.php "project=<slug>&type=audit_v4&logoScale=1.0"
//
// Writes raw JPEG bytes to STDOUT.

ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', 'php://stderr');
error_reporting(E_ALL);

$query = (string)($argv[1] ?? '');
parse_str($query, $_GET);

// Provide a tiny bit of request context for any code that expects it.
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['QUERY_STRING'] = $query;
$_SERVER['REQUEST_URI'] = '/certificate-intervention.php' . ($query !== '' ? ('?' . $query) : '');

$root = dirname(__DIR__);
$entry = $root . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'certificate-intervention.php';

if (!is_file($entry)) {
    fwrite(STDERR, "Missing entrypoint: {$entry}\n");
    exit(2);
}

ob_start();
try {
    require $entry;
    $bytes = ob_get_clean();
    fwrite(STDOUT, (string)$bytes);
} catch (Throwable $e) {
    ob_end_clean();
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
