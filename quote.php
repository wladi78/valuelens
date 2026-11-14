<?php
require_once __DIR__ . '/session-init.php';
require_once __DIR__ . '/yahoo-quote-lib.php';
header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['user'])) {
    http_response_code(401);
    echo json_encode(['error' => 'unauthorized']);
    exit;
}
// Sitzung sofort freigeben: diese Abfrage liest nur und kann lange dauern (Yahoo/SEC). Sonst blockiert PHPs Sitzungssperre
// alle weiteren Anfragen desselben Nutzers bis zum Ende (Zonen-Chart hing 05.11.2026 rund 30 s hinter fundamentals.php).
session_write_close();

$symbols = isset($_GET['symbols']) ? explode(',', $_GET['symbols']) : [];
$symbols = array_values(array_unique(array_filter(array_map('trim', $symbols))));
$symbols = array_slice($symbols, 0, 200); // Sicherheitslimit

if (!$symbols || !function_exists('curl_multi_init')) {
    echo json_encode([]);
    exit;
}

$ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36';

// Beta einer einzelnen Position ggue. einem Vergleichsindex (Nutzerwunsch vom 12.09.2026, nach
// Sichtung der Positions-Detailseite von Simply Safe Dividends) - Kovarianz der woechentlichen
// Renditen ueber die letzten ca. 2 Jahre gegen den MSCI-World-ETF (gleicher Standard-Vergleichsindex
// wie beim "Wertentwicklung"-Vergleich auf der Uebersichtsseite), geteilt durch die Varianz der
// Index-Renditen - direkt aus bereits oeffentlich zugaenglichen Yahoo-Kursreihen, kein bezahlter
// Datenanbieter noetig. Bewusst NUR fuer GENAU EIN Symbol (auf Klick auf der Positions-Detailseite,
// nicht automatisch fuers ganze Depot) - im Unterschied zu einer vollen Korrelationsmatrix/Sharpe-
// Ratio uebers gesamte Depot (recherchiert, aber als zu teuer verworfen) ist das fuer eine einzelne
// Position bei Bedarf unkritisch in Rechenzeit/Anfragen. Eigener fruehzeitiger Exit, bevor der
// normale (fuer diesen Zweck unnoetige) 5-Tage-Meta-Abruf unten laeuft.
if (isset($_GET['beta']) && $_GET['beta'] === '1' && count($symbols) === 1) {
    $benchmark = $_GET['benchmark'] ?? 'EUNL.DE';
    echo json_encode(['beta' => quote_compute_beta($symbols[0], $benchmark, $ua)]);
    exit;
}
// Kerzen (Eroeffnung/Hoch/Tief/Schluss) fuer den Zonen-Chart der KI-Analysen (Nutzerwunsch 05.11.2026: KI-Zonen auf
// Monatsbasis wie in TradingView, umgesetzt mit Lightweight Charts direkt in Capolio). Nur EIN Symbol, Intervall und
// Zeitraum aus einer festen Liste.
if (isset($_GET['ohlc']) && $_GET['ohlc'] === '1' && count($symbols) === 1) {
    $iv = in_array($_GET['interval'] ?? '', ['1mo', '1wk', '1d'], true) ? $_GET['interval'] : '1mo';
    $rg = in_array($_GET['range'] ?? '', ['max', '10y', '5y', '2y', '1y'], true) ? $_GET['range'] : 'max';
    echo json_encode(quote_fetch_ohlc($symbols[0], $ua, $rg, $iv));
    exit;
}
function quote_fetch_ohlc($symbol, $ua, $range, $interval) {
    // Fester Zeitraum statt range=max: Yahoo duennt range=max bei Monatskerzen auf jede dritte Kerze aus (MO: 169 statt
    // 504 Kerzen) und liefert dann auch nur einen Teil der Dividenden - live geprueft 05.11.2026.
    $jahre = ['max' => 60, '10y' => 10, '5y' => 5, '2y' => 2, '1y' => 1][$range] ?? 60;
    $von = $range === 'max' ? 0 : time() - $jahre * 365 * 86400;
    $url = 'https://query1.finance.yahoo.com/v8/finance/chart/' . rawurlencode($symbol) . '?interval=' . rawurlencode($interval) . '&period1=' . $von . '&period2=' . time() . '&events=div';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_CONNECTTIMEOUT => 6,
        CURLOPT_USERAGENT => $ua, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_FOLLOWLOCATION => true,
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code !== 200 || !$body) return ['error' => 'Keine Kursdaten (HTTP ' . $code . ')'];
    $r = json_decode($body, true)['chart']['result'][0] ?? null;
    $ts = $r['timestamp'] ?? null; $q = $r['indicators']['quote'][0] ?? null;
    $adj = $r['indicators']['adjclose'][0]['adjclose'] ?? [];  // um Dividenden und Abspaltungen bereinigt -> Gesamtrendite
    if (!$ts || !$q) return ['error' => 'Keine Kursdaten'];
    $off = (int) ($r['meta']['gmtoffset'] ?? 0);
    $kerzen = [];
    foreach ($ts as $i => $t) {
        $o = $q['open'][$i] ?? null; $h = $q['high'][$i] ?? null; $l = $q['low'][$i] ?? null; $c = $q['close'][$i] ?? null;
        if ($o === null || $h === null || $l === null || $c === null) continue;
        // Yahoo verankert Balken auf Mitternacht der Boersenzeit - mit dem Offset der Boerse auf den richtigen Kalendertag
        $tag = gmdate('Y-m-d', $t + $off);
        if ($interval === '1mo') $tag = substr($tag, 0, 7) . '-01';
        $kerzen[$tag] = [$tag, round($o, 4), round($h, 4), round($l, 4), round($c, 4), (int) ($q['volume'][$i] ?? 0), isset($adj[$i]) ? round((float) $adj[$i], 4) : null];
    }
    // Dividenden je Zahltag (Ex-Tag laut Yahoo) in der Kurswaehrung, fuer Dividendenverlauf und 3 %-Rendite-Kurs
    $divs = [];
    foreach (($r['events']['dividends'] ?? []) as $dv) {
        if (!isset($dv['date'], $dv['amount'])) continue;
        $divs[] = [gmdate('Y-m-d', (int) $dv['date'] + $off), round((float) $dv['amount'], 6)];
    }
    usort($divs, fn($x, $y) => strcmp($x[0], $y[0]));
    return ['symbol' => $symbol, 'currency' => $r['meta']['currency'] ?? null, 'interval' => $interval, 'kerzen' => array_values($kerzen), 'dividenden' => $divs];
}
function quote_fetch_closes_by_ts($symbol, $ua, $range = '2y', $interval = '1wk') {
    $url = 'https://query1.finance.yahoo.com/v8/finance/chart/' . rawurlencode($symbol) . '?interval=' . rawurlencode($interval) . '&range=' . rawurlencode($range);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 12, CURLOPT_CONNECTTIMEOUT => 6,
        CURLOPT_USERAGENT => $ua, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_FOLLOWLOCATION => true,
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code !== 200 || !$body) return null;
    $json = json_decode($body, true);
    $ts = $json['chart']['result'][0]['timestamp'] ?? null;
    $closes = $json['chart']['result'][0]['indicators']['quote'][0]['close'] ?? null;
    if (!$ts || !$closes) return null;
    $out = [];
    foreach ($ts as $i => $t) {
        if (!isset($closes[$i]) || $closes[$i] === null) continue;
        // Nach Kalenderwoche bucketn statt nach rohem Unix-Timestamp: Yahoo verankert woechentliche
        // Balken bei verschiedenen Boersen auf Mitternacht LOKALER Zeit des jeweiligen Handelsplatzes,
        // nicht UTC - live geprueft: AAPL (US) montags 04:00 UTC (=Mitternacht ET), EUNL.DE (Xetra)
        // dagegen SONNTAGS 22:00 UTC (=Mitternacht CEST Montag). Ein direkter Timestamp- oder sogar
        // ein naiver UTC-Kalenderwochen-Abgleich wuerde EUNL.DE dadurch faelschlich eine Woche FRUEHER
        // als AAPL einordnen (Sonntag gehoert zur VORHERIGEN ISO-Woche) - das hat beim ersten Test
        // tatsaechlich zu voelligem Unsinn gefuehrt (Beta nahe 0 fuer AAPL/TSLA). Ein pauschaler
        // +12h-Versatz VOR der Wochenberechnung gleicht das robust aus, ohne die Boersen-Zeitzone
        // jedes einzelnen Symbols kennen zu muessen (deckt die ueblichen Handelsplaetze locker ab).
        $week = gmdate('o-W', $t + 12*3600);
        $out[$week] = (float) $closes[$i];
    }
    return $out ?: null;
}
function quote_compute_beta($symbol, $benchmark, $ua) {
    $a = quote_fetch_closes_by_ts($symbol, $ua);
    $b = quote_fetch_closes_by_ts($benchmark, $ua);
    if (!$a || !$b) return null;
    $commonTs = array_values(array_intersect(array_keys($a), array_keys($b)));
    sort($commonTs);
    if (count($commonTs) < 20) return null; // zu wenig gemeinsame Datenpunkte fuer eine sinnvolle Beta
    $aReturns = []; $bReturns = [];
    for ($i = 1; $i < count($commonTs); $i++) {
        $pa0 = $a[$commonTs[$i-1]]; $pa1 = $a[$commonTs[$i]];
        $pb0 = $b[$commonTs[$i-1]]; $pb1 = $b[$commonTs[$i]];
        if ($pa0 > 0 && $pb0 > 0) { $aReturns[] = ($pa1 - $pa0) / $pa0; $bReturns[] = ($pb1 - $pb0) / $pb0; }
    }
    $n = count($aReturns);
    if ($n < 15) return null;
    $meanA = array_sum($aReturns) / $n;
    $meanB = array_sum($bReturns) / $n;
    $cov = 0; $varB = 0;
    for ($i = 0; $i < $n; $i++) {
        $cov += ($aReturns[$i] - $meanA) * ($bReturns[$i] - $meanB);
        $varB += ($bReturns[$i] - $meanB) ** 2;
    }
    if ($varB == 0) return null;
    return round(($cov / $n) / ($varB / $n), 2);
}

// Gemeinsamer Batch-Abruf mit daily-price-sync.php/check-alerts.php, siehe yahoo-quote-lib.php.
$metaBySymbol = yahoo_fetch_chart_meta($symbols);
$out = [];
foreach ($metaBySymbol as $sym => $meta) {
    $out[$sym] = [
        'symbol' => $sym,
        'regularMarketPrice' => $meta['regularMarketPrice'],
        'currency' => $meta['currency'] ?? null,
        'fiftyTwoWeekHigh' => $meta['fiftyTwoWeekHigh'] ?? null,
        'fiftyTwoWeekLow' => $meta['fiftyTwoWeekLow'] ?? null,
        'previousClose' => $meta['chartPreviousClose'] ?? ($meta['previousClose'] ?? null),
        'shortName' => $meta['shortName'] ?? null,
    ];
}

// Generischer zweiter, schlanker Batch: holt fuer die bereits erfolgreichen Symbole eine weitere
// Kursreihe (anderer range/interval) und schreibt "aktueller Kurs vs. erster Schlusskurs der
// Reihe" als Prozent-Feld in $out. Eigener Request noetig, weil der obige range=5d-Abruf nur den
// aktuellen Kurs liefert und "chartPreviousClose" bei anderen Ranges nicht mehr den gestrigen
// Schlusskurs (Tagesaenderung, wird an anderer Stelle im Frontend genutzt) bedeuten wuerde.
// Genutzt fuer die 3-Monats-Kurswarnung (siehe priceDropWarning im Frontend) und die
// Jahresperformance im "Markt heute"/Sektoren-Ueberblick der Bewertungsseite - jeweils opt-in
// per Query-Flag, damit die vielen anderen (oft kleinen) quote.php-Aufrufe nicht verlangsamt
// werden.
function quote_fetch_change_pct(&$out, $ua, $range, $interval, $field) {
    if (!$out) return;
    $mh2 = curl_multi_init();
    $handles2 = [];
    foreach (array_keys($out) as $sym) {
        $url = 'https://query1.finance.yahoo.com/v8/finance/chart/' . rawurlencode($sym) . '?interval=' . rawurlencode($interval) . '&range=' . rawurlencode($range);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_USERAGENT => $ua,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_FOLLOWLOCATION => true,
        ]);
        curl_multi_add_handle($mh2, $ch);
        $handles2[$sym] = $ch;
    }
    $running2 = null;
    do {
        $status2 = curl_multi_exec($mh2, $running2);
        if ($running2) { curl_multi_select($mh2, 1.0); }
    } while ($running2 > 0 && $status2 === CURLM_OK);

    foreach ($handles2 as $sym => $ch) {
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $body = curl_multi_getcontent($ch);
        curl_multi_remove_handle($mh2, $ch);
        curl_close($ch);
        if ($code !== 200 || !$body) continue;
        $json = json_decode($body, true);
        $closes = $json['chart']['result'][0]['indicators']['quote'][0]['close'] ?? [];
        $firstClose = null;
        foreach ($closes as $c) { if ($c !== null) { $firstClose = (float) $c; break; } }
        $currentPrice = $out[$sym]['regularMarketPrice'] ?? null;
        if ($firstClose && $firstClose > 0 && $currentPrice !== null) {
            $out[$sym][$field] = round(($currentPrice - $firstClose) / $firstClose * 100, 2);
        }
    }
    curl_multi_close($mh2);
}

if (isset($_GET['change3mo']) && $_GET['change3mo'] === '1') {
    quote_fetch_change_pct($out, $ua, '3mo', '1wk', 'change3moPct');
}
if (isset($_GET['changeYtd']) && $_GET['changeYtd'] === '1') {
    quote_fetch_change_pct($out, $ua, 'ytd', '1d', 'changeYtdPct');
}

// Fallback-Kursquellen fuer Symbole, die Yahoo NICHT gefunden hat (Nutzerwunsch vom 09.09.2026:
// "ich habe folgende API Keys, koennen wir die benutzen?" - eigene kostenlose Anbieter-Keys fuer
// Finnhub/Twelve Data/Alpha Vantage/Financial Modeling Prep). Bewusst NUR fuer die Yahoo-Luecke
// versucht (kleine Teilmenge der angefragten Symbole), nicht als Ersatz fuer Yahoo insgesamt -
// die z.T. sehr knappen Gratis-Kontingente dieser Anbieter (v.a. Alpha Vantage: wenige Anfragen
// pro Tag) wuerden sonst durch jeden normalen Depot-Refresh sofort aufgebraucht. Reihenfolge nach
// grosszuegigstem Kontingent zuerst (Finnhub 60/Min, Twelve Data 800/Tag, FMP haeufig schon
// ausgeschoepft, Alpha Vantage am knappsten - deshalb dort bewusst nur 1 Symbol pro Aufruf).
// Keys liegen in data/price_api_keys.json (durch data/.htaccess vor direktem Web-Zugriff
// geschuetzt, wie schon data/cfd_ea_token.txt), NICHT im Code - lassen sich so ohne Deploy
// austauschen und landen nicht im clientseitig sichtbaren JS.
function quote_load_api_keys() {
    $data = json_decode(@file_get_contents(__DIR__ . '/data/price_api_keys.json'), true);
    return is_array($data) ? $data : [];
}
function quote_still_missing($symbols, $out) {
    return array_values(array_diff($symbols, array_keys($out)));
}
function quote_curl_get($url, $ua, $timeout = 10) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 6,
        CURLOPT_USERAGENT => $ua, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_FOLLOWLOCATION => true,
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ($code === 200 && $body) ? $body : null;
}
function quote_finnhub_fallback(&$out, $missing, $apiKey, $ua) {
    if (!$apiKey) return;
    foreach ($missing as $sym) {
        $body = quote_curl_get('https://finnhub.io/api/v1/quote?symbol=' . rawurlencode($sym) . '&token=' . rawurlencode($apiKey), $ua, 8);
        if (!$body) continue;
        $j = json_decode($body, true);
        $price = $j['c'] ?? null;
        if (!$price) continue; // c=0 liefert Finnhub u.a. bei unbekanntem Symbol statt eines Fehlers
        $out[$sym] = [
            'symbol' => $sym, 'regularMarketPrice' => (float) $price, 'currency' => null,
            'fiftyTwoWeekHigh' => null, 'fiftyTwoWeekLow' => null,
            'previousClose' => $j['pc'] ?? null, 'shortName' => null,
        ];
    }
}
function quote_twelvedata_fallback(&$out, $missing, $apiKey, $ua) {
    if (!$apiKey || !$missing) return;
    $body = quote_curl_get('https://api.twelvedata.com/quote?symbol=' . rawurlencode(implode(',', $missing)) . '&apikey=' . rawurlencode($apiKey), $ua);
    if (!$body) return;
    $json = json_decode($body, true);
    if (!is_array($json)) return;
    // Bei genau einem angefragten Symbol liefert Twelve Data die Quote direkt (ohne
    // SYMBOL=>Quote-Wrapper) - beide Formen abfangen.
    $rows = (count($missing) === 1) ? [$missing[0] => $json] : $json;
    foreach ($rows as $sym => $row) {
        if (!is_array($row) || isset($row['code'])) continue; // Fehlerobjekt (z.B. Symbol unbekannt)
        $price = $row['close'] ?? null;
        if ($price === null) continue;
        $out[$sym] = [
            'symbol' => $sym, 'regularMarketPrice' => (float) $price,
            'currency' => $row['currency'] ?? null,
            'fiftyTwoWeekHigh' => $row['fifty_two_week']['high'] ?? null,
            'fiftyTwoWeekLow' => $row['fifty_two_week']['low'] ?? null,
            'previousClose' => $row['previous_close'] ?? null, 'shortName' => $row['name'] ?? null,
        ];
    }
}
function quote_fmp_fallback(&$out, $missing, $apiKey, $ua) {
    if (!$apiKey || !$missing) return;
    $body = quote_curl_get('https://financialmodelingprep.com/api/v3/quote/' . rawurlencode(implode(',', $missing)) . '?apikey=' . rawurlencode($apiKey), $ua);
    if (!$body) return;
    $json = json_decode($body, true);
    if (!is_array($json) || isset($json['Error Message'])) return; // z.B. Tageskontingent aufgebraucht
    foreach ($json as $row) {
        $sym = $row['symbol'] ?? null;
        $price = $row['price'] ?? null;
        if ($sym === null || $price === null) continue;
        $out[$sym] = [
            'symbol' => $sym, 'regularMarketPrice' => (float) $price, 'currency' => null,
            'fiftyTwoWeekHigh' => $row['yearHigh'] ?? null, 'fiftyTwoWeekLow' => $row['yearLow'] ?? null,
            'previousClose' => $row['previousClose'] ?? null, 'shortName' => $row['name'] ?? null,
        ];
    }
}
function quote_alphavantage_fallback(&$out, $missing, $apiKey, $ua) {
    // Kein Multi-Symbol-Endpunkt UND sehr kleines Gratis-Tageskontingent - bewusst nur das ERSTE
    // noch fehlende Symbol versucht, damit ein einzelner Depot-Refresh mit mehreren Luecken das
    // Kontingent nicht auf einen Schlag verbraucht.
    if (!$apiKey || !$missing) return;
    $sym = $missing[0];
    $body = quote_curl_get('https://www.alphavantage.co/query?function=GLOBAL_QUOTE&symbol=' . rawurlencode($sym) . '&apikey=' . rawurlencode($apiKey), $ua);
    if (!$body) return;
    $json = json_decode($body, true);
    $q = $json['Global Quote'] ?? null;
    $price = $q['05. price'] ?? null;
    if (!$price) return;
    $out[$sym] = [
        'symbol' => $sym, 'regularMarketPrice' => (float) $price, 'currency' => null,
        'fiftyTwoWeekHigh' => null, 'fiftyTwoWeekLow' => null,
        'previousClose' => $q['08. previous close'] ?? null, 'shortName' => null,
    ];
}

// Reihenfolge nach Staerke des Gratis-Kontingents UND Antwortqualitaet (Nutzerfrage vom
// 09.09.2026 "welche besser von allen?"): Twelve Data zuerst (800 Anfragen/Tag, Mehrfach-Abruf,
// liefert zusaetzlich Name/Waehrung/52-Wochen-Bereich), dann Finnhub (60/Min, aber nur 1 Symbol
// pro Aufruf und knappere Antwort), dann FMP (aehnlich reichhaltig wie Twelve Data, aber
// Gratis-Kontingent haeufig schon ausgeschoepft), Alpha Vantage bleibt letzte Reserve (siehe
// Kommentar bei quote_alphavantage_fallback).
$apiKeys = quote_load_api_keys();
$missing = quote_still_missing($symbols, $out);
if ($missing) { quote_twelvedata_fallback($out, $missing, $apiKeys['twelvedata'] ?? null, $ua); $missing = quote_still_missing($symbols, $out); }
if ($missing) { quote_finnhub_fallback($out, $missing, $apiKeys['finnhub'] ?? null, $ua); $missing = quote_still_missing($symbols, $out); }
if ($missing) { quote_fmp_fallback($out, $missing, $apiKeys['fmp'] ?? null, $ua); $missing = quote_still_missing($symbols, $out); }
if ($missing) { quote_alphavantage_fallback($out, $missing, $apiKeys['alphavantage'] ?? null, $ua); }

echo json_encode($out);
