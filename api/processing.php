<?php
// Lo que se está subiendo o procesando ahora mismo (para mostrarlo en la portada)
require __DIR__ . '/lib.php';
need_viewer();
$pdo = db();
$now = time();
$pdo->prepare('DELETE FROM uploads WHERE updated < ?')->execute([$now - 86400]);   // subidas abandonadas

$num = fn($x) => $x === null ? null : (int)$x;
$res = [];

// Subidas en curso (si no llega un trozo en 30 s se da por parada y no se muestra)
$q = $pdo->prepare("SELECT u.*, t.title, t.type, t.thumb, t.poster FROM uploads u JOIN titles t ON t.id=u.title_id WHERE u.updated >= ? ORDER BY u.started");
$q->execute([$now - 30]);
foreach ($q->fetchAll() as $r) {
    $elapsed = max(1, $now - (int)$r['started']);
    $speed = $r['received'] / $elapsed;                                  // bytes/s
    $res[] = ['key' => 'u' . $r['id'], 'kind' => 'upload', 'title_id' => (int)$r['title_id'], 'title' => $r['title'], 'type' => $r['type'],
              'season' => $num($r['season']), 'episode' => $num($r['episode']),
              'progress' => $r['total'] ? min(99, (int)floor($r['received'] / $r['total'] * 100)) : 0,
              'eta' => ($elapsed > 10 && $speed > 0) ? (int)round(($r['total'] - $r['received']) / $speed) : null,
              'stage' => '⬆ Subiendo', 'thumb' => media_url($r['thumb']), 'poster' => media_url($r['poster'])];
}

// Vídeos procesándose o en cola
$rows = $pdo->query("SELECT v.id, v.title_id, v.season, v.episode, v.progress, v.eta, v.stage, t.title, t.type, t.thumb, t.poster
    FROM videos v JOIN titles t ON t.id=v.title_id WHERE v.status='processing' ORDER BY v.id")->fetchAll();
foreach ($rows as $r) {
    $res[] = ['key' => 'p' . $r['id'], 'kind' => 'process', 'title_id' => (int)$r['title_id'], 'title' => $r['title'], 'type' => $r['type'],
              'season' => $num($r['season']), 'episode' => $num($r['episode']),
              'progress' => (int)$r['progress'], 'eta' => $num($r['eta']), 'stage' => $r['stage'], 'thumb' => media_url($r['thumb']), 'poster' => media_url($r['poster'])];
}
out($res);
