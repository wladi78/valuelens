<?php
// Session ueber die zentrale Initialisierung (siehe session-init.php): ein eigenes
// session_start() wuerde die dort gesetzten Cookie-Flags HttpOnly/Secure/SameSite
// umgehen. Diese Datei stammt noch aus der Zeit davor und war als einzige nicht
// umgestellt - sie lag bislang gar nicht auf dem Server.
require_once __DIR__ . '/session-init.php';
header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['user'])) {
    http_response_code(401);
    echo json_encode(['error' => 'unauthorized']);
    exit;
}

require __DIR__ . '/yahoo-fundamentals-lib.php';

$symbol = trim($_GET['symbol'] ?? '');
if ($symbol === '' || !function_exists('curl_init')) {
    echo json_encode(['sector' => null]);
    exit;
}

$ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36';
$info = yahoo_fetch_sector($symbol, $ua);

echo json_encode(['sector' => $info['sector'] ?? null, 'industry' => $info['industry'] ?? null]);
