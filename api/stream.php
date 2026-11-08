<?php
// Sirve un archivo de media con un enlace firmado y caducable (para el Chromecast, que no tiene la cookie de acceso).
// Admite peticiones por tramos (Range), necesarias para avanzar/retroceder en el vídeo.
require __DIR__ . '/lib.php';

header('Access-Control-Allow-Origin: *');   // el receptor de Chromecast carga los subtítulos desde otro origen
header('Access-Control-Allow-Headers: Range');
header('Access-Control-Expose-Headers: Content-Length, Content-Range, Accept-Ranges');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit;

$f = (string)($_GET['f'] ?? '');
$e = (int)($_GET['e'] ?? 0);
$s = (string)($_GET['s'] ?? '');
if (!preg_match('#^(videos|subs|thumbs)/[A-Za-z0-9_.-]+$#', $f) || $e < time() || !hash_equals(cast_sig($f, $e), $s)) {
    http_response_code(403);
    exit;
}
$path = MEDIA_DIR . '/' . $f;
if (!is_file($path)) { http_response_code(404); exit; }

$size = filesize($path);
$types = ['mp4' => 'video/mp4', 'vtt' => 'text/vtt; charset=utf-8', 'jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];
header('Content-Type: ' . ($types[strtolower(pathinfo($path, PATHINFO_EXTENSION))] ?? 'application/octet-stream'));
header('Accept-Ranges: bytes');
header('Cache-Control: private, max-age=3600');

$start = 0; $end = $size - 1;
if (isset($_SERVER['HTTP_RANGE']) && preg_match('/bytes=(\d*)-(\d*)/', $_SERVER['HTTP_RANGE'], $m)) {
    if ($m[1] === '') { $start = max(0, $size - (int)$m[2]); }          // "bytes=-500": los últimos 500
    else { $start = (int)$m[1]; if ($m[2] !== '') $end = min($end, (int)$m[2]); }
    if ($start > $end || $start >= $size) { http_response_code(416); header("Content-Range: bytes */$size"); exit; }
    http_response_code(206);
    header("Content-Range: bytes $start-$end/$size");
}
header('Content-Length: ' . ($end - $start + 1));
if ($_SERVER['REQUEST_METHOD'] === 'HEAD') exit;

set_time_limit(0);
while (ob_get_level()) ob_end_clean();
$fp = fopen($path, 'rb');
fseek($fp, $start);
$left = $end - $start + 1;
while ($left > 0 && !feof($fp) && !connection_aborted()) {
    $buf = fread($fp, min(1048576, $left));
    echo $buf;
    flush();
    $left -= strlen($buf);
}
fclose($fp);
