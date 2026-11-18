<?php
// Big Board (bigboard-lib.php): nachts gerechnetes Ergebnis an die Seite. Nutzer 18.11.2026.
require_once __DIR__ . '/session-init.php';
header('Content-Type: application/json; charset=utf-8');
if (empty($_SESSION['user'])) { http_response_code(401); echo json_encode(['error' => 'unauthorized']); exit; }
session_write_close();
require_once __DIR__ . '/bigboard-lib.php';
// ?schritt=1: ein Stueck (~25 s) weiterbewerten - die Seite ruft das, bis nichts mehr offen ist (Nutzer 18.11.2026: "nicht alle
// Best Bets angezeigt" - vor dem ersten Nachtlauf fehlten Bewertungen). Ergebnis gilt sofort fuer alle.
if (!empty($_GET['schritt'])) { @set_time_limit(120); ignore_user_abort(true); echo json_encode(bb_rechnen(25), JSON_UNESCAPED_UNICODE); exit; }
echo json_encode(bb_laden(), JSON_UNESCAPED_UNICODE);
