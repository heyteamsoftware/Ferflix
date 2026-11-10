<?php
require __DIR__ . '/lib.php';
need_viewer();
$titles = db()->query('SELECT id,type,title,description,year,thumb,poster,intro_start,intro_end,outro,lock_msg,created FROM titles ORDER BY created DESC')->fetchAll();
$vids = db()->query("SELECT id,title_id,season,episode,name,file,duration FROM videos WHERE status='ready' ORDER BY season,episode,id")->fetchAll();
$subs = db()->query('SELECT video_id,label,lang,file FROM subtitles ORDER BY id')->fetchAll();
$subBy = [];
foreach ($subs as $s) $subBy[$s['video_id']][] = ['label' => $s['label'], 'lang' => $s['lang'], 'file' => media_url($s['file'])];
$audBy = [];
foreach (db()->query('SELECT video_id,label,lang,file FROM audios ORDER BY id')->fetchAll() as $a)
    $audBy[$a['video_id']][] = ['label' => $a['label'], 'lang' => $a['lang'], 'file' => media_url($a['file'])];
$defBy = [];
foreach (db()->query('SELECT id,audio_label,audio_lang FROM videos')->fetchAll() as $d) $defBy[$d['id']] = $d;
$signExp = time() + 24 * 3600;
$vidBy = [];
foreach ($vids as $v) {
    // la primera pista va dentro del MP4 (file = null); el resto son archivos aparte
    $audios = [];
    if (!empty($audBy[$v['id']])) {
        $d = $defBy[$v['id']];
        $audios = array_merge([['label' => $d['audio_label'] ?: 'Original', 'lang' => $d['audio_lang'] ?: 'und', 'file' => null]], $audBy[$v['id']]);
    }
    $vidBy[$v['title_id']][] = [
        'id' => (int)$v['id'],
        'season' => $v['season'] === null ? null : (int)$v['season'],
        'episode' => $v['episode'] === null ? null : (int)$v['episode'],
        'name' => $v['name'], 'file' => media_url($v['file']), 'duration' => (int)$v['duration'],
        'signed' => signed_url($v['file'], $signExp),   // AirPlay: el Apple TV descarga el vídeo sin la cookie
        'subs' => $subBy[$v['id']] ?? [], 'audios' => $audios,
    ];
}
$res = [];
foreach ($titles as $t) {
    if (empty($vidBy[$t['id']])) continue;
    // bloqueado: se muestra en el catálogo con su aviso, pero sin nada que reproducir
    if ($t['lock_msg'] !== null)
        foreach ($vidBy[$t['id']] as &$lv) { $lv['file'] = $lv['signed'] = null; $lv['subs'] = $lv['audios'] = []; }
    unset($lv);
    $res[] = ['id' => (int)$t['id'], 'type' => $t['type'], 'title' => $t['title'], 'description' => $t['description'],
              'year' => $t['year'] ? (int)$t['year'] : null, 'thumb' => media_url($t['thumb']), 'poster' => media_url($t['poster']), 'videos' => $vidBy[$t['id']],
              // saltar intro / créditos (segundos); outro = cuánto duran los créditos contando desde el final
              'locked' => $t['lock_msg'],
              'skip' => ['intro' => $t['intro_start'] !== null ? [(int)$t['intro_start'], (int)$t['intro_end']] : null,
                         'outro' => $t['outro'] !== null ? (int)$t['outro'] : null]];
}
out($res);
