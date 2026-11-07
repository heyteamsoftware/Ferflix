<?php
require __DIR__ . '/lib.php';
need_viewer();
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    $pid = (int)($in['profile'] ?? 0);
    // desmarcar como vista
    if (!empty($in['unmark'])) {
        $pdo->prepare('UPDATE progress SET done=0, done_at=NULL, t=0, updated=? WHERE profile_id=? AND video_id=?')
            ->execute([time(), $pid, (int)($in['video_id'] ?? 0)]);
        out(['ok' => true]);
    }
    $items = isset($in['items']) ? $in['items'] : [$in];   // admite uno o varios (importación)
    // done_at guarda cuándo se terminó/marcó por primera vez (para el cuadro de actividad); va antes que done
    $st = $pdo->prepare('INSERT INTO progress(profile_id,video_id,t,done,updated,done_at) VALUES(?,?,?,?,?,?)
                         ON DUPLICATE KEY UPDATE done_at=IF(VALUES(done)=1 AND done=0, VALUES(updated), done_at),
                         t=VALUES(t), done=GREATEST(done,VALUES(done)), updated=VALUES(updated)');
    $ok = 0;
    foreach (array_slice($items, 0, 500) as $it) {
        try {
            $ts = min(time(), (int)($it['ts'] ?? 0) ?: time());
            $done = !empty($it['done']) ? 1 : 0;
            $st->execute([$pid, (int)($it['video_id'] ?? 0), max(0, (int)($it['t'] ?? 0)), $done, $ts, $done ? $ts : null]);
            $ok++;
        } catch (PDOException $e) { /* perfil o vídeo inexistente (claves foráneas): se ignora */ }
    }
    $ok ? out(['ok' => true, 'saved' => $ok]) : out(['error' => 'Perfil o vídeo no válido'], 400);
}

$q = $pdo->prepare('SELECT video_id,t,done,updated FROM progress WHERE profile_id=?');
$q->execute([(int)($_GET['profile'] ?? 0)]);
$res = [];
foreach ($q->fetchAll() as $r) $res[$r['video_id']] = ['t' => (int)$r['t'], 'done' => (bool)$r['done'], 'ts' => (int)$r['updated']];
out((object)$res);
