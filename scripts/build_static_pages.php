<?php

declare(strict_types=1);

// Build a fully static site (no PHP runtime) for Cloudflare Pages.
// - Pre-renders v4 cards (audit_v4 + kyc_v4) into dist/
// - Generates a simple index.html + copies CSS + projects.json
//
// Usage:
//   php -c config/php.ini scripts/build_static_pages.php
//   php -c config/php.ini scripts/build_static_pages.php --out=dist --limit=50

$root = dirname(__DIR__);

require $root . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';

use Preview\CertificateController;
use Intervention\Image\ImageManager;

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
            // Local-dev convenience
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_HTTPHEADER => [
                'Accept: */*',
                'User-Agent: local-certificate-static/1.0',
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

function normalize_projects(array $decoded): array {
    $projects = $decoded['data'] ?? $decoded ?? [];
    return is_array($projects) ? $projects : [];
}

function safe_key(string $raw): string {
    $raw = strtolower(trim($raw));
    $raw = preg_replace('/[^a-z0-9_-]+/', '-', $raw) ?? 'project';
    $raw = trim($raw, '-');
    return $raw !== '' ? $raw : 'project';
}

function ensure_dir(string $path): void {
    if (!is_dir($path)) {
        mkdir($path, 0777, true);
    }
}

$args = [];
foreach ($argv as $i => $a) {
    if ($i === 0) continue;
    if (str_starts_with($a, '--') && str_contains($a, '=')) {
        [$k, $v] = explode('=', substr($a, 2), 2);
        $args[$k] = $v;
    }
}

$outRoot = (string)($args['out'] ?? storage_path('dist'));
$limit = isset($args['limit']) && is_numeric($args['limit']) ? max(1, (int)$args['limit']) : null;
$thumbSize = isset($args['thumb']) && is_numeric($args['thumb']) ? max(64, (int)$args['thumb']) : 720;

$projectsJson = storage_path('data/projects.json');
if (!is_file($projectsJson)) {
    fwrite(STDERR, "Missing data/projects.json. Run: php scripts/fetch_projects.php\n");
    exit(2);
}

$decoded = json_decode((string)file_get_contents($projectsJson), true);
if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
    fwrite(STDERR, "Invalid JSON in data/projects.json: " . json_last_error_msg() . "\n");
    exit(2);
}

$projects = normalize_projects($decoded);
if (!$projects) {
    fwrite(STDERR, "No projects found in data/projects.json\n");
    exit(2);
}

// Output structure
ensure_dir($outRoot);
ensure_dir($outRoot . DIRECTORY_SEPARATOR . 'assets');
ensure_dir($outRoot . DIRECTORY_SEPARATOR . 'data');
ensure_dir($outRoot . DIRECTORY_SEPARATOR . 'cards' . DIRECTORY_SEPARATOR . 'audit_v4');
ensure_dir($outRoot . DIRECTORY_SEPARATOR . 'cards' . DIRECTORY_SEPARATOR . 'kyc_v4');
ensure_dir($outRoot . DIRECTORY_SEPARATOR . 'thumbs' . DIRECTORY_SEPARATOR . 'audit_v4');
ensure_dir($outRoot . DIRECTORY_SEPARATOR . 'thumbs' . DIRECTORY_SEPARATOR . 'kyc_v4');

// Copy CSS + data
$cssSrc = storage_path('public/assets/style.css');
$cssDst = $outRoot . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'style.css';
if (is_file($cssSrc)) {
    copy($cssSrc, $cssDst);
}
copy($projectsJson, $outRoot . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'projects.json');

$manifest = [];

$mgr = ImageManager::gd();

$count = 0;
foreach ($projects as $p) {
    if (!is_array($p)) continue;

    $slug = (string)($p['slug'] ?? $p['uuid'] ?? $p['id'] ?? 'project');
    $key = safe_key($slug);

    $title = (string)($p['name'] ?? $p['title'] ?? 'Project');
    $website = (string)($p['website'] ?? $p['url'] ?? 'https://solidproof.io');
    $websiteHost = (string)(parse_url($website, PHP_URL_HOST) ?: $website);
    $issuedAt = project_onboarded_datetime($p);

    // Cache logo into app/logos/ (same as runtime)
    $logoUrl = (string)($p['full_logo_url'] ?? '');
    $logoExt = 'png';
    if ($logoUrl !== '') {
        $path = (string)(parse_url($logoUrl, PHP_URL_PATH) ?? '');
        $ext = strtolower((string)pathinfo($path, PATHINFO_EXTENSION));
        if ($ext !== '') {
            $logoExt = $ext;
        }
    }
    $logoRel = 'logos/' . safe_key($slug) . '.' . $logoExt;
    $logoAbs = storage_path('app/' . $logoRel);

    if ($logoUrl !== '') {
        ensure_dir(dirname($logoAbs));
        if (!is_file($logoAbs) || filesize($logoAbs) < 1024) {
            try {
                $bytes = http_get($logoUrl);
                file_put_contents($logoAbs, $bytes);
            } catch (Throwable) {
                // keep going
            }
        }
    }

    $dataBase = [
        'title' => $title,
        'date' => $issuedAt->format('F jS, Y'),
        'website' => $websiteHost,
        'logo_url' => $logoRel,
        'copyright' => '© ' . date('Y') . ' SolidProof.io',
    ];

    foreach (['audit_v4', 'kyc_v4'] as $type) {
        $data = $dataBase;
        $data = CertificateController::applyConfigSelections($type, $p, $data);

        try {
            $encoded = CertificateController::generateCertificate($type, $data);
        } catch (Throwable $e) {
            fwrite(STDERR, "Render failed for {$key} {$type}: {$e->getMessage()}\n");
            continue;
        }

        $bytes = (string)$encoded;

        $cardRel = 'cards/' . $type . '/' . $key . '.jpg';
        $cardAbs = $outRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $cardRel);
        file_put_contents($cardAbs, $bytes);

        // Thumbnail for faster browsing
        $thumbRel = 'thumbs/' . $type . '/' . $key . '.jpg';
        $thumbAbs = $outRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $thumbRel);
        try {
            $img = $mgr->read($bytes);
            $img = $img->scaleDown(width: $thumbSize, height: $thumbSize);
            file_put_contents($thumbAbs, (string)$img->toJpeg(85));
        } catch (Throwable) {
            // best-effort
        }

        $manifest[$key][$type] = [
            'card' => $cardRel,
            'thumb' => $thumbRel,
        ];
    }

    $manifest[$key]['meta'] = [
        'slug' => $slug,
        'name' => $title,
        'website' => $websiteHost,
        'score' => $p['score'] ?? null,
        'audit_badge' => $p['audit_badge'] ?? null,
        'kyc_badge' => $p['kyc_badge'] ?? null,
    ];

    $count++;
    if ($limit !== null && $count >= $limit) {
        break;
    }
}

file_put_contents(
    $outRoot . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'manifest.json',
    json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
);

// Static index page
$indexHtml = <<<'HTML'
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>v4 Card Gallery</title>
  <link rel="stylesheet" href="./assets/style.css" />
  <style>
    .gallery-grid{display:grid;grid-template-columns:1fr;gap:14px}
    @media (min-width: 980px){.gallery-grid{grid-template-columns:1fr 1fr}}
    .thumbs{display:flex;gap:12px;flex-wrap:wrap;align-items:flex-start}
    .thumb{width: min(520px, 96vw);border-radius:12px;border:1px solid rgba(255,255,255,.10);background:rgba(0,0,0,.15)}
    .thumb a{display:block;color:inherit;text-decoration:none}
    .thumb img{display:block;width:100%;height:auto;border-radius:12px}
    .meta{display:flex;align-items:baseline;justify-content:space-between;gap:10px;flex-wrap:wrap}
    .meta .name{font-weight:800;color:var(--text)}
    .meta .sub{color:var(--muted)}
    .searchbar{position:sticky;top:0;background:rgba(7,8,22,.92);backdrop-filter: blur(8px);padding:10px 0;z-index:5}
  </style>
</head>
<body class="app-bg">
<div class="page">
  <header class="topbar">
    <div class="brand">v4 Card Gallery</div>
    <div class="actions">
      <a class="btn" href="./data/manifest.json" target="_blank" rel="noreferrer">manifest.json</a>
      <a class="btn" href="./data/projects.json" target="_blank" rel="noreferrer">projects.json</a>
    </div>
  </header>

  <div class="searchbar">
    <div class="row" style="justify-content:space-between;">
      <input id="q" class="search-input" type="search" placeholder="Search by project name or slug" autocomplete="off" />
      <button class="btn btn-small" id="clear" type="button">Clear</button>
    </div>
  </div>

  <main class="content">
    <div id="list" class="stack"></div>
  </main>
</div>

<script>
(async function(){
  const list = document.getElementById('list');
  const q = document.getElementById('q');
  const clear = document.getElementById('clear');
  const res = await fetch('./data/manifest.json', {cache:'no-store'});
  const manifest = await res.json();

  const entries = Object.keys(manifest).map(k => ({ key: k, ...manifest[k] }));
  entries.sort((a,b) => (a.meta?.name || a.key).localeCompare(b.meta?.name || b.key));

  function norm(s){ return (s||'').toString().toLowerCase().trim(); }
  function render(){
    const term = norm(q.value);
    list.innerHTML = '';
    let shown = 0;
    for(const e of entries){
      const name = e.meta?.name || e.key;
      const slug = e.meta?.slug || e.key;
      const blob = norm(name + ' ' + slug);
      if(term && !blob.includes(term)) continue;

      const card = document.createElement('div');
      card.className = 'card';
      const head = document.createElement('div');
      head.className = 'card-title';
      head.innerHTML = `<div class="meta"><div class="name">${escapeHtml(name)}</div><div class="sub">${escapeHtml(slug)}</div></div>`;
      const body = document.createElement('div');
      body.className = 'card-body';

      const thumbs = document.createElement('div');
      thumbs.className = 'thumbs';

      thumbs.appendChild(makeThumb('Audit v4', e.audit_v4?.thumb, e.audit_v4?.card));
      thumbs.appendChild(makeThumb('KYC v4', e.kyc_v4?.thumb, e.kyc_v4?.card));

      body.appendChild(thumbs);
      card.appendChild(head);
      card.appendChild(body);
      list.appendChild(card);
      shown++;
    }
    if(shown === 0){
      const empty = document.createElement('div');
      empty.className = 'card';
      empty.innerHTML = `<div class="card-body">No matches.</div>`;
      list.appendChild(empty);
    }
  }

  function makeThumb(label, thumb, full){
    const wrap = document.createElement('div');
    wrap.className = 'thumb';
    if(!thumb || !full){
      wrap.innerHTML = `<div class="card-body">Missing ${escapeHtml(label)}</div>`;
      return wrap;
    }
    wrap.innerHTML = `
      <a href="./${full}" target="_blank" rel="noreferrer">
        <img alt="${escapeHtml(label)}" src="./${thumb}" loading="lazy" decoding="async" />
      </a>
      <div class="card-body" style="padding-top:10px;">${escapeHtml(label)}</div>
    `;
    return wrap;
  }

  function escapeHtml(s){
    return (s||'').toString()
      .replaceAll('&','&amp;')
      .replaceAll('<','&lt;')
      .replaceAll('>','&gt;')
      .replaceAll('"','&quot;')
      .replaceAll("'",'&#39;');
  }

  q.addEventListener('input', render);
  clear.addEventListener('click', function(){ q.value=''; q.focus(); render(); });

  render();
})();
</script>
</body>
</html>
HTML;

file_put_contents($outRoot . DIRECTORY_SEPARATOR . 'index.html', $indexHtml);

fwrite(STDOUT, "Built static site in: {$outRoot}\n");
