<?php
// Gemeinsame Logik fuer dividend-database.php (liest/aktualisiert bei Bedarf) und
// sync-dividend-database.php (Cronjob, erzwingt Refresh). Holt den oeffentlichen,
// unauthentifizierten JSON-Datensatz von dripinvesting.org (~1.200 Dividendentitel,
// alle Kategorien Challenger/Contender/Champion/King in einem Rutsch) und cacht ihn
// lokal, damit nicht bei jedem Seitenaufruf neu von dort geladen werden muss.

define('DDB_SOURCE_URL', 'https://www.dripinvesting.org/wp-json/drip-investing/v1/stocks-dataset');
define('DDB_CACHE_FILE', __DIR__ . '/data/dividend-database.json');
define('DDB_CACHE_MAX_AGE', 7 * 86400); // woechentlich (Sonntags-Cron haelt das ohnehin frisch)

function ddb_map_row($r) {
    if (!isset($r['ticker'])) return null;
    $growth = $r['dgr_5y'] ?? $r['dgr_10y'] ?? $r['dgr_3y'] ?? $r['dgr_1y'] ?? null;
    return [
        'symbol' => strtoupper(trim($r['ticker'])),
        'name' => $r['company'] ?? $r['ticker'],
        'sector' => $r['sector'] ?? '',
        'industry' => $r['industry'] ?? '',
        'category' => $r['stock_type'] ?? null, // "Challenger" | "Contender" | "Champion" | "King"
        'price' => isset($r['price']) ? (float) $r['price'] : null,
        'low52' => isset($r['low_52w']) ? (float) $r['low_52w'] : null,
        'high52' => isset($r['high_52w']) ? (float) $r['high_52w'] : null,
        'annualDividend' => isset($r['annualized']) ? (float) $r['annualized'] : null,
        'growthRate' => $growth !== null ? (float) $growth : null,
        'divYield' => isset($r['div_yield']) ? (float) $r['div_yield'] : null,
        'pe' => isset($r['pe']) ? (float) $r['pe'] : null,
        'peg' => isset($r['peg']) ? (float) $r['peg'] : null,
        'fvLynch' => isset($r['fv_lynch']) ? (float) $r['fv_lynch'] : null,
    ];
}

// Holt den Datensatz frisch von dripinvesting.org, mappt ihn und schreibt den Cache.
// Gibt bei Erfolg das gemappte Array zurueck, sonst null.
function ddb_refresh() {
    if (!function_exists('curl_init')) return null;
    $ch = curl_init(DDB_SOURCE_URL);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_HTTPHEADER => ['Accept: application/json', 'User-Agent: Mozilla/5.0 (compatible; Capolio/1.0)'],
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_FOLLOWLOCATION => true,
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code !== 200 || !$body) return null;

    $json = json_decode($body, true);
    $rows = $json['dataset'] ?? null;
    if (!is_array($rows)) return null;

    $mapped = [];
    foreach ($rows as $r) {
        $m = ddb_map_row($r);
        if ($m) $mapped[] = $m;
    }
    if (!$mapped) return null;

    @mkdir(dirname(DDB_CACHE_FILE), 0755, true);
    $payload = ['updatedAt' => date('c'), 'count' => count($mapped), 'stocks' => $mapped];
    $tmp = DDB_CACHE_FILE . '.tmp_' . bin2hex(random_bytes(4));
    if (@file_put_contents($tmp, json_encode($payload, JSON_UNESCAPED_UNICODE)) !== false) {
        @rename($tmp, DDB_CACHE_FILE);
    }
    return $payload;
}

// Liest den Cache; holt automatisch frisch nach, wenn er fehlt oder zu alt ist
// (so funktioniert der erste Aufruf sofort, ohne auf den Sonntags-Cron warten zu muessen).
function ddb_get($forceRefresh = false) {
    if (!$forceRefresh && file_exists(DDB_CACHE_FILE) && (time() - filemtime(DDB_CACHE_FILE)) < DDB_CACHE_MAX_AGE) {
        $cached = json_decode(@file_get_contents(DDB_CACHE_FILE), true);
        if (is_array($cached) && !empty($cached['stocks'])) return $cached;
    }
    $fresh = ddb_refresh();
    if ($fresh) return $fresh;
    // Refresh fehlgeschlagen: lieber ein veralteter Cache als gar nichts
    if (file_exists(DDB_CACHE_FILE)) {
        $cached = json_decode(@file_get_contents(DDB_CACHE_FILE), true);
        if (is_array($cached)) return $cached;
    }
    return ['updatedAt' => null, 'count' => 0, 'stocks' => []];
}
