<?php
// Limpieza diaria (cron): borra archivos de media que ningún registro usa y que llevan más de 24 h sin tocarse.
if (php_sapi_name() !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/lib.php';
$pdo = db();
$used = [];
foreach (['SELECT file FROM videos', 'SELECT file FROM subtitles', 'SELECT file FROM audios', 'SELECT thumb FROM titles', 'SELECT poster FROM titles'] as $sql)
    foreach ($pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN) as $f) if ($f) $used[$f] = 1;
$n = 0; $bytes = 0;
foreach (['videos', 'subs', 'audios', 'thumbs', 'tmp'] as $d) {
    foreach (glob(MEDIA_DIR . "/$d/*") as $p) {
        if (isset($used["$d/" . basename($p)]) || !is_file($p) || time() - filemtime($p) < 86400) continue;
        $bytes += filesize($p); $n++; unlink($p);
    }
}
if ($n) echo date('c') . " borrados $n archivos (" . round($bytes / 1048576) . " MB)\n";
