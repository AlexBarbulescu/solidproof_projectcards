<?php

declare(strict_types=1);

// Downloads SolidProof projects API response into data/projects.json for local preview usage.

$root = dirname(__DIR__);
$outDir = $root . DIRECTORY_SEPARATOR . 'data';
$outFile = $outDir . DIRECTORY_SEPARATOR . 'projects.json';

$url = 'https://app.solidproof.io/api/v1/projects?sort=1&limit=50';

if (!is_dir($outDir)) {
    mkdir($outDir, 0777, true);
}

function fetch(string $url): string
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
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
            throw new RuntimeException('HTTP ' . $status . ' from API');
        }
        return $body;
    }

    $ctx = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => 20,
            'header' => "Accept: application/json\r\nUser-Agent: local-certificate-preview/1.0\r\n",
        ]
    ]);

    $body = @file_get_contents($url, false, $ctx);
    if ($body !== false) {
        return $body;
    }

    // Windows-friendly fallback: use external curl.exe (often available by default).
    $curl = trim((string)@shell_exec('where curl.exe'));
    if ($curl !== '') {
        $tmp = tempnam(sys_get_temp_dir(), 'sp_projects_');
        if ($tmp === false) {
            throw new RuntimeException('file_get_contents failed and could not create temp file for curl.exe fallback');
        }

        $cmd = 'curl.exe -L -sS --max-time 30 -H "Accept: application/json" -H "User-Agent: local-certificate-preview/1.0" -o ' . escapeshellarg($tmp) . ' ' . escapeshellarg($url);
        $out = [];
        $code = 0;
        @exec($cmd, $out, $code);
        if ($code !== 0) {
            @unlink($tmp);
            throw new RuntimeException('file_get_contents failed; curl.exe fallback failed with exit code ' . $code);
        }
        $downloaded = file_get_contents($tmp);
        @unlink($tmp);
        if ($downloaded === false || $downloaded === '') {
            throw new RuntimeException('curl.exe fallback downloaded empty response');
        }
        return $downloaded;
    }

    $err = error_get_last();
    throw new RuntimeException('file_get_contents failed: ' . ($err['message'] ?? 'unknown error') . ' (and curl.exe not available)');
}

try {
    $json = fetch($url);
    $decoded = json_decode($json, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new RuntimeException('API returned invalid JSON: ' . json_last_error_msg());
    }

    file_put_contents($outFile, json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    fwrite(STDOUT, "Saved: {$outFile}\n");
} catch (Throwable $e) {
    fwrite(STDERR, "Error: {$e->getMessage()}\n");
    exit(1);
}
