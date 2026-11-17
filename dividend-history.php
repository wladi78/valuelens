<?php
// Oeffentliche Dividenden-Zahlungshistorie eines einzelnen Symbols (Ex-/Record-/Zahl-/Deklarations-Tag
// + Betrag je Aktie in der Handelswaehrung des Titels, plus Kennzahlen wie Wachstum/Payout-Ratio/
// Streak-Jahre/Frequenz) - Nutzerwunsch vom 30.08.2026: erst "in Originalwaehrung die Brutto
// Dividenden ... seit meinem Kauf und ein Jahr davor", dann "gibt es andere Quellen ... mit
// Steigerung und anderen Daten?". Quelle: stockanalysis.com (freie, undokumentierte JSON-API hinter
// deren eigener Dividenden-Unterseite) - liefert im Gegensatz zu Yahoo auch Record-/Zahl-/
// Deklarations-Datum sowie Wachstum/Payout-Ratio/Streak-Jahre/Frequenz, dafuer nur ca. 10 Jahre
// Historie statt Yahoos 10y-Range (in der Praxis meist ausreichend). Unabhaengig von den selbst
// erfassten Kaeufen/Dividenden im Depot, on demand pro Symbol, kein Teil des zentralen render()-Zyklus.
require_once __DIR__ . '/session-init.php';
header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['user'])) {
    http_response_code(401);
    echo json_encode(['error' => 'unauthorized']);
    exit;
}

$symbol = isset($_GET['symbol']) ? trim($_GET['symbol']) : '';
if ($symbol === '') {
    echo json_encode(['error' => 'missing_symbol']);
    exit;
}

$ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36';
$url = 'https://stockanalysis.com/api/symbol/s/' . rawurlencode($symbol) . '/dividend';

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 12,
    CURLOPT_CONNECTTIMEOUT => 6,
    CURLOPT_USERAGENT => $ua,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_FOLLOWLOCATION => true,
]);
$body = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($code !== 200 || !$body) {
    echo json_encode(['error' => 'fetch_failed']);
    exit;
}
$json = json_decode($body, true);
$data = $json['data'] ?? null;
if (!$data) {
    echo json_encode(['error' => 'no_data']);
    exit;
}

function saMoney($s) {
    if ($s === null || $s === 'n/a' || $s === '') return null;
    $num = preg_replace('/[^0-9.\-]/', '', $s);
    return $num === '' || $num === '-' ? null : (float) $num;
}
function saField($v) {
    return ($v === null || $v === 'n/a' || $v === '') ? null : $v;
}

// Waehrung aus dem Symbol des ersten Betrags ableiten ($/€/£) - stockanalysis liefert kein eigenes
// Waehrungsfeld, die allermeisten dort gefuehrten Symbole sind aber USD.
$currency = 'USD';
$firstAmt = $data['history'][0]['amt'] ?? '';
if (strpos($firstAmt, '€') !== false) $currency = 'EUR';
elseif (strpos($firstAmt, '£') !== false) $currency = 'GBP';

$rows = [];
foreach (($data['history'] ?? []) as $d) {
    $rows[] = [
        'date' => saField($d['dt'] ?? null),
        'amount' => saMoney($d['amt'] ?? null),
        'declarationDate' => saField($d['dec'] ?? null),
        'recordDate' => saField($d['record'] ?? null),
        'payDate' => saField($d['pay'] ?? null),
    ];
}
usort($rows, fn($a, $b) => strcmp($a['date'] ?? '', $b['date'] ?? ''));

$info = $data['infoTable'] ?? [];
$meta = [
    'yield' => saField($info['yield'] ?? null),
    'annual' => saField($info['annual'] ?? null),
    'growth' => saField($info['growth'] ?? null),
    'payoutRatio' => saField($info['payoutRatio'] ?? null),
    'streakYears' => saField($info['years'] ?? null),
    'frequency' => saField($info['frequency'] ?? null),
];

echo json_encode(['symbol' => $symbol, 'currency' => $currency, 'dividends' => $rows, 'meta' => $meta]);
