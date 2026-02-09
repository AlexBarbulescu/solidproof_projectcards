<?php

declare(strict_types=1);

/**
 * Minimal Vite helper for PHP.
 *
 * - In dev: loads from Vite dev server (default http://localhost:5173)
 * - In prod: loads hashed assets from public/dist/manifest.json
 */

function vite_dev_server_url(): string
{
    $url = (string)(getenv('VITE_DEV_SERVER_URL') ?: 'http://localhost:5173');
    return rtrim($url, '/');
}

function vite_is_dev(): bool
{
    if ((string)getenv('VITE_DEV') === '1') {
        return true;
    }

    $manifest = __DIR__ . DIRECTORY_SEPARATOR . 'dist' . DIRECTORY_SEPARATOR . 'manifest.json';
    return !is_file($manifest);
}

/**
 * @param string $entry E.g. "src/main.js" (dev) or "index.html" (manifest key)
 */
function vite_tags(string $entry = 'src/main.js'): string
{
    if (vite_is_dev()) {
        $dev = vite_dev_server_url();
        $entryUrl = $dev . '/' . ltrim($entry, '/');

        return "\n" .
            '<script type="module" src="' . htmlspecialchars($dev . '/@vite/client', ENT_QUOTES) . '"></script>' . "\n" .
            '<script type="module" src="' . htmlspecialchars($entryUrl, ENT_QUOTES) . '"></script>' . "\n";
    }

    $manifestPath = __DIR__ . DIRECTORY_SEPARATOR . 'dist' . DIRECTORY_SEPARATOR . 'manifest.json';
    $raw = file_get_contents($manifestPath);
    $manifest = json_decode($raw ?: '', true);
    if (!is_array($manifest)) {
        throw new RuntimeException('Invalid Vite manifest: ' . $manifestPath);
    }

    // When building from index.html input, the key is usually "index.html".
    // But we also allow "src/main.js" if you switch build inputs.
    $chunk = $manifest[$entry] ?? $manifest['index.html'] ?? null;
    if (!is_array($chunk) || empty($chunk['file'])) {
        throw new RuntimeException('Vite entry not found in manifest: ' . $entry);
    }

    $tags = "\n";

    if (!empty($chunk['css']) && is_array($chunk['css'])) {
        foreach ($chunk['css'] as $css) {
            $href = '/dist/' . ltrim((string)$css, '/');
            $tags .= '<link rel="stylesheet" href="' . htmlspecialchars($href, ENT_QUOTES) . '">' . "\n";
        }
    }

    $src = '/dist/' . ltrim((string)$chunk['file'], '/');
    $tags .= '<script type="module" src="' . htmlspecialchars($src, ENT_QUOTES) . '"></script>' . "\n";

    return $tags;
}
