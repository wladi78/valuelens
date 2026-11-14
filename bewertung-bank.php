<?php
// Bank-Bewertung nach Kurs-Buchwert (bewertung_bank in bewertung-lib.php) fuer Bewertungsliste und Chart. 7 Tage Cache.
require_once __DIR__ . '/session-init.php';
require_once __DIR__ . '/bewertung-lib.php';
header('Content-Type: application/json; charset=utf-8');
if (empty($_SESSION['user'])) { http_response_code(401); echo json_encode(['error' => 'unauthorized']); exit; }
session_write_close();
$symbol = strtoupper(trim($_GET['symbol'] ?? ''));
if (!preg_match('/^[A-Z0-9.\-^=]{1,20}$/', $symbol)) { echo json_encode(['error' => 'symbol']); exit; }
echo json_encode(bewertung_bank($symbol, !empty($_GET['neu'])));
