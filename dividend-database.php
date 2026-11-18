<?php
// Liefert den gecachten dripinvesting-Datensatz (alle Kategorien) an die Watchlist-Seite.
// Aktualisiert automatisch, wenn der Cache fehlt oder aelter als 7 Tage ist - der eigentliche
// woechentliche Zwangs-Refresh laeuft per Cronjob ueber sync-dividend-database.php.
require_once __DIR__ . '/session-init.php';
header('Content-Type: application/json; charset=utf-8');
require __DIR__ . '/dividend-database-lib.php';

if (empty($_SESSION['user'])) {
    http_response_code(401);
    echo json_encode(['error' => 'unauthorized']);
    exit;
}

echo json_encode(ddb_get());
