<?php
// Liefert fuer EIN Symbol eine grobe KGV-basierte Bewertung (Peter-Lynch-/Vorlage-Stil):
// Kurse kommen von Yahoo (gleiche Quelle wie quote.php/dividend.php), die historischen
// Jahresgewinne (EPS) von der kostenlosen SEC-EDGAR-API (10-K-Meldungen, meist 15+ Jahre
// zurueck - deutlich mehr als Yahoos kostenlose Schnittstelle). Daraus wird ein "Normal-KGV"
// (Median der historischen KGVs) sowie eine eigene Wachstumsschaetzung berechnet. WICHTIG:
// das ist keine Analysten-Konsensschaetzung (die kostet Geld), sondern eine Trendfortschreibung.
require_once __DIR__ . '/session-init.php';
header('Content-Type: application/json; charset=utf-8');
require __DIR__ . '/fundamentals-lib.php';
require __DIR__ . '/sec-lib.php';
require __DIR__ . '/yahoo-fundamentals-lib.php';

if (empty($_SESSION['user'])) {
    http_response_code(401);
    echo json_encode(['error' => 'unauthorized']);
    exit;
}
// Sitzung sofort freigeben: diese Abfrage liest nur und kann lange dauern (Yahoo/SEC). Sonst blockiert PHPs Sitzungssperre
// alle weiteren Anfragen desselben Nutzers bis zum Ende (Zonen-Chart hing 05.11.2026 rund 30 s hinter fundamentals.php).
session_write_close();

// Sicherheitsnetz (eingebaut nach einem Vorfall am 19.08.2026, bei dem eine verwaiste Variable
// jede Anfrage mit einer leeren/kaputten Antwort abstuerzen liess, ohne dass sich die Ursache ohne
// Server-Zugriff auf die echten PHP-Fehlerlogs finden liess): loggt PHP-Fehler/Exceptions nach
// data/fundamentals_debug.log statt sie unsichtbar verpuffen zu lassen, und liefert bei einer
// unbehandelten Exception wenigstens eine gueltige JSON-Fehlerantwort statt einer kaputten. Reine
// Deprecation-Hinweise (E_DEPRECATED) werden nicht geloggt - die sind nicht handlungsrelevant und
// wuerden das Log nur zumuellen.
set_error_handler(function($errno, $errstr, $errfile, $errline){
    if ($errno === E_DEPRECATED) return false;
    @file_put_contents(__DIR__ . '/data/fundamentals_debug.log', date('c') . " [PHP $errno] $errstr in $errfile:$errline\n", FILE_APPEND);
    return false;
});
register_shutdown_function(function(){
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        @file_put_contents(__DIR__ . '/data/fundamentals_debug.log', date('c') . " [FATAL] {$e['message']} in {$e['file']}:{$e['line']}\n", FILE_APPEND);
    }
});
set_exception_handler(function($ex){
    @file_put_contents(__DIR__ . '/data/fundamentals_debug.log', date('c') . " [UNCAUGHT] " . get_class($ex) . ": " . $ex->getMessage() . " in " . $ex->getFile() . ":" . $ex->getLine() . "\n", FILE_APPEND);
    if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'server_exception']);
});

$symbol = isset($_GET['symbol']) ? trim($_GET['symbol']) : '';
if ($symbol === '' || !function_exists('curl_init')) {
    echo json_encode(['error' => 'invalid_symbol']);
    exit;
}
// Ergebnis 3 Stunden zwischenspeichern (alle Nutzer teilen ihn): die Berechnung ruft SEC und Yahoo rund 60-mal auf und
// dauerte bei KO jedes Mal 40 s (Messung 15.11.2026). Fehlerantworten werden nicht gespeichert. ?frisch=1 umgeht den Speicher.
$fcDir = __DIR__ . '/data/cache/fundamentals';
$fcDatei = $fcDir . '/' . preg_replace('/[^A-Z0-9.^=_-]/', '_', strtoupper($symbol)) . '.json';
if (empty($_GET['frisch']) && is_file($fcDatei) && time() - filemtime($fcDatei) < 3 * 3600) { readfile($fcDatei); exit; }
ob_start(function ($aus) use ($fcDir, $fcDatei) {
    $j = json_decode($aus, true);
    if (is_array($j) && empty($j['error']) && !empty($j['symbol'])) {
        if (!is_dir($fcDir)) @mkdir($fcDir, 0700, true);
        if (@file_put_contents($fcDatei . '.tmp', $aus) !== false) @rename($fcDatei . '.tmp', $fcDatei);
    }
    return $aus;
});

$ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36';

function curlGet($url, $ua) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_CONNECTTIMEOUT => 6,
        CURLOPT_USERAGENT => $ua,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_FOLLOWLOCATION => true,
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code !== 200 || !$body) return null;
    return json_decode($body, true);
}

// 1) Historische Monatskurse + aktueller Kurs (gleiche Chart-API wie quote.php, nur laenger)
$chartUrl = 'https://query1.finance.yahoo.com/v8/finance/chart/' . rawurlencode($symbol)
    . '?range=20y&interval=1mo';
$chartJson = curlGet($chartUrl, $ua);
$result = $chartJson['chart']['result'][0] ?? null;
if (!$result) {
    echo json_encode(['error' => 'no_price_data']);
    exit;
}
$meta = $result['meta'] ?? [];
$timestamps = $result['timestamp'] ?? [];
$closes = $result['indicators']['quote'][0]['close'] ?? [];
$priceSeries = [];
foreach ($timestamps as $i => $ts) {
    if (isset($closes[$i]) && $closes[$i] !== null) {
        $priceSeries[] = ['ts' => (int) $ts, 'price' => (float) $closes[$i]];
    }
}

// 2) Historische jaehrliche EPS von SEC EDGAR (10-K-Meldungen, kein API-Key)
$cikMap = sec_get_cik_map();
$epsSeries = sec_fetch_eps_single($symbol, $cikMap);
$dataSource = 'sec';

// Fallback fuer nicht-US-Boersen (SEC EDGAR kennt nur bei der SEC meldepflichtige Unternehmen):
// Yahoos inoffizielle Fundamentaldaten-Zeitreihen versuchen, siehe Kommentar in
// yahoo-fundamentals-lib.php. Deutlich weniger Historie (~4-5 statt 15+ Jahre) und weniger
// robust (kann jederzeit aufhoeren zu funktionieren), aber besser als "keine Daten" fuer
// europaeische/asiatische Aktien. Deckt nur das KGV-Modell + DCF ab, nicht die
// SEC-spezifischen Kennzahlen weiter unten (Profitabilitaet, Payout-Trend, Piotroski/Altman,
// Solvenz) - die bleiben bei nicht-US-Aktien bewusst "nicht verfuegbar" statt eine zweite,
// unsichere Berechnungslogik dafuer nachzubauen.
if (!$epsSeries) {
    $epsSeries = yahoo_fetch_eps_series($symbol, $ua);
    if ($epsSeries) $dataSource = 'yahoo_fallback';
}

if (!$epsSeries) {
    echo json_encode([
        'symbol' => $symbol,
        'companyName' => $meta['longName'] ?? $meta['shortName'] ?? null,
        'currency' => $meta['currency'] ?? null,
        'currentPrice' => $meta['regularMarketPrice'] ?? null,
        'error' => 'no_eps_data',
    ]);
    exit;
}

// 3) "Dividend Yield Theory": aktuelle Dividende je Aktie (SEC) im Verhaeltnis zur
// durchschnittlichen Rendite der letzten (bis zu) 5 Jahre - Fair Value = aktuelle Dividende /
// Ø-Rendite. Historische Rendite je Jahr = Dividende je Aktie dieses Jahres / Kurs zum jeweiligen
// Fiskaljahresende (aus derselben Monats-Kursreihe wie oben fuers KGV-Modell). Darf scheitern
// (bleibt dann null), blockiert nicht die restliche Auswertung.
$dpsSeries = sec_fetch_dividend_per_share_single($symbol, $cikMap);
$yieldTheory = fv_compute_yield_theory($priceSeries, $dpsSeries);
$avgYieldPct = $yieldTheory['avgYieldPct'] ?? null;
$yieldTheoryFairValue = $yieldTheory['fairValue'] ?? null;
$yieldTheoryYearsCount = $yieldTheory['yearsCount'] ?? 0;

// Dividenden-Wachstumsrate (fuer Dividend-Intelligence: Safety/Growth-Forecast/Yield-on-Cost auf
// der Bewertungsseite) - dieselbe 5J-/10J-CAGR+Regression-Mischung wie bei EPS/Umsatz/FCF
// (fv_blended_growth_rate), damit ein einzelnes besonders hohes/niedriges Dividendenjahr die
// Prognose nicht verzerrt. currentAnnualDividend ist die zuletzt gemeldete Jahresdividende je
// Aktie (SEC), fuer die Yield-on-Cost-Simulation im Frontend.
$divGrowthPoints = [];
foreach ($dpsSeries as $i => $e) { if ($e['eps'] > 0) $divGrowthPoints[] = ['x' => $i, 'y' => $e['eps']]; }
$dividendGrowthRatePct = fv_blended_growth_rate($divGrowthPoints, -30.0, 30.0, null);
$currentAnnualDividend = $dpsSeries ? end($dpsSeries)['eps'] : null;
// Getrenntes 5J-/10J-Dividendenwachstum (Nutzerwunsch vom 06.09.2026: "Dividend Growth 5Y" UND
// "Dividend Growth 10Y" als zwei eigene Zahlen) - nutzt dieselbe, oben schon aufgebaute
// $divGrowthPoints-Reihe, kein zusaetzlicher Datenabruf noetig (siehe fv_cagr_5_10-Kommentar).
$divCagr = fv_cagr_5_10($divGrowthPoints);
$divGrowth5yPct = $divCagr['cagr5'] !== null ? round($divCagr['cagr5'], 2) : null;
$divGrowth10yPct = $divCagr['cagr10'] !== null ? round($divCagr['cagr10'], 2) : null;

// 4) Profitabilitaet (Nettomarge/ROE/ROA) fuer den neuen Profitabilitaets-Score - eigene,
// kostenlose Naeherung an "Quality via Profitability" (siehe SEC-EDGAR-Kommentar oben), nur fuer
// SEC-meldepflichtige (US-)Unternehmen verfuegbar. Darf ruhig scheitern (bleibt dann einfach null) -
// blockiert nicht die eigentliche KGV-Auswertung oben.
$profitability = sec_fetch_profitability($symbol, $cikMap);

// 5) Dividenden-Sicherheit (Payout-Ratio + FCF-Deckung + Trend) - ergaenzt den Profitabilitaets-
// score oben, siehe Kommentar in sec-lib.php. Darf ruhig scheitern (bleibt dann null).
$payoutTrend = sec_fetch_payout_trend($symbol, $cikMap);

// 6) Tilgungsjahre (Nettoschulden / freier Cashflow) - eigenstaendiges Verschuldungsmass,
// siehe Kommentar in sec-lib.php. Darf ruhig scheitern (bleibt dann null).
$debtPayback = sec_fetch_debt_payback($symbol, $cikMap);

// 7) Zinsdeckungsgrad + Current Ratio - zwei weitere Solvenz-Kennzahlen, siehe Kommentar in
// sec-lib.php. Darf ruhig scheitern (bleibt dann null).
$solvency = sec_fetch_solvency($symbol, $cikMap);

// 8) Piotroski F-Score + Altman Z-Score - siehe Kommentar in sec-lib.php. Braucht den aktuellen
// Kurs (fuer die Marktkapitalisierung im Z-Score), der oben schon aus der Yahoo-Chart-API kommt.
// Darf ruhig scheitern (bleibt dann null).
$qualityScores = sec_fetch_quality_scores($symbol, $cikMap, $meta['regularMarketPrice'] ?? null);

// 9) Cash Conversion Cycle - siehe Kommentar in sec-lib.php. Darf ruhig scheitern (bleibt dann null).
$cashConversionCycle = sec_fetch_cash_conversion_cycle($symbol, $cikMap);

// 9b) ROIC / Debt-EBITDA / Umsatzwachstum (Nutzerwunsch vom 06.09.2026, Screener-Erweiterung) -
// siehe ausfuehrlicher Kommentar bei sec_fetch_quality_inputs() in sec-lib.php. Yahoo-Fallback
// analog zu den anderen SEC-Kennzahlen oben nur bei nicht-US-Symbolen (dataSource=yahoo_fallback).
$qualityInputs = sec_fetch_quality_inputs($symbol, $cikMap);
if (!$qualityInputs && $dataSource === 'yahoo_fallback') {
    $qualityInputs = yahoo_fetch_quality_inputs($symbol, $ua);
}
$revenueGrowth5yPct = null; $revenueGrowth10yPct = null;
$roicPct = null; $debtEbitdaX = null; $ebitdaVal = null; $totalDebtVal = null; $investedCapitalVal = null;
if ($qualityInputs) {
    $revenueGrowthPoints = [];
    foreach (($qualityInputs['revenueSeries'] ?? []) as $i => $e) { if ($e['val'] > 0) $revenueGrowthPoints[] = ['x' => $i, 'y' => $e['val']]; }
    $revCagr = fv_cagr_5_10($revenueGrowthPoints);
    $revenueGrowth5yPct = $revCagr['cagr5'] !== null ? round($revCagr['cagr5'], 2) : null;
    $revenueGrowth10yPct = $revCagr['cagr10'] !== null ? round($revCagr['cagr10'], 2) : null;

    $ebitdaVal = $qualityInputs['ebitda'];
    $totalDebtVal = $qualityInputs['totalDebt'];
    // Invested Capital: Yahoo liefert das direkt vorberechnet (investedCapitalDirect), der SEC-Pfad
    // rechnet es selbst aus Eigenkapital + Schulden - Cash (Standardformel).
    $investedCapitalVal = $qualityInputs['investedCapitalDirect'] ?? (
        ($qualityInputs['stockholdersEquity'] !== null && $totalDebtVal !== null)
            ? $qualityInputs['stockholdersEquity'] + $totalDebtVal - ($qualityInputs['cash'] ?? 0)
            : null
    );
    if ($ebitdaVal !== null && $ebitdaVal > 0 && $totalDebtVal !== null) {
        $debtEbitdaX = round($totalDebtVal / $ebitdaVal, 2);
    }
    if ($qualityInputs['operatingIncome'] !== null && $investedCapitalVal !== null && $investedCapitalVal > 0) {
        // ROIC = NOPAT / Invested Capital. Fehlt eine ermittelbare effektive Steuerquote (z.B.
        // Verlustjahr ohne sinnvolle Pretax-Income-Basis), wird ersatzweise der US-Koerperschaft-
        // steuersatz (21%) angenommen statt den ROIC komplett zu verweigern.
        $taxRatePctForRoic = $qualityInputs['taxRatePct'] ?? 21.0;
        $nopat = $qualityInputs['operatingIncome'] * (1 - $taxRatePctForRoic / 100);
        $roicPct = round($nopat / $investedCapitalVal * 100, 2);
    }
}

// 10) Beta (Marktrisiko vs. S&P 500) - siehe Kommentar in fundamentals-lib.php (beta_compute).
// Braucht eine zweite Kursreihe fuer den Vergleichsindex, gleiche kostenlose Yahoo-Chart-API wie
// oben fuers KGV-Modell. Darf ruhig scheitern (bleibt dann null).
$benchmarkChartUrl = 'https://query1.finance.yahoo.com/v8/finance/chart/' . rawurlencode('^GSPC') . '?range=20y&interval=1mo';
$benchmarkJson = curlGet($benchmarkChartUrl, $ua);
$benchmarkResult = $benchmarkJson['chart']['result'][0] ?? null;
$benchmarkPriceSeries = [];
if ($benchmarkResult) {
    $bTimestamps = $benchmarkResult['timestamp'] ?? [];
    $bCloses = $benchmarkResult['indicators']['quote'][0]['close'] ?? [];
    foreach ($bTimestamps as $i => $ts) {
        if (isset($bCloses[$i]) && $bCloses[$i] !== null) {
            $benchmarkPriceSeries[] = ['ts' => (int) $ts, 'price' => (float) $bCloses[$i]];
        }
    }
}
$betaResult = beta_compute($priceSeries, $benchmarkPriceSeries);

$calc = fv_compute($priceSeries, $epsSeries);
$normalPE = $calc['normalPE'];
$growthPct = $calc['growthRatePct'];
$latestEps = $calc['epsTTM'];
$fairValueNow = $calc['fairValueNow'];
$fairValueForward = $calc['fairValueForward1y'];
$pePoints = $calc['epsHistory'];
// Vorlage-artige "Earnings Justified Value"-Linie (aus dem Gewinnwachstum abgeleitetes KGV) -
// eigenstaendig neben dem historischen normalPE/fairValueNow oben, siehe Kommentar bei
// fv_growth_justified_pe() in fundamentals-lib.php.
$justifiedPE = $calc['justifiedPE'];
$fairValueJustified = $calc['fairValueJustified'];

// 11) P/S-Ersatzbewertung - nur wenn das KGV-Modell oben mangels positivem TTM-Gewinn keinen
// Fair Value liefern konnte (siehe Kommentar in fundamentals-lib.php/fv_compute_ps). Spart bei
// normalen, profitablen Firmen die zusaetzlichen SEC-Anfragen. Darf ruhig scheitern (bleibt
// dann einfach null).
$psResult = null;
if ($fairValueNow === null) {
    $rpsSeries = sec_fetch_revenue_per_share_series($symbol, $cikMap);
    $psResult = fv_compute_ps($priceSeries, $rpsSeries);
}

// 12) DCF-Modell (Discounted Cash Flow) - viertes, unabhaengiges Bewertungsmodell, siehe
// Kommentar in fundamentals-lib.php (dcf_compute). Darf ruhig scheitern (bleibt dann null),
// blockiert nicht die restliche Auswertung.
$dcfInputs = sec_fetch_dcf_inputs($symbol, $cikMap);
if (!$dcfInputs && $dataSource === 'yahoo_fallback') {
    $dcfInputs = yahoo_fetch_dcf_inputs($symbol, $ua);
}
$dcfResult = $dcfInputs ? dcf_compute($dcfInputs['fcfSeries'], $dcfInputs['netDebt'], $dcfInputs['dilutedShares'], $meta['regularMarketPrice'] ?? null, [], $priceSeries) : null;

// Monatliche Kurs-Reihe (fuers Chart) + dazu passende Bewertungslinie (Normal-KGV x jeweils
// zuletzt bekanntes Jahres-EPS, wie eine Treppenfunktion - so nach Vorlage' "orange line").
// Auf die letzten 15 Jahre begrenzt, damit die Antwort nicht unnoetig gross wird.
$monthly = [];
foreach ($priceSeries as $p) {
    $monthly[] = ['date' => gmdate('Y-m', $p['ts']), 'ts' => $p['ts'], 'price' => round($p['price'], 4)];
}
if (count($monthly) > 180) $monthly = array_slice($monthly, -180);

$valuationSeries = [];
foreach ($monthly as $m) {
    $applicableEps = null;
    foreach ($epsSeries as $e) {
        if ($e['ts'] <= $m['ts']) $applicableEps = $e['eps']; else break;
    }
    $val = ($applicableEps !== null && $applicableEps > 0) ? round($normalPE * $applicableEps, 4) : null;
    $valuationSeries[] = $val;
}
// Ein Jahr Projektion anhaengen (nur Bewertungslinie, kein Kurs - reine Schaetzung)
if ($monthly) {
    $lastTs = end($monthly)['ts'];
    $monthly[] = ['date' => gmdate('Y-m', strtotime('+1 year', $lastTs)), 'ts' => null, 'price' => null];
    $valuationSeries[] = $fairValueForward;
}

echo json_encode([
    'symbol' => $symbol,
    'companyName' => $meta['longName'] ?? $meta['shortName'] ?? null,
    'currency' => $meta['currency'] ?? null,
    'currentPrice' => $meta['regularMarketPrice'] ?? null,
    'dataSource' => $dataSource,
    'dataSourceNote' => $dataSource === 'yahoo_fallback'
        ? 'Fundamentaldaten von Yahoo Finance (inoffiziell, ' . count($epsSeries) . ' Jahre Historie), da SEC EDGAR dieses Symbol nicht kennt (keine US-Meldepflicht) - Profitabilitäts-/Sicherheits-/Qualitäts-Kennzahlen unten daher nicht verfügbar.'
        : null,
    'normalPE' => round($normalPE, 2),
    'normalPESource' => $calc['normalPESource'],
    'growthRatePct' => round($growthPct, 2),
    'epsTTM' => round($latestEps, 4),
    'epsYearsCount' => count($epsSeries),
    'justifiedPE' => $justifiedPE,
    'fairValueJustified' => $fairValueJustified,
    'fairValueNow' => $fairValueNow,
    'fairValueForward1y' => $fairValueForward,
    'epsHistory' => $pePoints,
    'epsDiscontinuity' => $calc['epsDiscontinuity'] ?? null,
    'chartLabels' => array_map(function ($m) { return $m['date']; }, $monthly),
    'chartPrice' => array_map(function ($m) { return $m['price']; }, $monthly),
    'chartValuation' => $valuationSeries,
    'netMarginPct' => $profitability['netMarginPct'] ?? null,
    'roePct' => $profitability['roePct'] ?? null,
    'roaPct' => $profitability['roaPct'] ?? null,
    'profitFiscalYearEnd' => $profitability['fiscalYearEnd'] ?? null,
    'yieldTheoryFairValue' => $yieldTheoryFairValue !== null ? round($yieldTheoryFairValue, 2) : null,
    'yieldTheoryAvgYieldPct' => $avgYieldPct !== null ? round($avgYieldPct, 2) : null,
    'dividendGrowthRatePct' => $dividendGrowthRatePct !== null ? round($dividendGrowthRatePct, 2) : null,
    'divGrowth5yPct' => $divGrowth5yPct,
    'divGrowth10yPct' => $divGrowth10yPct,
    'currentAnnualDividend' => $currentAnnualDividend !== null ? round($currentAnnualDividend, 4) : null,
    'dividendYearsCount' => count($dpsSeries),
    'yieldTheoryYearsCount' => $yieldTheoryYearsCount,
    'payoutRatioPct' => $payoutTrend['payoutRatioPct'] ?? null,
    'fcfPayoutRatioPct' => $payoutTrend['fcfPayoutRatioPct'] ?? null,
    'payoutTrend' => $payoutTrend['payoutTrend'] ?? null,
    'payoutYearsCount' => $payoutTrend['payoutYearsCount'] ?? null,
    'debtPaybackYears' => $debtPayback['paybackYears'] ?? null,
    'debtNetDebt' => $debtPayback['netDebt'] ?? null,
    'debtFcf' => $debtPayback['fcf'] ?? null,
    'debtFiscalYearEnd' => $debtPayback['fiscalYearEnd'] ?? null,
    'interestCoverage' => $solvency['interestCoverage'] ?? null,
    'interestCoverageFiscalYearEnd' => $solvency['interestCoverageFiscalYearEnd'] ?? null,
    'currentRatio' => $solvency['currentRatio'] ?? null,
    'currentRatioFiscalYearEnd' => $solvency['currentRatioFiscalYearEnd'] ?? null,
    'piotroski' => $qualityScores['piotroski'] ?? null,
    'altmanZ' => $qualityScores['altmanZ'] ?? null,
    'qualityScoresFiscalYearEnd' => $qualityScores['fiscalYearEnd'] ?? null,
    'daysInventory' => $cashConversionCycle['daysInventory'] ?? null,
    'daysSalesOutstanding' => $cashConversionCycle['daysSalesOutstanding'] ?? null,
    'daysPayable' => $cashConversionCycle['daysPayable'] ?? null,
    'cashConversionCycle' => $cashConversionCycle['cashConversionCycle'] ?? null,
    'cashConversionCycleFiscalYearEnd' => $cashConversionCycle['fiscalYearEnd'] ?? null,
    'roicPct' => $roicPct,
    'debtEbitdaX' => $debtEbitdaX,
    'ebitda' => $ebitdaVal !== null ? round($ebitdaVal, 0) : null,
    'totalDebt' => $totalDebtVal !== null ? round($totalDebtVal, 0) : null,
    'investedCapital' => $investedCapitalVal !== null ? round($investedCapitalVal, 0) : null,
    'revenueGrowth5yPct' => $revenueGrowth5yPct,
    'revenueGrowth10yPct' => $revenueGrowth10yPct,
    'beta' => $betaResult['beta'] ?? null,
    'betaMonthsCount' => $betaResult['monthsCount'] ?? null,
    'psFairValueNow' => $psResult['psFairValueNow'] ?? null,
    'psNormalPS' => $psResult['normalPS'] ?? null,
    'psNormalPSSource' => $psResult['normalPSSource'] ?? null,
    'psRevenueGrowthPct' => $psResult['revenueGrowthPct'] ?? null,
    'psRevenuePerShareTTM' => $psResult['revenuePerShareTTM'] ?? null,
    'dcfFairValue' => $dcfResult['dcfFairValue'] ?? null,
    'dcfGrowthPct' => $dcfResult['dcfGrowthPct'] ?? null,
    'dcfExitMultiple' => $dcfResult['dcfExitMultiple'] ?? null,
    'dcfFcfTTM' => $dcfResult['dcfFcfTTM'] ?? null,
    'dcfDiscountRatePct' => $dcfResult['dcfDiscountRatePct'] ?? null,
    'dcfLowConfidence' => $dcfResult['dcfLowConfidence'] ?? null,
    'dcfBear' => $dcfResult['dcfBear'] ?? null,
    'dcfBearGrowthPct' => $dcfResult['dcfBearGrowthPct'] ?? null,
    'dcfBearDiscountRatePct' => $dcfResult['dcfBearDiscountRatePct'] ?? null,
    'dcfBull' => $dcfResult['dcfBull'] ?? null,
    'dcfBullGrowthPct' => $dcfResult['dcfBullGrowthPct'] ?? null,
    'dcfBullDiscountRatePct' => $dcfResult['dcfBullDiscountRatePct'] ?? null,
]);
