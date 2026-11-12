<?php
// Películas gratuitas de RTVE Play (se abren en su web). Se actualizan solas cada 12 h en segundo plano.
require __DIR__ . '/lib.php';
need_viewer();
$f = MEDIA_DIR . '/rtve.json';
if (!is_file($f) || time() - filemtime($f) > 12 * 3600)
    exec('nohup /usr/bin/php ' . escapeshellarg(__DIR__ . '/rtve_sync.php') . ' > /dev/null 2>&1 &');
$list = json_decode(@file_get_contents($f) ?: '[]', true) ?: [];
out(array_values(array_filter($list, fn($r) => !$r['expires'] || $r['expires'] > time())));
