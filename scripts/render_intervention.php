<?php

declare(strict_types=1);

// CLI helper to render a certificate JPEG using the Intervention-based generator.
// Usage:
//   php -c config/php.ini scripts/render_intervention.php --type=audit_v4 --project=nanochain --out=out/audit_v4.jpg

$root = dirname(__DIR__);

require $root . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';

use Preview\CertificateController;

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

    $body = @file_get_contents($url);
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

$args = [];
foreach ($argv as $i => $a) {
    if ($i === 0) continue;
    if (str_starts_with($a, '--') && str_contains($a, '=')) {
        [$k, $v] = explode('=', substr($a, 2), 2);
        $args[$k] = $v;
    }
}

$type = (string)($args['type'] ?? 'audit_v4');
$requested = (string)($args['project'] ?? '');
$out = (string)($args['out'] ?? (storage_path('out') . DIRECTORY_SEPARATOR . $type . '.jpg'));
$logoScale = (string)($args['logoScale'] ?? '');

if (!in_array($type, ['audit_v4', 'kyc_v4'], true)) {
    fwrite(STDERR, "Invalid type. Supported: audit_v4, kyc_v4\n");
    exit(2);
}

$projectsJsonPath = storage_path('data/projects.json');
$raw = is_file($projectsJsonPath) ? file_get_contents($projectsJsonPath) : false;
$decoded = json_decode($raw ?: '', true);
$projects = (json_last_error() === JSON_ERROR_NONE) ? ($decoded['data'] ?? $decoded ?? []) : [];

if (!$projects) {
    fwrite(STDERR, "No projects found. Run: php scripts/fetch_projects.php\n");
    exit(2);
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
            // keep going; generator may fail if logo is unreadable
        }
    }
}

$data = [
    'title' => $title,
    'date' => $issuedAt->format('F jS, Y'),
    'website' => $websiteHost,
    'logo_url' => $logoRel,
    'copyright' => '© ' . date('Y') . ' solidproof.io',
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
    fwrite(STDERR, 'Render error: ' . $e->getMessage() . "\n");
    exit(1);
}

$outDir = dirname($out);
if (!is_dir($outDir)) {
    mkdir($outDir, 0777, true);
}

file_put_contents($out, (string)$encoded);

fwrite(STDOUT, "Wrote: $out\n");
