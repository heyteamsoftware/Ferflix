<?php
// Datos para enviar un vídeo al Chromecast: enlaces firmados (válidos 6 h) al vídeo, sus subtítulos y la imagen.
require __DIR__ . '/lib.php';
need_viewer();
$pdo = db();
$q = $pdo->prepare("SELECT v.id, v.file, v.name, v.season, v.episode, t.title, t.type, t.thumb, t.poster, t.lock_msg, t.lock_weekend
    FROM videos v JOIN titles t ON t.id=v.title_id WHERE v.id=? AND v.status='ready'");
$q->execute([(int)($_GET['video'] ?? 0)]);
$v = $q->fetch();
if (!$v) out(['error' => 'Vídeo no encontrado'], 404);
if (lock_active($v['lock_msg'], (int)$v['lock_weekend'])) out(['error' => $v['lock_msg']], 403);

$exp = time() + 6 * 3600;
$url = fn($f) => signed_url($f, $exp);

$subs = $pdo->prepare('SELECT label, lang, file FROM subtitles WHERE video_id=? ORDER BY id');
$subs->execute([$v['id']]);
out([
    'video' => $url($v['file']),
    'title' => $v['title'],
    'subtitle' => $v['type'] === 'series' ? "T{$v['season']}:E{$v['episode']} · {$v['name']}" : '',
    'image' => $url($v['thumb'] ?: $v['poster']),
    'subs' => array_map(fn($s) => ['label' => $s['label'], 'lang' => $s['lang'], 'url' => $url($s['file'])], $subs->fetchAll()),
]);
