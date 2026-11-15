<?php
// Aktuelle Version und Aenderungsliste (aus CHANGELOG.md) fuer die Anzeige "Neu in dieser Version" und die Hilfe.
require_once __DIR__ . '/session-init.php';
session_write_close();
header('Content-Type: application/json; charset=utf-8');
$version = trim((string) @file_get_contents(__DIR__ . '/VERSION')) ?: '0';
$liste = [];
$akt = null;
foreach (preg_split('/\R/', (string) @file_get_contents(__DIR__ . '/CHANGELOG.md')) as $z) {
    if (preg_match('/^##\s+([0-9.]+)\s*(?:[–-]\s*(.*))?$/u', $z, $m)) { if ($akt) $liste[] = $akt; $akt = ['version' => $m[1], 'datum' => trim($m[2] ?? ''), 'punkte' => []]; continue; }
    if ($akt && trim($z) !== '') {
        $t = trim(preg_replace('/^[-*]\s*/', '', $z));
        if (preg_match('/^[-*]\s/', $z) || !$akt['punkte']) $akt['punkte'][] = $t; else $akt['punkte'][count($akt['punkte']) - 1] .= ' ' . $t;
    }
}
if ($akt) $liste[] = $akt;
echo json_encode(['version' => $version, 'aenderungen' => $liste], JSON_UNESCAPED_UNICODE);
