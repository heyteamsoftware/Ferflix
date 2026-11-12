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
    // ¿tiene subtítulos en español? (se mira solo la primera vez)
    if (isset($old[$id])) $subs = $old[$id]['subs'] ?? null;
    else {
        $sj = json_decode(@file_get_contents("https://api.rtve.es/api/videos/$id/subtitulos.json", false, $ctx) ?: '', true);
        $subs = null;
        foreach ($sj['page']['items'] ?? [] as $it) if (str_starts_with((string)($it['lang'] ?? ''), 'es')) $subs = 'Español';
    }
    $orig = $v['languageOriginal'] ?? null;
    $out[] = [
        'id' => (int)$id, 'kind' => 'movie', 'subs' => $subs, 'title' => $v['title'], 'year' => $year,
        'description' => trim(html_entity_decode(strip_tags($v['description'] ?: ($v['shortDescription'] ?? '')), ENT_QUOTES, 'UTF-8')),
        'poster' => $v['previews']['vertical'] ?? null, 'image' => $v['thumbnail'] ?? ($v['imageSEO'] ?? null),
        'url' => $v['htmlUrl'], 'minutes' => (int)round($v['duration'] / 60000),
        'lang' => $lang . ($orig && $orig !== 'es' && $lang === 'Castellano' ? ' (doblada)' : ''),
        'director' => $v['director'] ?? null, 'expires' => $exp ? $exp->getTimestamp() : null,
        'rating' => $rating, 'age' => $v['ageRange'] ?? null,
    ];
}
// Series: solo enlace a su página de RTVE Play; la ficha, el póster y la nota salen de TMDB
$sh = @file_get_contents('https://www.rtve.es/play/series/', false, $ctx);
preg_match_all('#https://www\.rtve\.es/play/videos/([a-z0-9-]+)/"#', (string)$sh, $sm);
foreach (array_unique($sm[1]) as $slug) {
    $key = "s-$slug";
    if (isset($old[$key])) { $out[] = $old[$key]; continue; }
    $url = "https://www.rtve.es/play/videos/$slug/";
    $ph = @file_get_contents($url, false, $ctx);
    if (!$ph || !preg_match('#<meta property="og:title" content="([^"]+)"#', $ph, $m)) continue;
    // «Valle Salvaje | Ver serie… en RTVE Play», «Coco Chanel: Tus programas favoritos…» -> solo el nombre
    $name = trim(preg_split('/\s+[-|–]\s+|:\s+Tus programas/u', html_entity_decode($m[1], ENT_QUOTES, 'UTF-8'))[0]);
    if (preg_match('/videopodcast|making of/i', $m[1])) continue;
    $img = preg_match('#<meta property="og:image" content="([^"]+)"#', $ph, $m) ? $m[1] : null;
    // en TMDB se prefiere la producción española (hay muchas series con el mismo nombre)
    $t = null;
    $sj = tmdb_get('search/tv', ['query' => preg_replace('/\s*\(\d{4}\)$/', '', $name), 'language' => 'es-ES']);
    foreach ($sj['results'] ?? [] as $x) if (in_array('ES', $x['origin_country'] ?? [])) { $x2 = $x; break; }
    $x2 = $x2 ?? ($sj['results'][0] ?? null);
    if ($x2) $t = ['year' => (int)substr($x2['first_air_date'] ?? '', 0, 4) ?: null, 'overview' => $x2['overview'] ?? '', 'poster' => $x2['poster_path'] ?? null,
                   'image' => $x2['backdrop_path'] ?? null, 'rating' => ($x2['vote_count'] ?? 0) >= 20 ? round($x2['vote_average'], 1) : null];
    unset($x2);
    $out[] = [
        'id' => $key, 'kind' => 'series', 'title' => $name, 'year' => $t['year'] ?? null,
        'description' => $t['overview'] ?? '', 'poster' => !empty($t['poster']) ? 'https://image.tmdb.org/t/p/w342' . $t['poster'] : $img,
        'image' => !empty($t['image']) ? 'https://image.tmdb.org/t/p/w1280' . $t['image'] : $img,
        'url' => $url, 'lang' => 'Castellano', 'subs' => null, 'expires' => null, 'rating' => $t['rating'] ?? null,
    ];
}
if ($out) file_put_contents($cacheFile, json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
