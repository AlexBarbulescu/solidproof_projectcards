<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$projectsJsonPath = $root . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'projects.json';

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

$raw = is_file($projectsJsonPath) ? file_get_contents($projectsJsonPath) : false;
$decoded = json_decode($raw ?: '', true);
$projects = (json_last_error() === JSON_ERROR_NONE) ? ($decoded['data'] ?? $decoded ?? []) : [];

$requested = (string)($_GET['project'] ?? '');
$logoScale = (string)($_GET['logoScale'] ?? '');
$logoScaleParam = ($logoScale !== '' && is_numeric($logoScale)) ? ('&logoScale=' . rawurlencode($logoScale)) : '';

$project = null;
$currentIndex = null;
if ($projects) {
    if ($requested !== '') {
        foreach ($projects as $i => $p) {
            $slug = (string)($p['slug'] ?? $p['uuid'] ?? '');
            $name = (string)($p['name'] ?? $p['title'] ?? '');
            if ($slug === $requested || $name === $requested) {
                $project = $p;
                $currentIndex = $i;
                break;
            }
        }
    }
    if ($project === null) {
        $project = $projects[0];
        $currentIndex = 0;
    }
}

// Prev/next navigation (wrap-around)
$prevHref = null;
$nextHref = null;
$prevName = null;
$nextName = null;
if ($projects && $currentIndex !== null) {
    $count = count($projects);
    if ($count > 1) {
        $prevIndex = ($currentIndex - 1 + $count) % $count;
        $nextIndex = ($currentIndex + 1) % $count;

        $prev = $projects[$prevIndex];
        $next = $projects[$nextIndex];

        $prevKey = (string)($prev['slug'] ?? $prev['uuid'] ?? ($prev['name'] ?? $prev['title'] ?? ''));
        $nextKey = (string)($next['slug'] ?? $next['uuid'] ?? ($next['name'] ?? $next['title'] ?? ''));

        $prevName = (string)($prev['name'] ?? $prev['title'] ?? 'Previous');
        $nextName = (string)($next['name'] ?? $next['title'] ?? 'Next');

        $prevHref = '/preview.php?project=' . rawurlencode($prevKey);
        $nextHref = '/preview.php?project=' . rawurlencode($nextKey);
    }
}

$name = (string)($project['name'] ?? $project['title'] ?? 'Project Name');
$website = (string)($project['website'] ?? $project['url'] ?? 'example.com');
$qrUrl = (string)($project['website'] ?? $project['url'] ?? 'https://solidproof.io');
$logoUrl = (string)($project['full_logo_url'] ?? $project['logo_url'] ?? $project['logo'] ?? '');
$appProjectUrl = (string)($project['url'] ?? '');
if ($appProjectUrl === '') {
    $slugForUrl = (string)($project['slug'] ?? '');
    if ($slugForUrl !== '') {
        $appProjectUrl = 'https://app.solidproof.io/projects/' . rawurlencode($slugForUrl);
    }
}
$projectScore = (string)($project['score'] ?? '');
$kycBadgeUrl = (string)($project['kyc_badge'] ?? '');
$auditBadgeUrl = (string)($project['audit_badge'] ?? '');

$slugKey = (string)($project['slug'] ?? $project['uuid'] ?? $name);
$previewLink = '/preview.php?project=' . rawurlencode($slugKey) . $logoScaleParam;

$projectId = (string)($project['id'] ?? '');
$projectSlug = (string)($project['slug'] ?? $project['uuid'] ?? '');
$projectCategory = (string)($project['category'] ?? '');
$projectBlockchains = is_array($project['blockchains'] ?? null) ? $project['blockchains'] : [];
$projectSocials = is_array($project['socials'] ?? null) ? $project['socials'] : [];

$websiteHref = normalize_url((string)($project['website'] ?? $project['url'] ?? ''));
$twitterHref = normalize_url((string)($projectSocials['twitter'] ?? ''));
$telegramHref = normalize_url((string)($projectSocials['telegram'] ?? ''));
$discordHref = normalize_url((string)($projectSocials['discord'] ?? ''));
$githubHref = normalize_url((string)($projectSocials['github'] ?? ''));
$mediumHref = normalize_url((string)($projectSocials['medium'] ?? ''));

$scoreFloat = (float)($projectScore === '' ? 0 : $projectScore);

date_default_timezone_set('UTC');
$date = (function () use ($project): string {
    $raw = (string)($project['onboarded'] ?? '');
    if ($raw !== '') {
        try {
            return (new DateTimeImmutable($raw))->format('F jS, Y');
        } catch (Throwable) {
            // fall through
        }
    }

    return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('F jS, Y');
})();

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
        <a class="btn" href="/">← Projects</a>
        <div class="spacer"></div>
        <div class="muted">v4 preview</div>
    </header>

    <main class="content">
        <?php if (!$project): ?>
            <div class="card warning">
                <div class="card-title">No data</div>
                <div class="card-body">
                    <p>Run <code>php scripts/fetch_projects.php</code> to download sample data.</p>
                </div>
            </div>
        <?php else: ?>
            <section class="cert-wrap">
                <div style="width: min(1100px, 96vw);">
                    <div class="row" style="justify-content:space-between; margin-bottom: 10px;">
                        <div class="row">
                            <?php if ($prevHref): ?>
                                <a class="btn" href="<?= h($prevHref) ?><?= h($logoScaleParam) ?>" title="Previous: <?= h($prevName ?? 'Previous') ?>">← Prev</a>
                            <?php endif; ?>
                            <?php if ($nextHref): ?>
                                <a class="btn" href="<?= h($nextHref) ?><?= h($logoScaleParam) ?>" title="Next: <?= h($nextName ?? 'Next') ?>">Next →</a>
                            <?php endif; ?>
                        </div>
                        <div class="muted">Display card v4 (2000×2000)</div>
                        <div class="row">
                            <a class="btn" href="/certificate-intervention.php?project=<?= h((string)($project['slug'] ?? '')) ?>&type=audit_v4<?= h($logoScaleParam) ?>" target="_blank" rel="noreferrer">Open Audit v4</a>
                            <a class="btn" href="/certificate-intervention.php?project=<?= h((string)($project['slug'] ?? '')) ?>&type=kyc_v4<?= h($logoScaleParam) ?>" target="_blank" rel="noreferrer">Open KYC v4</a>
                        </div>
                    </div>

                    <div style="display:flex; gap: 12px; align-items:flex-start; flex-wrap:wrap;">
                        <div style="flex: 1 1 520px; min-width: min(520px, 96vw);">
                            <div class="muted" style="margin: 0 0 8px 0;">Audit v4</div>
                            <img class="img-preview" alt="Generated display card (audit_v4)" src="/certificate-intervention.php?project=<?= h((string)($project['slug'] ?? '')) ?>&type=audit_v4<?= h($logoScaleParam) ?>" />
                        </div>
                        <div style="flex: 1 1 520px; min-width: min(520px, 96vw);">
                            <div class="muted" style="margin: 0 0 8px 0;">KYC v4</div>
                            <img class="img-preview" alt="Generated display card (kyc_v4)" src="/certificate-intervention.php?project=<?= h((string)($project['slug'] ?? '')) ?>&type=kyc_v4<?= h($logoScaleParam) ?>" />
                        </div>
                    </div>

                    <div class="card" style="margin-top: 12px; width: 100%;">
                        <div class="card-title">v4 details</div>
                        <div class="card-body">
                            <div class="table-wrap" role="region" aria-label="Project summary" tabindex="0">
                                <table class="projects-table">
                                    <thead>
                                    <tr>
                                        <th class="col-id">#</th>
                                        <th class="col-name">Name</th>
                                        <th class="col-score">Security score</th>
                                        <th class="col-services">Services &amp; certificates</th>
                                        <th class="col-ecosystems">Ecosystems</th>
                                        <th class="col-category">Category</th>
                                    </tr>
                                    </thead>
                                    <tbody>
                                    <tr class="project-tr">
                                        <td class="col-id"><span class="mono"><?= h($projectId) ?></span></td>
                                        <td class="col-name">
                                            <div class="name-cell">
                                                <?php if ($logoUrl !== ''): ?>
                                                    <img class="project-logo" src="<?= h($logoUrl) ?>" alt="" loading="lazy" decoding="async" referrerpolicy="no-referrer" />
                                                <?php else: ?>
                                                    <div class="project-logo placeholder" aria-hidden="true"></div>
                                                <?php endif; ?>
                                                <div class="name-meta">
                                                    <a class="project-name" href="<?= h($previewLink) ?>"><?= h($name) ?></a>
                                                    <div class="socials" aria-label="Links">
                                                        <?php if ($websiteHref): ?>
                                                            <a class="icon-link" href="<?= h($websiteHref) ?>" target="_blank" rel="noreferrer" title="Website" aria-label="Website">
                                                                <svg viewBox="0 0 24 24" width="14" height="14" fill="currentColor" aria-hidden="true"><path d="M12 2a10 10 0 1 0 10 10A10.01 10.01 0 0 0 12 2Zm7.93 9h-3.16a15.5 15.5 0 0 0-1.18-5.02A8.03 8.03 0 0 1 19.93 11ZM12 4c.9 0 2.24 1.66 3.05 5H8.95C9.76 5.66 11.1 4 12 4ZM4.07 13h3.16a15.5 15.5 0 0 0 1.18 5.02A8.03 8.03 0 0 1 4.07 13ZM7.23 11H4.07a8.03 8.03 0 0 1 4.34-5.02A15.5 15.5 0 0 0 7.23 11Zm1.72 2h6.1c-.81 3.34-2.15 5-3.05 5s-2.24-1.66-3.05-5Zm6.82 0h3.16a8.03 8.03 0 0 1-4.34 5.02A15.5 15.5 0 0 0 15.77 13Zm-6.82-2c-.07.66-.11 1.33-.11 2s.04 1.34.11 2h6.1c.07-.66.11-1.33.11-2s-.04-1.34-.11-2Z"/></svg>
                                                            </a>
                                                        <?php endif; ?>
                                                        <?php if ($twitterHref): ?>
                                                            <a class="icon-link" href="<?= h($twitterHref) ?>" target="_blank" rel="noreferrer" title="X" aria-label="X">
                                                                <svg viewBox="0 0 24 24" width="14" height="14" fill="currentColor" aria-hidden="true"><path d="M18.9 2H22l-6.8 7.8L23 22h-6.1l-4.8-6.2L6.7 22H3.6l7.3-8.4L1 2h6.2l4.3 5.6L18.9 2Zm-1.1 18h1.7L7.1 3.9H5.3L17.8 20Z"/></svg>
                                                            </a>
                                                        <?php endif; ?>
                                                        <?php if ($telegramHref): ?>
                                                            <a class="icon-link" href="<?= h($telegramHref) ?>" target="_blank" rel="noreferrer" title="Telegram" aria-label="Telegram">
                                                                <svg viewBox="0 0 24 24" width="14" height="14" fill="currentColor" aria-hidden="true"><path d="M21.8 4.4c.2-.8-.6-1.5-1.4-1.2L2.9 10.2c-.9.4-.8 1.7.2 1.9l4.7 1 1.8 5.4c.3.9 1.5 1.1 2.1.4l2.6-3 4.9 3.6c.8.6 2 .1 2.2-.9l2.4-14.2ZM9.5 13.9l9.2-7.8-7.4 8.9-.3 3.2-1.8-5.3 0 0 0 0 .3-.9Z"/></svg>
                                                            </a>
                                                        <?php endif; ?>
                                                        <?php if ($discordHref): ?>
                                                            <a class="icon-link" href="<?= h($discordHref) ?>" target="_blank" rel="noreferrer" title="Discord" aria-label="Discord">
                                                                <svg viewBox="0 0 24 24" width="14" height="14" fill="currentColor" aria-hidden="true"><path d="M20 4.7A16.5 16.5 0 0 0 16.1 3l-.5 1a15 15 0 0 1 3.4 1.8A13 13 0 0 0 5 5.8 15 15 0 0 1 8.4 4l-.5-1A16.5 16.5 0 0 0 4 4.7C2.1 7.5 1.6 10.2 1.8 12.9a16.2 16.2 0 0 0 5 2.6l.7-1.1a10.4 10.4 0 0 1-1.6-.8l.4-.3a11.6 11.6 0 0 0 10.7 0l.4.3c-.5.3-1 .6-1.6.8l.7 1.1a16.2 16.2 0 0 0 5-2.6c.3-2.9-.2-5.6-1.9-8.2ZM8.5 12.4c-.8 0-1.4-.7-1.4-1.6 0-.9.6-1.6 1.4-1.6s1.4.7 1.4 1.6c0 .9-.6 1.6-1.4 1.6Zm7 0c-.8 0-1.4-.7-1.4-1.6 0-.9.6-1.6 1.4-1.6s1.4.7 1.4 1.6c0 .9-.6 1.6-1.4 1.6Z"/></svg>
                                                            </a>
                                                        <?php endif; ?>
                                                        <?php if ($githubHref): ?>
                                                            <a class="icon-link" href="<?= h($githubHref) ?>" target="_blank" rel="noreferrer" title="GitHub" aria-label="GitHub">
                                                                <svg viewBox="0 0 24 24" width="14" height="14" fill="currentColor" aria-hidden="true"><path d="M12 2a10 10 0 0 0-3.2 19.5c.5.1.7-.2.7-.5v-1.7c-2.9.6-3.5-1.2-3.5-1.2-.5-1.2-1.1-1.5-1.1-1.5-.9-.6.1-.6.1-.6 1 0 1.6 1 1.6 1 .9 1.6 2.5 1.1 3.1.8.1-.7.4-1.1.7-1.4-2.3-.3-4.7-1.1-4.7-5A3.9 3.9 0 0 1 6.7 8.1a3.6 3.6 0 0 1 .1-2.6s.8-.3 2.6 1a9 9 0 0 1 4.7 0c1.8-1.2 2.6-1 2.6-1a3.6 3.6 0 0 1 .1 2.6 3.9 3.9 0 0 1 1 2.7c0 3.9-2.4 4.7-4.7 5 .4.3.7 1 .7 2v3c0 .3.2.6.7.5A10 10 0 0 0 12 2Z"/></svg>
                                                            </a>
                                                        <?php endif; ?>
                                                        <?php if ($mediumHref): ?>
                                                            <a class="icon-link" href="<?= h($mediumHref) ?>" target="_blank" rel="noreferrer" title="Medium" aria-label="Medium">
                                                                <svg viewBox="0 0 24 24" width="14" height="14" fill="currentColor" aria-hidden="true"><path d="M4 7.2c0-.6.2-1 .7-1.4l1.7-1.4v-.2H1.2v.2l1.4 1.7c.1.1.2.4.2.6v12.6c0 .3-.1.5-.2.6l-1.4 1.7v.2h5.2v-.2l-1.4-1.7c-.1-.1-.2-.4-.2-.6V7.2Zm6.1-3.9 4.4 9.7 3.9-9.7h4.2v.2l-1.2 1.2c-.1.1-.1.2-.1.4v14.2c0 .2 0 .3.1.4l1.2 1.2v.2h-6.1v-.2l1.2-1.2c.1-.1.1-.2.1-.4V7.9l-5 12.5h-.7L6.4 7.9v10.5c0 .3.1.7.3 1l1.6 1.9v.2H3.8v-.2l1.6-1.9c.2-.3.3-.7.3-1V6.2c0-.3-.1-.6-.3-.8L4 3.5v-.2h6.1Z"/></svg>
                                                            </a>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="col-score"><span class="<?= h(score_class($scoreFloat)) ?>"><?= h(number_format($scoreFloat, 2)) ?></span></td>
                                        <td class="col-services">
                                            <div class="service-badges">
                                                <?php if ($auditBadgeUrl !== ''): ?>
                                                    <img class="service-badge" src="<?= h($auditBadgeUrl) ?>" alt="Audit badge" loading="lazy" decoding="async" referrerpolicy="no-referrer" />
                                                <?php endif; ?>
                                                <?php if ($kycBadgeUrl !== ''): ?>
                                                    <img class="service-badge" src="<?= h($kycBadgeUrl) ?>" alt="KYC badge" loading="lazy" decoding="async" referrerpolicy="no-referrer" />
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td class="col-ecosystems">
                                            <?php if (!$projectBlockchains): ?>
                                                <span class="muted">—</span>
                                            <?php else: ?>
                                                <div class="ecosystems" aria-label="Ecosystems">
                                                    <?php foreach (array_slice($projectBlockchains, 0, 4) as $bc):
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
                                            <?php if ($projectCategory !== ''): ?>
                                                <span class="category-pill"><?= h($projectCategory) ?></span>
                                            <?php else: ?>
                                                <span class="muted">—</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </section>
        <?php endif; ?>
    </main>
</div>
</body>
</html>
