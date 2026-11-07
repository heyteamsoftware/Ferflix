<?php
require __DIR__ . '/lib.php';
sess();
$a = $_GET['action'] ?? '';
if ($a === 'login') {
    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    guarded_login('admin', ADMIN_HASH, (string)($in['password'] ?? ''), function () {
        session_regenerate_id(true);
        $_SESSION['admin'] = true;
        set_viewer_cookie();   // el admin también necesita ver las portadas
    });
}
if ($a === 'logout') { $_SESSION = []; session_destroy(); out(['ok' => true]); }
out(['admin' => !empty($_SESSION['admin'])]);
