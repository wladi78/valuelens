<?php
// ValueLens laeuft lokal auf dem eigenen Rechner (php -S 127.0.0.1) - es gibt kein Login. Diese Datei ersetzt die
// Anmeldung: feste Sitzung "lokal" (Admin), CSRF-Token, eigener Sitzungsordner. Nur Anfragen vom eigenen Rechner.
if (PHP_SAPI !== 'cli') {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    if (!in_array($ip, ['127.0.0.1', '::1'], true)) { http_response_code(403); exit('nur lokal'); }
}
$__daten = __DIR__ . '/data';
foreach (['', '/sessions', '/cache', '/cache/bewertung', '/cache/fitness'] as $__u) if (!is_dir($__daten . $__u)) @mkdir($__daten . $__u, 0700, true);
if (!is_file($__daten . '/users.json')) {
    @file_put_contents($__daten . '/users.json', json_encode(['lokal' => ['isAdmin' => true, 'allowedDepots' => ['lokal']]], JSON_PRETTY_PRINT));
}
if (!is_file($__daten . '/depot_lokal.json')) {
    @file_put_contents($__daten . '/depot_lokal.json', json_encode(['positions' => [], 'transactions' => [], 'watchlist' => [], 'priceAlerts' => [], 'alertNotify' => new stdClass(), '_updatedAt' => 0], JSON_PRETTY_PRINT));
}
if (is_dir($__daten . '/sessions') && is_writable($__daten . '/sessions')) session_save_path($__daten . '/sessions');
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
$_SESSION['user'] = 'lokal';
if (empty($_SESSION['csrfToken'])) $_SESSION['csrfToken'] = bin2hex(random_bytes(16));
