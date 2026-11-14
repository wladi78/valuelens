<?php
// Jahresreihen je Aktie fuer den Bewertungs-Chart (Nutzerwunsch 05.11.2026: "keine kostenpflichtigen Quellen, Alternative
// dafuer rechnen"). GAAP-Gewinne enthalten Sondereffekte (Altria 2019: -0,70 wegen Juul-Abschreibung), bereinigte Gewinne
// gibt es frei nicht. Darum vier Bezugsgroessen, alle aus freien Daten:
//   gaap     = verwaesserter Gewinn je Aktie laut 10-K
//   operativ = (Betriebsergebnis - Zinsaufwand) x (1 - normaler Steuersatz) / verwaesserte Aktien  (ohne Abschreibungen auf
//              Beteiligungen, Bewertungsgewinne und andere Posten unterhalb des Betriebsergebnisses - Naeherung an "bereinigt")
//   fcf      = (operativer Cashflow - Investitionen) / verwaesserte Aktien
//   ocf      = operativer Cashflow / verwaesserte Aktien
// Quelle: SEC XBRL (US), sonst Yahoo-Zeitreihen (ca. 4-5 Jahre). Ergebnis 7 Tage in data/cache/bewertung zwischengespeichert.
require_once __DIR__ . '/session-init.php';
require_once __DIR__ . '/bewertung-lib.php';
header('Content-Type: application/json; charset=utf-8');
if (empty($_SESSION['user'])) { http_response_code(401); echo json_encode(['error' => 'unauthorized']); exit; }
session_write_close();

$symbol = strtoupper(trim($_GET['symbol'] ?? ''));
if (!preg_match('/^[A-Z0-9.\-^=]{1,20}$/', $symbol)) { echo json_encode(['error' => 'symbol']); exit; }

echo json_encode(bewertung_reihen($symbol, !empty($_GET['neu'])));
