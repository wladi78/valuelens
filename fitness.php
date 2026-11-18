<?php
// Finanzielle Fitness (fitness_daten in fitness-lib.php) fuer den Bewertungs-Chart. 7 Tage Cache.
require_once __DIR__ . '/session-init.php';
require_once __DIR__ . '/fitness-lib.php';
header('Content-Type: application/json; charset=utf-8');
if (empty($_SESSION['user'])) { http_response_code(401); echo json_encode(['error' => 'unauthorized']); exit; }
session_write_close();
$symbol = strtoupper(trim($_GET['symbol'] ?? ''));
if (!preg_match('/^[A-Z0-9.\-^=]{1,20}$/', $symbol)) { echo json_encode(['error' => 'symbol']); exit; }
if (!empty($_GET['vergleich'])) {
    $kand = array_values(array_unique(array_filter(array_map(fn($s) => strtoupper(trim($s)), explode(',', (string) ($_GET['kandidaten'] ?? ''))),
        fn($s) => preg_match('/^[A-Z0-9.\-]{1,20}$/', $s))));
    echo json_encode(firmen_vergleich($symbol, array_slice($kand, 0, 150))); exit;
}
if (!empty($_GET['kennzahlen'])) { echo json_encode(fitness_kennzahlen($symbol, empty($_GET['laden']))); exit; }
echo json_encode(!empty($_GET["info"]) ? firmen_info($symbol) : fitness_daten($symbol, !empty($_GET["neu"])));
