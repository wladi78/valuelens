<?php
// Telegram-Benachrichtigungen (Nutzerwunsch vom 24.09.2026: Handelssignale fuer das Options- und
// das Trading-Depot aufs Handy). Genutzt von etf-signale.php und swing-signale.php (Cronjobs) und
// telegram-api.php (Einrichtung im Admin-Bereich).
//
// Zugang: ein eigener Bot, angelegt ueber @BotFather. Token und Chat-ID liegen ausschliesslich in
// data/telegram.json (Rechte 600) - nie im Code, nie in Ausgaben. Der Token steckt in der URL der
// Bot-API; deshalb geben die Funktionen hier bei Fehlern nur Telegrams eigene Beschreibung zurueck,
// niemals die URL.

// Getrennte Bots je Kanal (Nutzerwunsch vom 25.09.2026: "getrennte Telegram-Bots", "TOP3 getrennten
// Telegram-Kanal"): 'optionen' (Long-Call-Signale), 'swing' (Aktien-Swing) und 'top3' (TOP3-
// Wochensignale) - je eine eigene Datei data/telegram_<kanal>.json. Ohne Kanal: die alte, gemeinsame
// Datei data/telegram.json. Sie wird bewusst KEINEM Kanal automatisch zugeordnet: Am 25.09.2026 hat
// der Nutzer dort den TOP3-Bot ueber den "Long Call"-Bot gespeichert - eine feste Zuordnung haette
// die Signale in den falschen Bot geschickt. Nach dem Livegang jeden Bot einmal je Kanal eintragen.
// 'kursalarm' (seit 04.10.2026): Kursalarme aus check-alerts.php statt E-Mail.
// 'analysen' (seit 17.10.2026, vorher 'ema' vom 16.10. - EMA-Strategie verworfen, Bot umgewidmet): KI-Aktienanalysen
// fuer alle Depots (ki-analyse-drive-sync.php, Kaufzonen-Uebersicht). Konfiguration data/telegram_analysen.json.
// 'ausbruch' (seit 08.10.2026, Nutzerwunsch: "fuer Ruecksetzer anderen Telegram-Bot, damit man es besser
// unterscheiden kann"): Swing-Ausbruch bekommt einen eigenen Bot, 'swing' bleibt der Swing-Ruecksetzer.
// Solange der Ausbruch-Bot nicht eingetragen ist, gehen seine Nachrichten weiter an den Swing-Bot.
// 'ict' (seit 30.10.2026): ICT-Swing (I2, 3 Monate Test); ohne eigenen Bot an den Swing-Bot.
// 'levermann' (seit 01.11.2026): Levermann-US, monatlich (levermann-signale.php); ohne eigenen Bot an den Swing-Bot.
// 'dividenden' (seit 09.10.2026, statt iCal-Abo wie bei Parqet): dividenden-telegram.php, taeglich 08:00.
// 'waechter' (seit 10.10.2026): capolio-waechter.php, stuendlich - meldet ausgefallene Datenquellen/Jobs.
// 'buchungen', 'sicherheit', 'depot' (seit 10.10.2026, statt E-Mails): neue Buchungen aus IBKR/EA/Drive, Login- und
// Kontomeldungen, Wochenbericht/Tagesbewegungen/KO-Abstand. Ohne eigenen Bot springt der Waechter-Bot ein.
const TELEGRAM_CHANNELS = ['kursalarm' => 'Kursalarme', 'analysen' => 'Bewertungs-Meldungen (unterbewertete Titel)', 'waechter' => 'Allgemein (Ersatz, wenn ein Kanal fehlt)'];

// Ersatz-Bot je Kanal (16.11.2026 an einer Stelle zusammengefasst): ohne eigenen Bot gehen die Nachrichten hierhin -
// levermann-signale.php, swing-signale.php ($tgKanal) und capolio_melden() (Waechter) machen genau das. Die
// Einrichtungsseite zeigt es an, statt "nicht eingerichtet" zu melden.
const TELEGRAM_ERSATZ = ['ausbruch' => 'swing', 'ict' => 'swing', 'levermann' => 'swing',
    'buchungen' => 'waechter', 'sicherheit' => 'waechter', 'depot' => 'waechter'];
// Kanal, ueber den tatsaechlich gesendet wird (eigener Bot, sonst Ersatz), oder null.
function telegram_wirksamer_kanal($dataDir, $channel) {
    if (telegram_config($dataDir, $channel)) return $channel;
    $e = TELEGRAM_ERSATZ[$channel] ?? null;
    return ($e && telegram_config($dataDir, $e)) ? $e : null;
}

function telegram_config_file($dataDir, $channel = null) {
    return $dataDir . '/telegram' . ($channel && isset(TELEGRAM_CHANNELS[$channel]) ? '_' . $channel : '') . '.json';
}

function telegram_config($dataDir, $channel = null) {
    $c = json_decode(@file_get_contents(telegram_config_file($dataDir, $channel)) ?: '', true);
    return (is_array($c) && !empty($c['botToken']) && !empty($c['chatId'])) ? $c : null;
}

function telegram_valid_token($t) { return (bool) preg_match('/^\d{5,}:[A-Za-z0-9_-]{30,}$/', (string) $t); }
function telegram_valid_chat_id($c) { return (bool) preg_match('/^-?\d{3,}$/', (string) $c); }

function telegram_save_config($dataDir, $botToken, $chatId, $channel = null) {
    $file = telegram_config_file($dataDir, $channel);
    $ok = file_put_contents($file, json_encode(['botToken' => $botToken, 'chatId' => (string) $chatId, 'savedAt' => date('c')]), LOCK_EX) !== false;
    if ($ok) @chmod($file, 0600);
    return $ok;
}

// Nachrichten-Text fuer parse_mode=HTML absichern (Firmennamen wie "AT&T" oder "<Name>").
function telegram_escape($s) { return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

// Eine HTTP-Anfrage an die Bot-API. $http ist fuer Tests austauschbar: fn($url, $postFields|null)
// liefert den Antwort-Text oder null.
function telegram_api_call($token, $method, $params = null, $http = null) {
    $url = 'https://api.telegram.org/bot' . $token . '/' . $method;
    if ($http) {
        $body = $http($url, $params);
    } else {
        $ch = curl_init($url);
        $opts = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_CONNECTTIMEOUT => 8];
        if ($params !== null) { $opts[CURLOPT_POST] = true; $opts[CURLOPT_POSTFIELDS] = http_build_query($params); }
        curl_setopt_array($ch, $opts);
        $body = curl_exec($ch);
        curl_close($ch);
    }
    $j = json_decode($body ?: '', true);
    if (!is_array($j)) return ['ok' => false, 'error' => 'Telegram nicht erreichbar'];
    if (empty($j['ok'])) return ['ok' => false, 'error' => (string) ($j['description'] ?? 'unbekannter Fehler'), 'migrate' => $j['parameters']['migrate_to_chat_id'] ?? null];
    return ['ok' => true, 'result' => $j['result'] ?? null];
}

// Nachricht an den eingerichteten Chat. Telegram begrenzt eine Nachricht auf 4096 Zeichen - laengere
// werden an Zeilengrenzen aufgeteilt, statt abgeschnitten zu werden.
// Trennlinie zwischen Abschnitten (Nutzerwunsch vom 11.10.2026: "so eine Trennung bei allen einfuegen"). Zentral hier, damit
// jeder Bot gleich aussieht: jede Leerzeile zwischen zwei Abschnitten wird zur Linie. Kurz genug fuer die Handybreite.
const TELEGRAM_TRENNER = '──────────────────';
function telegram_mit_trennern($text) {
    return preg_replace("/\n[ \t]*\n(?:[ \t]*\n)*/u", "\n" . TELEGRAM_TRENNER . "\n", trim((string) $text));
}

function telegram_send($dataDir, $text, $http = null, $channel = null) {
    $c = telegram_config($dataDir, $channel);
    if (!$c) return ['ok' => false, 'error' => 'not_configured'];
    $text = telegram_mit_trennern($text);
    $teile = [];
    $aktuell = '';
    foreach (explode("\n", $text) as $zeile) {
        if (strlen($aktuell) + strlen($zeile) + 1 > 3900 && $aktuell !== '') { $teile[] = $aktuell; $aktuell = ''; }
        $aktuell .= ($aktuell === '' ? '' : "\n") . $zeile;
    }
    if ($aktuell !== '') $teile[] = $aktuell;
    foreach ($teile as $teil) {
        $r = telegram_api_call($c['botToken'], 'sendMessage', [
            'chat_id' => $c['chatId'], 'text' => $teil, 'parse_mode' => 'HTML', 'disable_web_page_preview' => 'true',
        ], $http);
        // Gruppe zur Supergruppe geworden (neue Chat-ID; bei Kamikaze fiel so 03.-16.10.2026 alles aus):
        // neue ID speichern und denselben Teil einmal erneut schicken.
        if (!$r['ok'] && !empty($r['migrate']) && !$http) {
            telegram_save_config($dataDir, $c['botToken'], $r['migrate'], $channel);
            $c['chatId'] = (string) $r['migrate'];
            $r = telegram_api_call($c['botToken'], 'sendMessage', [
                'chat_id' => $c['chatId'], 'text' => $teil, 'parse_mode' => 'HTML', 'disable_web_page_preview' => 'true',
            ], $http);
        }
        if (!$r['ok']) return $r;
    }
    return ['ok' => true, 'parts' => count($teile)];
}

// Chat-ID ermitteln: der Nutzer schreibt seinem Bot eine beliebige Nachricht (z.B. /start), danach
// steht der Chat in getUpdates. Erspart das Heraussuchen der Zahl von Hand.
function telegram_find_chat_id($token, $http = null) {
    $r = telegram_api_call($token, 'getUpdates', null, $http);
    if (!$r['ok']) return $r;
    foreach (array_reverse($r['result'] ?? []) as $u) {
        $chat = $u['message']['chat'] ?? $u['channel_post']['chat'] ?? null;
        if ($chat && isset($chat['id'])) {
            return ['ok' => true, 'chatId' => (string) $chat['id'], 'name' => trim(($chat['first_name'] ?? '') . ' ' . ($chat['last_name'] ?? '')) ?: ($chat['title'] ?? '')];
        }
    }
    return ['ok' => false, 'error' => 'Keine Nachricht an den Bot gefunden - bitte dem Bot zuerst in Telegram /start schreiben.'];
}

// ================= Telegram statt E-Mail (Nutzerwunsch vom 10.10.2026: "E-Mails ausmachen") =================
// Meldungen an den Admin gehen per Telegram statt per Mail. Ist der Bot des Kanals noch nicht
// eingerichtet, springt der Waechter-Bot ein; klappt auch das nicht, bleibt es bei der Mail - eine Meldung
// darf nie ganz verloren gehen. Mails an Familienkonten bleiben Mails (die haben keinen Bot).
// Passwort-Ruecksetz-Links laufen bewusst NICHT hierueber (forgot-password.php): der Link muss an die
// hinterlegte Adresse gehen, sonst waere er kein Nachweis, dass das Postfach dem Konto gehoert.

// Telegram-Nachricht an den Kanal, ersatzweise an den Waechter-Bot. Gibt true zurueck, wenn sie raus ist.
function capolio_melden($dataDir, $kanal, $html) {
    foreach (array_unique([$kanal, 'waechter']) as $k) {
        if (!telegram_config($dataDir, $k)) continue;
        $r = telegram_send($dataDir, $html, null, $k);
        if (!empty($r['ok'])) return true;
    }
    return false;
}

// E-Mail-Adressen der Admin-Konten (users.json, "isAdmin": true).
function capolio_admin_mails($dataDir) {
    global $ALERT_EMAIL;   // login.php/account-api.php/admin-api.php: Sicherheits-Adresse des Admins
    $u = json_decode(@file_get_contents($dataDir . '/users.json') ?: '', true);
    $m = !empty($ALERT_EMAIL) ? [strtolower(trim($ALERT_EMAIL))] : [];
    foreach (is_array($u) ? $u : [] as $x) if (!empty($x['isAdmin']) && !empty($x['email'])) $m[] = strtolower(trim($x['email']));
    return $m;
}

// Vor jedem mail()-Aufruf: Admin-Empfaenger werden per Telegram benachrichtigt und aus der Liste genommen.
// Rueckgabe: die Empfaenger, die weiterhin eine Mail bekommen sollen ('' = keine Mail mehr noetig).
function capolio_mail_umleiten($dataDir, $to, $betreff, $text, $kanal) {
    $alle = array_values(array_filter(array_map('trim', explode(',', (string) $to))));
    $admins = capolio_admin_mails($dataDir);
    $anAdmin = array_values(array_filter($alle, fn($a) => in_array(strtolower($a), $admins, true)));
    if (!$anAdmin) return implode(', ', $alle);
    // Nur ein kurzer Hinweis (Nutzerwunsch vom 11.10.2026: "Nachrichten nicht komplett weitergeben, nur Hinweis, dass
    // abgerufen wurde") - Betreff plus erste Textzeile, die Einzelheiten stehen in Capolio.
    $ersteZeile = trim((string) strtok(trim($text), "\n"));
    $ersteZeile = rtrim(mb_strlen($ersteZeile) > 140 ? mb_substr($ersteZeile, 0, 137) . '…' : $ersteZeile, ':');
    $html = '<b>' . telegram_escape(preg_replace('/^Capolio:\s*/', '', $betreff)) . '</b>' . ($ersteZeile !== '' ? "\n" . telegram_escape($ersteZeile) : '');
    if (!capolio_melden($dataDir, $kanal, $html)) return implode(', ', $alle);
    return implode(', ', array_diff($alle, $anAdmin));
}

// ================= Kurze Fehlerinfo bei Abrufen (Nutzerwunsch vom 10.10.2026: "kurze Info, wenn Abruf Fehler gemacht hat") =================
// Ein Abruf (ActivTrades, Traders Place, ...) meldet seinen Fehler kurz an den Waechter-Bot - derselbe Fehler hoechstens
// alle 6 Stunden, damit ein 15-Minuten-Cron nicht dauernd schreibt. Laeuft der Abruf danach wieder durch, kommt einmal
// "wieder in Ordnung". Zustand in data/abruf_fehler_state.json.
function capolio_abruf_fehler($dataDir, $quelle, $text) {
    $f = $dataDir . '/abruf_fehler_state.json';
    $s = json_decode(@file_get_contents($f) ?: '', true) ?: [];
    $alt = $s[$quelle] ?? null;
    if ($alt && $alt['text'] === $text && time() - $alt['gemeldet'] < 6 * 3600) return;
    capolio_melden($dataDir, 'waechter', '⚠️ <b>Abruf ' . telegram_escape($quelle) . ' fehlgeschlagen</b>' . "\n" . telegram_escape($text));
    $s[$quelle] = ['text' => $text, 'gemeldet' => time(), 'seit' => $alt['seit'] ?? time()];
    @file_put_contents($f, json_encode($s, JSON_UNESCAPED_UNICODE), LOCK_EX);
}
function capolio_abruf_ok($dataDir, $quelle) {
    $f = $dataDir . '/abruf_fehler_state.json';
    $s = json_decode(@file_get_contents($f) ?: '', true) ?: [];
    if (!isset($s[$quelle])) return;
    capolio_melden($dataDir, 'waechter', '✅ Abruf ' . telegram_escape($quelle) . ' läuft wieder.');
    unset($s[$quelle]);
    @file_put_contents($f, json_encode($s, JSON_UNESCAPED_UNICODE), LOCK_EX);
}
