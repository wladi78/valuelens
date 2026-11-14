<?php
// Gemeinsame Berechnungslogik fuer das KGV-Modell ("Normal P/E"), genutzt von
// fundamentals.php (Einzelabfrage mit Chart) und fundamentals-batch.php (Watchlist,
// mehrere Symbole parallel). Reine Rechenfunktionen, kein Netzwerk-/Session-Code hier.

// Wie viele der juengsten Jahre in den KGV-Median (siehe fv_compute) einfliessen - siehe
// ausfuehrliche Begruendung dort (Bewertungsniveau-Drift + Schutz gegen unvollstaendig
// splitbereinigte SEC-Altjahre).
define('RECENT_PE_WINDOW_YEARS', 10);

function fv_nearest_price($priceSeries, $ts) {
    $best = null;
    $bestDiff = PHP_INT_MAX;
    foreach ($priceSeries as $p) {
        $diff = abs($p['ts'] - $ts);
        if ($diff < $bestDiff) { $bestDiff = $diff; $best = $p['price']; }
    }
    // nur verwenden, wenn der naechste Kurs nicht mehr als ~45 Tage entfernt liegt
    return $bestDiff <= 45 * 86400 ? $best : null;
}

// Wachstums-gerechtfertigtes KGV (Graham-Dodd-inspiriert, Ansatz "Earnings Justified
// Value"): trennt bewusst von normalPE (historischer Median, siehe fv_compute unten) - normalPE
// beschreibt, WIE der Markt die Aktie historisch bewertet hat, justifiedPE dagegen, welches KGV
// angesichts des Gewinnwachstums angemessen WAERE. Nur eine an oeffentlich dokumentierte
// Graham-Dodd-/PEG-Heuristiken angelehnte Naeherung, kein proprietaeres Modell einer bestimmten
// Seite. 0-5% Wachstum: klassische Graham-Formel (8,5 + 2*g), gedeckelt bei 15x. 5-15%: konstant
// 15x (der Bereich, in dem GDF und P/E=G in der Praxis etwa zusammenlaufen - eine lineare
// Interpolation waere hier kaum von einer Konstanten zu unterscheiden, da beide Kurven nahe 15x
// verlaufen). Ab 15%: P/E = Wachstumsrate ("P/E=G"-Heuristik), gedeckelt bei $cap.
function fv_growth_justified_pe($growthPct, $cap = 30.0) {
    if ($growthPct <= 5) return min(15.0, 8.5 + 2 * $growthPct);
    if ($growthPct < 15) return 15.0;
    return min($cap, max(15.0, $growthPct));
}

// Wachstumsrate aus einer Wertreihe (EPS oder FCF) - Mischung aus 5J-/10J-CAGR und log-linearer
// Regression statt nur Regression: eine reine Regression kann bei volatilen, aber im Trend
// steigenden Gewinnen ein Wachstum zeigen, das die tatsaechliche Schwankung verschleiert. CAGR
// (nur Start-/Endwert) ist dagegen anfaellig fuer einen zufaellig besonders hohen/niedrigen
// Einzelwert am Rand. Die Mischung daempft beide Schwaechen. $series: Liste von ['x'=>int (0..n-1
// chronologisch), 'y'=>float (positiver Rohwert, kein Logarithmus)], mindestens 2 Punkte.
function fv_blended_growth_rate($series, $growthMin, $growthMax, $fallback) {
    $n = count($series);
    if ($n < 2) return $fallback;

    // Log-lineare Regression ueber alle Punkte (wie bisher) - Y-Werte hier erst fuer die
    // Regression logarithmiert ($series selbst enthaelt bewusst Rohwerte, da die CAGR-Berechnung
    // unten Rohwerte braucht).
    $regressionPct = null;
    $sumX = 0; $sumY = 0; $sumXY = 0; $sumXX = 0;
    foreach ($series as $p) { $ly = log($p['y']); $sumX += $p['x']; $sumY += $ly; $sumXY += $p['x'] * $ly; $sumXX += $p['x'] * $p['x']; }
    $denom = ($n * $sumXX - $sumX * $sumX);
    if ($denom != 0) {
        $slope = ($n * $sumXY - $sumX * $sumY) / $denom;
        $regressionPct = (exp($slope) - 1) * 100;
    }

    // CAGR ueber die letzten 5 bzw. 10 Jahre (nur Start-/Endpunkt dieses Fensters) - $series ist
    // bereits chronologisch (x aufsteigend), letzter Eintrag = neuestes Jahr.
    $last = $series[$n - 1];
    $cagr = function ($years) use ($series, $n, $last) {
        $idx = $n - 1 - $years;
        if ($idx < 0) return null;
        $start = $series[$idx];
        if ($start['y'] <= 0 || $years <= 0) return null;
        return ((pow($last['y'] / $start['y'], 1 / $years)) - 1) * 100;
    };
    $cagr5 = $cagr(5);
    $cagr10 = $cagr(10);

    // Gewichtung: bevorzugt 5J-/10J-CAGR (robuster gegen einzelne Ausreisser-Jahre als reine
    // Regression), Regression nur als daempfender Zusatzfaktor bzw. alleiniger Ersatz, wenn keine
    // 5 Jahre Historie vorhanden sind.
    if ($cagr5 !== null && $cagr10 !== null) {
        $blended = 0.4 * $cagr5 + 0.4 * $cagr10 + 0.2 * ($regressionPct ?? $cagr5);
    } elseif ($cagr5 !== null) {
        $blended = 0.7 * $cagr5 + 0.3 * ($regressionPct ?? $cagr5);
    } elseif ($regressionPct !== null) {
        $blended = $regressionPct;
    } else {
        return $fallback;
    }
    return max($growthMin, min($growthMax, $blended));
}

// Getrenntes 5-Jahres-/10-Jahres-CAGR (Nutzerwunsch vom 06.09.2026: "Dividend Growth 5Y" UND
// "Dividend Growth 10Y" als zwei eigene Kennzahlen statt der bereits vorhandenen geblendeten
// fv_blended_growth_rate()-Zahl oben, die beide Fenster + eine Regression bewusst zu EINEM Wert
// verschmilzt). $series im selben Format wie bei fv_blended_growth_rate ([['x'=>int,'y'=>float],..],
// x aufsteigend chronologisch, letzter Eintrag = neuestes Jahr) - identische CAGR-Fensterlogik wie
// dort, nur eben beide Fenster einzeln zurueckgegeben statt gewichtet zusammengefasst.
function fv_cagr_5_10($series) {
    $n = count($series);
    if ($n < 2) return ['cagr5' => null, 'cagr10' => null];
    $last = $series[$n - 1];
    $cagr = function ($years) use ($series, $n, $last) {
        $idx = $n - 1 - $years;
        if ($idx < 0) return null;
        $start = $series[$idx];
        if ($start['y'] <= 0 || $years <= 0) return null;
        return ((pow($last['y'] / $start['y'], 1 / $years)) - 1) * 100;
    };
    return ['cagr5' => $cagr(5), 'cagr10' => $cagr(10)];
}

// $priceSeries: [['ts'=>int,'price'=>float], ...]  $epsSeries: [['date'=>'Y-m-d','ts'=>int,'eps'=>float], ...]
function fv_compute($priceSeries, $epsSeries, $opts = []) {
    $peMin = $opts['peMin'] ?? 2.0;
    $peMax = $opts['peMax'] ?? 60.0;
    $peFallback = $opts['peFallback'] ?? 15.0;
    $growthMin = $opts['growthMin'] ?? -10.0;
    $growthMax = $opts['growthMax'] ?? 25.0;
    $growthFallback = $opts['growthFallback'] ?? 5.0;

    if (!$epsSeries) return null;
    usort($epsSeries, function ($a, $b) { return $a['ts'] <=> $b['ts']; });

    // Nur die juengsten RECENT_PE_WINDOW_YEARS Jahre fliessen in den KGV-Median ein (volle
    // Historie bleibt unten in $pePoints/epsHistory weiterhin sichtbar, nur die Median-Bildung
    // ist eingeschraenkt). Zwei Gruende: (1) Bewertungsniveaus verschieben sich strukturell ueber
    // sehr lange Zeitraeume, ein KGV von vor 15+ Jahren ist kein guter Massstab fuer "normal"
    // heute. (2) Schutz gegen eine Datenklasse, die bei Walmart (3-fuer-1-Split Anfang 2024)
    // sichtbar wurde: SEC XBRL restatiert EPS-Vergleichswerte nach einem Split nur fuer die
    // Jahre, die in der naechsten 10-K-Meldung noch als Vergleichsperiode auftauchen (typ. ~2
    // Jahre), aeltere Jahre bleiben unbereinigt in alten (zu hohen) Werten stehen - waehrend die
    // Kurshistorie (Yahoo) immer vollstaendig splitbereinigt ist. Ueber die GESAMTE Historie
    // gemittelt verzerrte das den KGV-Median bei WMT um mehr als das 5-fache nach unten. Ein
    // engeres, aktuelleres Fenster reduziert dieses Risiko deutlich, auch ohne eine echte
    // Split-Erkennung zu benoetigen.
    $recentCutoffIndex = count($epsSeries) - RECENT_PE_WINDOW_YEARS;

    $pePoints = [];
    $peValues = [];
    foreach ($epsSeries as $i => $e) {
        $price = fv_nearest_price($priceSeries, $e['ts']);
        $pe = ($price !== null && $e['eps'] > 0) ? $price / $e['eps'] : null;
        $pePoints[] = [
            'year' => substr($e['date'], 0, 4),
            'date' => $e['date'],
            'eps' => round($e['eps'], 4),
            'price' => $price !== null ? round($price, 4) : null,
            'pe' => $pe !== null ? round($pe, 2) : null,
        ];
        if ($i >= $recentCutoffIndex && $pe !== null && $pe >= $peMin && $pe <= $peMax) $peValues[] = $pe;
    }

    sort($peValues);
    $n = count($peValues);
    if ($n >= 3) {
        $mid = intdiv($n, 2);
        $normalPE = ($n % 2 === 0) ? ($peValues[$mid - 1] + $peValues[$mid]) / 2 : $peValues[$mid];
    } else {
        $normalPE = $peFallback;
    }

    // Wachstumsrate: Mischung aus 5J-/10J-CAGR und Regression statt reiner Regression (siehe
    // fv_blended_growth_rate) - robuster gegen einzelne volatile Gewinnjahre. $posPoints nutzt
    // hier den Rohwert (nicht log()) da fv_blended_growth_rate selbst logarithmiert. Genau wie
    // beim KGV-Median oben (siehe Kommentar dort) nur aus dem juengsten Fenster gebildet, mit
    // frischem 0-basiertem Index - sonst wuerde z.B. die 10J-CAGR bei WMT exakt ueber den
    // Split-Bruch hinweg rechnen (2016 unbereinigt vs. 2026 bereinigt) und eine massiv falsche,
    // sogar negative "Wachstumsrate" liefern, obwohl die Ertragskraft je Aktie real gestiegen ist.
    $recentEpsForGrowth = array_slice($epsSeries, -RECENT_PE_WINDOW_YEARS);
    $posPoints = [];
    foreach ($recentEpsForGrowth as $i => $e) { if ($e['eps'] > 0) $posPoints[] = ['x' => $i, 'y' => $e['eps']]; }
    $growthPct = fv_blended_growth_rate($posPoints, $growthMin, $growthMax, $growthFallback);

    $latestEps = end($epsSeries)['eps'];
    $fairValueNow = ($latestEps > 0) ? round($normalPE * $latestEps, 2) : null;
    $fairValueForward = ($latestEps > 0) ? round($normalPE * $latestEps * (1 + $growthPct / 100), 2) : null;

    // Wachstums-gerechtfertigtes KGV ("Earnings Justified Value"-Linie) -
    // bewusst getrennt von normalPE/fairValueNow oben (siehe Kommentar bei
    // fv_growth_justified_pe): dort steht "wie hat der Markt historisch bezahlt", hier "was waere
    // angesichts des Wachstums gerechtfertigt".
    $justifiedPE = fv_growth_justified_pe($growthPct);
    $fairValueJustified = ($latestEps > 0) ? round($justifiedPE * $latestEps, 2) : null;

    // Sondereffekt-/Kapitalmassnahmen-Warnung: prueft aufeinanderfolgende Jahres-EPS auf
    // unplausible Sprünge (mehr als verdreifacht oder auf unter ein Drittel gefallen, bei beiden
    // Jahren positivem Gewinn) - typisches Anzeichen fuer Fusionen, Spin-offs, Aktiensplits/
    // -zusammenlegungen oder grosse Sonderabschreibungen, die die Jahre nicht mehr vergleichbar
    // machen (z.B. Amcor/Berry-Global-Fusion 2025: EPS-Basis verschob sich spuerbar). Rein
    // heuristisch (keine echte Split-/M&A-Erkennung noetig, die bräuchte lizenzierte Daten) -
    // dient nur als Warnhinweis, damit die Fair-Value-Zahl nicht blind uebernommen wird.
    $epsDiscontinuity = null;
    for ($i = 1; $i < count($epsSeries); $i++) {
        $prevEps = $epsSeries[$i - 1]['eps'];
        $curEps = $epsSeries[$i]['eps'];
        if ($prevEps > 0 && $curEps > 0) {
            $ratio = $curEps / $prevEps;
            if ($ratio > 3 || $ratio < (1 / 3)) {
                $epsDiscontinuity = [
                    'fromYear' => substr($epsSeries[$i - 1]['date'], 0, 4),
                    'toYear' => substr($epsSeries[$i]['date'], 0, 4),
                    'fromEps' => round($prevEps, 4),
                    'toEps' => round($curEps, 4),
                    'ratio' => round($ratio, 2),
                ];
                break; // erste gefundene Auffaelligkeit reicht als Warnhinweis
            }
        }
    }

    return [
        'normalPE' => round($normalPE, 2),
        'normalPESource' => $n >= 3 ? 'historical_median' : 'fallback_15x',
        'growthRatePct' => round($growthPct, 2),
        'epsTTM' => round($latestEps, 4),
        // justifiedPE/fairValueJustified: "Earnings Justified Value" (aus dem
        // Gewinnwachstum abgeleitetes KGV, siehe fv_growth_justified_pe) - eigenstaendig neben
        // dem historischen normalPE/fairValueNow, NICHT als dessen Ersatz gedacht. fairValueNow
        // bleibt aus Kompatibilitaetsgruenden unveraendert (wird an mehreren Stellen bereits
        // genutzt), justifiedPE/fairValueJustified sind rein additiv.
        'justifiedPE' => round($justifiedPE, 2),
        'fairValueJustified' => $fairValueJustified,
        'fairValueNow' => $fairValueNow,
        'fairValueForward1y' => $fairValueForward,
        'epsHistory' => $pePoints,
        'epsDiscontinuity' => $epsDiscontinuity,
    ];
}

// "Dividend Yield Theory": aktuelle Dividende je Aktie im Verhaeltnis zur durchschnittlichen
// Rendite der letzten (bis zu) 5 Jahre - Fair Value = aktuelle Dividende / Ø-Rendite. Historische
// Rendite je Jahr = Dividende je Aktie dieses Jahres / Kurs zum jeweiligen Fiskaljahresende.
// $priceSeries: [['ts'=>int,'price'=>float], ...]  $dpsSeries: [['date'=>..,'ts'=>int,'eps'=>float
// (Dividende je Aktie, gleiches Feld wie bei sec_fetch_dividend_per_share_single)], ...].
// Ausgelagert aus fundamentals.php, damit dieselbe Berechnung auch anderswo (z.B. spaeter im
// Batch-Endpunkt) ohne Codeduplizierung genutzt werden kann.
function fv_compute_yield_theory($priceSeries, $dpsSeries) {
    if (!$dpsSeries) return null;
    $yieldHistory = [];
    foreach ($dpsSeries as $e) {
        if (empty($e['eps']) || $e['eps'] <= 0) continue;
        $priceAtYearEnd = null;
        foreach ($priceSeries as $p) {
            if ($p['ts'] <= $e['ts']) $priceAtYearEnd = $p['price']; else break;
        }
        if (!$priceAtYearEnd || $priceAtYearEnd <= 0) continue;
        $yieldHistory[] = ['date' => $e['date'], 'yieldPct' => $e['eps'] / $priceAtYearEnd * 100];
    }
    $yieldHistoryRecent = array_slice($yieldHistory, -5);
    $avgYieldPct = null;
    if (count($yieldHistoryRecent) >= 2) {
        $avgYieldPct = array_sum(array_column($yieldHistoryRecent, 'yieldPct')) / count($yieldHistoryRecent);
    }
    $latestDps = end($dpsSeries)['eps'];
    $fairValue = ($avgYieldPct && $avgYieldPct > 0 && $latestDps) ? ($latestDps / ($avgYieldPct / 100)) : null;
    return ['avgYieldPct' => $avgYieldPct !== null ? round($avgYieldPct, 2) : null, 'fairValue' => $fairValue !== null ? round($fairValue, 2) : null, 'yearsCount' => count($yieldHistoryRecent)];
}

// P/S-Ersatzbewertung: greift NUR als Fallback, wenn fv_compute() oben mangels positivem
// TTM-Gewinn keinen Fair Value liefern konnte (typisch bei noch nicht profitablen
// Wachstumsfirmen wie Peloton) - gleicher Grundgedanke wie SimplyWallSt's "Key Valuation
// Metric" (wechselt bei unprofitablen Firmen automatisch von P/E auf P/S). Bewusst als
// separate, klar gekennzeichnete Notloesung gehalten statt in den Blended Fair Value gemischt -
// ein Umsatz-Multiple ignoriert Margen/Profitabilitaet komplett und ist damit eine deutlich
// groebere Naeherung als ein Gewinn-Multiple.
function fv_compute_ps($priceSeries, $rpsSeries, $opts = []) {
    $psMin = $opts['psMin'] ?? 0.1;
    $psMax = $opts['psMax'] ?? 30.0;
    $psFallback = $opts['psFallback'] ?? 2.0;
    $growthMin = $opts['growthMin'] ?? -20.0;
    $growthMax = $opts['growthMax'] ?? 40.0;

    if (!$rpsSeries) return null;
    usort($rpsSeries, function ($a, $b) { return $a['ts'] <=> $b['ts']; });

    $psValues = [];
    foreach ($rpsSeries as $e) {
        $price = fv_nearest_price($priceSeries, $e['ts']);
        $ps = ($price !== null && $e['rps'] > 0) ? $price / $e['rps'] : null;
        if ($ps !== null && $ps >= $psMin && $ps <= $psMax) $psValues[] = $ps;
    }
    sort($psValues);
    $n = count($psValues);
    if ($n >= 3) {
        $mid = intdiv($n, 2);
        $normalPS = ($n % 2 === 0) ? ($psValues[$mid - 1] + $psValues[$mid]) / 2 : $psValues[$mid];
    } else {
        $normalPS = $psFallback;
    }

    // Umsatzwachstum: Mischung aus 5J-/10J-CAGR und Regression (siehe fv_blended_growth_rate) -
    // Umsatz ist praktisch immer positiv, daher keine eps>0-Filterung wie beim Gewinnwachstum in
    // fv_compute() noetig.
    $points = [];
    foreach ($rpsSeries as $i => $e) { if ($e['rps'] > 0) $points[] = ['x' => $i, 'y' => $e['rps']]; }
    $growthPct = fv_blended_growth_rate($points, $growthMin, $growthMax, 0.0);

    $latestRps = end($rpsSeries)['rps'];
    if ($latestRps <= 0) return null;

    return [
        'normalPS' => round($normalPS, 2),
        'normalPSSource' => $n >= 3 ? 'historical_median' : 'fallback_2x',
        'revenueGrowthPct' => round($growthPct, 2),
        'revenuePerShareTTM' => round($latestRps, 4),
        'psFairValueNow' => round($normalPS * $latestRps, 2),
    ];
}

// DCF-Modell (Discounted Cash Flow), klassische Lehrbuchmethodik (Damodaran/John Burr Williams
// 1938) als viertes, unabhaengiges Bewertungsmodell neben KGV/DDM/Dividend-Yield-Theory -
// projiziert den freien Cashflow 5 Jahre in die Zukunft, diskontiert mit einem fixen Zinssatz,
// addiert einen ueber ein Exit-Multiple berechneten Endwert und teilt durch die verwaesserte
// Aktienanzahl. Bewusst konservativ (fixer Diskontsatz statt CAPM/WACC-Herleitung, gedeckelte
// Wachstumsrate, gedeckeltes Exit-Multiple, Zuverlaessigkeits-Flag bei extremen Ergebnissen)
// statt falsche Praezision vorzutaeuschen - angelehnt an oeffentlich dokumentierte, generische
// DCF-Guides (kein proprietaeres Modell einer bestimmten Seite kopiert).
function dcf_compute($fcfSeries, $netDebt, $dilutedShares, $currentPrice, $opts = [], $priceSeries = null) {
    $years = $opts['years'] ?? 5;
    $discountRate = $opts['discountRate'] ?? 0.10;
    $growthMin = $opts['growthMin'] ?? -50.0;
    $growthMax = $opts['growthMax'] ?? 20.0;
    $exitMultipleCap = $opts['exitMultipleCap'] ?? 25.0;
    $exitMultipleFloor = $opts['exitMultipleFloor'] ?? 8.0;

    if (!$fcfSeries || !$dilutedShares || $dilutedShares <= 0) return null;
    usort($fcfSeries, function ($a, $b) { return strcmp($a['date'], $b['date']); });
    $latestFcf = end($fcfSeries)['val'];
    if ($latestFcf <= 0) return null; // Modell passt nicht bei negativem freiem Cashflow

    // Historisches FCF-Wachstum: Mischung aus 5J-/10J-CAGR und Regression (siehe
    // fv_blended_growth_rate), nur positive Jahre, sonst Standardwert 5%.
    $points = [];
    foreach (array_values($fcfSeries) as $i => $e) { if ($e['val'] > 0) $points[] = ['x' => $i, 'y' => $e['val']]; }
    $growthPct = fv_blended_growth_rate($points, $growthMin, $growthMax, 5.0);

    // Exit-Multiple: bevorzugt der historische Median des Kurs/FCF-Verhaeltnisses (wie der Markt
    // das Unternehmen UEBER MEHRERE JAHRE bewertet hat) - NICHT vom aktuellen Kurs abgeleitet.
    // Die vorherige Version nahm min(aktuelles P/FCF, Deckel) als Exit-Multiple, wodurch der
    // heutige Marktpreis zirkulaer in den angeblich unabhaengigen Fair Value einfloss ("die Aktie
    // kostet heute X, also nehmen wir ungefaehr dieses Multiple" statt "welches Multiple ist
    // aufgrund von Wachstum/Qualitaet angemessen"). Nutzt zur Vereinfachung die AKTUELLE
    // verwaesserte Aktienzahl fuer alle historischen Jahre (keine Historie der Aktienzahl
    // verfuegbar) - bei Firmen mit grossen Rueckkauf-/Verwaesserungs-Bewegungen daher nur eine
    // Naeherung, aber deutlich unabhaengiger vom heutigen Kurs als zuvor. Faellt auf den
    // aktuellen P/FCF zurueck, wenn keine Kursreihe uebergeben wurde oder zu wenige historische
    // Jahre mit positivem FCF vorliegen.
    $historicalPFcf = [];
    if ($priceSeries) {
        foreach ($fcfSeries as $e) {
            if ($e['val'] <= 0) continue;
            $ts = strtotime($e['date']);
            $price = $ts ? fv_nearest_price($priceSeries, $ts) : null;
            $fcfPerShare = $e['val'] / $dilutedShares;
            if ($price !== null && $fcfPerShare > 0) $historicalPFcf[] = $price / $fcfPerShare;
        }
    }
    sort($historicalPFcf);
    $histN = count($historicalPFcf);
    $marketCap = $currentPrice !== null ? $currentPrice * $dilutedShares : null;
    $currentPFcf = ($marketCap !== null) ? $marketCap / $latestFcf : null;
    if ($histN >= 3) {
        $mid = intdiv($histN, 2);
        $rawExitMultiple = ($histN % 2 === 0) ? ($historicalPFcf[$mid - 1] + $historicalPFcf[$mid]) / 2 : $historicalPFcf[$mid];
        $exitMultipleSource = 'historical_median';
    } else {
        $rawExitMultiple = $currentPFcf !== null ? $currentPFcf : $exitMultipleCap;
        $exitMultipleSource = $currentPFcf !== null ? 'current_pfcf_fallback' : 'cap_fallback';
    }
    $exitMultiple = max($exitMultipleFloor, min($rawExitMultiple, $exitMultipleCap));

    $fairValuePerShare = dcf_run_scenario($latestFcf, $growthPct, $discountRate, $exitMultiple, $netDebt, $dilutedShares, $years);
    if ($fairValuePerShare === null || $fairValuePerShare <= 0) return null;

    $lowConfidence = false;
    if ($currentPrice !== null && $currentPrice > 0) {
        $ratio = $fairValuePerShare / $currentPrice;
        if ($ratio < 0.5 || $ratio > 2.0) $lowConfidence = true;
    }

    // Bear/Bull-Szenarien: dieselbe Methodik, aber mit vorsichtigerer bzw. optimistischerer
    // Wachstums-/Diskontsatz-Annahme (±3 Prozentpunkte Wachstum, ∓1.5 Punkte Diskontsatz) statt
    // einem einzigen, scheinbar praezisen Punktwert - macht die Sensitivitaet des Modells
    // gegenueber seinen Annahmen sichtbar, angelehnt an das generische Szenario-Konzept aus
    // oeffentlich dokumentierten DCF-Guides (kein proprietaeres Modell einer bestimmten Seite).
    $bearGrowthPct = max($growthMin, $growthPct - 3.0);
    $bullGrowthPct = min($growthMax, $growthPct + 3.0);
    $bearDiscountRate = $discountRate + 0.015;
    $bullDiscountRate = max(0.05, $discountRate - 0.015);
    $bearFv = dcf_run_scenario($latestFcf, $bearGrowthPct, $bearDiscountRate, $exitMultiple, $netDebt, $dilutedShares, $years);
    $bullFv = dcf_run_scenario($latestFcf, $bullGrowthPct, $bullDiscountRate, $exitMultiple, $netDebt, $dilutedShares, $years);

    return [
        'dcfFairValue' => round($fairValuePerShare, 2),
        'dcfGrowthPct' => round($growthPct, 2),
        'dcfExitMultiple' => round($exitMultiple, 2),
        'dcfExitMultipleSource' => $exitMultipleSource,
        'dcfFcfTTM' => round($latestFcf, 0),
        'dcfDiscountRatePct' => round($discountRate * 100, 1),
        'dcfLowConfidence' => $lowConfidence,
        'dcfBear' => $bearFv !== null ? round($bearFv, 2) : null,
        'dcfBearGrowthPct' => round($bearGrowthPct, 2),
        'dcfBearDiscountRatePct' => round($bearDiscountRate * 100, 1),
        'dcfBull' => $bullFv !== null ? round($bullFv, 2) : null,
        'dcfBullGrowthPct' => round($bullGrowthPct, 2),
        'dcfBullDiscountRatePct' => round($bullDiscountRate * 100, 1),
    ];
}

// Reiner Rechenkern fuer EIN DCF-Szenario (Basis/Bear/Bull nutzen alle dieselbe Funktion mit
// unterschiedlichem Wachstum/Diskontsatz) - projiziert den freien Cashflow, diskontiert ihn plus
// einen Exit-Multiple-Endwert, zieht die Nettoschulden ab und teilt durch die Aktienanzahl.
function dcf_run_scenario($latestFcf, $growthPct, $discountRate, $exitMultiple, $netDebt, $dilutedShares, $years) {
    $pvSum = 0;
    $fcfT = $latestFcf;
    for ($t = 1; $t <= $years; $t++) {
        $fcfT = $fcfT * (1 + $growthPct / 100);
        $pvSum += $fcfT / pow(1 + $discountRate, $t);
    }
    $terminalValue = $fcfT * $exitMultiple;
    $pvTerminal = $terminalValue / pow(1 + $discountRate, $years);
    $enterpriseValue = $pvSum + $pvTerminal;
    $equityValue = $enterpriseValue - $netDebt;
    $fairValuePerShare = $equityValue / $dilutedShares;
    return $fairValuePerShare;
}

// Beta (Marktrisiko vs. S&P 500): misst, wie stark eine Aktie im Vergleich zum Gesamtmarkt
// schwankt - Beta 1 = bewegt sich im Schnitt wie der Markt, >1 = staerkere Ausschlaege (nach
// oben wie unten), <1 = ruhiger als der Markt, negativ = bewegt sich tendenziell entgegengesetzt.
// Klassische Definition: Beta = Kovarianz(Aktienrendite, Marktrendite) / Varianz(Marktrendite),
// hier aus monatlichen Renditen der letzten (bis zu) 20 Jahre berechnet. $priceSeries/
// $benchmarkSeries: [['ts'=>int,'price'=>float], ...] - dieselbe kostenlose Yahoo-Kursreihe wie
// im KGV-Modell, nur zusaetzlich fuer den Vergleichsindex.
function beta_compute($priceSeries, $benchmarkSeries) {
    if (count($priceSeries) < 13 || count($benchmarkSeries) < 13) return null;

    $byMonth = function ($series) {
        $out = [];
        foreach ($series as $p) { $out[gmdate('Y-m', $p['ts'])] = $p['price']; } // letzter Wert je Monat gewinnt
        return $out;
    };
    $stockByMonth = $byMonth($priceSeries);
    $benchByMonth = $byMonth($benchmarkSeries);

    $months = array_values(array_intersect(array_keys($stockByMonth), array_keys($benchByMonth)));
    sort($months);
    if (count($months) < 13) return null;

    $stockReturns = [];
    $benchReturns = [];
    for ($i = 1; $i < count($months); $i++) {
        $prevStock = $stockByMonth[$months[$i - 1]];
        $curStock = $stockByMonth[$months[$i]];
        $prevBench = $benchByMonth[$months[$i - 1]];
        $curBench = $benchByMonth[$months[$i]];
        if ($prevStock > 0 && $prevBench > 0) {
            $stockReturns[] = ($curStock - $prevStock) / $prevStock;
            $benchReturns[] = ($curBench - $prevBench) / $prevBench;
        }
    }
    $n = count($stockReturns);
    if ($n < 12) return null;

    $meanStock = array_sum($stockReturns) / $n;
    $meanBench = array_sum($benchReturns) / $n;
    $cov = 0;
    $varBench = 0;
    for ($i = 0; $i < $n; $i++) {
        $cov += ($stockReturns[$i] - $meanStock) * ($benchReturns[$i] - $meanBench);
        $varBench += ($benchReturns[$i] - $meanBench) ** 2;
    }
    if ($varBench == 0) return null;

    return ['beta' => round($cov / $varBench, 2), 'monthsCount' => $n];
}
