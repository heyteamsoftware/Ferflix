<?php
// Lo que ha visto cada persona (visible para todos), agrupado por perfil.
// Entera = ≥80 % o marcada; un trozo = al menos 2 minutos.
require __DIR__ . '/lib.php';
need_viewer();
$pdo = db();
$rows = $pdo->query("SELECT g.profile_id, t.id AS title_id, t.title, t.type, v.season, v.episode, v.name AS ep_name, v.duration,
        g.t, g.done, IF(g.done=1, IFNULL(g.done_at, g.updated), g.updated) AS ts
    FROM progress g JOIN videos v ON v.id=g.video_id JOIN titles t ON t.id=v.title_id
    WHERE g.done=1 OR g.t >= 120
    ORDER BY ts DESC")->fetchAll();

$people = [];
foreach ($pdo->query('SELECT id,name,animal FROM profiles')->fetchAll() as $p)
    $people[$p['id']] = ['name' => $p['name'], 'animal' => $p['animal'], 'last' => 0, 'whole' => 0, 'partial' => 0, 'seconds' => 0, 'items' => []];

foreach ($rows as $r) {
    if (!isset($people[$r['profile_id']])) continue;
    $p = &$people[$r['profile_id']];
    $done = (bool)$r['done'];
    $done ? $p['whole']++ : $p['partial']++;
    $p['seconds'] += $done ? (int)$r['duration'] : min((int)$r['t'], (int)$r['duration']);   // tiempo visto aproximado
    $p['last'] = max($p['last'], (int)$r['ts']);
    if (count($p['items']) < 60) $p['items'][] = [
        'title_id' => (int)$r['title_id'], 'title' => $r['title'], 'type' => $r['type'],
        'season' => $r['season'] === null ? null : (int)$r['season'], 'episode' => $r['episode'] === null ? null : (int)$r['episode'],
        'ep_name' => $r['ep_name'], 'ts' => (int)$r['ts'], 'done' => $done,
        'pct' => $r['duration'] ? min(99, (int)round($r['t'] / $r['duration'] * 100)) : null,
    ];
    unset($p);
}
// "Viendo ahora": el reproductor guarda el progreso cada 10 s, así que si hubo guardado en los últimos 40 s está viendo algo
$q = $pdo->prepare("SELECT g.profile_id, t.title, t.type, v.season, v.episode FROM progress g JOIN videos v ON v.id=g.video_id
    JOIN titles t ON t.id=v.title_id WHERE g.updated >= ? AND g.t > 0 ORDER BY g.updated DESC");   // t>0: marcar/desmarcar a mano no cuenta
$q->execute([time() - 40]);
foreach ($q->fetchAll() as $w) if (isset($people[$w['profile_id']]) && empty($people[$w['profile_id']]['watching']))
    $people[$w['profile_id']]['watching'] = ['title' => $w['title'], 'type' => $w['type'],
        'season' => $w['season'] === null ? null : (int)$w['season'], 'episode' => $w['episode'] === null ? null : (int)$w['episode']];

// Perfiles con only_self=1 (p. ej. Mariló) solo ven su propia actividad; el resto ve la de todos
$me = (int)($_GET['profile'] ?? 0);
$q = $pdo->prepare('SELECT only_self FROM profiles WHERE id=?'); $q->execute([$me]);
$selfOnly = (bool)$q->fetchColumn();
if ($selfOnly) $people = isset($people[$me]) ? [$me => $people[$me]] : [];

// primero quien ha visto algo más recientemente; los que no han visto nada, al final
$people = array_values($people);
usort($people, fn($a, $b) => $b['last'] <=> $a['last'] ?: strcmp($a['name'], $b['name']));
out(['self_only' => $selfOnly, 'people' => $people]);
