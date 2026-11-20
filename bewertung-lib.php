<?php
// Rechenkern fuer Bewertungs-Chart, Bewertungsliste und den naechtlichen Bewertungs-Bot (bewertung-signale.php).
// Beschreibung der Reihen siehe bewertung-reihen.php.
require_once __DIR__ . '/sec-lib.php';
require_once __DIR__ . '/yahoo-fundamentals-lib.php';

function br_median($a) { sort($a); $n = count($a); if (!$n) return null; return $n % 2 ? $a[intdiv($n, 2)] : ($a[$n / 2 - 1] + $a[$n / 2]) / 2; }

function br_nach_datum($reihe) { $o = []; foreach ($reihe as $e) $o[$e['date']] = $e['val']; return $o; }

function br_jahre($units, &$filed = null) {
    $byEnd = [];
    foreach ($units as $e) {
        if (!in_array($e['form'] ?? '', ['10-K', '10-K/A', '20-F', '20-F/A', '40-F', '40-F/A'], true)) continue;
        if (($e['fp'] ?? '') !== 'FY' || !isset($e['start'], $e['end'], $e['val'])) continue;
        if ($e['form'][0] !== '1') $GLOBALS['br_auslaendisch'] = true;   // 20-F/40-F = auslaendischer Melder (ADR moeglich)
        $tage = (strtotime($e['end']) - strtotime($e['start'])) / 86400;
        if ($tage < 340 || $tage > 390) continue;
        $f = $e['filed'] ?? '';
        if (!isset($byEnd[$e['end']]) || $f >= $byEnd[$e['end']]['f']) $byEnd[$e['end']] = ['v' => (float) $e['val'], 'f' => $f];
    }
    ksort($byEnd);
    $o = []; $filed = []; foreach ($byEnd as $d => $e) { $o[$d] = $e['v']; $filed[$d] = $e['f']; }
    return $o;
}

function br_name($s) { $s = strtolower(preg_replace('/[^a-z0-9 ]/i', ' ', $s)); $w = array_values(array_filter(explode(' ', $s), fn($x) => strlen($x) > 1 && !in_array($x, ['the', 'inc', 'ag', 'se', 'nv', 'plc', 'sa', 'co', 'corp', 'ltd', 'group', 'holding', 'holdings'], true))); return $w[0] ?? ''; }

// SEC-Abruf mit Statuscode: 404 = Kennzeichen gibt es fuer die Firma nicht (normal), alles andere ausser 200 (429 Drosselung,
// 403, 5xx, Zeitueberschreitung) = Abruf gescheitert -> Ergebnis nicht zwischenspeichern, sonst fehlen Reihen 7 Tage lang still
// (gefunden 06.11.2026: Realty Income ohne GAAP-Reihe, waehrend drei Laeufe parallel abriefen).
function br_sec_json($url) {
    for ($versuch = 0; $versuch < 3; $versuch++) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_ENCODING => 'gzip',
            CURLOPT_HTTPHEADER => ['User-Agent: ' . SEC_USER_AGENT, 'Accept-Encoding: gzip'], CURLOPT_FOLLOWLOCATION => true]);
        $body = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        usleep(150000);
        if ($code === 200 && $body) return json_decode($body, true) ?: [];
        if ($code === 404) return [];
        sleep(2 + 3 * $versuch);
    }
    $GLOBALS['br_sec_fehler'] = ($GLOBALS['br_sec_fehler'] ?? 0) + 1;
    return [];
}
// Woechentliche Kontrolle (bigboard-lib.php, sonntags im Nachtlauf; 20.11.2026): Einheitenfehler in den Gewinnreihen aller
// Zwischenspeicher suchen und die betroffenen Titel neu holen. Gibt [gefunden, repariert, rest] zurueck.
function br_einheiten_pruefen(int $budget = 300): array {
    $sprung = function ($c) { $v = array_values(array_filter(array_map(fn($e) => $e['v'], $c['reihen']['operativ'] ?? []), fn($v) => $v > 0.05));
        for ($i = 1; $i < count($v); $i++) if ($v[$i] / $v[$i - 1] > 300 || $v[$i - 1] / $v[$i] > 300) return true; return false; };
    $start = time(); $gef = []; $rest = [];
    foreach (glob(__DIR__ . '/data/cache/bewertung/*.json') ?: [] as $f) { $c = json_decode((string) @file_get_contents($f), true); if (is_array($c) && $sprung($c)) $gef[] = basename($f, '.json'); }
    foreach ($gef as $s) { if (time() - $start > $budget) { $rest[] = $s; continue; } try { $c = bewertung_reihen($s, true); if ($sprung($c)) $rest[] = $s; } catch (Throwable $e) { $rest[] = $s; } }
    return ['gefunden' => count($gef), 'repariert' => count($gef) - count($rest), 'rest' => $rest];
}
function bewertung_reihen($symbol, $neu = false) {
    $GLOBALS['br_sec_fehler'] = 0;
    $GLOBALS['br_auslaendisch'] = false;
    $GLOBALS['br_filed'] = [];
    $cacheDir = __DIR__ . '/data/cache/bewertung';
    $cacheFile = $cacheDir . '/' . preg_replace('/[^A-Z0-9.\-]/', '_', $symbol) . '.json';
    if (!$neu && sec_cache_frisch($cacheFile, sec_cik_fuer($symbol))) {   // bis zur naechsten SEC-Meldung (sec-lib.php)
        $c = json_decode((string) file_get_contents($cacheFile), true);
        if (is_array($c)) return $c;
    }


    $out = ['symbol' => $symbol, 'quelle' => null, 'steuersatz' => null, 'waehrung' => null, 'firma' => null, 'reihen' => ['gaap' => [], 'operativ' => [], 'fcf' => [], 'ocf' => [], 'sbc' => []]];
    $cikMap = sec_get_cik_map();
    $cik = $cikMap[$symbol] ?? null;
    $ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36';

    // Jahreswerte aus 10-K (US), 20-F und 40-F (auslaendische Firmen mit US-Notierung, oft IFRS) - wie sec_parse_annual_duration,
    // nur mit allen drei Jahresformularen

    // Symbol einer anderen Boerse (SAP.DE, ASML.AS) auf die US-Notierung derselben Firma abbilden - nur wenn der Firmenname passt
    // (ALV.DE = Allianz, ALV an der NYSE = Autoliv -> wird verworfen)
    if (!$cik && strpos($symbol, '.') !== false) {
        $basis = strtok($symbol, '.');
        if (isset($cikMap[$basis])) {
            $ch = curl_init('https://query1.finance.yahoo.com/v8/finance/chart/' . rawurlencode($symbol) . '?interval=1d&range=5d');
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_USERAGENT => $ua]);
            $meta = json_decode((string) curl_exec($ch), true)['chart']['result'][0]['meta'] ?? [];
            curl_close($ch);
            $yName = $meta['longName'] ?? $meta['shortName'] ?? '';
            $probe = sec_curl_get("https://data.sec.gov/api/xbrl/companyfacts/CIK{$cikMap[$basis]}.json");
            $secName = $probe['entityName'] ?? '';
            if ($yName && $secName && br_name($yName) !== '' && br_name($yName) === br_name($secName)) { $cik = $cikMap[$basis]; $out['firma'] = $secName . ' (über ' . $basis . ')'; }
        }
    }

    if ($cik) {
        // Taxonomie bestimmen: US-GAAP, sonst IFRS (20-F-Melder wie Unilever, ASML, SAP)
        $tax = 'us-gaap';
        $T = [
            'aktien'  => ['us-gaap' => ['WeightedAverageNumberOfDilutedSharesOutstanding', 'WeightedAverageNumberOfShareOutstandingBasicAndDiluted', 'WeightedAverageNumberOfSharesOutstandingBasic'], 'ifrs-full' => ['AdjustedWeightedAverageShares', 'WeightedAverageShares']],
            'eps'     => ['us-gaap' => ['EarningsPerShareDiluted', 'EarningsPerShareBasicAndDiluted', 'EarningsPerShareBasic'], 'ifrs-full' => ['DilutedEarningsLossPerShare', 'BasicEarningsLossPerShare', 'DilutedEarningsLossPerShareFromContinuingOperations']],
            'betrieb' => ['us-gaap' => ['OperatingIncomeLoss'], 'ifrs-full' => ['ProfitLossFromOperatingActivities']],
            // Ertraege aus Beteiligungen (Coca-Cola ~1,7 Mrd. $/Jahr) stehen nicht im Betriebsergebnis, gehoeren aber zum
            // dauerhaften Gewinn - ohne sie lag KO "operativ" 30 % unter GAAP (07.11.2026)
            'beteil'  => ['us-gaap' => ['IncomeLossFromEquityMethodInvestments'], 'ifrs-full' => ['ShareOfProfitLossOfAssociatesAndJointVenturesAccountedForUsingEquityMethod']],
            'zins'    => ['us-gaap' => ['InterestExpenseNonoperating', 'InterestExpense', 'InterestExpenseDebt', 'InterestAndDebtExpense'], 'ifrs-full' => ['InterestExpense', 'FinanceCosts']],
            'wmGw'    => ['us-gaap' => ['GoodwillImpairmentLoss'], 'ifrs-full' => ['ImpairmentLossRecognisedInProfitOrLossGoodwill']],
            'wmIm'    => ['us-gaap' => ['ImpairmentOfIntangibleAssetsExcludingGoodwill', 'ImpairmentOfIntangibleAssetsIndefinitelivedExcludingGoodwill', 'ImpairmentOfIntangibleAssetsFinitelived'], 'ifrs-full' => ['ImpairmentLossRecognisedInProfitOrLossIntangibleAssetsOtherThanGoodwill']],
            'wmAn'    => ['us-gaap' => ['AssetImpairmentCharges'], 'ifrs-full' => ['ImpairmentLossRecognisedInProfitOrLoss']],
            'amort'   => ['us-gaap' => ['AmortizationOfIntangibleAssets'], 'ifrs-full' => ['AmortisationIntangibleAssetsOtherThanGoodwill']],
            'restr'   => ['us-gaap' => ['RestructuringCharges', 'RestructuringAndRelatedCostIncurredCost'], 'ifrs-full' => []],
            'sbc'     => ['us-gaap' => ['ShareBasedCompensation', 'AllocatedShareBasedCompensationExpense'], 'ifrs-full' => ['AdjustmentsForSharebasedPayments']],
            'vorSt'   => ['us-gaap' => ['IncomeLossFromContinuingOperationsBeforeIncomeTaxesExtraordinaryItemsNoncontrollingInterest', 'IncomeLossFromContinuingOperationsBeforeIncomeTaxesMinorityInterestAndIncomeLossFromEquityMethodInvestments', 'IncomeLossFromContinuingOperationsBeforeIncomeTaxesDomestic'], 'ifrs-full' => ['ProfitLossBeforeTax']],
            'steuer'  => ['us-gaap' => ['IncomeTaxExpenseBenefit'], 'ifrs-full' => ['IncomeTaxExpenseContinuingOperations']],
            'ocf'     => ['us-gaap' => ['NetCashProvidedByUsedInOperatingActivities', 'NetCashProvidedByUsedInOperatingActivitiesContinuingOperations'], 'ifrs-full' => ['CashFlowsFromUsedInOperatingActivities']],
            'capex'   => ['us-gaap' => ['PaymentsToAcquirePropertyPlantAndEquipment', 'PaymentsForCapitalExpenditures'], 'ifrs-full' => ['PurchaseOfPropertyPlantAndEquipmentClassifiedAsInvestingActivities', 'PurchaseOfPropertyPlantAndEquipment']],
            'gewinn'  => ['us-gaap' => ['NetIncomeLoss'], 'ifrs-full' => ['ProfitLossAttributableToOwnersOfParent']],
        ];
        $waehr = null;
        // Vorgaenger-Firmen mit eigener SEC-Nummer (17.11.2026, BLK: BlackRock meldet seit dem Holding-Umbau 2024 unter einer neuen
        // Nummer, dort nur Jahre ab 2022 -> Chart zeigte 4 Jahre). Aeltere Jahre aus der alten Nummer ergaenzen; neuere Werte haben Vorrang.
        $VORGAENGER = ['BLK' => ['0001364742']];
        $ciks = array_merge([$cik], $VORGAENGER[$symbol] ?? []);
        // je Jahr aus allen Kennzeichen zusammengefuehrt (Firmen wechseln das Kennzeichen); Waehrung = Berichtswaehrung der Firma
        $hole = function ($art, $typ = 'geld') use ($ciks, &$tax, &$waehr, $T) {
            $o = [];
            foreach ($ciks as $cik) foreach ($T[$art][$tax] as $tag) {
                $json = br_sec_json("https://data.sec.gov/api/xbrl/companyconcept/CIK{$cik}/{$tax}/{$tag}.json");
                $units = $json['units'] ?? [];
                if (!$units) continue;
                if ($typ === 'stueck') $key = isset($units['shares']) ? 'shares' : null;
                elseif ($typ === 'jeAktie') { $key = null; foreach (array_keys($units) as $k) if (str_ends_with($k, '/shares') && (!$waehr || str_starts_with($k, $waehr . '/'))) { $key = $k; break; } }
                else { $key = null; foreach (array_keys($units) as $k) if (strpos($k, '/') === false && $k !== 'shares' && $k !== 'pure' && (!$waehr || $k === $waehr)) { $key = $k; break; } if ($key && !$waehr) $waehr = $key; }
                if (!$key) continue;
                foreach (br_jahre($units[$key], $fd) as $d => $v) if (!isset($o[$d])) { $o[$d] = $v; $GLOBALS['br_filed'][$art][$d] = $fd[$d] ?? ''; }
            }
            return $o;
        };
        $aktien = $hole('aktien', 'stueck');
        // Kein US-GAAP oder veraltet (Toyota meldet seit 2021 nach IFRS): IFRS nehmen, wenn es neuere Jahre hat
        $letztesJahr = fn($r) => $r ? (int) substr(max(array_keys($r)), 0, 4) : 0;
        if (!$aktien || $letztesJahr($aktien) < (int) date('Y') - 2) {
            $tax = 'ifrs-full'; $aktienIfrs = $hole('aktien', 'stueck');
            if ($aktienIfrs && $letztesJahr($aktienIfrs) > $letztesJahr($aktien)) $aktien = $aktienIfrs; else $tax = 'us-gaap';
        }
        // Aktienzahl in falscher Einheit (07.11.2026: McDonald's meldet ab 2021 "747" statt 747 Mio. -> Gewinn je Aktie
        // 9,8 Mio. $, Fair Value 185 Mio.). Werte, die um mehr als Faktor 1.000 unter dem Median liegen, mit 1.000 bzw.
        // 1.000.000 malnehmen - nur wenn das Ergebnis danach zum Median passt (0,3- bis 3-fach), sonst den Wert verwerfen.
        if (count($aktien) >= 3) {
            $med = br_median(array_values(array_filter($aktien, fn($x) => $x > 0)));
            foreach ($aktien as $d => $n) {
                if ($med && $n > 0 && $n * 300 < $med) {   // 300 statt 1.000 (20.11.2026: GPC 2010 lag genau beim Faktor 1.000)
                    $neu = null;
                    foreach ([1e3, 1e6, 1e9] as $f) { $q = $n * $f / $med; if ($q > 0.3 && $q < 3) { $neu = $n * $f; break; } }
                    if ($neu) $aktien[$d] = $neu; else unset($aktien[$d]);
                }
            }
        }
        $ocf = $hole('ocf');            // zuerst eine Geldgroesse -> legt die Berichtswaehrung fest
        $betrieb = $hole('betrieb'); $zins = $hole('zins'); $wmGoodwill = $hole('wmGw'); $wmImmat = $hole('wmIm'); $wmAnlagen = $hole('wmAn');
        $beteil = $hole('beteil'); $amort = $hole('amort'); $restr = $hole('restr'); $sbc = $hole('sbc'); $vorSteuerAlle = $hole('vorSt'); $steuer = $hole('steuer'); $capex = $hole('capex');
        $vorSteuer = $vorSteuerAlle;
        $epsJ = $hole('eps', 'jeAktie');
        // Aktienzahl fehlt fuer einzelne Jahre (GOOG 17.11.2026: Alphabet meldet sie bis 2021 nur je Aktiengattung -> Reihe "operativ"
        // nur 2022-2025). Dann Gewinn / verwaesserter Gewinn je Aktie; Einreichdatum vom EPS-Wert, damit die Split-Bereinigung unten greift.
        if ($epsJ) {
            $gewinnJ = $hole('gewinn');
            // Aktienzahl in Tausend gemeldet, und zwar mehrere Jahre am Stueck (19.11.2026: Badger Meter BMI 2018-2022 "29.000"
            // statt 29 Mio. -> Gewinn je Aktie operativ 1.761 $, Normal-KGV 0,04). Der Median-Abgleich oben greift dann nicht.
            // Gegenprobe je Jahr: Gewinn / gemeldeter Gewinn je Aktie = Aktienzahl. Weicht die gemeldete um > Faktor 300 ab,
            // mit 1.000 bzw. 1.000.000 skalieren, wenn sie danach passt (0,5- bis 2-fach), sonst den Wert verwerfen.
            foreach ($aktien as $d => $n) {
                $e = $epsJ[$d] ?? null; $g = $gewinnJ[$d] ?? null;
                if (!$n || $n <= 0 || !$e || abs($e) < 0.01 || !$g || $g / $e <= 0) continue;
                $soll = $g / $e;
                if ($n * 300 < $soll || $n > $soll * 300) {
                    $neu = null;
                    foreach ([1e3, 1e6, 1e-3, 1e-6] as $f) { $q = $n * $f / $soll; if ($q > 0.5 && $q < 2) { $neu = $n * $f; break; } }
                    if ($neu) $aktien[$d] = $neu; else unset($aktien[$d]);
                }
            }
            $jung = array_values(array_filter(array_slice($aktien, -3), fn($x) => $x > 0)); $ref = count($jung) >= 2 ? br_median($jung) : null;
            if ($ref) foreach ($aktien as $d => $n) {
                if (isset($epsJ[$d]) || !$n || $n <= 0 || $n * 300 >= $ref) continue;   // nur Jahre ohne EPS-Gegenprobe, nur zu kleine Werte
                $neu = null; foreach ([1e3, 1e6] as $f) { $q = $n * $f / $ref; if ($q > 0.3 && $q < 3) { $neu = $n * $f; break; } }
                if ($neu) $aktien[$d] = $neu; else unset($aktien[$d]);
            }
            foreach ($epsJ as $d => $e) {
                if (isset($aktien[$d]) || abs($e) < 0.01 || empty($gewinnJ[$d]) || $gewinnJ[$d] / $e <= 0) continue;
                $aktien[$d] = $gewinnJ[$d] / $e;
                $GLOBALS['br_filed']['aktien'][$d] = $GLOBALS['br_filed']['eps'][$d] ?? '';
            }
            ksort($aktien);
        }
        // Zwei Aktiengattungen (20.11.2026, Hershey HSY: Common + Class B): seit 2015 stehen verwaesserte Aktienzahl und Gewinn je
        // Aktie nur noch je Gattung in der SEC-Meldung, ohne Gesamtwert -> Reihe endete 2014, Chart fiel auf 4 Jahre Yahoo zurueck.
        // Fuer Jahre mit Gewinn, aber ohne Aktienzahl: Aktien im Umlauf zum Bilanzstichtag (Summe aller Gattungen); fehlt auch der
        // gemeldete Gewinn je Aktie, Gewinn / diese Aktienzahl.
        if ($tax === 'us-gaap') {
            $gewinnG = $gewinnJ ?? $hole('gewinn');
            $fehlt = array_diff_key($gewinnG, $aktien);
            if ($fehlt) {
                foreach ($ciks as $ck) {
                    $js = br_sec_json("https://data.sec.gov/api/xbrl/companyconcept/CIK{$ck}/us-gaap/CommonStockSharesOutstanding.json");
                    foreach ($js['units']['shares'] ?? [] as $x) {
                        $d = $x['end'] ?? ''; if (!isset($fehlt[$d]) || isset($aktien[$d]) || ($x['form'] ?? '') !== '10-K' || !(($x['val'] ?? 0) > 0)) continue;
                        $aktien[$d] = (float) $x['val']; $GLOBALS['br_filed']['aktien'][$d] = $x['filed'] ?? '';
                        if (!isset($epsJ[$d]) && $gewinnG[$d] != 0) { $epsJ[$d] = $gewinnG[$d] / $aktien[$d]; $GLOBALS['br_filed']['eps'][$d] = $x['filed'] ?? ''; }
                    }
                }
                ksort($aktien); ksort($epsJ);
            }
        }
        if ($aktien || $epsJ) {
            $out['quelle'] = $tax === 'ifrs-full' ? 'sec-ifrs' : 'sec';
            $out['waehrung'] = $waehr ?: 'USD';
            // normaler Steuersatz = Median der effektiven Saetze, Obergrenze 25 % (hoehere Saetze kommen meist von Sondereffekten)
            $saetze = [];
            foreach ($steuer as $d => $t) { if (!empty($vorSteuer[$d]) && $vorSteuer[$d] > 0) { $s = $t / $vorSteuer[$d]; if ($s > 0.05 && $s < 0.5) $saetze[] = $s; } }
            $satz = br_median($saetze); $satz = $satz === null ? 0.21 : max(0.10, min(0.25, $satz));
            $out['steuersatz'] = round($satz, 3);
            foreach ($epsJ as $d => $v) $out['reihen']['gaap'][] = ['date' => $d, 'v' => round($v, 4)];
            foreach ($aktien as $d => $n) {
                if ($n <= 0) continue;
                $basisWert = isset($betrieb[$d]) ? $betrieb[$d] - abs($zins[$d] ?? 0) + ($beteil[$d] ?? 0) : ($vorSteuerAlle[$d] ?? null);
                if ($basisWert !== null) {
                    $wm = max(abs($wmGoodwill[$d] ?? 0) + abs($wmImmat[$d] ?? 0), abs($wmAnlagen[$d] ?? 0));
                    // Aktienverguetung (Nutzer 06.11.2026: standardmaessig herausrechnen wie bereinigte Gewinne, im Chart abschaltbar) -
                    // als eigene Reihe nach Steuern je Aktie, damit der Chart sie wieder abziehen kann
                    $sbcJe = abs($sbc[$d] ?? 0) * (1 - $satz) / $n;
                    $out['reihen']['operativ'][] = ['date' => $d, 'v' => round(($basisWert + $wm + abs($amort[$d] ?? 0) + max(0, $restr[$d] ?? 0)) * (1 - $satz) / $n + $sbcJe, 4)];
                    $out['reihen']['sbc'][] = ['date' => $d, 'v' => round($sbcJe, 4)];
                }
                if (isset($ocf[$d])) {
                    $out['reihen']['ocf'][] = ['date' => $d, 'v' => round($ocf[$d] / $n, 4)];
                    $out['reihen']['fcf'][] = ['date' => $d, 'v' => round(($ocf[$d] - abs($capex[$d] ?? 0)) / $n, 4)];
                }
            }
        }
    }
    // SEC-Reihe veraltet (BIDU 17.11.2026: Baidu liefert der SEC seit 2011 keine Jahreswerte mehr in dieser Form, die Reihe endete
    // 2010 -> Fair Value 1.785 $ bei 71 $ Kurs, erwartete Rendite 8,5 Mrd. %). Endet sie mehr als 3 Jahre vor heute, Yahoo nehmen;
    // liefert auch Yahoo nichts, lieber keine Bewertung als eine falsche.
    if ($out['quelle']) {
        $letzt = 0;
        foreach (['operativ', 'gaap'] as $r) foreach ($out['reihen'][$r] as $e) $letzt = max($letzt, (int) substr($e['date'], 0, 4));
        if ($letzt < (int) date('Y') - 2) {   // 17.11.2026 von 3 auf 2 (BAM: Reihe endet 2023)
            $out['secVeraltet'] = $letzt;
            $out['quelle'] = null; $out['waehrung'] = null; $out['steuersatz'] = null; $out['firma'] = null;
            $out['reihen'] = ['gaap' => [], 'operativ' => [], 'fcf' => [], 'ocf' => [], 'sbc' => []];
        }
    }
    if (!$out['quelle']) {

        $ts = yahoo_fetch_timeseries($symbol, ['annualDilutedEPS', 'annualOperatingIncome', 'annualInterestExpense', 'annualTaxRateForCalcs',
            'annualDilutedAverageShares', 'annualOperatingCashFlow', 'annualFreeCashFlow'], $ua);
        if ($ts) {
            $out['quelle'] = 'yahoo';
            foreach ($ts as $reihe) { foreach ($reihe as $e) { if (!empty($e['cur'])) { $out['waehrung'] = $e['cur']; break 2; } } }
            $nd = function ($k) use ($ts) { $o = []; foreach (($ts[$k] ?? []) as $e) $o[$e['date']] = $e['value']; return $o; };
            $aktien = $nd('annualDilutedAverageShares'); $betrieb = $nd('annualOperatingIncome'); $zins = $nd('annualInterestExpense');
            $satzJ = $nd('annualTaxRateForCalcs'); $ocf = $nd('annualOperatingCashFlow'); $fcf = $nd('annualFreeCashFlow');
            $satz = br_median(array_values(array_filter($satzJ, fn($x) => $x > 0.05 && $x < 0.5))); $satz = $satz === null ? 0.21 : max(0.10, min(0.25, $satz));
            $out['steuersatz'] = round($satz, 3);
            foreach ($nd('annualDilutedEPS') as $d => $v) $out['reihen']['gaap'][] = ['date' => $d, 'v' => round($v, 4)];
            foreach ($aktien as $d => $n) {
                if ($n <= 0) continue;
                if (isset($betrieb[$d])) $out['reihen']['operativ'][] = ['date' => $d, 'v' => round(($betrieb[$d] - abs($zins[$d] ?? 0)) * (1 - $satz) / $n, 4)];
                if (isset($ocf[$d])) $out['reihen']['ocf'][] = ['date' => $d, 'v' => round($ocf[$d] / $n, 4)];
                if (isset($fcf[$d])) $out['reihen']['fcf'][] = ['date' => $d, 'v' => round($fcf[$d] / $n, 4)];
            }
        }
    }
    foreach ($out['reihen'] as $k => $r) { usort($r, fn($a, $b) => strcmp($a['date'], $b['date'])); $out['reihen'][$k] = $r; }
    // Aktiensplits (07.11.2026, McCormick 2:1 im Dez. 2020: Fair Value sprang 2018 um die Haelfte). Die SEC liefert je Jahr den
    // zuletzt eingereichten Wert; Jahre, die nur in Berichten VOR dem Split stehen, sind nicht bereinigt - Yahoo-Kurse schon.
    // Solche Werte (Einreichung vor dem Splittag) durch das Verhaeltnis teilen: GAAP nach dem EPS-Einreichdatum, die aus der
    // Aktienzahl gerechneten Reihen nach deren Einreichdatum. Nur US-Melder (bei ADRs aendert sich sonst auch das ADR-Verhaeltnis).
    if (strpos((string) $out['quelle'], 'sec') === 0 && empty($GLOBALS['br_auslaendisch'])) {
        $ch = curl_init('https://query1.finance.yahoo.com/v8/finance/chart/' . rawurlencode($symbol) . '?interval=1mo&period1=0&period2=' . time() . '&events=split');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_USERAGENT => $ua]);
        $splits = json_decode((string) curl_exec($ch), true)['chart']['result'][0]['events']['splits'] ?? [];
        curl_close($ch);
        $out['splits'] = [];
        foreach ($splits as $sp) {
            $faktor = ($sp['denominator'] ?? 0) > 0 ? $sp['numerator'] / $sp['denominator'] : 0;
            if ($faktor <= 0 || abs($faktor - 1) < 0.01 || empty($sp['date'])) continue;
            $tag = gmdate('Y-m-d', (int) $sp['date']);
            $out['splits'][] = ['date' => $tag, 'faktor' => round($faktor, 4)];
            foreach ($out['reihen'] as $k => $r) {
                $quelle = $k === 'gaap' ? 'eps' : 'aktien';
                foreach ($r as $i => $e) {
                    $f = $GLOBALS['br_filed'][$quelle][$e['date']] ?? '';
                    if ($f !== '' && $f < $tag) $out['reihen'][$k][$i]['v'] = round($e['v'] / $faktor, 4);
                }
            }
        }
    }
    // ADR-Verhaeltnis (JD: 1 ADS = 2 Aktien, BABA: 8): SEC-Werte gelten je Stammaktie, der Kurs je ADR. Verhaeltnis aus Yahoos
    // Gewinn je Aktie (trailingEps, je ADR, Kurswaehrung) gegen unseren letzten GAAP-Wert (umgerechnet), auf uebliche Verhaeltnisse
    // gerundet; nur wenn es eindeutig passt (+-25 %), sonst 1.
    $out['adrFaktor'] = 1; $out['adrPruefen'] = false;
    // Bekannte Verhaeltnisse (Aktien je ADR) - Vorrang vor der Automatik, die bei abweichenden TTM-Gewinnen danebenliegen kann
    $ADR = ['BABA' => 8, 'JD' => 2, 'BIDU' => 8, 'PDD' => 4, 'NTES' => 5, 'TCOM' => 1, 'BEKE' => 3, 'LI' => 2, 'XPEV' => 2, 'NIO' => 1, 'YUMC' => 1,
            'TM' => 10, 'HMC' => 3, 'SONY' => 1, 'MUFG' => 1, 'TSM' => 5, 'INFY' => 1, 'HDB' => 3, 'IBN' => 2, 'BYDDY' => 2,
            'BP' => 6, 'SHEL' => 2, 'AZN' => 0.5, 'GSK' => 2, 'SNY' => 0.5, 'DEO' => 4, 'VOD' => 10, 'BTI' => 1, 'BHP' => 2, 'RIO' => 1, 'NGG' => 5, 'E' => 2];
    $bekannt = $ADR[$symbol] ?? null;
    if ($bekannt) $out['adrFaktor'] = $bekannt;
    // Automatik nur fuer auslaendische Melder (20-F/40-F): US-Firmen mit 10-K haben nie ein ADR-Verhaeltnis. Am 07.11.2026 bekam
    // General Mills sonst Faktor 10, weil Yahoos TTM-Gewinn (-1,6) zufaellig das Zehnfache des letzten GAAP-Werts (-0,16) war.
    elseif (!empty($GLOBALS['br_auslaendisch']) && strpos((string) $out['quelle'], 'sec') === 0 && $out['reihen']['gaap'] && strpos($symbol, '.') === false) {
        $sess = yahoo_get_session($ua);
        if ($sess) {
            $res = yahoo_curl_with_session('https://query2.finance.yahoo.com/v10/finance/quoteSummary/' . rawurlencode($symbol) . '?modules=defaultKeyStatistics,price&crumb=' . rawurlencode($sess['crumb']), $ua, $sess);
            $qs = $res['code'] === 200 ? (json_decode($res['body'], true)['quoteSummary']['result'][0] ?? null) : null;
            $yEps = $qs['defaultKeyStatistics']['trailingEps']['raw'] ?? null;
            $kursW = $qs['price']['currency'] ?? null;
            $letzt = end($out['reihen']['gaap'])['v'];
            $fx = 1.0;
            if ($kursW && $out['waehrung'] && strtoupper($kursW) !== strtoupper($out['waehrung'])) {
                $ch = curl_init('https://query1.finance.yahoo.com/v8/finance/chart/' . rawurlencode(strtoupper($out['waehrung']) . strtoupper($kursW) . '=X') . '?interval=1d&range=5d');
                curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_USERAGENT => $ua]);
                $fx = (float) (json_decode((string) curl_exec($ch), true)['chart']['result'][0]['meta']['regularMarketPrice'] ?? 0);
                curl_close($ch);
            }
            if ($yEps > 0 && $letzt > 0 && $fx > 0) {   // nur positive Gewinne vergleichen
                $roh = $yEps / ($letzt * $fx);
                $treffer = false;
                foreach ([1, 2, 3, 4, 5, 6, 8, 10, 15, 20, 25, 30, 40, 50, 100] as $f) if (abs($roh / $f - 1) <= 0.10) { $out['adrFaktor'] = $f; $treffer = true; break; }
                if (!$treffer && ($roh > 1.4 || $roh < 0.7)) $out['adrPruefen'] = round($roh, 2);
            }
        }
    }
    // Nur SEC-Werte gelten je Stammaktie. Yahoo liefert je gehandeltem Papier (also je ADR) - BIDU 17.11.2026: Yahoo-EPS 2024 65,92 CNY
    // = Baidus Wert je ADS; mal 8 genommen ergab Fair Value 314 $ bei 71 $ Kurs.
    if (strpos((string) $out['quelle'], 'sec') !== 0) { $out['adrFaktor'] = 1; $out['adrPruefen'] = false; }
    if ($out['adrFaktor'] != 1) foreach ($out['reihen'] as $k => $r) foreach ($r as $i => $e) $out['reihen'][$k][$i]['v'] = round($e['v'] * $out['adrFaktor'], 4);
    // Analystenschaetzungen (Konsens) fuer laufendes und naechstes Geschaeftsjahr aus Yahoo earningsTrend - frei, wie die
    // "E"-Jahre bei Vorlage (ADM 07.11.2026: Yahoo 5,59/5,67, Vorlage 5,62E/5,81E). Je Aktie in Kurswaehrung (bei ADRs je ADR).
    $out['schaetzung'] = [];
    if ($out['quelle'] && strpos($symbol, '.') === false) {
        $sess = $sess ?? yahoo_get_session($ua);
        if ($sess) {
            $res = yahoo_curl_with_session('https://query2.finance.yahoo.com/v10/finance/quoteSummary/' . rawurlencode($symbol) . '?modules=earningsTrend&crumb=' . rawurlencode($sess['crumb']), $ua, $sess);
            $tr = $res['code'] === 200 ? (json_decode($res['body'], true)['quoteSummary']['result'][0]['earningsTrend']['trend'] ?? []) : [];
            foreach ($tr as $t) {
                if (!in_array($t['period'] ?? '', ['0y', '+1y'], true)) continue;
                $eps = $t['earningsEstimate']['avg']['raw'] ?? null;
                if ($eps === null || empty($t['endDate'])) continue;
                $out['schaetzung'][] = ['ende' => $t['endDate'], 'eps' => round((float) $eps, 4), 'analysten' => (int) ($t['earningsEstimate']['numberOfAnalysts']['raw'] ?? 0)];
            }
            // Yahoo meldet fuer 0y und +1y manchmal dasselbe Jahresende (AAPL 13.11.2026: beide 2027-09-30) -> +1y ein Jahr spaeter
            $sz = &$out['schaetzung'];
            for ($i = 1; $i < count($sz); $i++) if ($sz[$i]['ende'] <= $sz[$i - 1]['ende']) $sz[$i]['ende'] = date('Y-m-d', strtotime($sz[$i - 1]['ende'] . ' +1 year'));
            unset($sz);
        }
    }
    if (!empty($GLOBALS['br_sec_fehler'])) $out['unvollstaendig'] = $GLOBALS['br_sec_fehler'];
    elseif ($out['quelle']) { @mkdir($cacheDir, 0700, true); @file_put_contents($cacheFile, json_encode($out), LOCK_EX); }
    return $out;
}

// Monatskerzen mit Dividenden und bereinigtem Schluss (gleiche Abfrage wie quote.php?ohlc=1, fester Zeitraum statt range=max)
// ===== Banken: Fair Value ueber das Kurs-Buchwert-Verhaeltnis (Nutzer 12.11.2026, Methode Adam Khoo 10.11.2026) =====
// Bei Banken taugen KGV, DCF und FCF nicht. Zwei Wege, Ergebnis als Spanne:
//   historisch   = KBV-Durchschnitt (Jahresenden, bis 5 J.) x Buchwert je Aktie
//   gerechtfertigt = (EK-Rendite-Schnitt - g) / (EK-Kosten - g) x Buchwert je Aktie, g = 3 %, EK-Kosten 9 % Singapur, sonst 10 %
// Buchwert je Aktie aus dem letzten Quartal -> die Spanne wandert jedes Quartal mit. Erkennung ueber die Yahoo-Branche
// ("Banks - Diversified/Regional"), damit Versicherer, Boersen und Vermoegensverwalter nicht nach Buchwert bewertet werden.
// Zusaetzlich materieller Buchwert (ohne Goodwill) als zweite Spanne - bei US-Grossbanken der uebliche Massstab.
function bewertung_bank_kosten($symbol) { return preg_match('/\.SI$/', $symbol) ? 9.0 : 10.0; }

function bewertung_bank($symbol, $neu = false) {
    $dir = __DIR__ . '/data/cache/bewertung';
    $datei = $dir . '/bank_' . preg_replace('/[^A-Z0-9._-]/', '_', strtoupper($symbol)) . '.json';
    if (!$neu && is_file($datei) && filemtime($datei) > time() - 7 * 86400) { $c = json_decode((string) file_get_contents($datei), true); if (is_array($c)) return $c; }
    $ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36';
    $sek = yahoo_fetch_sector($symbol, $ua) ?: [];
    $out = ['symbol' => $symbol, 'bank' => (bool) preg_match('/bank/i', (string) ($sek['industry'] ?? '')), 'branche' => $sek['industry'] ?? null, 'stand' => date('Y-m-d')];
    if ($out['bank']) $out += bewertung_bank_rechnen($symbol, $ua);
    if (($sek['industry'] ?? null) !== null) { @mkdir($dir, 0775, true); @file_put_contents($datei, json_encode($out, JSON_UNESCAPED_UNICODE)); }
    return $out;
}

function bewertung_bank_rechnen($symbol, $ua) {
    $typen = ['annualNetIncomeCommonStockholders', 'annualCommonStockEquity', 'annualOrdinarySharesNumber', 'annualTangibleBookValue',
              'quarterlyCommonStockEquity', 'quarterlyOrdinarySharesNumber', 'quarterlyTangibleBookValue'];
    $ts = yahoo_fetch_timeseries($symbol, $typen, $ua) ?: [];
    $jr = function ($k) use ($ts) { $o = []; foreach ($ts[$k] ?? [] as $e) $o[substr($e['date'], 0, 4)] = $e['value']; ksort($o); return $o; };
    $letzt = function ($k) use ($ts) { $s = $ts[$k] ?? []; usort($s, fn($a, $b) => strcmp($a['date'], $b['date'])); return $s ? end($s) : null; };
    $ni = $jr('annualNetIncomeCommonStockholders'); $eq = $jr('annualCommonStockEquity'); $sh = $jr('annualOrdinarySharesNumber'); $tb = $jr('annualTangibleBookValue');
    $qEq = $letzt('quarterlyCommonStockEquity') ?: ($eq ? ['date' => array_key_last($eq) . '-12-31', 'value' => end($eq)] : null);
    $qSh = $letzt('quarterlyOrdinarySharesNumber') ?: ($sh ? ['value' => end($sh)] : null);
    $qTb = $letzt('quarterlyTangibleBookValue') ?: ($tb ? ['value' => end($tb)] : null);
    if (!$qEq || !$qSh || $qSh['value'] <= 0 || !$ni || !$eq) return ['fehler' => 'keine Bilanzdaten'];
    $cur = $ts['annualCommonStockEquity'][0]['cur'] ?? null;
    $q = bewertung_ohlc($symbol, 10);
    if (!$q || !$q['kerzen']) return ['fehler' => 'keine Kurse'];
    if ($cur && $q['waehrung'] && strtoupper($cur) !== strtoupper($q['waehrung'])) return ['fehler' => 'Bilanz in ' . $cur . ', Kurs in ' . $q['waehrung']];
    $kurs = end($q['kerzen'])[1];
    // Aktienzahl an Splits/Bonusaktien anpassen (DBS 2024: 1 Bonusaktie je 10 -> +10 % Aktien), sonst ist das alte KBV zu hoch
    $jahre = array_keys($sh); $faktor = []; $f = 1.0;
    for ($i = count($jahre) - 1; $i >= 0; $i--) { $faktor[$jahre[$i]] = $f; if ($i > 0 && $sh[$jahre[$i - 1]] > 0) { $s = $sh[$jahre[$i]] / $sh[$jahre[$i - 1]]; if ($s > 1.08) $f *= $s; } }
    $endKurs = []; foreach ($q['kerzen'] as [$d, $c]) if (substr($d, 5, 2) === '12') $endKurs[substr($d, 0, 4)] = $c;
    $roe = []; $rotce = []; $kbv = [];
    foreach ($ni as $j => $n) {
        if (empty($eq[$j]) || $eq[$j] <= 0) continue;
        $roe[$j] = round($n / $eq[$j] * 100, 2);
        if (!empty($tb[$j]) && $tb[$j] > 0) $rotce[$j] = round($n / $tb[$j] * 100, 2);
        if (!empty($sh[$j]) && isset($endKurs[$j])) $kbv[$j] = round($endKurs[$j] / ($eq[$j] / ($sh[$j] * ($faktor[$j] ?? 1))), 2);
    }
    $roe = array_slice($roe, -5, null, true); $rotce = array_slice($rotce, -5, null, true); $kbv = array_slice($kbv, -5, null, true);
    if (!$roe) return ['fehler' => 'keine EK-Rendite'];
    $bvps = $qEq['value'] / $qSh['value']; $tbvps = $qTb ? $qTb['value'] / $qSh['value'] : null;
    // Quartalswert fuer materiellen Buchwert fehlt bei Yahoo oft oder ist gleich dem Eigenkapital (DBS 09/2026) - dann
    // Verhaeltnis materiell/gesamt aus dem letzten Jahresabschluss auf den Quartals-Buchwert anwenden
    $jL = $eq ? array_key_last($eq) : null;
    if ((!$tbvps || $tbvps >= $bvps * 0.999) && $jL && !empty($tb[$jL]) && $tb[$jL] < $eq[$jL]) $tbvps = $bvps * $tb[$jL] / $eq[$jL];
    $coe = bewertung_bank_kosten($symbol); $g = 3.0;
    $schnitt = fn($a) => $a ? array_sum($a) / count($a) : null;
    $rs = $schnitt($roe); $kbvS = $schnitt($kbv);
    $gerecht = fn($r, $k) => max(0.0, ($r - $g) / ($k - $g));
    $fvG = $gerecht($rs, $coe) * $bvps; $fvH = $kbvS ? $kbvS * $bvps : null;
    $o = ['kurs' => $kurs, 'waehrung' => $q['waehrung'], 'bvps' => round($bvps, 2), 'bvps_stand' => $qEq['date'] ?? null, 'kbv' => round($kurs / $bvps, 2),
          'roe_jahre' => $roe, 'roe_schnitt' => round($rs, 2), 'kbv_jahre' => $kbv, 'kbv_schnitt' => $kbvS ? round($kbvS, 2) : null,
          'ek_kosten' => $coe, 'g' => $g, 'kbv_gerecht' => round($gerecht($rs, $coe), 2),
          'fv_historisch' => $fvH ? round($fvH, 2) : null, 'fv_gerecht' => round($fvG, 2),
          'fv_ek_kosten_minus1' => round($gerecht($rs, $coe - 1) * $bvps, 2), 'fv_ek_kosten_plus1' => round($gerecht($rs, $coe + 1) * $bvps, 2)];
    $o['fv_unten'] = round(min(array_filter([$fvG, $fvH], fn($x) => $x !== null)), 2);
    $o['fv_oben'] = round(max(array_filter([$fvG, $fvH], fn($x) => $x !== null)), 2);
    if ($tbvps && $rotce) {
        $rt = $schnitt($rotce);
        $o['materiell'] = ['tbvps' => round($tbvps, 2), 'rotce_schnitt' => round($rt, 2), 'rotce_jahre' => $rotce,
                           'fv_gerecht' => round($gerecht($rt, $coe) * $tbvps, 2), 'kbv_gerecht' => round($gerecht($rt, $coe), 2)];
    }
    return $o;
}

// Wert der Fair-Value-Linie (Bezug x Faktor) am heutigen Tag - zwischen letztem Ist-Jahr und naechster Schaetzung linear
// interpoliert, wie die orange Linie im Bewertungs-Chart. Fuer mitlaufende Kursalarme (Nutzer 12.11.2026: "soll sich immer
// fortlaufend an die Linie anpassen"). Schaetzung: Yahoo-Konsens (nur Gewinn), sonst mit dem Wachstum fortgeschrieben.
function bewertung_fv_linie_heute($symbol, $basis, $faktor, $sbcRaus = true) {
    $rh = bewertung_reihen($symbol);
    $r = $rh['reihen'][$basis] ?? [];
    if (!$r) return null;
    $sbc = []; foreach ($rh['reihen']['sbc'] ?? [] as $e) $sbc[$e['date']] = $e['v'];
    $pkt = [];
    foreach ($r as $e) { if ($e['v'] === null) continue; $v = ($basis === 'operativ' && $sbcRaus) ? $e['v'] - ($sbc[$e['date']] ?? 0) : $e['v']; $pkt[] = [$e['date'], $v]; }
    if (!$pkt) return null;
    $letzt = end($pkt);
    $prog = [];
    if (in_array($basis, ['operativ', 'gaap'], true)) foreach ($rh['schaetzung'] ?? [] as $x) if (($x['ende'] ?? '') > $letzt[0] && ($x['eps'] ?? 0) > 0) $prog[] = [$x['ende'], $x['eps']];
    if (!$prog) {   // Fortschreibung mit dem Wachstum der letzten bis 10 Jahre (begrenzt -10 .. +15 %)
        $pos = array_values(array_filter(array_slice($pkt, -11), fn($e) => $e[1] > 0));
        $g = 0.0;
        if (count($pos) >= 3) { $j = (strtotime(end($pos)[0]) - strtotime($pos[0][0])) / 31557600; if ($j > 0) $g = pow(end($pos)[1] / $pos[0][1], 1 / $j) - 1; }
        $g = max(-0.10, min(0.15, $g));
        for ($k = 1; $k <= 2; $k++) $prog[] = [date('Y-m-d', strtotime($letzt[0] . " +$k year")), $letzt[1] * pow(1 + $g, $k)];
    }
    $linie = array_merge([$letzt], $prog);
    $heute = date('Y-m-d');
    for ($i = 0; $i < count($linie) - 1; $i++) {
        [$t0, $v0] = $linie[$i]; [$t1, $v1] = $linie[$i + 1];
        if ($heute >= $t0 && $heute <= $t1) { $f = (strtotime($heute) - strtotime($t0)) / max(1, strtotime($t1) - strtotime($t0)); $v = $v0 + ($v1 - $v0) * $f; return $v > 0 ? round($v * $faktor, 2) : null; }
    }
    $v = $heute < $linie[0][0] ? $linie[0][1] : end($linie)[1];
    return $v > 0 ? round($v * $faktor, 2) : null;
}

function bewertung_ohlc($symbol, $jahre = 10) {
    $ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36';
    $von = $jahre >= 60 ? 0 : time() - $jahre * 365 * 86400;
    $ch = curl_init('https://query1.finance.yahoo.com/v8/finance/chart/' . rawurlencode($symbol) . '?interval=1mo&period1=' . $von . '&period2=' . time() . '&events=div');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_CONNECTTIMEOUT => 6, CURLOPT_USERAGENT => $ua, CURLOPT_FOLLOWLOCATION => true]);
    $body = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    if ($code !== 200 || !$body) return null;
    $r = json_decode($body, true)['chart']['result'][0] ?? null;
    $ts = $r['timestamp'] ?? null; $q = $r['indicators']['quote'][0] ?? null;
    if (!$ts || !$q) return null;
    $off = (int) ($r['meta']['gmtoffset'] ?? 0); $k = [];
    foreach ($ts as $i => $t) { $c = $q['close'][$i] ?? null; if ($c === null) continue; $tag = substr(gmdate('Y-m-d', $t + $off), 0, 7) . '-01'; $k[$tag] = [$tag, (float) $c]; }
    $divs = [];
    foreach (($r['events']['dividends'] ?? []) as $dv) if (isset($dv['date'], $dv['amount'])) $divs[] = [gmdate('Y-m-d', (int) $dv['date'] + $off), (float) $dv['amount']];
    usort($divs, fn($a, $b) => strcmp($a[0], $b[0]));
    // Sonderausschuettungen (> 3x Median der 8 vorigen) heraus, wie im Chart
    $sauber = [];
    foreach ($divs as $i => $d) { $vor = array_map(fn($x) => $x[1], array_slice($divs, max(0, $i - 8), min(8, $i))); sort($vor);
        if (count($vor) >= 3 && $d[1] > 3 * $vor[intdiv(count($vor), 2)]) continue; $sauber[] = $d; }
    return ['waehrung' => $r['meta']['currency'] ?? null, 'kerzen' => array_values($k), 'dividenden' => $sauber, 'name' => $r['meta']['longName'] ?? ($r['meta']['shortName'] ?? null)];
}

// Eine Zeile der Bewertungsliste - dieselbe Rechnung wie acBewertungZeile() in index.php (Bezug je Branche, Aktienverguetung
// herausgerechnet, Fair Value = Wert x 15, Normal-KGV = Median 10 J., erwartete Rendite 5 J. inkl. Dividende)
function bewertung_zeile($sym, $sektor = '', $fvr = 15.0) {
    $z = ['sym' => $sym];
    $q = bewertung_ohlc($sym, 10);
    if (!$q || !$q['kerzen']) return $z + ['fehler' => 'keine Kurse'];
    $k = $q['kerzen']; $kurs = end($k)[1]; $z['kurs'] = $kurs; $z['waehrung'] = $q['waehrung']; $z['name'] = $q['name'];
    $grenze = time() - 365 * 86400; $d12 = 0;
    foreach ($q['dividenden'] as [$d, $b]) if (strtotime($d) > $grenze) $d12 += $b;
    $z['divR'] = $kurs > 0 && $d12 > 0 ? $d12 / $kurs * 100 : 0;
    // Erhoehungen in Folge (volle Kalenderjahre)
    $jahr = []; foreach ($q['dividenden'] as [$d, $b]) { $y = (int) substr($d, 0, 4); if ($y < (int) date('Y')) $jahr[$y] = ($jahr[$y] ?? 0) + $b; }
    $folge = 0; if ($jahr) { $y = max(array_keys($jahr)); while (isset($jahr[$y - 1]) && $jahr[$y] > $jahr[$y - 1] * 1.0001) { $folge++; $y--; } }
    $z['folge'] = $folge;
    if (preg_match('/financ|finanz|bank/i', $sektor)) {   // Banken nach Buchwert (bewertung_bank), siehe oben
        $b = bewertung_bank($sym);
        if (!empty($b['bank'])) {
            if (!empty($b['fehler'])) return $z + ['fehler' => 'Bank: ' . $b['fehler']];
            $z['basis'] = 'Buchwert (Bank)'; $z['bank'] = $b; $z['kbv'] = $b['kbv'];
            $z['fv'] = $b['fv_gerecht']; $z['abw'] = $b['fv_gerecht'] > 0 ? ($kurs / $b['fv_gerecht'] - 1) * 100 : null;
            if ($b['fv_historisch']) { $z['normWert'] = $b['fv_historisch']; $z['abwN'] = ($kurs / $b['fv_historisch'] - 1) * 100; }
            $z['npe'] = $b['kbv_schnitt'];
            return $z;
        }
    }
    $rh = bewertung_reihen($sym);
    if (!$rh || empty($rh['reihen'])) return $z + ['fehler' => 'keine Gewinndaten'];
    $roh = (string) $q['waehrung']; $pence = in_array($roh, ['GBp', 'GBX'], true); $ziel = $pence ? 'GBP' : strtoupper($roh);
    $fxAm = null;
    if (!empty($rh['waehrung']) && $ziel && strtoupper($rh['waehrung']) !== $ziel) {
        $fx = bewertung_ohlc(strtoupper($rh['waehrung']) . $ziel . '=X', 60);
        if (!$fx || !$fx['kerzen']) return $z + ['fehler' => 'Wechselkurs fehlt'];
        $fk = $fx['kerzen'];
        $fxAm = function ($d) use ($fk) { $m = substr($d, 0, 7); $w = $fk[0][1]; foreach ($fk as $x) { if (substr($x[0], 0, 7) <= $m) $w = $x[1]; else break; } return $w; };
    }
    $basis = 'operativ';
    if (preg_match('/real estate|reit|immobil/i', $sektor)) $basis = !empty($rh['reihen']['ocf']) ? 'ocf' : 'gaap';
    elseif (preg_match('/financ|utilit|energy|versorg|finanz|energie|bank|insur/i', $sektor)) $basis = 'gaap';
    elseif (empty($rh['reihen']['operativ'])) $basis = !empty($rh['reihen']['gaap']) ? 'gaap' : 'ocf';
    $r = [];
    foreach (($rh['reihen'][$basis] ?? []) as $e) $r[] = ['date' => $e['date'], 'v' => $e['v'] * ($fxAm ? $fxAm($e['date']) : 1) * ($pence ? 100 : 1)];
    if (!$r) return $z + ['fehler' => 'keine Werte'];
    $letztJ = (int) substr(end($r)['date'], 0, 4);   // veraltete Daten (BIDU endet 2010) nicht bewerten, siehe acBewertungZeile
    if ($letztJ < (int) date('Y') - 2) return $z + ['fehler' => 'Gewinndaten enden ' . $letztJ];
    $z['basis'] = ['operativ' => 'Gewinn operativ', 'gaap' => 'Gewinn GAAP', 'ocf' => 'Op. Cashflow', 'fcf' => 'Free Cashflow'][$basis];
    $wert = end($r)['v']; $z['wert'] = $wert;
    // Gewinneinbruch im letzten Jahr (GPC 2025: 2,73 nach 8,45-10,35) - meist Sonderlasten; Bewertung dann unsicher
    $vor = array_map(fn($e) => $e['v'], array_slice($r, -4, 3)); $mv = count($vor) >= 2 ? br_median($vor) : null;
    if ($mv > 0 && $wert < 0.6 * $mv) $z['einbruch'] = round(($wert / $mv - 1) * 100);   // ab -40 % (Nutzer 20.11.2026, HSY Kakao 2025: -44 %)
    $schluss = function ($d) use ($k) { $m = substr($d, 0, 7); $w = null; foreach ($k as $x) { if (substr($x[0], 0, 7) <= $m) $w = $x[1]; else break; } return $w; };
    $vielf = [];
    foreach (array_slice($r, -10) as $e) { $s = $schluss($e['date']); if ($e['v'] > 0 && $s) $vielf[] = $s / $e['v']; }
    $npe = br_median($vielf);
    $pos = array_values(array_filter(array_slice($r, -11), fn($e) => $e['v'] > 0 && $e['v'] >= 0.1 * end($r)['v']));   // Ausgangswert mind. 10 % des letzten (VICI)
    $g = null;
    if (count($pos) >= 3) { $j = (strtotime(end($pos)['date']) - strtotime($pos[0]['date'])) / 31557600; if ($j > 0) $g = (pow(end($pos)['v'] / $pos[0]['v'], 1 / $j) - 1) * 100; }
    $z['g'] = $g; $z['npe'] = $npe;
    if ($wert > 0) {
        $z['fv'] = $wert * $fvr; $z['abw'] = ($kurs / $z['fv'] - 1) * 100; $z['kgv'] = $kurs / $wert;
        if ($npe) { $z['normWert'] = $wert * $npe; $z['abwN'] = ($kurs / $z['normWert'] - 1) * 100; }
        if ($npe && $g !== null) $z['ror'] = (pow($wert * pow(1 + max(-20, min(30, $g)) / 100, 5) * $npe / $kurs, 1 / 5) - 1) * 100 + $z['divR'];
    }
    // Bei Gewinneinbruch zusaetzlich mit dem Vorwaerts-Gewinn (Analystenkonsens der naechsten 12 Monate) bewerten - vorueber-
    // gehende Einbrueche (HSY 2025: Kakaopreise) machen die Bewertung mit dem letzten Jahr sonst zu teuer. Nutzer 20.11.2026.
    if (isset($z['einbruch']) && !$fxAm && in_array($basis, ['operativ', 'gaap'], true)) {
        $vw = br_vorwaerts_eps($rh['schaetzung'] ?? []);
        if ($vw > 0) {
            $z['wertVorw'] = round($vw, 4); $z['fvVorw'] = $vw * $fvr; $z['abwVorw'] = ($kurs / $z['fvVorw'] - 1) * 100;
            if ($npe) { $z['normWertVorw'] = $vw * $npe; $z['abwNVorw'] = ($kurs / $z['normWertVorw'] - 1) * 100; }
        }
    }
    return $z;
}
// Gewinn der naechsten 12 Monate aus dem Konsens (laufendes und naechstes Geschaeftsjahr, zeitanteilig gemischt)
function br_vorwaerts_eps(array $sz): ?float {
    $sz = array_values(array_filter($sz, fn($x) => ($x['eps'] ?? 0) > 0 && !empty($x['ende']) && strtotime($x['ende']) > time()));
    if (!$sz) return null;
    usort($sz, fn($a, $b) => strcmp($a['ende'], $b['ende']));
    $anteil = max(0, min(1, (strtotime($sz[0]['ende']) - time()) / 31557600));
    return $sz[0]['eps'] * $anteil + ($sz[1]['eps'] ?? $sz[0]['eps']) * (1 - $anteil);
}
