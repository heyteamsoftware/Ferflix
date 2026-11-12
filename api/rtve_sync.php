<?php
// Solo por línea de comandos: lee las películas de RTVE Play (gratis, se ven en su web) y las guarda en media/rtve.json
if (PHP_SAPI !== 'cli') exit;
require __DIR__ . '/lib.php';
$lock = fopen(MEDIA_DIR . '/.rtve.lock', 'c');
if (!flock($lock, LOCK_EX | LOCK_NB)) exit;   // ya hay otra actualización en marcha

$ctx = stream_context_create(['http' => ['timeout' => 25, 'header' => "User-Agent: Mozilla/5.0\r\n"]]);
$html = @file_get_contents('https://www.rtve.es/play/cine/', false, $ctx);
if (!$html) exit(1);
preg_match_all('#https://www\.rtve\.es/play/videos/[^"/]+/[^"/]+/(\d{6,})/#', $html, $m);
$ids = array_unique($m[1]);

$cacheFile = MEDIA_DIR . '/rtve.json';
$old = [];
foreach (json_decode(@file_get_contents($cacheFile) ?: '[]', true) ?: [] as $o) $old[$o['id']] = $o;

$langs = ['es' => 'Castellano', 'ca' => 'Catalán', 'va' => 'Valenciano', 'gl' => 'Gallego', 'eu' => 'Euskera'];
$out = [];
foreach ($ids as $id) {
    $j = json_decode(@file_get_contents("https://api.rtve.es/api/videos/$id.json", false, $ctx) ?: '', true);
    $v = $j['page']['items'][0] ?? null;
    if (!$v || ($v['subType']['name'] ?? '') !== 'Película' || ($v['pubState']['code'] ?? '') !== 'ENPUB') continue;
    if (($v['duration'] ?? 0) < 50 * 60000) continue;
    $exp = !empty($v['expirationDate']) ? DateTime::createFromFormat('d-m-Y H:i:s', $v['expirationDate'], new DateTimeZone('Europe/Madrid')) : null;
    if ($exp && $exp->getTimestamp() < time()) continue;
    $year = preg_match('/(\d{4})/', (string)($v['productionDate'] ?? ''), $y) ? (int)$y[1] : null;
    // la nota se busca en TMDB solo la primera vez
    $rating = $old[$id]['rating'] ?? null;
    if (!isset($old[$id])) { $t = tmdb_lookup($v['title'], $year, 'movie'); $rating = $t['rating'] ?? null; }
    $lang = $langs[$v['language'] ?? 'es'] ?? 'Castellano';
    $orig = $v['languageOriginal'] ?? null;
    $out[] = [
        'id' => (int)$id, 'title' => $v['title'], 'year' => $year,
        'description' => trim(html_entity_decode(strip_tags($v['description'] ?: ($v['shortDescription'] ?? '')), ENT_QUOTES, 'UTF-8')),
        'poster' => $v['previews']['vertical'] ?? null, 'image' => $v['thumbnail'] ?? ($v['imageSEO'] ?? null),
        'url' => $v['htmlUrl'], 'minutes' => (int)round($v['duration'] / 60000),
        'lang' => $lang . ($orig && $orig !== 'es' && $lang === 'Castellano' ? ' (doblada)' : ''),
        'director' => $v['director'] ?? null, 'expires' => $exp ? $exp->getTimestamp() : null,
        'rating' => $rating, 'age' => $v['ageRange'] ?? null,
    ];
}
if ($out) file_put_contents($cacheFile, json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
