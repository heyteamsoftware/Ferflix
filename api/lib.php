<?php
require __DIR__ . '/config.php';

function db() {
    static $p;
    if (!$p) {
        $p = new PDO('mysql:host=localhost;dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }
    return $p;
}

function out($data, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function sess() {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_set_cookie_params(['httponly' => true, 'samesite' => 'Strict', 'secure' => true, 'path' => '/MariFlix']);
        session_start();
    }
}

function need_admin() {
    sess();
    if (empty($_SESSION['admin'])) out(['error' => 'No autorizado'], 401);
}

/* ---------- acceso de espectadores ---------- */
function viewer_ok() {
    return isset($_COOKIE['mf_access']) && hash_equals(VIEWER_TOKEN, (string)$_COOKIE['mf_access']);
}
function need_viewer() {
    if (!viewer_ok()) out(['error' => 'Acceso restringido', 'gate' => true], 401);
}
function set_viewer_cookie() {
    setcookie('mf_access', VIEWER_TOKEN, ['expires' => time() + 30 * 86400, 'path' => '/MariFlix', 'secure' => true, 'httponly' => true, 'samesite' => 'Lax']);
}

// Bloqueo por IP: 2 fallos seguidos -> 1 hora sin poder intentarlo
const MAX_FAILS = 2, LOCK_SECS = 3600;
function lock_remaining($scope) {
    $q = db()->prepare('SELECT locked_until FROM login_attempts WHERE ip=? AND scope=?');
    $q->execute([$_SERVER['REMOTE_ADDR'], $scope]);
    return max(0, (int)$q->fetchColumn() - time());
}
function register_fail($scope) {
    $ip = $_SERVER['REMOTE_ADDR'];
    $q = db()->prepare('SELECT fails,locked_until FROM login_attempts WHERE ip=? AND scope=?');
    $q->execute([$ip, $scope]);
    $r = $q->fetch();
    $fails = ($r && (int)$r['locked_until'] === 0) ? $r['fails'] + 1 : 1;   // tras un bloqueo caducado se empieza de cero
    $until = $fails >= MAX_FAILS ? time() + LOCK_SECS : 0;
    db()->prepare('REPLACE INTO login_attempts(ip,scope,fails,locked_until) VALUES(?,?,?,?)')->execute([$ip, $scope, $fails, $until]);
    return [$fails, $until ? LOCK_SECS : 0];
}
function clear_fails($scope) {
    db()->prepare('DELETE FROM login_attempts WHERE ip=? AND scope=?')->execute([$_SERVER['REMOTE_ADDR'], $scope]);
}
// Comprueba contraseña con bloqueo. Responde y termina la petición.
function guarded_login($scope, $hash, $password, $onOk) {
    if ($left = lock_remaining($scope)) out(['error' => 'Demasiados intentos', 'locked' => $left], 429);
    // un bloqueo ya caducado se limpia antes de contar
    db()->prepare('DELETE FROM login_attempts WHERE ip=? AND scope=? AND locked_until>0 AND locked_until<=?')->execute([$_SERVER['REMOTE_ADDR'], $scope, time()]);
    usleep(300000);
    if (password_verify($password, $hash)) { clear_fails($scope); $onOk(); out(['ok' => true]); }
    [$fails, $lock] = register_fail($scope);
    out(['error' => 'Contraseña incorrecta', 'left' => max(0, MAX_FAILS - $fails), 'locked' => $lock], $lock ? 429 : 401);
}

// Firma de los enlaces para el Chromecast (api/stream.php): archivo + caducidad, con una clave derivada de la de acceso
function cast_sig($file, $exp) {
    return hash_hmac('sha256', "$file|$exp", VIEWER_TOKEN . '|cast');
}

// Enlace firmado y caducable a un archivo de media (para Chromecast y AirPlay, que no llevan la cookie de acceso)
function signed_url($rel, $exp) {
    if (!$rel) return null;
    if (is_external($rel)) return $rel;   // las externas ya son un enlace público: no pasan por nuestro servidor
    $base = 'https://' . $_SERVER['HTTP_HOST'] . dirname(dirname($_SERVER['SCRIPT_NAME']));
    return "$base/api/stream.php?f=" . rawurlencode($rel) . "&e=$exp&s=" . cast_sig($rel, $exp);
}

// ¿Está bloqueado ahora? Con lock_weekend=1 se desbloquea solo cada fin de semana:
// del viernes a las 14:00 al domingo a las 23:59, hora de Canarias.
function lock_active($msg, $weekend) {
    if ($msg === null) return false;
    if (!$weekend) return true;
    $now = new DateTime('now', new DateTimeZone('Atlantic/Canary'));
    $dow = (int)$now->format('N');          // 1 = lunes … 7 = domingo
    $open = ($dow === 5 && (int)$now->format('G') >= 14) || $dow >= 6;
    return !$open;
}

// Las películas «externas» guardan en videos.file la dirección completa (Internet Archive) en vez de un archivo nuestro
function is_external($rel) { return (bool)preg_match('#^https?://#i', (string)$rel); }
function media_url($rel) { return $rel ? (is_external($rel) ? $rel : 'media/' . $rel) : null; }

// Busca en un elemento de Internet Archive el MP4 que se puede reproducir. Devuelve [url, duration, title, year] o null.
function archive_item($ident) {
    $ctx = stream_context_create(['http' => ['timeout' => 25, 'header' => "User-Agent: FerFlix\r\n"]]);
    $j = json_decode(@file_get_contents('https://archive.org/metadata/' . rawurlencode($ident), false, $ctx) ?: '', true);
    if (empty($j['files'])) return null;
    $best = null; $score = -1;
    foreach ($j['files'] as $f) {
        if (!preg_match('/\.(mp4|m4v)$/i', $f['name'] ?? '')) continue;
        // se prefiere el MP4 H.264 (se ve en cualquier navegador); a igualdad, el más grande (la película, no un tráiler)
        $s = (stripos($f['format'] ?? '', 'h.264') !== false ? 1e13 : 0) + (float)($f['size'] ?? 0);
        if ($s > $score) { $score = $s; $best = $f; }
    }
    if (!$best) return null;
    $len = (string)($best['length'] ?? '');
    $dur = 0;
    if (strpos($len, ':') !== false) foreach (explode(':', $len) as $p) $dur = $dur * 60 + (float)$p; else $dur = (float)$len;
    $md = $j['metadata'] ?? [];
    $title = is_array($md['title'] ?? null) ? $md['title'][0] : ($md['title'] ?? $ident);
    $year = preg_match('/((?:18|19|20)\d{2})/', (string)(is_array($md['year'] ?? null) ? $md['year'][0] : ($md['year'] ?? ($md['date'] ?? ''))), $m) ? (int)$m[1] : null;
    return [
        'url' => 'https://archive.org/download/' . rawurlencode($ident) . '/' . implode('/', array_map('rawurlencode', explode('/', $best['name']))),
        'duration' => (int)round($dur), 'title' => $title, 'year' => $year,
    ];
}

// Añade una película externa (alojada en Internet Archive). Devuelve ['id','title'] o ['error'].
function external_add($pdo, $ident, $lang, $query = null, $year = null) {
    if (!preg_match('/^[A-Za-z0-9._-]{1,200}$/', $ident)) return ['error' => 'Enlace de Internet Archive no válido'];
    $q = $pdo->prepare('SELECT COUNT(*) FROM videos WHERE file LIKE ?');
    $q->execute(['https://archive.org/download/' . addcslashes(rawurlencode($ident), '%_') . '/%']);
    if ($q->fetchColumn()) return ['error' => 'Esa película ya está añadida'];
    $it = archive_item($ident);
    if (!$it) return ['error' => 'No se encontró un vídeo MP4 reproducible en ese enlace'];
    [$ct, $cy] = clean_title($it['title']);
    $title = $query ?: ($ct ?: $it['title']);
    $pdo->prepare('INSERT INTO titles(type,title,description,year,external,lang) VALUES(?,?,?,?,1,?)')
        ->execute(['movie', $title, '', $year ?: ($it['year'] ?: $cy), mb_substr(trim((string)$lang), 0, 60) ?: null]);
    $id = (int)$pdo->lastInsertId();
    $found = tmdb_apply($pdo, $id, $title, $year ?: ($it['year'] ?: $cy));
    $pdo->prepare('INSERT INTO videos(title_id,name,file,duration,status) VALUES(?,?,?,?,?)')
        ->execute([$id, $found ?: $title, $it['url'], $it['duration'], 'ready']);
    return ['id' => $id, 'title' => $found ?: $title, 'found' => (bool)$found];
}

function srt_to_vtt($text) {
    $text = preg_replace('/^\xEF\xBB\xBF/', '', $text);
    if (!mb_check_encoding($text, 'UTF-8')) $text = mb_convert_encoding($text, 'UTF-8', 'Windows-1252');
    $text = str_replace(["\r\n", "\r"], "\n", $text);
    $text = preg_replace('/(\d{2}:\d{2}:\d{2}),(\d{3})/', '$1.$2', $text);
    $text = preg_replace('/^\d+\n(?=\d{2}:\d{2}:\d{2}\.\d{3} -->)/m', '', $text);
    return "WEBVTT\n\n" . trim($text) . "\n";
}

function lang_label($code) {
    $map = ['es' => 'Español', 'spa' => 'Español', 'en' => 'English', 'eng' => 'English', 'fr' => 'Français', 'fra' => 'Français',
            'de' => 'Deutsch', 'ger' => 'Deutsch', 'deu' => 'Deutsch', 'it' => 'Italiano', 'ita' => 'Italiano', 'pt' => 'Português',
            'por' => 'Português', 'ca' => 'Català', 'cat' => 'Català', 'ja' => '日本語', 'jpn' => '日本語'];
    return $map[strtolower($code)] ?? strtoupper($code);
}

// Nombre de archivo -> [título limpio, año]. "Ex Machina (2014) (Alex Garland) HD.avi" -> ["Ex Machina", 2014]
function clean_title($raw) {
    $s = preg_replace('/\.(mp4|mkv|avi|mov|webm|m4v|ts|wmv|flv)$/i', '', $raw);
    $s = str_replace(['_', '.'], ' ', $s);
    $year = null;
    if (preg_match('/[\(\[\{]((?:19|20)\d{2})[\)\]\}]/', $s, $m, PREG_OFFSET_CAPTURE)) {
        $year = (int)$m[1][0];
        $s = substr($s, 0, $m[0][1]);
    } elseif (preg_match('/\s((?:19|20)\d{2})(?:\s|$)/', $s, $m, PREG_OFFSET_CAPTURE)) {
        $year = (int)$m[1][0];
        $s = substr($s, 0, $m[0][1]);
    }
    $s = preg_replace('/[\(\[\{].*$/u', '', $s);
    $s = preg_replace('/\b(1080p|720p|480p|2160p|4k|x264|x265|h264|h265|hevc|bluray|brrip|bdrip|dvdrip|web-?dl|webrip|hdrip|hd|castellano|spanish|english|latino|dual|subs?)\b.*$/iu', '', $s);
    $s = trim(preg_replace('/\s+/', ' ', $s), " -");
    return [$s, $year];
}

function tmdb_get($path, $params = []) {
    if (!defined('TMDB_KEY') || !TMDB_KEY) return null;
    $headers = '';
    if (strlen(TMDB_KEY) > 40) $headers = 'Authorization: Bearer ' . TMDB_KEY . "\r\n";   // token v4
    else $params['api_key'] = TMDB_KEY;                                                    // clave v3
    $ctx = stream_context_create(['http' => ['header' => $headers, 'timeout' => 15, 'ignore_errors' => true]]);
    $r = @file_get_contents('https://api.themoviedb.org/3/' . $path . '?' . http_build_query($params), false, $ctx);
    return $r ? json_decode($r, true) : null;
}

// Busca en TMDB y devuelve los datos del primer resultado, o null
function tmdb_lookup($title, $year, $type) {
    $ep = $type === 'series' ? 'search/tv' : 'search/movie';
    $yk = $type === 'series' ? 'first_air_date_year' : 'year';
    $res = null;
    foreach ($year ? [true, false] : [false] as $withYear) {
        $p = ['query' => $title, 'language' => 'es-ES'];
        if ($withYear) $p[$yk] = $year;
        $j = tmdb_get($ep, $p);
        if (!empty($j['results'])) { $res = $j['results'][0]; break; }
    }
    if (!$res) return null;
    $date = $res[$type === 'series' ? 'first_air_date' : 'release_date'] ?? '';
    $overview = $res['overview'] ?? '';
    if ($overview === '') {
        $d = tmdb_get(($type === 'series' ? 'tv/' : 'movie/') . $res['id'], ['language' => 'en-US']);
        $overview = $d['overview'] ?? '';
    }
    return [
        'tmdb_id' => (int)$res['id'],
        'title' => $res[$type === 'series' ? 'name' : 'title'] ?? $title,
        'year' => preg_match('/^(\d{4})/', $date, $m) ? (int)$m[1] : $year,
        'overview' => $overview,
        'image' => $res['backdrop_path'] ?: $res['poster_path'],
        'poster' => $res['poster_path'] ?? null,
        'rating' => ($res['vote_count'] ?? 0) >= 20 ? round((float)$res['vote_average'], 1) : null,
    ];
}

// Aplica los datos de TMDB a un título (incluida la portada). Devuelve el título nuevo o null.
function tmdb_apply($pdo, $id, $query = null, $year = null) {
    $q = $pdo->prepare('SELECT * FROM titles WHERE id=?'); $q->execute([$id]);
    $t = $q->fetch();
    if (!$t) return null;
    if ($query === null) [$query, $y2] = clean_title($t['title']); else $y2 = null;
    $m = tmdb_lookup($query, $year ?: ($y2 ?: $t['year']), $t['type']);
    if (!$m) return null;
    $thumb = $t['thumb'];
    if ($m['image']) {
        $img = @file_get_contents('https://image.tmdb.org/t/p/w1280' . $m['image']);
        if ($img) {
            $rel = "thumbs/t{$id}_" . bin2hex(random_bytes(3)) . '.jpg';
            file_put_contents(MEDIA_DIR . "/$rel", $img);
            rm_media($thumb);
            $thumb = $rel;
        }
    }
    // póster vertical solo si el título aún no tiene uno (no pisar el que se haya subido a mano)
    $poster = $t['poster'];
    if (!$poster && $m['poster'] && ($img = @file_get_contents('https://image.tmdb.org/t/p/w500' . $m['poster']))) {
        $poster = "thumbs/p{$id}_" . bin2hex(random_bytes(3)) . '.jpg';
        file_put_contents(MEDIA_DIR . "/$poster", $img);
    }
    $pdo->prepare('UPDATE titles SET title=?, description=?, year=?, thumb=?, poster=?, tmdb_id=?, rating=? WHERE id=?')
        ->execute([$m['title'], $m['overview'], $m['year'], $thumb, $poster, $m['tmdb_id'], $m['rating'], $id]);
    if ($t['type'] === 'series') tmdb_episodes($pdo, $id, $m['tmdb_id']);
    return $m['title'];
}

// Pone el nombre oficial a los capítulos de una serie
function tmdb_episodes($pdo, $id, $tmdbId) {
    $q = $pdo->prepare('SELECT id,season,episode FROM videos WHERE title_id=? AND season IS NOT NULL AND episode IS NOT NULL'); $q->execute([$id]);
    $vids = $q->fetchAll(); $cache = [];
    foreach ($vids as $v) {
        if (!isset($cache[$v['season']])) {
            $j = tmdb_get("tv/$tmdbId/season/{$v['season']}", ['language' => 'es-ES']);
            $cache[$v['season']] = [];
            foreach ($j['episodes'] ?? [] as $e) $cache[$v['season']][$e['episode_number']] = $e['name'];
        }
        $name = $cache[$v['season']][$v['episode']] ?? null;
        if ($name) $pdo->prepare('UPDATE videos SET name=? WHERE id=?')->execute([$name, $v['id']]);
    }
}

function rm_media($rel) {
    if (!$rel) return;
    $p = MEDIA_DIR . '/' . $rel;
    if (is_file($p)) @unlink($p);
}
