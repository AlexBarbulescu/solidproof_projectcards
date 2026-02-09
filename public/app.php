<?php

declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'vite.php';

?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width,initial-scale=1" />
    <title>Certificate (Vite)</title>
    <link rel="stylesheet" href="/assets/style.css" />
    <?= vite_tags('src/main.js') ?>
</head>
<body class="app-bg">
<div id="app"></div>
</body>
</html>
