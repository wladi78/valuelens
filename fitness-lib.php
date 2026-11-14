<?php
// Finanzielle Fitness nach Vorlage "Fiscal fitness" (Nutzer 13.11.2026: Vorlage wird abgeschafft, alles uebernehmen).
// Jahreswerte aus den SEC-Jahresberichten (10-K/20-F/40-F) ueber companyfacts - ein Abruf je Firma, 7 Tage Cache.
// Liefert Rohwerte je Geschaeftsjahr; die 12 Grafiken (FCF > Dividende, Rueckkaeufe, Margen, ROE, ROIC ...) rechnet index.php.
require_once __DIR__ . '/bewertung-lib.php';

function fitness_daten($symbol, $neu = false) {
    $dir = __DIR__ . '/data/cache/fitness';
    $datei = $dir . '/' . preg_replace('/[^A-Z0-9.\-]/', '_', $symbol) . '.v2.json';
    if (!$neu && is_file($datei) && time() - filemtime($datei) < 7 * 86400) {
        $c = json_decode((string) file_get_contents($datei), true);
        if (is_array($c)) return $c;
    }
    $out = ['symbol' => $symbol, 'quelle' => null, 'waehrung' => null, 'jahre' => [], 'stand' => date('Y-m-d')];
    $map = sec_get_cik_map();
    $cik = $map[$symbol] ?? ($map[strtok($symbol, '.')] ?? null);
    if (!$cik) { $out['fehler'] = 'Keine SEC-Daten (nur US-gelistete Firmen).'; return $out; }
    $cf = br_sec_json("https://data.sec.gov/api/xbrl/companyfacts/CIK{$cik}.json");
    if (!$cf) { $out['fehler'] = 'SEC nicht erreichbar.'; return $out; }
    $facts = $cf['facts'] ?? [];
    $tax = (count($facts['ifrs-full'] ?? []) > count($facts['us-gaap'] ?? [])) ? 'ifrs-full' : 'us-gaap';
    $waehr = null;
    // Einheit waehlen: Geld (USD/EUR...), Stueck (shares) oder je Aktie (USD/shares)
    $einheit = function ($units, $typ) use (&$waehr) {
        foreach (array_keys($units) as $k) {
            if ($typ === 'stueck' && $k === 'shares') return $k;
            if ($typ === 'jeAktie' && str_ends_with($k, '/shares') && (!$waehr || str_starts_with($k, $waehr . '/'))) return $k;
            if ($typ === 'geld' && strpos($k, '/') === false && !in_array($k, ['shares', 'pure'], true) && (!$waehr || $k === $waehr)) { if (!$waehr) $waehr = $k; return $k; }
        }
        return null;
    };
    $jahre = function ($tags, $typ = 'geld', &$eingereicht = null) use ($facts, $tax, $einheit) {   // Zeitraum-Werte (12 Monate)
        $o = []; $eingereicht = [];
        foreach ((array) $tags as $t) {
            $u = $facts[$tax][$t]['units'] ?? null; if (!$u) continue;
            $k = $einheit($u, $typ); if (!$k) continue;
            foreach (br_jahre($u[$k], $fd) as $d => $v) if (!isset($o[$d])) { $o[$d] = $v; $eingereicht[$d] = $fd[$d] ?? ''; }
        }
        ksort($o); return $o;
    };
    $stichtag = function ($tags, $alle = false) use ($facts, $tax, $einheit) {   // Bilanzwerte zum Jahresende (alle = auch Quartalsberichte)
        $o = [];
        foreach ((array) $tags as $t) {
            $u = $facts[$tax][$t]['units'] ?? null; if (!$u) continue;
            $k = $einheit($u, 'geld'); if (!$k) continue;
            $by = [];
            foreach ($u[$k] as $e) {
                if (!in_array($e['form'] ?? '', $alle ? ['10-K', '10-K/A', '10-Q', '10-Q/A', '20-F', '20-F/A', '40-F', '40-F/A', '6-K'] : ['10-K', '10-K/A', '20-F', '20-F/A', '40-F', '40-F/A'], true) || isset($e['start']) || !isset($e['end'], $e['val'])) continue;
                $f = $e['filed'] ?? '';
                if (!isset($by[$e['end']]) || $f >= $by[$e['end']][1]) $by[$e['end']] = [(float) $e['val'], $f];
            }
            foreach ($by as $d => $x) if (!isset($o[$d])) $o[$d] = $x[0];
        }
        ksort($o); return $o;
    };
    $quartale = function ($tags, $typ = 'geld') use ($facts, $tax, $einheit) {
        $o = [];
        foreach ((array) $tags as $t) {
            $u = $facts[$tax][$t]['units'] ?? null; if (!$u) continue;
            $k = $einheit($u, $typ); if (!$k) continue;
            $q3 = []; $cum = []; $fy = [];
            foreach ($u[$k] as $e) {
                if (!isset($e['start'], $e['end'], $e['val']) || !preg_match('/^(10-[KQ]|20-F|40-F)/', $e['form'] ?? '')) continue;
                $tage = (strtotime($e['end']) - strtotime($e['start'])) / 86400; $f = $e['filed'] ?? '';
                if ($tage >= 80 && $tage <= 100 && (!isset($q3[$e['end']]) || $f >= $q3[$e['end']][1])) $q3[$e['end']] = [(float) $e['val'], $f];
                if (!isset($cum[$e['start']][$e['end']]) || $f >= $cum[$e['start']][$e['end']][1]) $cum[$e['start']][$e['end']] = [(float) $e['val'], $f];
                if ($tage >= 340 && $tage <= 390) $fy[$e['start']] = $e['end'];
            }
            // Geschaeftsjahresanfaenge: mit Jahreswert ODER mit aufgelaufenem Halbjahreswert (laufendes Jahr ohne 10-K)
            foreach ($cum as $start => $reihe) foreach ($reihe as $d => $x) { $tg = (strtotime($d) - strtotime($start)) / 86400; if ($tg >= 170 && $tg <= 200 && !isset($fy[$start])) $fy[$start] = date('Y-m-d', strtotime($start . ' +1 year -1 day')); }
            foreach ($fy as $start => $ende) {
                $pkte = array_filter($cum[$start] ?? [], fn($x, $d) => $d <= $ende && (strtotime($d) - strtotime($start)) / 86400 >= 80, ARRAY_FILTER_USE_BOTH);
                ksort($pkte); $vor = null;
                foreach ($pkte as $d => $x) {
                    if (!isset($o[$d])) {
                        if (isset($q3[$d])) $o[$d] = $q3[$d][0];
                        elseif ($typ === 'stueck') $o[$d] = $x[0];   // Aktienzahl: Durchschnitt, nicht differenzierbar
                        elseif ($vor === null) { if ((strtotime($d) - strtotime($start)) / 86400 <= 100) $o[$d] = $x[0]; }
                        else $o[$d] = $x[0] - $vor;
                    }
                    $vor = $x[0];
                }
            }
            foreach ($q3 as $d => $x) if (!isset($o[$d])) $o[$d] = $x[0];
        }
        ksort($o); return $o;
    };
    $g = $tax === 'us-gaap';
    $ocf = $jahre($g ? ['NetCashProvidedByUsedInOperatingActivities', 'NetCashProvidedByUsedInOperatingActivitiesContinuingOperations'] : ['CashFlowsFromUsedInOperatingActivities']);
    $umsatz = $jahre($g ? ['Revenues', 'RevenueFromContractWithCustomerExcludingAssessedTax', 'SalesRevenueNet', 'RevenueFromContractWithCustomerIncludingAssessedTax', 'SalesRevenueGoodsNet'] : ['Revenue', 'RevenueFromContractsWithCustomers']);
    $gewinn = $jahre($g ? ['NetIncomeLoss', 'NetIncomeLossAvailableToCommonStockholdersBasic', 'ProfitLoss'] : ['ProfitLossAttributableToOwnersOfParent', 'ProfitLoss']);
    $brutto = $jahre($g ? ['GrossProfit'] : ['GrossProfit']);
    $betrieb = $jahre($g ? ['OperatingIncomeLoss'] : ['ProfitLossFromOperatingActivities']);
    $kosten = $jahre($g ? ['CostOfRevenue', 'CostOfGoodsAndServicesSold', 'CostOfGoodsSold'] : ['CostOfSales']);
    $capex = $jahre($g ? ['PaymentsToAcquirePropertyPlantAndEquipment', 'PaymentsForCapitalExpenditures'] : ['PurchaseOfPropertyPlantAndEquipmentClassifiedAsInvestingActivities', 'PurchaseOfPropertyPlantAndEquipment']);
    $divGez = $jahre($g ? ['PaymentsOfDividendsCommonStock', 'PaymentsOfDividends'] : ['DividendsPaidClassifiedAsFinancingActivities', 'DividendsPaid']);
    $divJe = $jahre($g ? ['CommonStockDividendsPerShareCashPaid', 'CommonStockDividendsPerShareDeclared'] : ['DividendsRecognisedAsDistributionsToOwnersPerShare'], 'jeAktie', $fdDiv);
    $rueck = $jahre($g ? ['PaymentsForRepurchaseOfCommonStock', 'PaymentsForRepurchaseOfEquity'] : ['PaymentsToAcquireOrRedeemEntitysShares']);
    $aktien = $jahre($g ? ['WeightedAverageNumberOfDilutedSharesOutstanding', 'WeightedAverageNumberOfShareOutstandingBasicAndDiluted', 'WeightedAverageNumberOfSharesOutstandingBasic'] : ['AdjustedWeightedAverageShares', 'WeightedAverageShares'], 'stueck', $fdAkt);
    // Aktiensplits wie in bewertung_reihen: Jahre, die nur in Berichten vor dem Split stehen, auf heutige Aktien umrechnen
    // (sonst springt der Free Cashflow je Aktie beim Split; Yahoo-Kurse und -Dividenden sind bereits bereinigt)
    try { $splits = bewertung_reihen($symbol)['splits'] ?? []; } catch (Throwable $e) { $splits = []; }
    foreach ($splits as $sp) {
        foreach ($aktien as $d => $v) if (($fdAkt[$d] ?? '') !== '' && $fdAkt[$d] < $sp['date']) $aktien[$d] = $v * $sp['faktor'];
        foreach ($divJe as $d => $v) if (($fdDiv[$d] ?? '') !== '' && $fdDiv[$d] < $sp['date']) $divJe[$d] = $v / $sp['faktor'];
    }
    $out['splits'] = $splits;
    $steuer = $jahre($g ? ['IncomeTaxExpenseBenefit'] : ['IncomeTaxExpenseContinuingOperations']);
    $abschr = $jahre($g ? ['DepreciationDepletionAndAmortization', 'DepreciationAmortizationAndAccretionNet', 'DepreciationAndAmortization', 'Depreciation'] : ['DepreciationAndAmortisationExpense', 'DepreciationAmortisationAndImpairmentLossReversalOfImpairmentLossRecognisedInProfitOrLoss']);
    $vorSt = $jahre($g ? ['IncomeLossFromContinuingOperationsBeforeIncomeTaxesExtraordinaryItemsNoncontrollingInterest', 'IncomeLossFromContinuingOperationsBeforeIncomeTaxesMinorityInterestAndIncomeLossFromEquityMethodInvestments'] : ['ProfitLossBeforeTax']);
    $vermoegen = $stichtag($g ? ['Assets'] : ['Assets']);
    $ek = $stichtag($g ? ['StockholdersEquity', 'StockholdersEquityIncludingPortionAttributableToNoncontrollingInterest'] : ['EquityAttributableToOwnersOfParent', 'Equity']);
    $schuldGes = $stichtag($g ? ['LongTermDebt', 'LongTermDebtAndCapitalLeaseObligationsIncludingCurrentMaturities', 'DebtLongtermAndShorttermCombinedAmount'] : ['Borrowings']);
    $schuldLang = $stichtag($g ? ['LongTermDebtNoncurrent', 'LongTermDebtAndCapitalLeaseObligations'] : ['NoncurrentPortionOfNoncurrentBorrowings', 'LongtermBorrowings']);
    $schuldKurz = $stichtag($g ? ['LongTermDebtCurrent', 'LongTermDebtAndCapitalLeaseObligationsCurrent'] : ['CurrentPortionOfNoncurrentBorrowings']);
    // Bilanzwert zum Jahresende - Stichtag kann um ein paar Tage abweichen (52/53-Wochen-Jahr)
    $am = function ($reihe, $d) { if (isset($reihe[$d])) return $reihe[$d]; $t = strtotime($d); foreach ($reihe as $x => $v) if (abs(strtotime($x) - $t) <= 12 * 86400) return $v; return null; };
    $basis = $umsatz ?: $ocf;
    $out['quelle'] = $tax === 'us-gaap' ? 'SEC 10-K' : 'SEC 20-F (IFRS)';
    $out['waehrung'] = $waehr ?: 'USD';
    $out['firma'] = $cf['entityName'] ?? null;
    foreach ($basis as $d => $_) {
        if ($d < date('Y-m-d', strtotime('-21 years'))) continue;
        $sch = $am($schuldGes, $d);
        if ($sch === null) { $l = $am($schuldLang, $d); $k = $am($schuldKurz, $d); if ($l !== null || $k !== null) $sch = (float) $l + (float) $k; }
        $st = ($steuer[$d] ?? null) !== null && !empty($vorSt[$d]) && $vorSt[$d] > 0 ? $steuer[$d] / $vorSt[$d] : null;
        $br = $brutto[$d] ?? ((isset($umsatz[$d], $kosten[$d])) ? $umsatz[$d] - $kosten[$d] : null);
        $out['jahre'][] = [
            'date' => $d, 'umsatz' => $umsatz[$d] ?? null, 'gewinn' => $gewinn[$d] ?? null, 'brutto' => $br, 'betrieb' => $betrieb[$d] ?? null,
            'ocf' => $ocf[$d] ?? null, 'capex' => isset($capex[$d]) ? abs($capex[$d]) : null, 'divGezahlt' => isset($divGez[$d]) ? abs($divGez[$d]) : null,
            'divJe' => $divJe[$d] ?? null, 'rueckkauf' => isset($rueck[$d]) ? abs($rueck[$d]) : null, 'aktien' => $aktien[$d] ?? null,
            'steuersatz' => $st !== null ? round(max(0, min(0.5, $st)), 4) : null, 'abschreibung' => isset($abschr[$d]) ? abs($abschr[$d]) : null,
            'vermoegen' => $am($vermoegen, $d), 'ek' => $am($ek, $d), 'schulden' => $sch,
            'schuldenLang' => $am($schuldLang, $d) ?? ($sch !== null && $am($schuldKurz, $d) !== null ? $sch - $am($schuldKurz, $d) : null),
        ];
    }
    // Quartale der letzten 10 Jahre (Nutzer 13.11.2026: nach Vorlage "Period: Quarterly")
    $T = [
        'umsatz' => $g ? ['Revenues', 'RevenueFromContractWithCustomerExcludingAssessedTax', 'SalesRevenueNet', 'RevenueFromContractWithCustomerIncludingAssessedTax', 'SalesRevenueGoodsNet'] : ['Revenue', 'RevenueFromContractsWithCustomers'],
        'gewinn' => $g ? ['NetIncomeLoss', 'NetIncomeLossAvailableToCommonStockholdersBasic', 'ProfitLoss'] : ['ProfitLossAttributableToOwnersOfParent', 'ProfitLoss'],
        'brutto' => ['GrossProfit'], 'betrieb' => $g ? ['OperatingIncomeLoss'] : ['ProfitLossFromOperatingActivities'],
        'kosten' => $g ? ['CostOfRevenue', 'CostOfGoodsAndServicesSold', 'CostOfGoodsSold'] : ['CostOfSales'],
        'ocf' => $g ? ['NetCashProvidedByUsedInOperatingActivities', 'NetCashProvidedByUsedInOperatingActivitiesContinuingOperations'] : ['CashFlowsFromUsedInOperatingActivities'],
        'capex' => $g ? ['PaymentsToAcquirePropertyPlantAndEquipment', 'PaymentsForCapitalExpenditures'] : ['PurchaseOfPropertyPlantAndEquipmentClassifiedAsInvestingActivities', 'PurchaseOfPropertyPlantAndEquipment'],
        'divGezahlt' => $g ? ['PaymentsOfDividendsCommonStock', 'PaymentsOfDividends'] : ['DividendsPaidClassifiedAsFinancingActivities', 'DividendsPaid'],
        'rueckkauf' => $g ? ['PaymentsForRepurchaseOfCommonStock', 'PaymentsForRepurchaseOfEquity'] : ['PaymentsToAcquireOrRedeemEntitysShares'],
    ];
    $Q = []; foreach ($T as $n => $tags) $Q[$n] = $quartale($tags);
    $Q['aktien'] = $quartale($g ? ['WeightedAverageNumberOfDilutedSharesOutstanding', 'WeightedAverageNumberOfShareOutstandingBasicAndDiluted', 'WeightedAverageNumberOfSharesOutstandingBasic'] : ['AdjustedWeightedAverageShares', 'WeightedAverageShares'], 'stueck');
    $vermQ = $stichtag($g ? ['Assets'] : ['Assets'], true);
    $ekQ = $stichtag($g ? ['StockholdersEquity', 'StockholdersEquityIncludingPortionAttributableToNoncontrollingInterest'] : ['EquityAttributableToOwnersOfParent', 'Equity'], true);
    $schQ = $stichtag($g ? ['LongTermDebt', 'LongTermDebtAndCapitalLeaseObligationsIncludingCurrentMaturities', 'DebtLongtermAndShorttermCombinedAmount'] : ['Borrowings'], true);
    $schLQ = $stichtag($g ? ['LongTermDebtNoncurrent', 'LongTermDebtAndCapitalLeaseObligations'] : ['NoncurrentPortionOfNoncurrentBorrowings', 'LongtermBorrowings'], true);
    $out['quartale'] = [];
    $grenze = date('Y-m-d', strtotime('-10 years'));
    foreach (($Q['umsatz'] ?: $Q['ocf']) as $d => $_) {
        if ($d < $grenze) continue;
        $z = ['date' => $d];
        foreach (['umsatz', 'gewinn', 'betrieb', 'ocf'] as $n) $z[$n] = $Q[$n][$d] ?? null;
        foreach (['capex', 'divGezahlt', 'rueckkauf'] as $n) $z[$n] = isset($Q[$n][$d]) ? abs($Q[$n][$d]) : null;
        $z['brutto'] = $Q['brutto'][$d] ?? (isset($Q['umsatz'][$d], $Q['kosten'][$d]) ? $Q['umsatz'][$d] - $Q['kosten'][$d] : null);
        $z['aktien'] = $Q['aktien'][$d] ?? null;
        $z['vermoegen'] = $am($vermQ, $d); $z['ek'] = $am($ekQ, $d); $z['schulden'] = $am($schQ, $d); $z['schuldenLang'] = $am($schLQ, $d);
        $z['steuersatz'] = null; $z['divJe'] = null;
        $out['quartale'][] = $z;
    }
    // Aktienzahl der Quartale um Splits bereinigen: Quartale vor dem Splittag (Berichtsdatum unbekannt -> Quartalsende) umrechnen,
    // wenn die Zahl gegenueber dem naechsten Quartal um den Splitfaktor springt
    foreach ($splits as $sp) {
        $n = count($out['quartale']);
        for ($i = 0; $i < $n; $i++) {
            $a = $out['quartale'][$i]['aktien'] ?? null; if (!$a || $out['quartale'][$i]['date'] >= $sp['date']) continue;
            $nach = null; for ($j = $i + 1; $j < $n; $j++) if (($out['quartale'][$j]['date'] ?? '') >= $sp['date'] && !empty($out['quartale'][$j]['aktien'])) { $nach = $out['quartale'][$j]['aktien']; break; }
            if ($nach && $nach / $a > $sp['faktor'] * 0.7) $out['quartale'][$i]['aktien'] = $a * $sp['faktor'];
        }
    }
    if (!$out['jahre']) $out['fehler'] = 'Keine Jahreswerte in den SEC-Berichten gefunden.';
    if (empty($GLOBALS['br_sec_fehler']) && $out['jahre']) { if (!is_dir($dir)) @mkdir($dir, 0700, true); @file_put_contents($datei, json_encode($out, JSON_UNESCAPED_UNICODE)); }
    return $out;
}

// Firmenangaben nach Vorlage "Company Info" / "Vorlage Facts" (Nutzer 13.11.2026): Land, Branche, Marktwert, Unternehmenswert,
// Schulden, Analystenurteil und die Gewinnueberraschungen der letzten 4 Quartale (Ersatz fuer FASTs "Analyst Scorecard",
// deren Schaetzungshistorie es frei nicht gibt). Yahoo quoteSummary, 1 Tag Cache. S&P-Kreditrating gibt es frei nicht.
function firmen_info($symbol) {
    $dir = __DIR__ . '/data/cache/fitness';
    $datei = $dir . '/info3_' . preg_replace('/[^A-Z0-9.\-]/', '_', $symbol) . '.json';
    if (is_file($datei) && time() - filemtime($datei) < 86400) { $c = json_decode((string) file_get_contents($datei), true); if (is_array($c)) return $c; }
    $ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36';
    $sess = yahoo_get_session($ua);
    if (!$sess) return ['fehler' => 'Yahoo nicht erreichbar'];
    $res = yahoo_curl_with_session('https://query2.finance.yahoo.com/v10/finance/quoteSummary/' . rawurlencode($symbol)
        . '?modules=assetProfile,price,defaultKeyStatistics,financialData,earningsHistory,summaryDetail,earningsTrend,recommendationTrend&crumb=' . rawurlencode($sess['crumb']), $ua, $sess);
    $q = $res['code'] === 200 ? (json_decode($res['body'], true)['quoteSummary']['result'][0] ?? null) : null;
    if (!$q) return ['fehler' => 'Yahoo ohne Daten (' . $res['code'] . ')'];
    $r = fn($a, $k) => $a[$k]['raw'] ?? null;
    $ap = $q['assetProfile'] ?? []; $pr = $q['price'] ?? []; $ks = $q['defaultKeyStatistics'] ?? []; $fd = $q['financialData'] ?? []; $sd = $q['summaryDetail'] ?? [];
    $out = [
        'land' => $ap['country'] ?? null, 'branche' => $ap['industry'] ?? null, 'sektor' => $ap['sector'] ?? null, 'mitarbeiter' => $ap['fullTimeEmployees'] ?? null,
        'waehrung' => $pr['currency'] ?? null, 'typ' => $pr['quoteType'] ?? null, 'boerse' => $pr['exchangeName'] ?? null,
        'marktwert' => $r($pr, 'marketCap'), 'unternehmenswert' => $r($ks, 'enterpriseValue'), 'schulden' => $r($fd, 'totalDebt'), 'kasse' => $r($fd, 'totalCash'),
        'beta' => $r($sd, 'beta') ?? $r($ks, 'beta'), 'vortag' => $r($sd, 'previousClose'),
        'urteil' => $fd['recommendationKey'] ?? null, 'urteilWert' => $r($fd, 'recommendationMean'), 'analysten' => $r($fd, 'numberOfAnalystOpinions'),
        'kursziel' => $r($fd, 'targetMeanPrice'), 'kurszielTief' => $r($fd, 'targetLowPrice'), 'kurszielHoch' => $r($fd, 'targetHighPrice'),
        'quartale' => [], 'stand' => date('Y-m-d H:i'),
    ];
    foreach ($q['earningsHistory']['history'] ?? [] as $h) {
        $out['quartale'][] = ['quartal' => $h['quarter']['fmt'] ?? null, 'schaetzung' => $r($h, 'epsEstimate'), 'ist' => $r($h, 'epsActual'), 'abweichung' => $r($h, 'surprisePercent')];
    }
    // Langfrist-Wachstum (+5y) und Schaetzungs-Historie je Geschaeftsjahr (0y, +1y): heute, vor 7/30/60/90 Tagen, Spanne
    $out['ltg'] = null; $out['trend'] = [];
    foreach ($q['earningsTrend']['trend'] ?? [] as $t) {
        $per = $t['period'] ?? '';
        if ($per === '+5y' || $per === 'LTG') { $v = $r($t, 'growth'); if ($v !== null) $out['ltg'] = $v; }
        if (in_array($per, ['0y', '+1y'], true)) {
            $et = $t['epsTrend'] ?? []; $ee = $t['earningsEstimate'] ?? [];
            $out['trend'][] = ['periode' => $per, 'ende' => $t['endDate'] ?? null, 'aktuell' => $r($et, 'current'), 'vor7' => $r($et, '7daysAgo'),
                'vor30' => $r($et, '30daysAgo'), 'vor60' => $r($et, '60daysAgo'), 'vor90' => $r($et, '90daysAgo'),
                'tief' => $r($ee, 'low'), 'hoch' => $r($ee, 'high'), 'mittel' => $r($ee, 'avg'), 'analysten' => $r($ee, 'numberOfAnalysts'),
                // Schaetzungsaenderungen: wie viele Analysten haben in 7/30 Tagen angehoben bzw. gesenkt
                'rauf7' => $r($t['epsRevisions'] ?? [], 'upLast7days'), 'rauf30' => $r($t['epsRevisions'] ?? [], 'upLast30days'),
                'runter7' => $r($t['epsRevisions'] ?? [], 'downLast7Days') ?? $r($t['epsRevisions'] ?? [], 'downLast7days'),
                'runter30' => $r($t['epsRevisions'] ?? [], 'downLast30days')];
        }
    }
    // Analystenstimmung: Anzahl Empfehlungen jetzt (0m) und vor 1-3 Monaten
    $out['stimmung'] = [];
    foreach ($q['recommendationTrend']['trend'] ?? [] as $t) $out['stimmung'][] = ['periode' => $t['period'] ?? '', 'starkKaufen' => (int) ($t['strongBuy'] ?? 0),
        'kaufen' => (int) ($t['buy'] ?? 0), 'halten' => (int) ($t['hold'] ?? 0), 'verkaufen' => (int) ($t['sell'] ?? 0), 'starkVerkaufen' => (int) ($t['strongSell'] ?? 0)];
    for ($i = 1; $i < count($out['trend']); $i++) if ($out['trend'][$i]['ende'] && $out['trend'][$i]['ende'] <= $out['trend'][$i - 1]['ende']) $out['trend'][$i]['ende'] = date('Y-m-d', strtotime($out['trend'][$i - 1]['ende'] . ' +1 year'));
    if (!is_dir($dir)) @mkdir($dir, 0700, true);
    @file_put_contents($datei, json_encode($out, JSON_UNESCAPED_UNICODE));
    return $out;
}


// Kurzkennzahlen fuer die Bewertungsliste (Nutzer 13.11.2026: Filter ROE, Schulden/Kapital, "FCF deckt Dividende").
// $nurCache = true: nur vorhandenen Zwischenspeicher nutzen (kein SEC-Abruf) - die Liste bleibt schnell, der Bewertungs-Bot
// fuellt den Speicher nachts (bewertung-signale.php).
function fitness_kennzahlen($symbol, $nurCache = false) {
    $datei = __DIR__ . '/data/cache/fitness/' . preg_replace('/[^A-Z0-9.\-]/', '_', $symbol) . '.v2.json';
    if ($nurCache && !is_file($datei)) return ['fehlt' => true];
    $d = fitness_daten($symbol);
    $j = $d['jahre'] ?? []; $q = $d['quartale'] ?? [];
    if (!$j) return ['fehler' => $d['fehler'] ?? 'keine Daten'];
    $l = end($j); $vor = count($j) > 1 ? $j[count($j) - 2] : null;
    $o = ['stand' => $l['date']];
    // ROE nach Vorlage: Gewinn / Durchschnitt der 4 Quartalsstaende des Eigenkapitals im Geschaeftsjahr
    $ekQ = array_values(array_filter($q, fn($x) => $vor && $x['date'] > $vor['date'] && $x['date'] <= $l['date'] && $x['ek'] !== null));
    $ek = count($ekQ) === 4 ? array_sum(array_column($ekQ, 'ek')) / 4 : (($l['ek'] ?? 0) > 0 && ($vor['ek'] ?? 0) > 0 ? ($l['ek'] + $vor['ek']) / 2 : ($l['ek'] ?? null));
    $o['roe'] = ($l['gewinn'] ?? null) !== null && $ek > 0 ? round($l['gewinn'] / $ek * 100, 1) : null;
    // Langfristige Schulden / (langfr. Schulden + Eigenkapital), letzter Quartalsbericht - nach Vorlage "LT Debt/Capital"
    $lq = $q ? end($q) : $l; $ls = $lq['schuldenLang'] ?? $lq['schulden'] ?? null;
    $o['ldk'] = $ls !== null && $lq['ek'] !== null && ($ls + $lq['ek']) > 0 ? round($ls / ($ls + $lq['ek']) * 100, 1) : null;
    // Free Cashflow / gezahlte Dividenden: letztes Jahr und Summe der letzten 5 Jahre (glaettet Ausreisser)
    $fcf = fn($y) => ($y['ocf'] ?? null) !== null ? $y['ocf'] - ($y['capex'] ?? 0) : null;
    $o['fcfDiv'] = ($l['divGezahlt'] ?? 0) > 0 && $fcf($l) !== null ? round($fcf($l) / $l['divGezahlt'], 2) : null;
    $f5 = 0; $d5 = 0; foreach (array_slice($j, -5) as $y) { if ($fcf($y) === null || ($y['divGezahlt'] ?? null) === null) continue; $f5 += $fcf($y); $d5 += $y['divGezahlt']; }
    $o['fcfDiv5'] = $d5 > 0 ? round($f5 / $d5, 2) : null;
    $o['marge'] = ($l['umsatz'] ?? 0) > 0 && ($l['gewinn'] ?? null) !== null ? round($l['gewinn'] / $l['umsatz'] * 100, 1) : null;
    $u5 = array_slice($j, -6); $a = $u5[0]['umsatz'] ?? null; $b = end($u5)['umsatz'] ?? null; $n = count($u5) - 1;
    $o['umsatzG'] = $a > 0 && $b > 0 && $n >= 3 ? round((pow($b / $a, 1 / $n) - 1) * 100, 1) : null;
    return $o;
}
