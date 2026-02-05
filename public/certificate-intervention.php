<?php

declare(strict_types=1);

// Intervention Image (GD driver) preview that mirrors the Laravel controller's generateCertificate() flow.
// IMPORTANT: Project data is loaded ONLY from local data/projects.json.
// The only network request this script may do is to download/cache the project's logo image.
// Usage: /certificate-intervention.php?project=nanochain&type=audit_v4

$root = dirname(__DIR__);

require $root . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';

use Preview\CertificateController;

function fail(string $message, int $status = 500): never {
    http_response_code($status);
    header('Content-Type: text/plain; charset=utf-8');
    echo $message;
    exit;
}

function storage_path(string $path = ''): string {
    $root = dirname(__DIR__);
    $clean = ltrim($path, "\\/");
    return $root . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $clean);
}

function config(string $key): mixed {
    static $cache = null;
    if ($cache === null) {
        $root = dirname(__DIR__);
        $file = $root . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'certificate.php';
        if (!is_file($file)) {
            throw new RuntimeException('Missing config file: ' . $file);
        }

        $cache = require $file;
    }

    // supports: certificate.audit_v4, certificate.kyc_v4
    $parts = explode('.', $key);
    if (count($parts) !== 2 || $parts[0] !== 'certificate') {
        throw new RuntimeException('Unsupported config key: ' . $key);
    }

    return $cache[$parts[1]] ?? null;
}

function http_get(string $url): string {
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 25,
            CURLOPT_CONNECTTIMEOUT => 10,
            // Local-dev convenience: Windows PHP curl installs often lack a CA bundle.
            // This avoids SSL failures when fetching known public assets.
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_HTTPHEADER => [
                'Accept: */*',
                'User-Agent: local-certificate-preview/1.0',
            ],
        ]);
        $body = curl_exec($ch);
        if ($body === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException('cURL error: ' . $err);
        }
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException('HTTP ' . $status . ' from ' . $url);
        }
        return $body;
    }

    $ctx = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => 25,
            'header' => "User-Agent: local-certificate-preview/1.0\r\n",
        ]
    ]);

    $body = @file_get_contents($url, false, $ctx);
    if ($body === false) {
        $err = error_get_last();
        throw new RuntimeException('HTTP fetch failed: ' . ($err['message'] ?? 'unknown error'));
    }

    return $body;
}

function project_onboarded_datetime(array $project): DateTimeImmutable {
    $raw = (string)($project['onboarded'] ?? '');
    if ($raw !== '') {
        try {
            return new DateTimeImmutable($raw);
        } catch (Throwable) {
            // fall through
        }
    }

    return new DateTimeImmutable('now', new DateTimeZone('UTC'));
}

$projectsJsonPath = $root . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'projects.json';
$raw = is_file($projectsJsonPath) ? file_get_contents($projectsJsonPath) : false;
$decoded = json_decode($raw ?: '', true);
$projects = (json_last_error() === JSON_ERROR_NONE) ? ($decoded['data'] ?? $decoded ?? []) : [];

$requested = (string)($_GET['project'] ?? '');
$type = (string)($_GET['type'] ?? 'audit_v4');
$logoScale = (string)($_GET['logoScale'] ?? '');

if (!in_array($type, ['audit_v4', 'kyc_v4'], true)) {
    fail('Invalid type. Supported: audit_v4, kyc_v4', 400);
}

if (!$projects) {
    fail('No projects found. Expected local JSON at: ' . $projectsJsonPath, 404);
}

$project = null;
foreach ($projects as $p) {
    if ($requested !== '' && (string)($p['slug'] ?? '') === $requested) {
        $project = $p;
        break;
    }
}
$project ??= $projects[0];

$title = (string)($project['name'] ?? 'Project');
$website = (string)($project['website'] ?? $project['url'] ?? 'https://solidproof.io');
$websiteHost = (string)(parse_url($website, PHP_URL_HOST) ?: $website);
$issuedAt = project_onboarded_datetime($project);

// Cache project logo into storage_path('app/...') so the generator can read it the same way Laravel does.
$logoUrl = (string)($project['full_logo_url'] ?? '');
$slug = (string)($project['slug'] ?? 'project');
$logoExt = 'png';
if ($logoUrl !== '') {
    $path = (string)(parse_url($logoUrl, PHP_URL_PATH) ?? '');
    $ext = strtolower((string)pathinfo($path, PATHINFO_EXTENSION));
    if ($ext !== '') {
        $logoExt = $ext;
    }
}
$logoRel = 'logos/' . preg_replace('/[^a-z0-9_-]+/i', '-', strtolower($slug)) . '.' . $logoExt;
$logoAbs = storage_path('app/' . $logoRel);

if ($logoUrl !== '') {
    $dir = dirname($logoAbs);
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
    if (!is_file($logoAbs) || filesize($logoAbs) < 1024) {
        try {
            $bytes = http_get($logoUrl);
            file_put_contents($logoAbs, $bytes);
        } catch (Throwable $e) {
            // keep going; we will fail later if logo is required
        }
    }
}

// Match your controller's input shape
$data = [
    'title' => $title,
    // Use the format your design shows (2nd, 2026). Change to F dS, Y if you want leading zero.
    'date' => $issuedAt->format('F jS, Y'),
    'website' => $websiteHost,
    'logo_url' => $logoRel,
    // v4 cards use a slightly different footer text in the design
    
    'copyright' => '© ' . date('Y') . ' SolidProof.io',
];

if ($logoScale !== '' && is_numeric($logoScale)) {
    $scale = (float)$logoScale;
    if (is_finite($scale) && $scale > 0) {
        $data['logo_scale'] = $scale;
    }
}

// Config-driven selections (background + badges)
$data = CertificateController::applyConfigSelections($type, $project, $data);

try {
    $encoded = CertificateController::generateCertificate($type, $data);
} catch (Throwable $e) {
    fail('Render error: ' . $e->getMessage(), 500);
}

header('Content-Type: image/jpeg');
header('Cache-Control: no-cache');

echo (string)$encoded;
