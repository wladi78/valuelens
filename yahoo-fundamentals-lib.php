<?php
// Best-effort Fundamentaldaten-Fallback ueber Yahoos inoffizielle "fundamentals-timeseries"-API,
// fuer Symbole, die SEC EDGAR nicht kennt (nicht-US-Boersen, z.B. SAP.DE, 7203.T). Gleiche
// Herkunft (finance.yahoo.com) wie die Kursdaten in quote.php/dividend.php/fundamentals.php,
// aber die Fundamentaldaten-Endpunkte brauchen seit 2024 zusaetzlich ein "Crumb"-Token + Cookie
// (Yahoos Anti-Scraping-Massnahme) - werden hier einmalig geholt und in data/ zwischengespeichert,
// damit nicht bei jeder Anfrage neu gehandelt werden muss. WICHTIG: unoffizielle, undokumentierte
// Schnittstelle - kann sich jederzeit aendern oder ganz wegfallen, und liefert nur ~4-5 Jahre
// Historie statt der 15+ Jahre von SEC EDGAR. Wird deshalb NUR als Fallback genutzt, wenn SEC
// EDGAR fuer das Symbol nichts hat (siehe fundamentals.php), und im Frontend als solcher
// gekennzeichnet (dataSource: 'yahoo_fallback').

function yahoo_session_cache_path() {
    return __DIR__ . '/data/yahoo_session_cache.json';
}

// Holt (bzw. laedt aus dem Cache) ein Crumb-Token + Session-Cookie, das Yahoo seit 2024 fuer
// die Fundamentaldaten-Endpunkte verlangt (die reine Kurs-Chart-API in quote.php/fundamentals.php
// braucht das nicht). Cache haelt 6 Stunden, damit nicht bei jeder Anfrage neu gehandelt wird -
// bei einem Watchlist-Refresh mit vielen Symbolen waere das sonst unnoetig viele Extra-Requests.
function yahoo_get_session($ua, $forceRefresh = false) {
    $cacheFile = yahoo_session_cache_path();
    if (!$forceRefresh && file_exists($cacheFile)) {
        $cached = json_decode(@file_get_contents($cacheFile), true);
        if ($cached && !empty($cached['crumb']) && !empty($cached['cookie']) && (time() - ($cached['fetchedAt'] ?? 0)) < 6 * 3600) {
            return $cached;
        }
    }
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_CONNECTTIMEOUT => 6,
        CURLOPT_USERAGENT => $ua,
        CURLOPT_COOKIEJAR => '',
        CURLOPT_COOKIEFILE => '',
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_FOLLOWLOCATION => true,
    ]);
    // Schritt 1: irgendeine Yahoo-Finance-Seite aufrufen, um Session-Cookies zu bekommen
    curl_setopt($ch, CURLOPT_URL, 'https://fc.yahoo.com');
    curl_exec($ch);
    // Schritt 2: Crumb-Token mit denselben Cookies anfordern
    curl_setopt($ch, CURLOPT_URL, 'https://query2.finance.yahoo.com/v1/test/getcrumb');
    $crumb = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($code !== 200 || !$crumb || stripos($crumb, '<html') !== false) { curl_close($ch); return null; }
    $crumb = trim($crumb);
    $cookieList = curl_getinfo($ch, CURLINFO_COOKIELIST);
    curl_close($ch);
    $parts = [];
    foreach ($cookieList as $line) {
        $f = explode("\t", $line);
        if (count($f) >= 7) $parts[] = $f[5] . '=' . $f[6];
    }
    $cookie = implode('; ', $parts);
    if (!$cookie) return null;
    $session = ['crumb' => $crumb, 'cookie' => $cookie, 'fetchedAt' => time()];
    @file_put_contents($cacheFile, json_encode($session));
    return $session;
}

function yahoo_curl_with_session($url, $ua, $session) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_CONNECTTIMEOUT => 6,
        CURLOPT_USERAGENT => $ua,
        CURLOPT_COOKIE => $session['cookie'],
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_FOLLOWLOCATION => true,
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $code, 'body' => $body];
}

// Holt eine oder mehrere Jahres-Zeitreihen ueber Yahoos "fundamentals-timeseries"-Endpunkt
// (dieselbe Quelle, die z.B. auch der finance.yahoo.com-eigene "Statistics"-Tab nutzt). $types:
// Yahoo-interne Feldnamen, z.B. ['annualDilutedEPSNormalized','annualFreeCashFlow']. Baut bei
// abgelaufenem Crumb (401/403) die Session einmal neu auf und versucht es genau einmal erneut.
function yahoo_fetch_timeseries($symbol, $types, $ua) {
    $session = yahoo_get_session($ua);
    if (!$session) return null;
    $period1 = 493590046; // fester alter Startzeitpunkt (1985) - Yahoo liefert ohnehin nur, was verfuegbar ist
    $period2 = time();
    $buildUrl = function ($session) use ($symbol, $types, $period1, $period2) {
        return 'https://query2.finance.yahoo.com/ws/fundamentals-timeseries/v1/finance/timeseries/'
            . rawurlencode($symbol) . '?symbol=' . rawurlencode($symbol)
            . '&type=' . rawurlencode(implode(',', $types))
            . '&period1=' . $period1 . '&period2=' . $period2
            . '&crumb=' . rawurlencode($session['crumb']);
    };
    $res = yahoo_curl_with_session($buildUrl($session), $ua, $session);
    if (in_array($res['code'], [401, 403], true)) {
        $session = yahoo_get_session($ua, true);
        if (!$session) return null;
        $res = yahoo_curl_with_session($buildUrl($session), $ua, $session);
    }
    if ($res['code'] !== 200 || !$res['body']) return null;
    $json = json_decode($res['body'], true);
    $results = $json['timeseries']['result'] ?? [];
    $out = [];
    foreach ($results as $r) {
        $type = $r['meta']['type'][0] ?? null;
        if (!$type || empty($r[$type])) continue;
        $series = [];
        foreach ($r[$type] as $entry) {
            if (!$entry || !isset($entry['reportedValue']['raw']) || empty($entry['asOfDate'])) continue;
            $series[] = ['date' => $entry['asOfDate'], 'ts' => strtotime($entry['asOfDate'] . ' 00:00:00'), 'value' => (float) $entry['reportedValue']['raw'], 'cur' => $entry['currencyCode'] ?? null];
        }
        if ($series) $out[$type] = $series;
    }
    return $out ?: null;
}

// Baut aus Yahoos Zeitreihen ein $epsSeries im selben Format wie sec_fetch_eps_single()
// (['date'=>,'ts'=>,'eps'=>]), damit die bestehende fv_compute()-Logik (Normal-KGV-Modell)
// unveraendert weiterverwendet werden kann - keine Doppelung der Bewertungsrechnung noetig.
function yahoo_fetch_eps_series($symbol, $ua) {
    $ts = yahoo_fetch_timeseries($symbol, ['annualDilutedEPSNormalized', 'annualBasicEPS'], $ua);
    if (!$ts) return null;
    $raw = $ts['annualDilutedEPSNormalized'] ?? $ts['annualBasicEPS'] ?? null;
    if (!$raw) return null;
    $out = [];
    foreach ($raw as $e) { $out[] = ['date' => $e['date'], 'ts' => $e['ts'], 'eps' => $e['value']]; }
    return $out ?: null;
}

// Baut $fcfSeries + Nettoschulden + verwaesserte Aktienanzahl fuers DCF-Modell, gleiches
// Zielformat wie sec_fetch_dcf_inputs() in sec-lib.php - dcf_compute() bleibt unveraendert.
function yahoo_fetch_dcf_inputs($symbol, $ua) {
    $ts = yahoo_fetch_timeseries($symbol, ['annualFreeCashFlow', 'annualTotalDebt', 'annualCashAndCashEquivalents', 'annualDilutedAverageShares'], $ua);
    if (!$ts || empty($ts['annualFreeCashFlow'])) return null;
    $fcfSeries = [];
    foreach ($ts['annualFreeCashFlow'] as $e) { $fcfSeries[] = ['date' => $e['date'], 'val' => $e['value']]; }
    $latestDebt = !empty($ts['annualTotalDebt']) ? end($ts['annualTotalDebt'])['value'] : 0;
    $latestCash = !empty($ts['annualCashAndCashEquivalents']) ? end($ts['annualCashAndCashEquivalents'])['value'] : 0;
    $netDebt = $latestDebt - $latestCash;
    $dilutedShares = !empty($ts['annualDilutedAverageShares']) ? end($ts['annualDilutedAverageShares'])['value'] : null;
    if (!$dilutedShares || $dilutedShares <= 0) return null;
    return ['fcfSeries' => $fcfSeries, 'netDebt' => $netDebt, 'dilutedShares' => $dilutedShares];
}

// Rohdaten fuer ROIC/Debt-EBITDA/Revenue-Wachstum (Nutzerwunsch vom 06.09.2026) - Pendant zu
// sec_fetch_quality_inputs() in sec-lib.php fuer nicht-US-Symbole ohne SEC-Meldepflicht. Alle
// Feldnamen live gegen die echte Yahoo-API verifiziert (05.09.2026, Symbol KO): liefert
// 'annualInvestedCapital' bereits fertig vorberechnet (anders als der SEC-Pfad, der Equity+
// Schulden-Cash selbst zusammenrechnen muss) sowie 'annualTaxRateForCalcs' als Bruchteil
// (z.B. 0.1788), nicht als Prozentzahl - hier direkt in Prozent umgerechnet.
function yahoo_fetch_quality_inputs($symbol, $ua) {
    $ts = yahoo_fetch_timeseries($symbol, ['annualEBITDA', 'annualTotalDebt', 'annualInvestedCapital', 'annualOperatingIncome', 'annualTaxRateForCalcs', 'annualTotalRevenue', 'annualCashAndCashEquivalents'], $ua);
    if (!$ts) return null;
    $last = function ($key) use ($ts) { return !empty($ts[$key]) ? end($ts[$key])['value'] : null; };
    $revenueSeries = [];
    foreach (($ts['annualTotalRevenue'] ?? []) as $e) { $revenueSeries[] = ['date' => $e['date'], 'val' => $e['value']]; }
    $operatingIncome = $last('annualOperatingIncome');
    if (!$revenueSeries && $operatingIncome === null) return null;
    $taxRatePct = $last('annualTaxRateForCalcs');
    if ($taxRatePct !== null) $taxRatePct = max(0, min(50, $taxRatePct * 100));
    return [
        'revenueSeries' => $revenueSeries,
        'operatingIncome' => $operatingIncome,
        'stockholdersEquity' => null, // Yahoo liefert investedCapital direkt, siehe investedCapitalDirect
        'investedCapitalDirect' => $last('annualInvestedCapital'),
        'totalDebt' => $last('annualTotalDebt'),
        'cash' => $last('annualCashAndCashEquivalents'),
        'ebitda' => $last('annualEBITDA'),
        'taxRatePct' => $taxRatePct,
    ];
}

// Sektor/Branche bei Yahoo (assetProfile) - fuer sector.php (Sektor-Automatik) und bewertung_bank() (Bank-Erkennung).
// Stand bce4c5d (19.08.2026); ging beim Abgleich mit dem Live-Stand (911fc2e) verloren, wiederhergestellt 12.11.2026.
function yahoo_fetch_sector($symbol, $ua) {
    $session = yahoo_get_session($ua);
    if (!$session) return null;
    $buildUrl = function ($session) use ($symbol) {
        return 'https://query2.finance.yahoo.com/v10/finance/quoteSummary/' . rawurlencode($symbol)
            . '?modules=assetProfile&crumb=' . rawurlencode($session['crumb']);
    };
    $res = yahoo_curl_with_session($buildUrl($session), $ua, $session);
    if (in_array($res['code'], [401, 403], true)) {
        $session = yahoo_get_session($ua, true);
        if (!$session) return null;
        $res = yahoo_curl_with_session($buildUrl($session), $ua, $session);
    }
    if ($res['code'] !== 200 || !$res['body']) return null;
    $json = json_decode($res['body'], true);
    $profile = $json['quoteSummary']['result'][0]['assetProfile'] ?? null;
    if (!$profile || empty($profile['sector'])) return null;
    return ['sector' => $profile['sector'], 'industry' => $profile['industry'] ?? null];
}
