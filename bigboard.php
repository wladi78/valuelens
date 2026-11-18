<?php
// Big Board (bigboard-lib.php): nachts gerechnetes Ergebnis an die Seite. Nutzer 18.11.2026.
require_once __DIR__ . '/session-init.php';
header('Content-Type: application/json; charset=utf-8');
if (empty($_SESSION['user'])) { http_response_code(401); echo json_encode(['error' => 'unauthorized']); exit; }
session_write_close();
require_once __DIR__ . '/bigboard-lib.php';
echo json_encode(bb_laden(), JSON_UNESCAPED_UNICODE);
