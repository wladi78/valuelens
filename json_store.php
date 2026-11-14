<?php
// Gemeinsame Hilfsfunktionen fuer robustes Lesen/Schreiben der JSON-Statusdateien
// (pending_logins.json, trusted_devices.json, login_attempts.json).
//
// Vorher hat jede Datei einfach mit file_get_contents() gelesen und mit
// file_put_contents() ueberschrieben - ohne jede Sperre. Wenn zwei Requests
// gleichzeitig laufen (z.B. der 3-Sekunden-Poll aus check-approval.php und
// zeitgleich eine Freigabe ueber approve-login.php), konnte der eine Schreib-
// vorgang den anderen ueberschreiben ("lost update") oder eine Datei mitten im
// Schreiben lesen. Ergebnis: ein Geraet "vergisst", dass es schon freigegeben
// wurde, eine Freigabe geht verloren, o.ae.
//
// jsonStoreUpdate() oeffnet die Datei mit einer exklusiven Sperre (flock),
// liest den aktuellen Stand, laesst den Aufrufer die Aenderung vornehmen und
// schreibt danach in derselben Sperre zurueck. Dadurch koennen zwei Prozesse
// nie gleichzeitig denselben Lese-Aendern-Schreiben-Zyklus durchlaufen.

// ---- Familienkonten (Nutzerwunsch vom 25.09.2026) ----
// Neben dem Admin gibt es Konten fuer Familienmitglieder, die ihre EIGENEN Depots anlegen und
// verwalten, komplett getrennt vom Admin. Alles, was auf den Admin zugeschnitten ist (IBKR- und
// ActivTrades-Abrufe, Google-Drive-PDFs, Signale, RSL26/TOP3/Swing, Telegram), darf deshalb nur
// Depots des Admins lesen oder beschreiben. Wichtig, weil einige Abrufe ihr Zieldepot ueber den
// NAMEN finden ("Trading", "RSL26", "Herakles" ...) - ein Familienmitglied kann seine Depots
// beliebig benennen, auch genauso.
function capolioUsers($dataDir) {
    static $cache = [];
    if (!isset($cache[$dataDir])) $cache[$dataDir] = jsonStoreRead($dataDir . '/users.json');
    return $cache[$dataDir];
}
function capolioIsAdmin($dataDir, $username) {
    $u = capolioUsers($dataDir);
    return $username !== null && $username !== '' && !empty($u[$username]['isAdmin']);
}
// Depot-Schluessel (Dateiname depot_<schluessel>.json), auf die ein Admin Zugriff hat.
function capolioAdminDepotKeys($dataDir) {
    $keys = [];
    $users = capolioUsers($dataDir);
    foreach ($users as $u) {
        if (!empty($u['isAdmin'])) $keys = array_merge($keys, $u['allowedDepots'] ?? []);
    }
    // Depots des Vorfuehr-Kontos "demo" (erfundene Buchungen, tools/demo-aufbauen.php) nie mitzaehlen, auch wenn der Admin
    // sie zum Ansehen in seinen allowedDepots hat. Am 04.11.2026 zeigte die Signale-Seite sonst SPY-/QQQ-Calls aus
    // "IBKR Konto 3" als eigene offene Calls (das Demo-Depot hatte die meisten Optionsgeschaefte).
    $demo = array_flip($users['demo']['allowedDepots'] ?? []);
    return array_values(array_filter(array_unique($keys), fn($k) => !isset($demo[$k]) && strpos((string) $k, 'demo') !== 0));
}
function capolioDepotKeyOfFile($file) {
    return preg_replace('/^depot_|\.json$/', '', basename($file));
}
// Zieldepot eines IBKR-Kontos, das ein Nutzer selbst verbunden hat (ibkr-connect.php): Eintrag in
// data/ibkr_accounts.json mit "depotKey" und "owner". Nur, wenn das Depot dem Eigentuemer (noch)
// gehoert - sonst null. Die vom Admin von Hand eingetragenen Konten laufen weiter ueber
// "depotLabel" (findDepotFileByLabel in den Abruf-Skripten, nur unter den Admin-Depots).
function capolioOwnedDepotFile($dataDir, $owner, $depotKey) {
    if (!is_string($depotKey) || !preg_match('/^[a-zA-Z0-9_\-]{1,40}$/', $depotKey)) return null;
    $u = capolioUsers($dataDir);
    if (!isset($u[$owner]) || !in_array($depotKey, $u[$owner]['allowedDepots'] ?? [], true)) return null;
    $file = $dataDir . '/depot_' . $depotKey . '.json';
    return is_file($file) ? $file : null;
}
// Zieldepot fuer einen Eintrag aus data/ibkr_accounts.json (beide Arten, siehe oben).
function capolioIbkrAccountDepotFile($dataDir, $account, callable $findByLabel) {
    if (!empty($account['depotKey'])) return capolioOwnedDepotFile($dataDir, (string) ($account['owner'] ?? ''), $account['depotKey']);
    return $findByLabel($dataDir, $account['depotLabel'] ?? ($account['name'] ?? ''));
}

// Depot-Dateien des Admins (ohne Sicherungskopien).
function capolioAdminDepotFiles($dataDir) {
    $erlaubt = array_flip(capolioAdminDepotKeys($dataDir));
    return array_values(array_filter(glob($dataDir . '/depot_*.json') ?: [], fn($f) =>
        strpos(basename($f), '_backup') === false && isset($erlaubt[capolioDepotKeyOfFile($f)])));
}

function jsonStoreRead($file) {
    $fh = @fopen($file, 'c+');
    if (!$fh) { return []; }
    flock($fh, LOCK_SH);
    $raw = stream_get_contents($fh);
    flock($fh, LOCK_UN);
    fclose($fh);
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

/**
 * @param string $file
 * @param callable $mutate  bekommt das aktuelle Array (per Referenz) und darf
 *                          es veraendern. Optional: ein neues Array zurueckgeben,
 *                          das dann anstelle des veraenderten Arrays gespeichert wird.
 * @return array            der tatsaechlich gespeicherte Stand
 */
function jsonStoreUpdate($file, callable $mutate) {
    $dir = dirname($file);
    if (!is_dir($dir)) { @mkdir($dir, 0700, true); }

    $fh = fopen($file, 'c+');
    if (!$fh) { return []; }
    if (!flock($fh, LOCK_EX)) { fclose($fh); return []; }

    $raw = stream_get_contents($fh);
    $data = json_decode($raw, true);
    if (!is_array($data)) { $data = []; }

    $result = $mutate($data);
    $newData = is_array($result) ? $result : $data;

    ftruncate($fh, 0);
    rewind($fh);
    fwrite($fh, json_encode($newData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    fflush($fh);
    flock($fh, LOCK_UN);
    fclose($fh);

    return $newData;
}

// Robuste HTTPS-Erkennung: $_SERVER['HTTPS'] ist nicht immer gesetzt, wenn ein
// Reverse Proxy/Loadbalancer vor Apache haengt - dann faellt der "secure"-Wert
// bisher stillschweigend auf false und der Browser behandelt das Cookie etwas
// laxer als noetig. Zusaetzliche Fallbacks decken das ab.
// Feste Adresse der App fuer Links in Mails und Selbstaufrufe (gstack-Befund 2, umgesetzt 07.11.2026): nie aus dem
// Host-Header bauen - sonst landet ein Reset-Link oder der cron_secret bei einem fremden Host, falls Plesk den Capolio-Vhost
// einmal als Standard fuer unbekannte Hosts nimmt. Abweichende Adresse (Testkopie, Paket) per Konstante CAPOLIO_BASIS_URL.
function capolio_basis_url() {
    return rtrim(defined('CAPOLIO_BASIS_URL') ? CAPOLIO_BASIS_URL : 'http://127.0.0.1:8484', '/');
}

// Schutz schreibender Anfragen ohne Formular-Token (gstack-Befund 4, umgesetzt 07.11.2026): depot-store.php und
// pdf-store.php hatten nur SameSite=Lax. Das haelt fremde Seiten ab, aber nicht eine andere App derselben Domain (gleiche
// "Site"). Jetzt: Sec-Fetch-Site muss "same-origin" sein (senden alle aktuellen Browser bei fetch), Origin - falls
// vorhanden - muss die eigene Adresse sein, und das CSRF-Token im Kopf X-CSRF-Token muss stimmen, sobald es fehlt der
// Sec-Fetch-Site-Kopf (alte Browser) oder es mitgeschickt wird. Lesen (GET/HEAD) bleibt unberuehrt.
function capolio_schreibschutz_pruefen() {
    $m = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($m === 'GET' || $m === 'HEAD') return;
    $nein = function ($grund) { http_response_code(403); header('Content-Type: application/json; charset=UTF-8'); echo json_encode(['error' => 'csrf', 'grund' => $grund]); exit; };
    $site = $_SERVER['HTTP_SEC_FETCH_SITE'] ?? '';
    if ($site !== '' && $site !== 'same-origin') $nein('sec_fetch_site');
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    $erlaubt = [capolio_basis_url(), (isHttpsRequest() ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? '')];
    if ($origin !== '' && !in_array(rtrim($origin, '/'), $erlaubt, true)) $nein('origin');
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if ($token !== '' || $site === '') {
        if (empty($_SESSION['csrfToken']) || !hash_equals((string) $_SESSION['csrfToken'], (string) $token)) $nein('token');
    }
}

function isHttpsRequest() {
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') return true;
    if ((string)($_SERVER['SERVER_PORT'] ?? '') === '443') return true;
    if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') return true;
    return false;
}

// Gemeinsame, konsistente Cookie-Einstellungen (SameSite=Lax explizit gesetzt,
// statt dem browserabhaengigen Default zu vertrauen).
function setAppCookie($name, $value, $ttlSeconds) {
    setcookie($name, $value, [
        'expires' => time() + $ttlSeconds,
        'path' => '/',
        'domain' => '',
        'secure' => isHttpsRequest(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}
function clearAppCookie($name) {
    setcookie($name, '', [
        'expires' => time() - 3600,
        'path' => '/',
        'domain' => '',
        'secure' => isHttpsRequest(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

// Das depot_device-Cookie speicherte bisher genau EIN Geraete-Token pro Browser. Problem:
// wenn sich zwei Personen denselben Browser teilen (z.B. Person A meldet sich ab, Person B meldet
// sich im selben Browser ohne Neuladen an), wird das Token beim naechsten Login ueberschrieben
// und der Vergleich "gehoert dieses Token zu diesem Benutzernamen?" schlaegt fehl - das Geraet
// gilt dann faelschlich wieder als neu, obwohl es fuer beide Nutzer schon einmal freigegeben
// wurde. Fix: das Cookie speichert eine kommagetrennte LISTE von Tokens (ein Token pro Nutzer,
// der diesen Browser je freigegeben hat), statt nur des zuletzt vergebenen.
function getDeviceTokens() {
    if (empty($_COOKIE['depot_device'])) return [];
    return array_values(array_filter(array_map('trim', explode(',', $_COOKIE['depot_device']))));
}
function addDeviceToken($newToken) {
    $tokens = getDeviceTokens();
    if (!in_array($newToken, $tokens, true)) { $tokens[] = $newToken; }
    // Auf die letzten 10 begrenzen, falls im Lauf der Zeit viele verschiedene Nutzer an
    // diesem Browser angemeldet waren - verhindert unbegrenztes Cookie-Wachstum.
    if (count($tokens) > 10) { $tokens = array_slice($tokens, -10); }
    setAppCookie('depot_device', implode(',', $tokens), 60*60*24*365);
}
// Geraete aufraeumen (seit 24.10.2026, Nutzer: Liste mit 115 Eintraegen zu lang, 101 seit ueber 30 Tagen unbenutzt):
// entfernt Eintraege, deren letzte Nutzung (lastSeen, sonst Freigabe) laenger als $tage zurueckliegt. Rueckgabe: Anzahl.
// Ein entferntes Geraet ist danach wieder "neu" - Anmeldung geht trotzdem, es kommt nur die Neu-Geraet-Meldung.
function geraeteAufraeumen($devicesFile, $tage = 90) {
    $grenze = time() - max(7, (int) $tage) * 86400;
    $weg = 0;
    jsonStoreUpdate($devicesFile, function(&$d) use ($grenze, &$weg) {
        foreach ($d as $t => $e) {
            $zuletzt = max((int) ($e['lastSeen'] ?? 0), (int) ($e['approvedAt'] ?? 0));
            if ($zuletzt && $zuletzt < $grenze) { unset($d[$t]); $weg++; }
        }
    });
    return $weg;
}
function findTrustedDeviceToken($devices, $username) {
    foreach (getDeviceTokens() as $t) {
        if (isset($devices[$t]) && ($devices[$t]['username'] ?? null) === $username) return $t;
    }
    return null;
}
