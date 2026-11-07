<?php
require __DIR__ . '/lib.php';
need_viewer();
$pdo = db();

const ANIMALS = ['🐶','🐱','🦊','🐻','🐼','🐨','🐯','🦁','🐮','🐷','🐸','🐵','🐔','🐧','🦉','🦄','🐙','🐢','🦋','🐬','🦖','🐰','🐹','🐺'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    $name = trim(preg_replace('/\s+/u', ' ', (string)($in['name'] ?? '')));
    if (mb_strlen($name) < 2 || mb_strlen($name) > 30) out(['error' => 'El nombre debe tener entre 2 y 30 letras'], 400);
    if (!in_array($in['animal'] ?? '', ANIMALS, true)) out(['error' => 'Elige un animal'], 400);
    $q = $pdo->prepare('SELECT id,name,animal FROM profiles WHERE name=?'); $q->execute([$name]);
    if ($q->fetch()) out(['error' => 'Ese nombre ya existe. Selecciónalo en la lista para seguir siendo esa persona.'], 409);
    $pdo->prepare('INSERT INTO profiles(name,animal) VALUES(?,?)')->execute([$name, $in['animal']]);
    out(['id' => (int)$pdo->lastInsertId(), 'name' => $name, 'animal' => $in['animal']]);
}

if (isset($_GET['id'])) {
    $q = $pdo->prepare('SELECT id,name,animal FROM profiles WHERE id=?'); $q->execute([(int)$_GET['id']]);
    $p = $q->fetch();
    $p ? out(['id' => (int)$p['id'], 'name' => $p['name'], 'animal' => $p['animal']]) : out(['error' => 'No existe'], 404);
}

// Búsqueda por nombre (autocompletar)
$term = trim((string)($_GET['q'] ?? ''));
if ($term === '') out(['animals' => ANIMALS, 'profiles' => []]);
$like = '%' . addcslashes($term, '%_\\') . '%';
$q = $pdo->prepare('SELECT id,name,animal FROM profiles WHERE name LIKE ? ORDER BY name LIKE ? DESC, name LIMIT 8');
$q->execute([$like, addcslashes($term, '%_\\') . '%']);
out(['animals' => ANIMALS, 'profiles' => array_map(fn($p) => ['id' => (int)$p['id'], 'name' => $p['name'], 'animal' => $p['animal']], $q->fetchAll())]);
