<?php
// ValueLens aktualisieren: neueste Fassung von GitHub (oeffentliches Repo wladi78/valuelens) holen und einspielen.
// GET  -> {lokal, neu, update}: Versionen vergleichen (Datei VERSION)
// POST -> ZIP laden, pruefen, alle Programmdateien ersetzen. Der Ordner data/ (Kaeufe, Watchlist, Alarme, Telegram,
//         Einstellungen) wird NIE angefasst. Liegt ein Git-Klon vor (.git), wird stattdessen "git pull" empfohlen.
require_once __DIR__ . '/session-init.php';
session_write_close();
header('Content-Type: application/json; charset=utf-8');
set_time_limit(180);
const VL_REPO = 'wladi78/valuelens';

function vl_holen($url) {
    $c = curl_init($url);
    curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 120, CURLOPT_USERAGENT => 'ValueLens-Update']);
    $r = curl_exec($c); $code = curl_getinfo($c, CURLINFO_HTTP_CODE); $fehler = curl_error($c); curl_close($c);
    return [$code, $r, $fehler];
}
$lokal = trim((string) @file_get_contents(__DIR__ . '/VERSION')) ?: '0';
[$code, $neu] = vl_holen('https://raw.githubusercontent.com/' . VL_REPO . '/main/VERSION?t=' . time());
$neu = $code === 200 ? trim((string) $neu) : null;
$istGit = is_dir(__DIR__ . '/.git');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['lokal' => $lokal, 'neu' => $neu, 'update' => $neu !== null && version_compare($neu, $lokal, '>'), 'git' => $istGit, 'fehler' => $neu === null ? 'GitHub nicht erreichbar' : null]);
    exit;
}
if (!hash_equals($_SESSION['csrfToken'], (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''))) { http_response_code(403); echo json_encode(['error' => 'csrf']); exit; }
if ($istGit) { echo json_encode(['error' => 'Dieser Ordner ist ein Git-Klon – bitte mit "git pull" aktualisieren.']); exit; }
if (!class_exists('ZipArchive')) { echo json_encode(['error' => 'PHP-Erweiterung zip fehlt – ValueLens mit der neuen start-Datei starten.']); exit; }

[$code, $zipDaten, $fehler] = vl_holen('https://codeload.github.com/' . VL_REPO . '/zip/refs/heads/main');
if ($code !== 200 || strlen((string) $zipDaten) < 10000) { echo json_encode(['error' => 'Download fehlgeschlagen (' . $code . ' ' . $fehler . ')']); exit; }
$tmp = __DIR__ . '/data/update-' . date('Ymd-His') . '.zip';
file_put_contents($tmp, $zipDaten);
$zip = new ZipArchive();
if ($zip->open($tmp) !== true) { @unlink($tmp); echo json_encode(['error' => 'ZIP nicht lesbar']); exit; }

// Pruefen: ein Wurzelordner, darin index.php und VERSION; nur erlaubte Dateiarten; kein data/, keine Pfad-Tricks
$wurzel = null; $plan = [];
for ($i = 0; $i < $zip->numFiles; $i++) {
    $name = $zip->getNameIndex($i);
    if ($wurzel === null) $wurzel = explode('/', $name)[0] . '/';
    if (strpos($name, $wurzel) !== 0 || strpos($name, '..') !== false || strpos($name, '\\') !== false) { $zip->close(); @unlink($tmp); echo json_encode(['error' => 'ZIP-Inhalt unerwartet: ' . $name]); exit; }
    $rel = substr($name, strlen($wurzel));
    if ($rel === '' || substr($rel, -1) === '/') continue;
    if (preg_match('#^data/#i', $rel)) continue;   // eigene Daten nie ueberschreiben
    if (!preg_match('#^[A-Za-z0-9_./-]+\.(php|js|css|html|md|bat|command|sh)$|^(VERSION|\.gitignore|\.gitattributes)$#', $rel)) continue;
    $plan[$rel] = $i;
}
if (!isset($plan['index.php'], $plan['VERSION'])) { $zip->close(); @unlink($tmp); echo json_encode(['error' => 'ZIP ist keine ValueLens-Fassung']); exit; }

// Erst alles in einen Zwischenordner, dann umkopieren - bricht etwas ab, bleibt die alte Fassung vollstaendig
$zw = __DIR__ . '/data/update-neu'; if (is_dir($zw)) { foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($zw, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $f) $f->isDir() ? @rmdir($f) : @unlink($f); }
foreach ($plan as $rel => $i) {
    $ziel = $zw . '/' . $rel; if (!is_dir(dirname($ziel))) mkdir(dirname($ziel), 0700, true);
    if (file_put_contents($ziel, $zip->getFromIndex($i)) === false) { $zip->close(); echo json_encode(['error' => 'Entpacken fehlgeschlagen: ' . $rel]); exit; }
}
$zip->close();
$anzahl = 0;
$startNeu = false;
foreach ($plan as $rel => $_) {
    $ziel = __DIR__ . '/' . $rel; if (!is_dir(dirname($ziel))) mkdir(dirname($ziel), 0755, true);
    // Laufende Startdatei nicht ueberschreiben (Windows/Bash lesen sie waehrend des Laufs nach): geaendert -> <name>.neu,
    // die Startdatei tauscht sie beim naechsten Start selbst aus
    if (preg_match('#^start\.(bat|command|sh)$#', $rel)) {
        if (is_file($ziel) && sha1_file($ziel) === sha1_file($zw . '/' . $rel)) continue;
        if (@copy($zw . '/' . $rel, $ziel . '.neu')) { $anzahl++; $startNeu = true; }
        continue;
    }
    if (@copy($zw . '/' . $rel, $ziel)) $anzahl++;
}
@unlink($tmp);
// Zwischenordner wegraeumen
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($zw, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $f) $f->isDir() ? @rmdir($f) : @unlink($f);
@rmdir($zw);
$jetzt = trim((string) @file_get_contents(__DIR__ . '/VERSION'));
echo json_encode(['ok' => true, 'version' => $jetzt, 'dateien' => $anzahl, 'hinweis' => $startNeu ? 'Startdatei geändert – ValueLens einmal beenden und neu starten.' : 'Seite neu laden.']);
