<?php
// Taeglicher Lauf (Bewertungs-Meldungen, mitlaufende Alarme an der Fair-Value-Linie, Fitness-Speicher) als eigener
// Hintergrund-Prozess - der eingebaute PHP-Server bearbeitet nur eine Anfrage gleichzeitig und bliebe sonst haengen.
// Die Erweiterungen und Zertifikate des laufenden Servers werden mitgegeben (sie stehen nur in den Startparametern).
require_once __DIR__ . '/session-init.php';
session_write_close();
header('Content-Type: application/json; charset=utf-8');
$log = __DIR__ . '/data/nachtlauf.log';
$stand = __DIR__ . '/data/nachtlauf-stand.txt';
if (($_GET['nur'] ?? '') === 'stand') { echo json_encode(['letzter' => @file_get_contents($stand) ?: null, 'log' => @file_get_contents($log) ?: '']); exit; }
if (!hash_equals($_SESSION['csrfToken'], (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''))) { http_response_code(403); echo json_encode(['error' => 'csrf']); exit; }
$opt = [];
foreach (['extension_dir', 'curl.cainfo', 'openssl.cafile'] as $k) { $v = ini_get($k); if ($v) $opt[] = '-d ' . escapeshellarg($k . '=' . $v); }
foreach (['curl', 'openssl', 'mbstring'] as $e) if (extension_loaded($e) && !in_array($e, ['mbstring'], true)) $opt[] = '-d extension=' . $e;
if (extension_loaded('mbstring')) $opt[] = '-d extension=mbstring';
$befehl = escapeshellarg(PHP_BINARY) . ' ' . implode(' ', $opt) . ' ' . escapeshellarg(__DIR__ . '/bewertung-signale.php') . ' run';
file_put_contents($stand, date('Y-m-d H:i'));
if (stripos(PHP_OS_FAMILY, 'Windows') === 0) pclose(popen('start "" /B ' . $befehl . ' > ' . escapeshellarg($log) . ' 2>&1', 'r'));
else exec($befehl . ' > ' . escapeshellarg($log) . ' 2>&1 &');
echo json_encode(['ok' => true, 'gestartet' => date('Y-m-d H:i')]);
