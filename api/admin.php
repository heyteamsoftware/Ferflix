<?php
require __DIR__ . '/lib.php';
need_admin();
$a = $_GET['action'] ?? '';
$pdo = db();
$in = json_decode(file_get_contents('php://input'), true) ?: [];

foreach (['videos', 'subs', 'audios', 'thumbs', 'tmp'] as $d) {
    if (!is_dir(MEDIA_DIR . "/$d")) mkdir(MEDIA_DIR . "/$d", 0775, true);
}

switch ($a) {
case 'list':
    $t = $pdo->query('SELECT * FROM titles ORDER BY created DESC')->fetchAll();
    $v = $pdo->query('SELECT id,title_id,season,episode,name,status,error,duration,progress,stage,eta FROM videos ORDER BY season,episode,id')->fetchAll();
    $s = $pdo->query('SELECT id,video_id,label,lang,source FROM subtitles')->fetchAll();
    foreach ($v as &$x) $x['subs'] = array_values(array_filter($s, fn($q) => $q['video_id'] == $x['id']));
    unset($x);
    foreach ($t as &$x) {
        $x['thumb'] = media_url($x['thumb']); $x['poster'] = media_url($x['poster']);
        $x['videos'] = array_values(array_filter($v, fn($q) => $q['title_id'] == $x['id']));
    }
    out($t);

case 'title_save':
    $type = in_array($in['type'] ?? '', ['movie', 'series']) ? $in['type'] : 'movie';
    $title = trim($in['title'] ?? '');
    if ($title === '') out(['error' => 'Falta el título'], 400);
    $year = ($in['year'] ?? '') !== '' ? (int)$in['year'] : null;
    if (!empty($in['id'])) {
        $pdo->prepare('UPDATE titles SET title=?,description=?,year=? WHERE id=?')
            ->execute([$title, $in['description'] ?? '', $year, (int)$in['id']]);
        out(['id' => (int)$in['id']]);
    }
    [$ct, $cy] = clean_title($title);
    $pdo->prepare('INSERT INTO titles(type,title,description,year) VALUES(?,?,?,?)')
        ->execute([$type, $ct ?: $title, $in['description'] ?? '', $year ?: $cy]);
    $nid = (int)$pdo->lastInsertId();
    $found = null;
    if (!empty($in['auto']) && defined('TMDB_KEY') && TMDB_KEY) $found = tmdb_apply($pdo, $nid);
    out(['id' => $nid, 'found' => $found]);

case 'skip_save':
    // tiempos para "Saltar intro" y para cuándo empiezan los créditos (segundos; vacío = sin definir)
    $n = fn($k) => isset($in[$k]) && $in[$k] !== '' && $in[$k] !== null ? max(0, (int)$in[$k]) : null;
    [$is, $ie, $ou] = [$n('intro_start'), $n('intro_end'), $n('outro')];
    if (($is === null) !== ($ie === null) || ($is !== null && $ie <= $is)) out(['error' => 'La intro necesita inicio y fin, y el fin después del inicio'], 400);
    $pdo->prepare('UPDATE titles SET intro_start=?, intro_end=?, outro=? WHERE id=?')->execute([$is, $ie, $ou, (int)($in['id'] ?? 0)]);
    out(['ok' => true]);

case 'disk':
    // espacio del disco donde se guardan los vídeos
    out(['free' => (int)disk_free_space(MEDIA_DIR), 'total' => (int)disk_total_space(MEDIA_DIR)]);

case 'tmdb_refresh':
    if (!defined('TMDB_KEY') || !TMDB_KEY) out(['error' => 'Falta configurar la clave de TMDB en api/config.php'], 400);
    $found = tmdb_apply($pdo, (int)($in['id'] ?? 0), ($in['query'] ?? '') !== '' ? $in['query'] : null, ($in['year'] ?? '') !== '' ? (int)$in['year'] : null);
    if (!$found) out(['error' => 'No se encontró en TMDB. Prueba con otro nombre.'], 404);
    out(['ok' => true, 'title' => $found]);

case 'upload_chunk':
    $uid = $_POST['upload_id'] ?? '';
    if (!preg_match('/^[a-f0-9]{16,40}$/', $uid)) out(['error' => 'upload_id inválido'], 400);
    $idx = (int)($_POST['index'] ?? 0);
    $total = (int)($_POST['total'] ?? 1);
    if (empty($_FILES['chunk']) || $_FILES['chunk']['error'] !== UPLOAD_ERR_OK) out(['error' => 'Fallo al recibir el trozo'], 400);
    $part = MEDIA_DIR . "/tmp/$uid.part";
    if ($idx === 0 && is_file($part)) unlink($part);
    if ($idx > 0 && !is_file($part)) out(['error' => 'Subida perdida, reinicia'], 409);
    file_put_contents($part, file_get_contents($_FILES['chunk']['tmp_name']), FILE_APPEND | LOCK_EX);
    clearstatcache(true, $part);
    if (filesize($part) > 10 * 1024 ** 3) {
        unlink($part); $pdo->prepare('DELETE FROM uploads WHERE id=?')->execute([$uid]);
        out(['error' => 'El vídeo supera el límite de 10 GB'], 413);
    }
    if ($idx < $total - 1) {
        // registrar el avance de la subida para que se vea en la portada
        if (($tid = (int)($_POST['title_id'] ?? 0)) && ($size = (int)($_POST['size'] ?? 0))) {
            $now = time();
            $pdo->prepare('INSERT INTO uploads(id,title_id,season,episode,received,total,started,updated) VALUES(?,?,?,?,?,?,?,?)
                           ON DUPLICATE KEY UPDATE received=VALUES(received), updated=VALUES(updated)')
                ->execute([$uid, $tid, ($_POST['season'] ?? '') !== '' ? (int)$_POST['season'] : null,
                           ($_POST['episode'] ?? '') !== '' ? (int)$_POST['episode'] : null, filesize($part), $size, $now, $now]);
        }
        out(['ok' => true]);
    }
    $pdo->prepare('DELETE FROM uploads WHERE id=?')->execute([$uid]);

    // último trozo: registrar el vídeo y lanzar el procesado
    $titleId = (int)($_POST['title_id'] ?? 0);
    if (!$titleId) out(['error' => 'Falta title_id'], 400);
    $ext = strtolower(pathinfo($_POST['name'] ?? '', PATHINFO_EXTENSION));
    if (!in_array($ext, ['mp4', 'mkv', 'webm', 'avi', 'mov', 'm4v', 'ts', 'wmv', 'flv'])) {
        unlink($part);
        out(['error' => 'Formato de vídeo no soportado'], 400);
    }
    $src = "videos/src_$uid.$ext";
    rename($part, MEDIA_DIR . "/$src");
    $season = ($_POST['season'] ?? '') !== '' ? (int)$_POST['season'] : null;
    $ep = ($_POST['episode'] ?? '') !== '' ? (int)$_POST['episode'] : null;
    $name = trim($_POST['vname'] ?? '') ?: pathinfo($_POST['name'], PATHINFO_FILENAME);
    $pdo->prepare('INSERT INTO videos(title_id,season,episode,name,file,status) VALUES(?,?,?,?,?,?)')
        ->execute([$titleId, $season, $ep, $name, $src, 'processing']);
    $vid = (int)$pdo->lastInsertId();
    // por defecto se copia el HEVC; solo se recodifica si el panel lo pide expresamente (casilla desmarcada)
    $flags = ($_POST['hevc_copy'] ?? '1') !== '0' ? ' --hevc-copy' : '';
    exec('nohup /usr/bin/php ' . escapeshellarg(__DIR__ . '/process.php') . ' ' . $vid . $flags . ' > /dev/null 2>&1 &');
    out(['done' => true, 'video_id' => $vid]);

case 'upload_cancel':
    // borra la subida a medias; si era una película nueva sin vídeos, también su título vacío
    $ids = array_filter((array)($in['ids'] ?? []), fn($u) => is_string($u) && preg_match('/^[a-f0-9]{16,40}$/', $u));
    $tids = array_filter(array_map('intval', (array)($in['title_ids'] ?? [])));
    foreach ($ids as $uid) {
        $q = $pdo->prepare('SELECT title_id FROM uploads WHERE id=?'); $q->execute([$uid]);
        if ($tid = (int)$q->fetchColumn()) $tids[] = $tid;
        $pdo->prepare('DELETE FROM uploads WHERE id=?')->execute([$uid]);
        @unlink(MEDIA_DIR . "/tmp/$uid.part");
    }
    foreach (array_unique($tids) as $tid) {
        $q = $pdo->prepare("SELECT t.type, t.thumb, t.poster, (SELECT COUNT(*) FROM videos v WHERE v.title_id=t.id) n FROM titles t WHERE t.id=?");
        $q->execute([$tid]);
        $t = $q->fetch();
        if ($t && $t['type'] === 'movie' && !$t['n']) {
            rm_media($t['thumb']); rm_media($t['poster']);
            $pdo->prepare('DELETE FROM titles WHERE id=?')->execute([$tid]);
        }
    }
    out(['ok' => true, 'cancelled' => count($ids)]);

case 'sub_add':
    $vid = (int)($_POST['video_id'] ?? 0);
    if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK || !$vid) out(['error' => 'Datos incompletos'], 400);
    $ext = strtolower(pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['srt', 'vtt'])) out(['error' => 'Solo .srt o .vtt'], 400);
    $raw = file_get_contents($_FILES['file']['tmp_name']);
    $isVtt = str_starts_with(ltrim($raw, "\xEF\xBB\xBF"), 'WEBVTT');
    $vtt = $isVtt ? $raw : srt_to_vtt($raw);
    $lang = preg_match('/^[a-z]{2,3}$/i', $_POST['lang'] ?? '') ? strtolower($_POST['lang']) : 'es';
    $rel = "subs/v{$vid}_" . bin2hex(random_bytes(4)) . '.vtt';
    file_put_contents(MEDIA_DIR . "/$rel", $vtt);
    $label = trim($_POST['label'] ?? '') ?: lang_label($lang);
    $pdo->prepare('INSERT INTO subtitles(video_id,label,lang,file,source) VALUES(?,?,?,?,?)')
        ->execute([$vid, $label, $lang, $rel, 'srt']);
    out(['ok' => true]);

case 'thumb_set':
    $id = (int)($_POST['title_id'] ?? 0);
    if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK || !$id) out(['error' => 'Datos incompletos'], 400);
    $info = @getimagesize($_FILES['file']['tmp_name']);
    $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$info['mime'] ?? ''] ?? null;
    if (!$ext) out(['error' => 'Imagen no válida'], 400);
    // kind=poster: póster vertical (cuadrícula de películas); si no, imagen horizontal (portada y filas)
    $col = ($_POST['kind'] ?? '') === 'poster' ? 'poster' : 'thumb';
    $q = $pdo->prepare("SELECT $col FROM titles WHERE id=?"); $q->execute([$id]); rm_media($q->fetchColumn());
    $rel = "thumbs/" . ($col === 'poster' ? 'p' : 't') . "{$id}_" . bin2hex(random_bytes(3)) . ".$ext";
    move_uploaded_file($_FILES['file']['tmp_name'], MEDIA_DIR . "/$rel");
    $pdo->prepare("UPDATE titles SET $col=? WHERE id=?")->execute([$rel, $id]);
    out(['ok' => true]);

case 'delete_title':
    $id = (int)($in['id'] ?? 0);
    $q = $pdo->prepare('SELECT file FROM videos WHERE title_id=?'); $q->execute([$id]);
    foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $f) rm_media($f);
    $q = $pdo->prepare('SELECT s.file FROM subtitles s JOIN videos v ON v.id=s.video_id WHERE v.title_id=?'); $q->execute([$id]);
    foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $f) rm_media($f);
    $q = $pdo->prepare('SELECT a.file FROM audios a JOIN videos v ON v.id=a.video_id WHERE v.title_id=?'); $q->execute([$id]);
    foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $f) rm_media($f);
    $q = $pdo->prepare('SELECT thumb,poster FROM titles WHERE id=?'); $q->execute([$id]);
    foreach ($q->fetch() ?: [] as $f) rm_media($f);
    $pdo->prepare('DELETE FROM titles WHERE id=?')->execute([$id]);
    out(['ok' => true]);

case 'delete_video':
    $id = (int)($in['id'] ?? 0);
    $q = $pdo->prepare('SELECT file FROM videos WHERE id=?'); $q->execute([$id]); rm_media($q->fetchColumn());
    foreach (['subtitles', 'audios'] as $tbl) {
        $q = $pdo->prepare("SELECT file FROM $tbl WHERE video_id=?"); $q->execute([$id]);
        foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $f) rm_media($f);
    }
    $pdo->prepare('DELETE FROM videos WHERE id=?')->execute([$id]);
    out(['ok' => true]);

case 'video_save':
    $pdo->prepare('UPDATE videos SET name=?,season=?,episode=? WHERE id=?')->execute([
        trim($in['name'] ?? ''),
        ($in['season'] ?? '') !== '' ? (int)$in['season'] : null,
        ($in['episode'] ?? '') !== '' ? (int)$in['episode'] : null,
        (int)$in['id']]);
    out(['ok' => true]);

case 'cleanup':
    // borra archivos que ningún vídeo/subtítulo/audio/portada usa (ignora lo modificado en las últimas 24 h)
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
    out(['files' => $n, 'mb' => round($bytes / 1048576)]);

case 'delete_sub':
    $id = (int)($in['id'] ?? 0);
    $q = $pdo->prepare('SELECT file FROM subtitles WHERE id=?'); $q->execute([$id]); rm_media($q->fetchColumn());
    $pdo->prepare('DELETE FROM subtitles WHERE id=?')->execute([$id]);
    out(['ok' => true]);
}
out(['error' => 'Acción desconocida'], 404);
