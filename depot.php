<?php
// Lokales Depot (Meine Käufe, Watchlist, Kursalarme, Meldeweg) laden und speichern: data/depot_lokal.json.
// Dieselbe Datei liest die Alarmpruefung (check-alerts.php) und der Nachtlauf (bewertung-signale.php).
require_once __DIR__ . '/session-init.php';
require_once __DIR__ . '/json_store.php';
header('Content-Type: application/json; charset=utf-8');
$datei = __DIR__ . '/data/depot_lokal.json';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrfToken'], (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''))) { http_response_code(403); echo json_encode(['error' => 'csrf']); exit; }
    $neu = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($neu)) { http_response_code(400); echo json_encode(['error' => 'json']); exit; }
    $fp = fopen($datei, 'c+'); flock($fp, LOCK_EX);
    $alt = json_decode((string) stream_get_contents($fp), true) ?: [];
    // Felder, die der Server selbst pflegt (ausgeloeste Alarme, mitlaufende Grenzen), nicht mit einem aelteren Stand ueberschreiben
    if (($alt['_updatedAt'] ?? 0) > ($neu['_basis'] ?? 0)) {
        $serverAlarme = []; foreach ($alt['priceAlerts'] ?? [] as $a) $serverAlarme[$a['id'] ?? ''] = $a;
        foreach ($neu['priceAlerts'] ?? [] as $i => $a) {
            $s = $serverAlarme[$a['id'] ?? ''] ?? null; if (!$s) continue;
            foreach (['fired', 'lastTriggeredAt', 'targetPrice', 'dynamischStand'] as $k) if (array_key_exists($k, $s)) $neu['priceAlerts'][$i][$k] = $s[$k];
        }
    }
    unset($neu['_basis']);
    $neu['_updatedAt'] = (int) round(microtime(true) * 1000);
    ftruncate($fp, 0); rewind($fp); fwrite($fp, json_encode($neu, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)); fflush($fp);
    flock($fp, LOCK_UN); fclose($fp);
    echo json_encode(['ok' => true, '_updatedAt' => $neu['_updatedAt']]); exit;
}
echo (string) @file_get_contents($datei) ?: '{}';
