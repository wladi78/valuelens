<?php
// Lokale Einstellungen (data/einstellungen.json): Kontakt-Mail fuer die SEC (Pflicht im User-Agent der SEC-Abrufe).
require_once __DIR__ . '/session-init.php';
header('Content-Type: application/json; charset=utf-8');
$datei = __DIR__ . '/data/einstellungen.json';
$e = json_decode((string) @file_get_contents($datei), true) ?: [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrfToken'], (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''))) { http_response_code(403); echo json_encode(['error' => 'csrf']); exit; }
    $in = json_decode((string) file_get_contents('php://input'), true) ?: [];
    $mail = trim((string) ($in['secMail'] ?? ''));
    if ($mail !== '' && !filter_var($mail, FILTER_VALIDATE_EMAIL)) { echo json_encode(['error' => 'Mailadresse ungültig']); exit; }
    $e['secMail'] = $mail;
    file_put_contents($datei, json_encode($e, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    echo json_encode(['ok' => true]); exit;
}
echo json_encode(['secMail' => $e['secMail'] ?? '', 'php' => PHP_VERSION, 'curl' => extension_loaded('curl'), 'openssl' => extension_loaded('openssl')]);
