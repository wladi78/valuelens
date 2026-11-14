<?php
// Prueft alle gesetzten Kursalarme (ueber alle Depots hinweg) und verschickt
// bei Erreichen eine E-Mail. Laeuft unabhaengig vom Browser - gedacht fuer
// einen Netcup-Cronjob (CLI: "php check-alerts.php", oder per URL mit
// ?key=<Secret aus data/cron_secret.txt> falls nur URL-Aufruf moeglich ist).

// Seit 04.10.2026 (Nutzerwunsch: "E-Mail-Versand erstmal ausstellen und nach Bedarf zu jeder Aktie
// einstellen lassen, und ein Telegram-Bot"):
//  - Benachrichtigung je Aktie in depot.alertNotify[<Symbol>] = {mail: bool, telegram: bool}.
//    Ohne Eintrag: E-Mail AUS, Telegram AN (Kanal "kursalarm", nur fuer Depots eines Admins).
//  - Ein Alarm meldet sich EINMAL ('fired') und wird erst wieder scharf, wenn der Kurs
//    ALERT_REARM_PCT zurueckgelaufen ist. Vorher feuerte er nach 20 Stunden Sperre jeden Tag neu,
//    solange der Kurs unter dem Ziel blieb - am 04.10.2026 kamen so ueber 25 Mails auf einmal.
//  - Telegram: eine gesammelte Nachricht je Lauf statt einer je Alarm.
require_once __DIR__ . '/yahoo-quote-lib.php';
require_once __DIR__ . '/telegram-lib.php';
const ALERT_REARM_PCT = 2.0;

$isCli = (php_sapi_name() === 'cli') || defined('VALUELENS_LOKAL');   // ValueLens: Aufruf aus alarm-pruefen.php
$dataDir = __DIR__ . '/data';

if (!$isCli) {
    $secretFile = $dataDir . '/cron_secret.txt';
    $secret = trim(@file_get_contents($secretFile) ?: '');
    if (!$secret || !isset($_GET['key']) || !hash_equals($secret, $_GET['key'])) {
        http_response_code(403);
        echo "forbidden";
        exit;
    }
    header('Content-Type: text/plain; charset=UTF-8');
}

$usersFile = $dataDir . '/users.json';

function loadJson($file){
    $data = json_decode(@file_get_contents($file), true);
    return is_array($data) ? $data : [];
}
function saveJsonAtomic($file, $data){
    $tmp = $file . '.tmp_' . bin2hex(random_bytes(4));
    if (@file_put_contents($tmp, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) === false) {
        return false;
    }
    return @rename($tmp, $file);
}
function sendAlertMail($to, $subject, $body){
    $baseDomain = 'localhost';   // ValueLens laeuft lokal
    $headers = "From: ValueLens Kursalarm <no-reply@$baseDomain>\r\nContent-Type: text/plain; charset=UTF-8";
    $sent = @mail($to, $subject, $body, $headers);
    $log = '[' . date('Y-m-d H:i:s') . "] to=$to subject=\"$subject\" mail()=" . ($sent ? 'true' : 'false');
    @file_put_contents(__DIR__ . '/data/mail-debug.log', $log . "\n", FILE_APPEND);
}

$users = loadJson($usersFile);

$depotFiles = array_values(array_filter(glob($dataDir . '/depot_*.json') ?: [], fn($f) => strpos(basename($f), '_backup') === false));
if (!$depotFiles) { echo "keine Depot-Dateien gefunden\n"; exit; }

$allSymbols = [];
$depots = [];
foreach ($depotFiles as $file) {
    $key = preg_replace('/^depot_/', '', basename($file, '.json'));
    $depot = loadJson($file);
    if (empty($depot['priceAlerts'])) continue;
    foreach ($depot['priceAlerts'] as $alert) {
        if (empty($alert['active']) || empty($alert['symbol'])) continue;
        $allSymbols[$alert['symbol']] = true;
    }
    $depots[$key] = ['file' => $file, 'depot' => $depot];
}
if (!$allSymbols) { echo "keine aktiven Kursalarme\n"; exit; }

// Kurse per Yahoo abrufen - gemeinsamer Batch-Abruf mit quote.php/daily-price-sync.php,
// siehe yahoo-quote-lib.php.
$symbols = array_keys($allSymbols);
$prices = [];
foreach (yahoo_fetch_chart_meta($symbols) as $sym => $meta) {
    $prices[$sym] = (float) $meta['regularMarketPrice'];
}

$now = time();
$triggeredCount = 0;
$telegramZeilen = [];
$mailCount = 0;

foreach ($depots as $key => $entry) {
    $depot = $entry['depot'];
    $changed = false;
    // Telegram nur fuer Depots, auf die ein Admin Zugriff hat (der Bot gehoert dem Admin).
    $adminDepot = false;
    foreach ($users as $u) {
        if (!empty($u['isAdmin']) && in_array($key, $u['allowedDepots'] ?? [], true)) { $adminDepot = true; break; }
    }

    // Empfaenger fuer dieses Depot ermitteln: entspricht der Depot-Key einem
    // Benutzernamen, ist das die eigentliche Besitzerin/der Besitzer. Sonst
    // (z.B. gemeinsam angelegtes Extra-Depot) alle mit Zugriff benachrichtigen.
    $recipients = [];
    if (isset($users[$key]['email'])) {
        $recipients[] = $users[$key]['email'];
    } else {
        foreach ($users as $u) {
            if (in_array($key, $u['allowedDepots'] ?? [], true) && !empty($u['email'])) {
                $recipients[] = $u['email'];
            }
        }
    }
    $recipients = array_values(array_unique(array_filter($recipients)));

    foreach ($depot['priceAlerts'] as &$alert) {
        if (empty($alert['active']) || empty($alert['symbol'])) continue;
        $price = $prices[$alert['symbol']] ?? null;
        if ($price === null) continue;

        $target = (float) $alert['targetPrice'];
        $below = $alert['condition'] !== 'above';
        $triggered = $below ? $price <= $target : $price >= $target;

        // Altbestand ohne 'fired': schon einmal gemeldet und noch ausgeloest -> still als gemeldet
        // markieren, sonst kaeme beim ersten Lauf nach der Umstellung noch einmal die ganze Flut.
        if (!array_key_exists('fired', $alert)) {
            $alert['fired'] = $triggered && !empty($alert['lastTriggeredAt']);
            $changed = true;
        }
        if (!$triggered) {
            $zurueck = $below ? $price > $target * (1 + ALERT_REARM_PCT / 100) : $price < $target * (1 - ALERT_REARM_PCT / 100);
            if (!empty($alert['fired']) && $zurueck) { $alert['fired'] = false; $changed = true; }
            continue;
        }
        if (!empty($alert['fired'])) continue;   // schon gemeldet, Kurs noch nicht zurueckgelaufen

        $notify = $depot['alertNotify'][$alert['symbol']] ?? [];
        $perMail = !empty($notify['mail']);                          // Standard: aus
        $perTelegram = !array_key_exists('telegram', $notify) || !empty($notify['telegram']);   // Standard: an
        $dir = $below ? 'unter' : 'ueber';
        if ($perMail) {
            $subject = 'ValueLens: Kursalarm ' . $alert['symbol'];
            $body = "Kursalarm ausgeloest:\n\n" .
                ($alert['name'] ?: $alert['symbol']) . ' (' . $alert['symbol'] . ")\n" .
                'Aktueller Kurs: ' . number_format($price, 2, ',', '.') . "\n" .
                "Alarm: Kurs $dir " . number_format($target, 2, ',', '.') . "\n\n" .
                "Depot: $key\nZeit: " . date('d.m.Y H:i:s');
            foreach ($recipients as $to) { sendAlertMail($to, $subject, $body); $mailCount++; }
        }
        if ($perTelegram && $adminDepot) {
            $telegramZeilen[$alert['symbol'] . '|' . $target] = ($below ? '🟢 ' : '🔺 ') . '<b>' . telegram_escape($alert['symbol']) . '</b> '
                . telegram_escape($alert['name'] ?: '') . "\n"
                . 'Kurs ' . number_format($price, 2, ',', '.') . " · Alarm $dir " . number_format($target, 2, ',', '.')
                . ' (' . telegram_escape($depot['label'] ?? $key) . ')';
        }
        $alert['fired'] = true;
        $alert['lastTriggeredAt'] = $now;
        $changed = true;
        $triggeredCount++;
    }
    unset($alert);

    if ($changed) {
        saveJsonAtomic($entry['file'], $depot);
    }
}

$tgInfo = '';
if ($telegramZeilen) {
    // Kanal "kursalarm" fehlt (12.11.2026) -> uebergangsweise ueber den Waechter-Bot, damit kein Alarm verloren geht
    $kanal = telegram_config($dataDir, 'kursalarm') ? 'kursalarm' : (telegram_config($dataDir, 'waechter') ? 'waechter' : null);
    if ($kanal) {
        $text = "🚨 <b>Kursalarm</b> – " . count($telegramZeilen) . ' ' . (count($telegramZeilen) === 1 ? 'Aktie' : 'Aktien') . "\n\n"
            . implode("\n\n", array_values($telegramZeilen)) . "\n\n" . date('d.m.Y H:i');
        if ($kanal === 'waechter') $text .= "
<i>(über den Wächter-Bot, bis der Kanal Kursalarme eingerichtet ist)</i>";
        $r = telegram_send($dataDir, $text, null, $kanal);
        $tgInfo = !empty($r['ok']) ? ', Telegram gesendet' : ', Telegram fehlgeschlagen';
    } else {
        $tgInfo = ', Telegram-Kanal Kursalarm nicht eingerichtet';
    }
}
echo "geprueft: " . count($symbols) . " Symbole, ausgeloest: $triggeredCount, Mails: $mailCount$tgInfo\n";
