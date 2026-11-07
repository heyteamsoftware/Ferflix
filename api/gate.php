<?php
require __DIR__ . '/lib.php';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    guarded_login('viewer', VIEWER_HASH, (string)($in['password'] ?? ''), 'set_viewer_cookie');
}
out(['ok' => viewer_ok(), 'locked' => lock_remaining('viewer')]);
