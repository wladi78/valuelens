<?php
// Installation pruefen: PHP, Erweiterungen, Zertifikate, Datenordner, Yahoo, SEC, Telegram, GitHub (Updates) - mit Ampel.
// Im Browser: Hilfe -> "Installation pruefen" (JSON). Ohne Browser: pruefen.bat (Windows) bzw. ./start.command pruefen
// Sendet KEINE Telegram-Nachricht (nur getMe = "gibt es den Bot"), schreibt nichts ausser einer Testdatei in data/.
require_once __DIR__ . '/session-init.php';
if (PHP_SAPI !== 'cli') session_write_close();
set_time_limit(90);
$ergebnis = [];
function pr($name, $ampel, $text) { global $ergebnis; $ergebnis[] = ['name' => $name, 'ampel' => $ampel, 'text' => $text]; }
function pr_holen($url, $ua = 'ValueLens-Pruefung/1.0') {
    if (!function_exists('curl_init')) return [0, '', 'curl fehlt'];
    $c = curl_init($url);
    curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 20, CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_USERAGENT => $ua, CURLOPT_ENCODING => 'gzip', CURLOPT_HTTPHEADER => ['Accept: application/json']]);
    $r = curl_exec($c); $code = curl_getinfo($c, CURLINFO_HTTP_CODE); $f = curl_error($c); curl_close($c);
    return [$code, (string) $r, $f];
}

// 1) PHP und Erweiterungen
pr('PHP', version_compare(PHP_VERSION, '8.0', '>=') ? 'gruen' : 'rot', 'Version ' . PHP_VERSION . (version_compare(PHP_VERSION, '8.0', '>=') ? '' : ' – PHP 8 oder neuer nötig'));
foreach (['curl' => 'Datenabrufe (Yahoo, SEC, Telegram)', 'openssl' => 'verschlüsselte Verbindungen', 'zip' => 'Updates einspielen', 'mbstring' => 'Umlaute/Texte'] as $e => $wozu) {
    $ok = extension_loaded($e);
    pr('Erweiterung ' . $e, $ok ? 'gruen' : ($e === 'zip' || $e === 'mbstring' ? 'gelb' : 'rot'), $ok ? 'vorhanden' : "fehlt – nötig für $wozu. Mit start.bat / start.command starten (schaltet sie ein).");
}
// 2) Zertifikate (Windows-PHP hat keine eigenen)
$ca = ini_get('curl.cainfo');
if ($ca) pr('Zertifikate', is_file($ca) && filesize($ca) > 1000 ? 'gruen' : 'rot', is_file($ca) && filesize($ca) > 1000 ? 'Datei vorhanden (' . round(filesize($ca) / 1024) . ' KB)' : 'Datei fehlt oder leer – start.bat neu starten');
else pr('Zertifikate', 'gruen', 'System-Zertifikate (keine eigene Datei nötig)');
// 3) Datenordner
$t = __DIR__ . '/data/.schreibtest';
$ok = @file_put_contents($t, 'x') !== false; @unlink($t);
pr('Datenordner', $ok ? 'gruen' : 'rot', $ok ? 'data/ beschreibbar' : 'data/ nicht beschreibbar – Ordnerrechte prüfen');
// 4) Yahoo (Kurse, Analysten)
[$code, $r, $f] = pr_holen('https://query1.finance.yahoo.com/v8/finance/chart/AAPL?range=5d&interval=1d', 'Mozilla/5.0');
$kurs = json_decode($r, true)['chart']['result'][0]['meta']['regularMarketPrice'] ?? null;
pr('Yahoo (Kurse)', $code === 200 && $kurs ? 'gruen' : 'rot', $code === 200 && $kurs ? 'erreichbar – AAPL ' . number_format($kurs, 2, ',', '.') . ' USD' : 'nicht erreichbar (' . ($f ?: 'HTTP ' . $code) . ')');
// 5) SEC (Jahres- und Quartalsberichte)
$e = json_decode((string) @file_get_contents(__DIR__ . '/data/einstellungen.json'), true) ?: [];
$mail = $e['secMail'] ?? '';
[$code, $r, $f] = pr_holen('https://data.sec.gov/api/xbrl/companyconcept/CIK0000320193/us-gaap/AccountsPayableCurrent.json', 'ValueLens/1.0 (' . ($mail ?: 'valuelens@example.com') . ')');
if ($code === 200) pr('SEC (Berichte)', $mail ? 'gruen' : 'gelb', $mail ? 'erreichbar, Kontakt-Mail eingetragen' : 'erreichbar – bitte unter Einstellungen eine Kontakt-Mail eintragen (verlangt die SEC)');
else pr('SEC (Berichte)', 'rot', 'nicht erreichbar (' . ($f ?: 'HTTP ' . $code) . ')' . (!$mail ? ' – Kontakt-Mail unter Einstellungen eintragen' : ''));
// 6) Telegram: je eingerichtetem Kanal pruefen, ob der Bot existiert (keine Nachricht)
require_once __DIR__ . '/telegram-lib.php';
$eingerichtet = 0;
foreach (TELEGRAM_CHANNELS as $k => $label) {
    $c = telegram_config(__DIR__ . '/data', $k); if (!$c) continue; $eingerichtet++;
    [$code, $r] = pr_holen('https://api.telegram.org/bot' . $c['botToken'] . '/getMe');
    $bot = json_decode($r, true)['result']['username'] ?? null;
    pr('Telegram: ' . $label, $bot ? 'gruen' : 'rot', $bot ? 'Bot @' . $bot . ' antwortet' : 'Bot antwortet nicht – Token unter Alarme & Telegram neu eintragen');
}
if (!$eingerichtet) pr('Telegram', 'gelb', 'noch nicht eingerichtet – nur nötig für Meldungen aufs Handy (Alarme & Telegram)');
// 7) GitHub (Updates)
[$code, $r] = pr_holen('https://raw.githubusercontent.com/wladi78/valuelens/main/VERSION?t=' . time());
$lokal = trim((string) @file_get_contents(__DIR__ . '/VERSION'));
if ($code === 200) { $neu = trim($r); pr('Updates (GitHub)', version_compare($neu, $lokal, '>') ? 'gelb' : 'gruen', version_compare($neu, $lokal, '>') ? "Version $lokal – neue Version $neu verfügbar" : "Version $lokal – aktuell"); }
else pr('Updates (GitHub)', 'gelb', "Version $lokal – GitHub nicht erreichbar, Updates später");

$gesamt = in_array('rot', array_column($ergebnis, 'ampel'), true) ? 'rot' : (in_array('gelb', array_column($ergebnis, 'ampel'), true) ? 'gelb' : 'gruen');
if (PHP_SAPI === 'cli') {
    $z = ['gruen' => '[OK]  ', 'gelb' => '[HINW]', 'rot' => '[FEHL]'];
    echo "ValueLens – Installation pruefen\n\n";
    foreach ($ergebnis as $e) printf("%s %-32s %s\n", $z[$e['ampel']], $e['name'], $e['text']);
    echo "\nGesamt: " . ['gruen' => 'alles in Ordnung', 'gelb' => 'laeuft, mit Hinweisen', 'rot' => 'es gibt Fehler'][$gesamt] . "\n";
    exit($gesamt === 'rot' ? 1 : 0);
}
header('Content-Type: application/json; charset=utf-8');
echo json_encode(['gesamt' => $gesamt, 'punkte' => $ergebnis], JSON_UNESCAPED_UNICODE);
