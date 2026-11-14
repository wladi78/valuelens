<?php
// Kursalarme pruefen - ruft die geoeffnete ValueLens-Seite alle 15 Minuten auf (kein Cronjob noetig).
// Meldet ausgeloeste Alarme per Telegram (Kanal Kursalarme), jeder Alarm einmal.
require_once __DIR__ . '/session-init.php';
session_write_close();
header('Content-Type: text/plain; charset=utf-8');
define('VALUELENS_LOKAL', true);
set_time_limit(120);
ob_start();
include __DIR__ . '/check-alerts.php';
echo trim((string) ob_get_clean());
