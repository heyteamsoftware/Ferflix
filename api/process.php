<?php
// Worker CLI: php process.php <video_id>
if (php_sapi_name() !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/lib.php';
set_time_limit(0);
$id = (int)($argv[1] ?? 0);
$pdo = db();
$st = $pdo->prepare('SELECT * FROM videos WHERE id=?'); $st->execute([$id]);
$v = $st->fetch();
if (!$v) exit(1);

function fail($pdo, $id, $msg) {
    // un vídeo fallido no se puede reintentar: no dejar el original (varios GB) ni salidas a medias
    $f = $pdo->prepare('SELECT file FROM videos WHERE id=?'); $f->execute([$id]);
    foreach (array_merge([MEDIA_DIR . '/' . $f->fetchColumn()], glob(MEDIA_DIR . "/videos/v{$id}.*")) as $p) if (is_file($p)) @unlink($p);
    $pdo->prepare("UPDATE videos SET status='error', error=? WHERE id=?")->execute([substr($msg, 0, 2000), $id]);
    exit(1);
}

function set_stage($pdo, $id, $stage, $progress = 0) {
    $pdo->prepare('UPDATE videos SET stage=?, progress=? WHERE id=?')->execute([$stage, $progress, $id]);
}

// Ejecuta ffmpeg con -progress y va guardando el % en la base de datos. Devuelve null si va bien o el error.
function run_ffmpeg($pdo, $id, $args, $duration) {
    $errFile = MEDIA_DIR . "/tmp/ffmpeg_err_$id.txt";
    // "exec" para que el proceso sea ffmpeg y no un sh intermedio (así proc_terminate lo para de verdad)
    $p = proc_open('exec ffmpeg -y -v error -nostats -progress pipe:1 ' . $args . ' 2>' . escapeshellarg($errFile), [1 => ['pipe', 'w']], $pipes);
    if (!is_resource($p)) return 'no se pudo lanzar ffmpeg';
    $last = 0; $start = microtime(true);
    $upd = $pdo->prepare('UPDATE videos SET progress=?, eta=? WHERE id=?');
    $exists = $pdo->prepare('SELECT COUNT(*) FROM videos WHERE id=?');
    while (($line = fgets($pipes[1])) !== false) {
        if (preg_match('/^out_time_(?:us|ms)=(\d+)/', $line, $m) && $duration > 0 && microtime(true) - $last > 3) {
            // si han borrado el vídeo, parar ffmpeg en el momento en vez de terminar la conversión
            $exists->execute([$id]);
            if (!$exists->fetchColumn()) { proc_terminate($p, 9); break; }
            $done = $m[1] / 1e6;                                   // segundos de película ya convertidos
            $elapsed = microtime(true) - $start;
            $pct = min(99, (int)floor($done / $duration * 100));
            // tiempo restante = lo que falta / velocidad real (solo cuando ya hay datos fiables)
            $eta = ($done > 5 && $elapsed > 10) ? (int)round(($duration - $done) / ($done / $elapsed)) : null;
            $upd->execute([$pct, $eta, $id]);
            $last = microtime(true);
        }
    }
    $pdo->prepare('UPDATE videos SET eta=NULL WHERE id=?')->execute([$id]);
    $rc = proc_close($p);
    $err = is_file($errFile) ? trim(implode("\n", array_slice(file($errFile), -5))) : '';
    @unlink($errFile);
    return $rc === 0 ? null : ($err ?: "código $rc");
}

// Si borraron el vídeo mientras se procesaba, no dejar archivos huérfanos
function abort_if_deleted($pdo, $id, $src) {
    $c = $pdo->prepare('SELECT COUNT(*) FROM videos WHERE id=?'); $c->execute([$id]);
    if ($c->fetchColumn()) return;
    foreach (array_merge([$src], glob(MEDIA_DIR . "/videos/v{$id}.*"), glob(MEDIA_DIR . "/subs/v{$id}_*"), glob(MEDIA_DIR . "/audios/v{$id}_*")) as $f) @unlink($f);
    exit(0);
}

$src = MEDIA_DIR . '/' . $v['file'];

// Cola: un solo vídeo procesándose a la vez (el servidor tiene 1 núcleo). Los demás esperan aquí.
set_stage($pdo, $id, 'En cola');
$lock = fopen(MEDIA_DIR . '/.worker.lock', 'c');
flock($lock, LOCK_EX);
abort_if_deleted($pdo, $id, $src);   // por si lo borraron mientras esperaba

$probe = json_decode(shell_exec('ffprobe -v error -print_format json -show_streams -show_format ' . escapeshellarg($src)), true);
if (!$probe || empty($probe['streams'])) fail($pdo, $id, 'ffprobe no pudo leer el archivo');

$vcodec = null; $acodec = null; $subs = []; $auds = [];
foreach ($probe['streams'] as $s) {
    if ($s['codec_type'] === 'video' && !$vcodec && empty($s['disposition']['attached_pic'])) $vcodec = $s['codec_name'];
    if ($s['codec_type'] === 'audio') { if (!$acodec) $acodec = $s['codec_name']; $auds[] = $s; }
    if ($s['codec_type'] === 'subtitle') $subs[] = $s;
}
if (!$vcodec) fail($pdo, $id, 'El archivo no contiene vídeo');
$duration = (int)round((float)($probe['format']['duration'] ?? 0));
$pdo->prepare('UPDATE videos SET duration=? WHERE id=?')->execute([$duration, $id]);
set_stage($pdo, $id, 'Extrayendo subtítulos');

// 1) Subtítulos incrustados (solo texto) -> WebVTT
$textCodecs = ['subrip', 'srt', 'ass', 'ssa', 'mov_text', 'webvtt', 'text'];
foreach ($subs as $n => $s) {
    if (!in_array($s['codec_name'], $textCodecs)) continue;
    $lang = $s['tags']['language'] ?? 'und';
    $label = $s['tags']['title'] ?? ($lang === 'und' ? 'Subtítulos ' . ($n + 1) : lang_label($lang));
    $rel = "subs/v{$id}_emb{$n}.vtt";
    $o = [];
    exec('ffmpeg -y -v error -i ' . escapeshellarg($src) . ' -map 0:s:' . $n . ' -c:s webvtt ' . escapeshellarg(MEDIA_DIR . "/$rel"), $o, $rc);
    if ($rc === 0 && is_file(MEDIA_DIR . "/$rel") && filesize(MEDIA_DIR . "/$rel") > 10) {
        $pdo->prepare('INSERT INTO subtitles(video_id,label,lang,file,source) VALUES(?,?,?,?,?)')
            ->execute([$id, $label, strlen($lang) <= 3 ? $lang : 'und', $rel, 'embedded']);
    }
}

// 2) Vídeo compatible con navegadores: MP4 (H.264 + AAC), sin remuxar si no hace falta transcodificar
$out = "videos/v{$id}.mp4";
$outAbs = MEDIA_DIR . "/$out";
// --hevc-copy: mete el HEVC tal cual (casi instantáneo; solo lo reproducen navegadores con soporte H.265)
$hevcCopy = $vcodec === 'hevc' && in_array('--hevc-copy', $argv, true);
$vopt = $vcodec === 'h264' ? '-c:v copy' : ($hevcCopy ? '-c:v copy -tag:v hvc1' : '-c:v libx264 -preset veryfast -crf 22 -pix_fmt yuv420p');
$aopt = $acodec === 'aac' ? '-c:a copy' : '-c:a aac -b:a 192k -ac 2';
$args = '-i ' . escapeshellarg($src) . ' -map 0:v:0 ' . ($acodec ? '-map 0:a:0 ' : '')
      . "$vopt " . ($acodec ? "$aopt " : '') . '-sn -movflags +faststart ' . escapeshellarg($outAbs);
set_stage($pdo, $id, $vcodec === 'h264' || $hevcCopy ? 'Copiando vídeo' : 'Convirtiendo vídeo', 0);
$err = run_ffmpeg($pdo, $id, $args, $duration);
abort_if_deleted($pdo, $id, $src);
if ($err !== null || !is_file($outAbs)) fail($pdo, $id, 'ffmpeg: ' . $err);

// 2b) Pistas de audio: la primera va dentro del MP4; las demás, como M4A aparte
$audName = function ($s, $n) {
    $lang = $s['tags']['language'] ?? 'und';
    $label = $s['tags']['title'] ?? ($lang === 'und' ? 'Audio ' . ($n + 1) : lang_label($lang));
    return [$label, strlen($lang) <= 3 ? $lang : 'und'];
};
if (count($auds) > 1) set_stage($pdo, $id, 'Extrayendo audios', 99);
if ($auds) {
    [$l0, $g0] = $audName($auds[0], 0);
    $pdo->prepare('UPDATE videos SET audio_label=?, audio_lang=? WHERE id=?')->execute([$l0, $g0, $id]);
}
foreach ($auds as $n => $s) {
    if ($n === 0) continue;
    [$label, $lang] = $audName($s, $n);
    $rel = "audios/v{$id}_a{$n}.m4a";
    @mkdir(MEDIA_DIR . '/audios', 0775, true);
    $a = $s['codec_name'] === 'aac' ? '-c:a copy' : '-c:a aac -b:a 192k -ac 2';
    $o3 = [];
    exec('ffmpeg -y -v error -i ' . escapeshellarg($src) . ' -map 0:a:' . $n . " $a -vn -movflags +faststart " . escapeshellarg(MEDIA_DIR . "/$rel") . ' 2>&1', $o3, $rc);
    if ($rc === 0 && is_file(MEDIA_DIR . "/$rel"))
        $pdo->prepare('INSERT INTO audios(video_id,label,lang,file) VALUES(?,?,?,?)')->execute([$id, $label, $lang, $rel]);
}
@unlink($src);
abort_if_deleted($pdo, $id, $src);

// 3) Miniatura automática si el título aún no tiene
$t = $pdo->prepare('SELECT thumb FROM titles WHERE id=?'); $t->execute([$v['title_id']]);
if (!$t->fetchColumn()) {
    $th = "thumbs/t{$v['title_id']}_auto.jpg";
    $at = max(1, (int)($duration * 0.1));
    exec('ffmpeg -y -v error -ss ' . $at . ' -i ' . escapeshellarg($outAbs) . ' -frames:v 1 -vf scale=1280:-2 -q:v 3 ' . escapeshellarg(MEDIA_DIR . "/$th"));
    if (is_file(MEDIA_DIR . "/$th")) $pdo->prepare('UPDATE titles SET thumb=? WHERE id=?')->execute([$th, $v['title_id']]);
}

$pdo->prepare("UPDATE videos SET file=?, duration=?, status='ready', error=NULL, progress=100, stage=NULL WHERE id=?")->execute([$out, $duration, $id]);
