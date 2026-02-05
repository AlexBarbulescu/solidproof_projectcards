<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$projectsJsonPath = $root . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'projects.json';
$projects = [];
$error = null;

if (is_file($projectsJsonPath)) {
    $raw = file_get_contents($projectsJsonPath);
    $decoded = json_decode($raw ?: '', true);
    if (json_last_error() === JSON_ERROR_NONE) {
        // API shape: { data: [...] }
        $projects = $decoded['data'] ?? $decoded ?? [];
    } else {
        $error = 'projects.json exists but is not valid JSON: ' . json_last_error_msg();
    }
} else {
    $error = 'Missing data/projects.json. Run: php scripts/fetch_projects.php';
}

function h(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function score_class(float $score): string {
    if ($score >= 85) {
        return 'score-pill good';
    }
    if ($score >= 70) {
        return 'score-pill ok';
    }
    if ($score >= 50) {
        return 'score-pill warn';
    }
    return 'score-pill bad';
}

function normalize_url(?string $url): ?string {
    $url = trim((string)$url);
    if ($url === '') {
        return null;
    }
    if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
        return $url;
    }
    return 'https://' . $url;
}

$selectedSlug = (string)($_GET['project'] ?? '');
if ($selectedSlug === '' && $projects) {
    $first = $projects[0];
    $selectedSlug = (string)($first['slug'] ?? $first['uuid'] ?? '');
}

// Sort by ID desc (matches typical app listing)
usort($projects, static function ($a, $b): int {
    $ai = (int)($a['id'] ?? 0);
    $bi = (int)($b['id'] ?? 0);
    return $bi <=> $ai;
});

?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width,initial-scale=1" />
    <title>Certificate Preview</title>
    <link rel="stylesheet" href="/assets/style.css" />
</head>
<body class="app-bg">
<div class="page">
    <header class="topbar">
        <div class="brand">Certificate Preview</div>
        <div class="actions">
            <a class="btn" href="/preview.php">Open preview</a>
        </div>
    </header>

    <main class="content">
        <?php if ($error): ?>
            <div class="card warning">
                <div class="card-title">Setup</div>
                <div class="card-body">
                    <p><?= h($error) ?></p>
                    <p>API endpoint: <a href="https://app.solidproof.io/api/v1/projects?sort=1&amp;limit=50" target="_blank" rel="noreferrer">projects</a></p>
                </div>
            </div>
        <?php endif; ?>

        <div class="stack">
            <div class="card">
                <div class="card-title">
                    <div class="title-row">
                        <div>Projects</div>
                        <div class="muted" id="resultsLabel"></div>
                    </div>
                </div>
                <div class="card-body">
                    <div class="searchbar">
                        <input id="projectSearch" class="search-input" type="search" placeholder="Search for a project by name or address" autocomplete="off" />
                        <div class="search-actions">
                            <button class="btn btn-small" id="clearSearch" type="button">Clear</button>
                        </div>
                    </div>

                    <?php if (!$projects): ?>
                        <p>No projects loaded yet.</p>
                    <?php else: ?>
                        <div class="table-wrap" role="region" aria-label="Projects table" tabindex="0">
                            <table class="projects-table">
                                <thead>
                                <tr>
                                    <th class="col-fav" aria-label="Favorite"></th>
                                    <th class="col-id">#</th>
                                    <th class="col-name">Name</th>
                                    <th class="col-score">Security score</th>
                                    <th class="col-services">Services &amp; certificates</th>
                                    <th class="col-ecosystems">Ecosystems</th>
                                    <th class="col-category">Category</th>
                                </tr>
                                </thead>
                                <tbody id="projectsTbody">
                                <?php foreach ($projects as $p):
                                    $id = (string)($p['id'] ?? '');
                                    $name = (string)($p['name'] ?? $p['title'] ?? 'Untitled');
                                    $slug = (string)($p['slug'] ?? $p['uuid'] ?? $name);
                                    $scoreRaw = (string)($p['score'] ?? '0');
                                    $score = (float)($scoreRaw === '' ? 0 : $scoreRaw);
                                    $previewLink = '/preview.php?project=' . rawurlencode($slug);
                                    $logo = (string)($p['full_logo_url'] ?? '');
                                    $website = normalize_url((string)($p['website'] ?? ''));
                                    $socials = is_array($p['socials'] ?? null) ? $p['socials'] : [];
                                    $twitter = normalize_url((string)($socials['twitter'] ?? ''));
                                    $telegram = normalize_url((string)($socials['telegram'] ?? ''));
                                    $discord = normalize_url((string)($socials['discord'] ?? ''));
                                    $github = normalize_url((string)($socials['github'] ?? ''));
                                    $medium = normalize_url((string)($socials['medium'] ?? ''));
                                    $auditBadge = (string)($p['audit_badge'] ?? '');
                                    $kycBadge = (string)($p['kyc_badge'] ?? '');
                                    $category = (string)($p['category'] ?? '');
                                    $blockchains = is_array($p['blockchains'] ?? null) ? $p['blockchains'] : [];
                                    $contracts = is_array($p['contracts'] ?? null) ? $p['contracts'] : [];

                                    $addresses = [];
                                    foreach ($contracts as $c) {
                                        $addr = trim((string)($c['address'] ?? ''));
                                        if ($addr !== '') {
                                            $addresses[] = $addr;
                                        }
                                    }

                                    $searchBlob = strtolower(trim(implode(' ', array_filter([
                                        $id,
                                        $name,
                                        $slug,
                                        (string)($p['uuid'] ?? ''),
                                        (string)($p['website'] ?? ''),
                                        implode(' ', $addresses),
                                    ]))));
                                ?>
                                    <tr class="project-tr" data-search="<?= h($searchBlob) ?>" data-slug="<?= h($slug) ?>">
                                        <td class="col-fav">
                                            <button class="fav-btn" type="button" aria-label="Toggle favorite" title="Favorite">
                                                <span class="fav-icon" aria-hidden="true">☆</span>
                                            </button>
                                        </td>
                                        <td class="col-id"><span class="mono"><?= h($id) ?></span></td>
                                        <td class="col-name">
                                            <div class="name-cell">
                                                <?php if ($logo !== ''): ?>
                                                    <img class="project-logo" src="<?= h($logo) ?>" alt="" loading="lazy" decoding="async" referrerpolicy="no-referrer" />
                                                <?php else: ?>
                                                    <div class="project-logo placeholder" aria-hidden="true"></div>
                                                <?php endif; ?>
                                                <div class="name-meta">
                                                    <a class="project-name" href="<?= h($previewLink) ?>"><?= h($name) ?></a>
                                                    <div class="socials" aria-label="Links">
                                                        <?php if ($website): ?>
                                                            <a class="icon-link" href="<?= h($website) ?>" target="_blank" rel="noreferrer" title="Website" aria-label="Website">
                                                                <svg viewBox="0 0 24 24" width="14" height="14" fill="currentColor" aria-hidden="true"><path d="M12 2a10 10 0 1 0 10 10A10.01 10.01 0 0 0 12 2Zm7.93 9h-3.16a15.5 15.5 0 0 0-1.18-5.02A8.03 8.03 0 0 1 19.93 11ZM12 4c.9 0 2.24 1.66 3.05 5H8.95C9.76 5.66 11.1 4 12 4ZM4.07 13h3.16a15.5 15.5 0 0 0 1.18 5.02A8.03 8.03 0 0 1 4.07 13ZM7.23 11H4.07a8.03 8.03 0 0 1 4.34-5.02A15.5 15.5 0 0 0 7.23 11Zm1.72 2h6.1c-.81 3.34-2.15 5-3.05 5s-2.24-1.66-3.05-5Zm6.82 0h3.16a8.03 8.03 0 0 1-4.34 5.02A15.5 15.5 0 0 0 15.77 13Zm-6.82-2c-.07.66-.11 1.33-.11 2s.04 1.34.11 2h6.1c.07-.66.11-1.33.11-2s-.04-1.34-.11-2Z"/></svg>
                                                            </a>
                                                        <?php endif; ?>
                                                        <?php if ($twitter): ?>
                                                            <a class="icon-link" href="<?= h($twitter) ?>" target="_blank" rel="noreferrer" title="X" aria-label="X">
                                                                <svg viewBox="0 0 24 24" width="14" height="14" fill="currentColor" aria-hidden="true"><path d="M18.9 2H22l-6.8 7.8L23 22h-6.1l-4.8-6.2L6.7 22H3.6l7.3-8.4L1 2h6.2l4.3 5.6L18.9 2Zm-1.1 18h1.7L7.1 3.9H5.3L17.8 20Z"/></svg>
                                                            </a>
                                                        <?php endif; ?>
                                                        <?php if ($telegram): ?>
                                                            <a class="icon-link" href="<?= h($telegram) ?>" target="_blank" rel="noreferrer" title="Telegram" aria-label="Telegram">
                                                                <svg viewBox="0 0 24 24" width="14" height="14" fill="currentColor" aria-hidden="true"><path d="M21.8 4.4c.2-.8-.6-1.5-1.4-1.2L2.9 10.2c-.9.4-.8 1.7.2 1.9l4.7 1 1.8 5.4c.3.9 1.5 1.1 2.1.4l2.6-3 4.9 3.6c.8.6 2 .1 2.2-.9l2.4-14.2ZM9.5 13.9l9.2-7.8-7.4 8.9-.3 3.2-1.8-5.3 0 0 0 0 .3-.9Z"/></svg>
                                                            </a>
                                                        <?php endif; ?>
                                                        <?php if ($discord): ?>
                                                            <a class="icon-link" href="<?= h($discord) ?>" target="_blank" rel="noreferrer" title="Discord" aria-label="Discord">
                                                                <svg viewBox="0 0 24 24" width="14" height="14" fill="currentColor" aria-hidden="true"><path d="M20 4.7A16.5 16.5 0 0 0 16.1 3l-.5 1a15 15 0 0 1 3.4 1.8A13 13 0 0 0 5 5.8 15 15 0 0 1 8.4 4l-.5-1A16.5 16.5 0 0 0 4 4.7C2.1 7.5 1.6 10.2 1.8 12.9a16.2 16.2 0 0 0 5 2.6l.7-1.1a10.4 10.4 0 0 1-1.6-.8l.4-.3a11.6 11.6 0 0 0 10.7 0l.4.3c-.5.3-1 .6-1.6.8l.7 1.1a16.2 16.2 0 0 0 5-2.6c.3-2.9-.2-5.6-1.9-8.2ZM8.5 12.4c-.8 0-1.4-.7-1.4-1.6 0-.9.6-1.6 1.4-1.6s1.4.7 1.4 1.6c0 .9-.6 1.6-1.4 1.6Zm7 0c-.8 0-1.4-.7-1.4-1.6 0-.9.6-1.6 1.4-1.6s1.4.7 1.4 1.6c0 .9-.6 1.6-1.4 1.6Z"/></svg>
                                                            </a>
                                                        <?php endif; ?>
                                                        <?php if ($github): ?>
                                                            <a class="icon-link" href="<?= h($github) ?>" target="_blank" rel="noreferrer" title="GitHub" aria-label="GitHub">
                                                                <svg viewBox="0 0 24 24" width="14" height="14" fill="currentColor" aria-hidden="true"><path d="M12 2a10 10 0 0 0-3.2 19.5c.5.1.7-.2.7-.5v-1.7c-2.9.6-3.5-1.2-3.5-1.2-.5-1.2-1.1-1.5-1.1-1.5-.9-.6.1-.6.1-.6 1 0 1.6 1 1.6 1 .9 1.6 2.5 1.1 3.1.8.1-.7.4-1.1.7-1.4-2.3-.3-4.7-1.1-4.7-5A3.9 3.9 0 0 1 6.7 8.1a3.6 3.6 0 0 1 .1-2.6s.8-.3 2.6 1a9 9 0 0 1 4.7 0c1.8-1.2 2.6-1 2.6-1a3.6 3.6 0 0 1 .1 2.6 3.9 3.9 0 0 1 1 2.7c0 3.9-2.4 4.7-4.7 5 .4.3.7 1 .7 2v3c0 .3.2.6.7.5A10 10 0 0 0 12 2Z"/></svg>
                                                            </a>
                                                        <?php endif; ?>
                                                        <?php if ($medium): ?>
                                                            <a class="icon-link" href="<?= h($medium) ?>" target="_blank" rel="noreferrer" title="Medium" aria-label="Medium">
                                                                <svg viewBox="0 0 24 24" width="14" height="14" fill="currentColor" aria-hidden="true"><path d="M4 7.2c0-.6.2-1 .7-1.4l1.7-1.4v-.2H1.2v.2l1.4 1.7c.1.1.2.4.2.6v12.6c0 .3-.1.5-.2.6l-1.4 1.7v.2h5.2v-.2l-1.4-1.7c-.1-.1-.2-.4-.2-.6V7.2Zm6.1-3.9 4.4 9.7 3.9-9.7h4.2v.2l-1.2 1.2c-.1.1-.1.2-.1.4v14.2c0 .2 0 .3.1.4l1.2 1.2v.2h-6.1v-.2l1.2-1.2c.1-.1.1-.2.1-.4V7.9l-5 12.5h-.7L6.4 7.9v10.5c0 .3.1.7.3 1l1.6 1.9v.2H3.8v-.2l1.6-1.9c.2-.3.3-.7.3-1V6.2c0-.3-.1-.6-.3-.8L4 3.5v-.2h6.1Z"/></svg>
                                                            </a>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="col-score"><span class="<?= h(score_class($score)) ?>"><?= h(number_format($score, 2)) ?></span></td>
                                        <td class="col-services">
                                            <div class="service-badges">
                                                <?php if ($auditBadge !== ''): ?>
                                                    <img class="service-badge" src="<?= h($auditBadge) ?>" alt="Audit badge" loading="lazy" decoding="async" referrerpolicy="no-referrer" />
                                                <?php endif; ?>
                                                <?php if ($kycBadge !== ''): ?>
                                                    <img class="service-badge" src="<?= h($kycBadge) ?>" alt="KYC badge" loading="lazy" decoding="async" referrerpolicy="no-referrer" />
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td class="col-ecosystems">
                                            <?php if (!$blockchains): ?>
                                                <span class="muted">—</span>
                                            <?php else: ?>
                                                <div class="ecosystems" aria-label="Ecosystems">
                                                    <?php foreach (array_slice($blockchains, 0, 4) as $bc):
                                                        $bcName = (string)($bc['name'] ?? '');
                                                        $bcIcon = (string)($bc['icon_url'] ?? '');
                                                    ?>
                                                        <?php if ($bcIcon !== ''): ?>
                                                            <img class="ecosystem" src="<?= h($bcIcon) ?>" alt="" title="<?= h($bcName) ?>" loading="lazy" decoding="async" referrerpolicy="no-referrer" />
                                                        <?php else: ?>
                                                            <span class="ecosystem placeholder" title="<?= h($bcName) ?>"></span>
                                                        <?php endif; ?>
                                                    <?php endforeach; ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="col-category">
                                            <?php if ($category !== ''): ?>
                                                <span class="category-pill"><?= h($category) ?></span>
                                            <?php else: ?>
                                                <span class="muted">—</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="card">
                <div class="card-title">How to run</div>
                <div class="card-body">
                    <ol class="list-ol">
                        <li>Fetch data: <code>php scripts/fetch_projects.php</code></li>
                        <li>Start server: <code>php -c config/php.ini -S 127.0.0.1:8000 -t public</code></li>
                        <li>Open: <code>http://127.0.0.1:8000</code></li>
                    </ol>
                    <p class="muted">Edits to PHP/CSS show on refresh.</p>
                </div>
            </div>
        </div>
    </main>
</div>

<script>
    (function(){
        var input = document.getElementById('projectSearch');
        var clearBtn = document.getElementById('clearSearch');
        var tbody = document.getElementById('projectsTbody');
        var label = document.getElementById('resultsLabel');
        if(!input || !tbody) return;

        var rows = Array.prototype.slice.call(tbody.querySelectorAll('tr[data-search]'));

        function norm(s){
            return (s || '').toString().toLowerCase().trim();
        }

        function tokens(s){
            s = norm(s);
            if(!s) return [];
            return s.split(/\s+/g).filter(Boolean);
        }

        function updateCount(visible){
            if(!label) return;
            label.textContent = visible + ' / ' + rows.length;
        }

        function applyFilter(){
            var tks = tokens(input.value);
            var visible = 0;
            for(var i=0;i<rows.length;i++){
                var row = rows[i];
                var blob = norm(row.getAttribute('data-search'));
                var ok = true;
                for(var j=0;j<tks.length;j++){
                    if(blob.indexOf(tks[j]) === -1){ ok = false; break; }
                }
                row.style.display = ok ? '' : 'none';
                if(ok) visible++;
            }
            updateCount(visible);
        }

        // Favorites
        var favKey = 'solidproof_favs_v1';
        function loadFavs(){
            try{
                var raw = localStorage.getItem(favKey);
                if(!raw) return {};
                var obj = JSON.parse(raw);
                return obj && typeof obj === 'object' ? obj : {};
            } catch(e){ return {}; }
        }
        function saveFavs(map){
            try{ localStorage.setItem(favKey, JSON.stringify(map || {})); } catch(e){}
        }
        var favs = loadFavs();

        function syncFavUI(){
            for(var i=0;i<rows.length;i++){
                var row = rows[i];
                var slug = row.getAttribute('data-slug') || '';
                var btn = row.querySelector('.fav-btn');
                var icon = row.querySelector('.fav-icon');
                var on = !!favs[slug];
                if(btn){ btn.setAttribute('aria-pressed', on ? 'true' : 'false'); }
                if(icon){ icon.textContent = on ? '★' : '☆'; }
                if(on){ row.classList.add('is-fav'); } else { row.classList.remove('is-fav'); }
            }
        }

        tbody.addEventListener('click', function(e){
            var target = e.target;
            if(!target) return;
            var btn = target.closest ? target.closest('.fav-btn') : null;
            if(!btn) return;
            var row = btn.closest('tr');
            if(!row) return;
            var slug = row.getAttribute('data-slug') || '';
            if(!slug) return;
            favs[slug] = !favs[slug];
            if(!favs[slug]) delete favs[slug];
            saveFavs(favs);
            syncFavUI();
        });

        input.addEventListener('input', applyFilter);
        if(clearBtn){
            clearBtn.addEventListener('click', function(){
                input.value = '';
                input.focus();
                applyFilter();
            });
        }

        syncFavUI();
        applyFilter();
    })();
</script>
</body>
</html>
