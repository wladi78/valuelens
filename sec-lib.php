<?php
// Kostenlose, unauthentifizierte SEC-EDGAR-XBRL-API als EPS-Datenquelle fuers KGV-Modell -
// liefert die offiziell gemeldeten Jahresgewinne direkt aus den 10-K-Bilanzen, meist 15+ Jahre
// zurueck (seit XBRL-Pflicht ~2009). Deutlich mehr Historie als Yahoos kostenlose Schnittstelle.
// SEC verlangt nur einen aussagekraeftigen User-Agent (Kontakt-Mail), kein API-Key/Signup.
// Limit lt. SEC: 10 Anfragen/Sekunde - siehe https://www.sec.gov/search-filings/edgar-application-programming-interfaces

// ValueLens: Kontakt-Mail aus data/einstellungen.json (Seite Einstellungen) - die SEC verlangt Name + Mail im User-Agent
define('SEC_USER_AGENT', 'ValueLens/1.0 (' . ((json_decode((string) @file_get_contents(__DIR__ . '/data/einstellungen.json'), true)['secMail'] ?? '') ?: 'valuelens@example.com') . ')');
define('SEC_TICKER_CACHE', __DIR__ . '/data/sec-tickers.json');
define('SEC_TICKER_CACHE_MAX_AGE', 30 * 86400); // Ticker<->CIK-Zuordnung aendert sich selten

function sec_curl_get_roh($url, &$code = null) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_CONNECTTIMEOUT => 6,
        CURLOPT_HTTPHEADER => ['User-Agent: ' . SEC_USER_AGENT, 'Accept-Encoding: gzip'],
        CURLOPT_ENCODING => 'gzip',
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_FOLLOWLOCATION => true,
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code !== 200 || !$body) return null;
    return json_decode($body, true);
}

// Quartalsweise statt fester Ablaufzeit (Nutzer 15.11.2026: "quartalweise mit Pruefung auf neue Daten, damit die Seite
// schneller laeuft"): SEC-Zahlen gelten, bis die Firma einen neuen 10-K/10-Q (auslaendische: 20-F/40-F) einreicht -
// hoechstens 100 Tage. Ob es eine neue Meldung gibt, fragt sec_neueste_meldung() hoechstens einmal am Tag je Firma ab
// (1 kleiner Abruf statt rund 60). Die SEC stellt die XBRL-Zahlen erst kurz nach der Meldung bereit -> ein Zwischenspeicher
// zaehlt erst als aktuell, wenn er mindestens 2 Tage nach dem Meldedatum entstanden ist.
function sec_neueste_meldung($cik) {
    $cik = str_pad(preg_replace('/\D/', '', (string) $cik), 10, '0', STR_PAD_LEFT);
    $dir = __DIR__ . '/data/cache/sec-meldung'; $datei = "$dir/$cik.json";
    if (is_file($datei) && time() - filemtime($datei) < 86400) {
        $c = json_decode((string) file_get_contents($datei), true);
        if (is_array($c)) return $c['datum'] ?? null;
    }
    $j = sec_curl_get_roh("https://data.sec.gov/submissions/CIK$cik.json");
    if (!is_array($j)) return false;   // SEC nicht erreichbar
    $r = $j['filings']['recent'] ?? []; $datum = null;
    foreach (($r['form'] ?? []) as $i => $f) {
        if (!preg_match('/^(10-K|10-Q|20-F|40-F)(\/A)?$/', (string) $f)) continue;
        $dd = $r['filingDate'][$i] ?? null; if ($dd && (!$datum || $dd > $datum)) $datum = $dd;
    }
    if (!is_dir($dir)) @mkdir($dir, 0700, true);
    @file_put_contents($datei, json_encode(['datum' => $datum, 'geprueft' => date('c')]));
    return $datum;
}
// Gilt ein Zwischenspeicher noch? Ohne CIK (keine SEC-Firma, Daten von Yahoo): wie bisher $sonstTage.
function sec_cache_frisch($datei, $cik, $sonstTage = 7) {
    if (!is_file($datei)) return false;
    $alter = time() - filemtime($datei);
    if (!$cik) return $alter < $sonstTage * 86400;
    if ($alter < 86400) return true;            // hoechstens einmal am Tag nachsehen
    if ($alter > 100 * 86400) return false;     // spaetestens nach einem Quartal neu
    $m = sec_neueste_meldung($cik);
    if ($m === false) return $alter < 14 * 86400;   // SEC gerade nicht erreichbar: alten Stand noch nutzen
    if (!$m) return true;
    return filemtime($datei) >= strtotime($m) + 2 * 86400;
}
function sec_cik_fuer($symbol) {
    $m = sec_get_cik_map(); $s = strtoupper((string) $symbol);
    return $m[$s] ?? ($m[strtok($s, '.')] ?? null);
}
// Einzelabrufe (companyconcept/companyfacts) ebenfalls bis zur naechsten Meldung aufheben, gepackt (gzip).
// Die Pause zwischen SEC-Abrufen (Limit 10/s) entfaellt, wenn die Antwort aus dem Zwischenspeicher kam: sec_pause().
function sec_curl_get($url) {
    $GLOBALS['sec_aus_cache'] = false;
    if (!preg_match('~data\.sec\.gov/api/xbrl/[a-z]+/CIK(\d{10})~', $url, $m)) return sec_curl_get_roh($url);
    $dir = __DIR__ . '/data/cache/sec-roh'; $datei = $dir . '/' . md5($url) . '.json.gz';
    if (sec_cache_frisch($datei, $m[1])) {
        $j = json_decode((string) @gzdecode((string) file_get_contents($datei)), true);
        if (is_array($j)) { $GLOBALS['sec_aus_cache'] = true; return $j ?: null; }
    }
    $j = sec_curl_get_roh($url, $code);
    if (is_array($j) || $code === 404) {
        if (!is_dir($dir)) @mkdir($dir, 0700, true);
        @file_put_contents($datei, gzencode(json_encode(is_array($j) ? $j : []), 6));
    } elseif (is_file($datei)) {   // Abruf gescheitert: lieber der alte Stand als gar nichts
        $alt = json_decode((string) @gzdecode((string) file_get_contents($datei)), true);
        if (is_array($alt)) return $alt ?: null;
    }
    return $j;
}
function sec_pause() { if (empty($GLOBALS['sec_aus_cache'])) usleep(110000); }

// Ticker -> 10-stellige CIK, lokal gecacht (data/sec-tickers.json), damit nicht jede Anfrage
// erst die komplette SEC-Firmenliste (~1MB) neu laden muss.
function sec_get_cik_map() {
    if (file_exists(SEC_TICKER_CACHE) && (time() - filemtime(SEC_TICKER_CACHE)) < SEC_TICKER_CACHE_MAX_AGE) {
        $cached = json_decode(@file_get_contents(SEC_TICKER_CACHE), true);
        if (is_array($cached) && $cached) return $cached;
    }
    $data = sec_curl_get('https://www.sec.gov/files/company_tickers.json');
    if (is_array($data)) {
        $map = [];
        foreach ($data as $row) {
            if (!isset($row['ticker'], $row['cik_str'])) continue;
            $map[strtoupper($row['ticker'])] = str_pad((string) $row['cik_str'], 10, '0', STR_PAD_LEFT);
        }
        if ($map) {
            @mkdir(dirname(SEC_TICKER_CACHE), 0755, true);
            @file_put_contents(SEC_TICKER_CACHE, json_encode($map));
            return $map;
        }
    }
    // Fetch fehlgeschlagen: lieber ein veralteter Cache als gar keiner
    if (file_exists(SEC_TICKER_CACHE)) {
        $cached = json_decode(@file_get_contents(SEC_TICKER_CACHE), true);
        if (is_array($cached)) return $cached;
    }
    return [];
}

// Rohe XBRL-"units"-Liste (Mix aus Jahres-/Quartalswerten, teils mehrfach durch Korrekturen)
// auf eine saubere, deduplizierte Jahresreihe reduzieren: nur 10-K/Full-Year-Eintraege mit
// ca. 12 Monaten Dauer, bei Mehrfachmeldung fuer dasselbe Geschaeftsjahr die zuletzt
// eingereichte (korrigierte) Fassung nehmen.
//
// WICHTIG: die Dedup-Gruppierung erfolgt bewusst ueber das GESCHAEFTSJAHRESENDE (end-Datum),
// NICHT ueber SECs eigenes "fy"-Feld - das bezeichnet naemlich nicht zuverlaessig den
// abgedeckten Zeitraum, sondern eher "in welchem Jahresbericht dieser Wert zuletzt auftauchte".
// Nach einem Aktiensplit meldet die Firma ihre Vergleichszahlen aus Vorjahren im NEUEN 10-K
// splitbereinigt, aber SEC taggt diese Vergleichswerte mit dem "fy" des NEUEN Berichts statt des
// eigentlichen Geschaeftsjahres - z.B. bei Walmart (3-fuer-1-Split Anfang 2024) landete das
// splitbereinigte Vergleichs-EPS fuer das am 31.01.2022 endende Geschaeftsjahr (1,62 USD) unter
// "fy"=2024, waehrend der urspruengliche, NICHT bereinigte Wert (4,87 USD) weiter unter "fy"=2022
// stand - eine Gruppierung nach "fy" haette also die veraltete, dreifach zu hohe Zahl behalten.
// Nach "end" gruppiert werden beide Meldungen demselben Geschaeftsjahr zugeordnet, sodass die
// zuletzt eingereichte (bereinigte) Fassung wie beabsichtigt gewinnt.
function sec_parse_annual_eps($units) {
    $byEnd = [];
    foreach ($units as $e) {
        if (($e['form'] ?? '') !== '10-K') continue;
        if (($e['fp'] ?? '') !== 'FY') continue;
        if (!isset($e['start'], $e['end'], $e['val'])) continue;
        $startTs = strtotime($e['start']);
        $endTs = strtotime($e['end']);
        if (!$startTs || !$endTs) continue;
        $days = ($endTs - $startTs) / 86400;
        if ($days < 340 || $days > 390) continue; // nur volle Geschaeftsjahre, keine Teilperioden
        $end = $e['end'];
        $filed = $e['filed'] ?? '';
        if (!isset($byEnd[$end]) || $filed >= $byEnd[$end]['filed']) {
            $byEnd[$end] = ['end' => $end, 'val' => (float) $e['val'], 'filed' => $filed];
        }
    }
    ksort($byEnd);
    $out = [];
    foreach ($byEnd as $e) {
        $out[] = ['date' => $e['end'], 'ts' => strtotime($e['end']), 'eps' => $e['val']];
    }
    return $out;
}

// Einzelabfrage (Bewertungsseite): probiert mehrere EPS-Tags nacheinander, da nicht jede
// Firma "EarningsPerShareDiluted" meldet (z.B. einfache Kapitalstruktur -> "BasicAndDiluted").
function sec_fetch_eps_single($symbol, $cikMap) {
    $cik = $cikMap[strtoupper($symbol)] ?? null;
    if (!$cik) return [];
    foreach (['EarningsPerShareDiluted', 'EarningsPerShareBasicAndDiluted', 'EarningsPerShareBasic'] as $tag) {
        $json = sec_curl_get("https://data.sec.gov/api/xbrl/companyconcept/CIK{$cik}/us-gaap/{$tag}.json");
        $units = $json['units']['USD/shares'] ?? null;
        if (!$units) continue;
        $series = sec_parse_annual_eps($units);
        if (count($series) >= 2) return $series;
    }
    return [];
}

// Wie sec_fetch_eps_single, nur fuer die gezahlte/erklaerte Dividende je Aktie (fuer die
// "Dividend Yield Theory": aktuelle Rendite vs. historische Durchschnittsrendite der letzten
// Jahre) - probiert die gaengigsten Tags nacheinander durch.
function sec_fetch_dividend_per_share_single($symbol, $cikMap) {
    $cik = $cikMap[strtoupper($symbol)] ?? null;
    if (!$cik) return [];
    foreach (['CommonStockDividendsPerShareDeclared', 'CommonStockDividendsPerShareCashPaid'] as $tag) {
        $json = sec_curl_get("https://data.sec.gov/api/xbrl/companyconcept/CIK{$cik}/us-gaap/{$tag}.json");
        $units = $json['units']['USD/shares'] ?? null;
        sec_pause();
        if (!$units) continue;
        $series = sec_parse_annual_eps($units); // gleiche Duration-Logik (10-K, FY, ~365 Tage) passt 1:1
        if (count($series) >= 2) return $series;
    }
    return [];
}

// Wie sec_parse_annual_eps, aber generisch fuer beliebige "Duration"-Kennzahlen der Erfolgs-
// rechnung (z.B. NetIncomeLoss/Revenues statt EPS). Gleiche Logik: nur volle Geschaeftsjahre
// (10-K, FY, ca. 365 Tage Periode), bei Mehrfachmeldung die zuletzt eingereichte Fassung.
// Gruppierung bewusst ueber das Periodenende (end), nicht SECs "fy"-Feld - siehe ausfuehrliche
// Begruendung bei sec_parse_annual_eps (Aktiensplit-Restatements landen sonst unter einem
// anderen "fy" als die urspruengliche, veraltete Meldung und werden nie dedupliziert).
function sec_parse_annual_duration($units) {
    $byEnd = [];
    foreach ($units as $e) {
        if (($e['form'] ?? '') !== '10-K') continue;
        if (($e['fp'] ?? '') !== 'FY') continue;
        if (!isset($e['start'], $e['end'], $e['val'])) continue;
        $startTs = strtotime($e['start']);
        $endTs = strtotime($e['end']);
        if (!$startTs || !$endTs) continue;
        $days = ($endTs - $startTs) / 86400;
        if ($days < 340 || $days > 390) continue;
        $end = $e['end'];
        $filed = $e['filed'] ?? '';
        if (!isset($byEnd[$end]) || $filed >= $byEnd[$end]['filed']) {
            $byEnd[$end] = ['end' => $end, 'val' => (float) $e['val'], 'filed' => $filed];
        }
    }
    ksort($byEnd);
    $out = [];
    foreach ($byEnd as $e) { $out[] = ['date' => $e['end'], 'val' => $e['val']]; }
    return $out;
}

// Fuer Bilanz-Stichtagswerte (z.B. Assets/StockholdersEquity) - diese XBRL-Konzepte sind
// "instant" (Momentaufnahme zum Bilanzstichtag), haben also KEIN "start"-Feld wie die
// Erfolgsrechnungs-Kennzahlen oben. Gleiches Dedup-Prinzip (zuletzt eingereichte Fassung je
// Stichtag) wie oben, ebenfalls ueber "end" statt "fy" gruppiert.
function sec_parse_annual_instant($units) {
    $byEnd = [];
    foreach ($units as $e) {
        if (($e['form'] ?? '') !== '10-K') continue;
        if (!isset($e['end'], $e['val'])) continue;
        $end = $e['end'];
        $filed = $e['filed'] ?? '';
        if (!isset($byEnd[$end]) || $filed >= $byEnd[$end]['filed']) {
            $byEnd[$end] = ['end' => $end, 'val' => (float) $e['val'], 'filed' => $filed];
        }
    }
    ksort($byEnd);
    $out = [];
    foreach ($byEnd as $e) { $out[] = ['date' => $e['end'], 'val' => $e['val']]; }
    return $out;
}

// Profitabilitaets-Rohdaten (Nettomarge/ROE/ROA) fuer EIN Symbol aus den zuletzt gemeldeten
// Jahreszahlen (10-K) - inspiriert vom "Quality-als-Profitabilitaet"-Ansatz (Novy-Marx u.a.):
// probiert je Konzept mehrere gaengige XBRL-Tags, da unterschiedliche Firmen leicht
// unterschiedliche Tags fuer denselben Sachverhalt nutzen. Nur fuer SEC-meldepflichtige (also
// US-boersennotierte) Unternehmen verfuegbar - bei allen anderen liefert das schlicht null.
function sec_fetch_profitability($symbol, $cikMap) {
    $cik = $cikMap[strtoupper($symbol)] ?? null;
    if (!$cik) return null;

    $fetchLatest = function($tags, $instant) use ($cik) {
        foreach ($tags as $tag) {
            $json = sec_curl_get("https://data.sec.gov/api/xbrl/companyconcept/CIK{$cik}/us-gaap/{$tag}.json");
            $units = $json['units']['USD'] ?? null;
            sec_pause(); // ~9 Anfragen/Sekunde, unter SECs Limit von 10/s bleiben
            if (!$units) continue;
            $series = $instant ? sec_parse_annual_instant($units) : sec_parse_annual_duration($units);
            if (count($series)) return end($series);
        }
        return null;
    };

    $netIncome = $fetchLatest(['NetIncomeLoss'], false);
    if (!$netIncome) return null; // ohne Nettogewinn kein sinnvoller Score moeglich

    $revenue = $fetchLatest(['Revenues', 'RevenueFromContractWithCustomerExcludingAssessedTax', 'RevenueFromContractWithCustomerIncludingAssessedTax'], false);
    $equity = $fetchLatest(['StockholdersEquity', 'StockholdersEquityIncludingPortionAttributableToNoncontrollingInterest'], true);
    $assets = $fetchLatest(['Assets'], true);

    return [
        'fiscalYearEnd' => $netIncome['end'],
        'netMarginPct' => ($revenue && $revenue['val'] != 0) ? round($netIncome['val'] / $revenue['val'] * 100, 2) : null,
        'roePct' => ($equity && $equity['val'] != 0) ? round($netIncome['val'] / $equity['val'] * 100, 2) : null,
        'roaPct' => ($assets && $assets['val'] != 0) ? round($netIncome['val'] / $assets['val'] * 100, 2) : null,
    ];
}

// Dividenden-"Sicherheits"-Kennzahlen (Bewertungsseite, Einzelabfrage): Ausschuettungsquote
// (Payout-Ratio = gezahlte Dividende / Nettogewinn) UND deren Deckung durch den Free Cashflow
// (Dividende / (operativer Cashflow - Capex)) je Geschaeftsjahr, plus der Trend der Payout-Ratio
// ueber die letzten Jahre (steigend/fallend/stabil). Ergaenzt den reinen Profitabilitaets-Score
// um die eigentliche Kernfrage bei Dividendenaktien: "kann sich die Firma das leisten, und wird
// es eher leichter oder schwerer?". Nur fuer SEC-meldepflichtige (US-)Unternehmen verfuegbar -
// bei fehlenden Dividendenmeldungen (kein Dividendenzahler o.ae.) liefert das schlicht null.
function sec_fetch_payout_trend($symbol, $cikMap) {
    $cik = $cikMap[strtoupper($symbol)] ?? null;
    if (!$cik) return null;

    $fetchSeries = function ($tags) use ($cik) {
        foreach ($tags as $tag) {
            $json = sec_curl_get("https://data.sec.gov/api/xbrl/companyconcept/CIK{$cik}/us-gaap/{$tag}.json");
            $units = $json['units']['USD'] ?? null;
            sec_pause(); // ~9 Anfragen/Sekunde, unter SECs Limit von 10/s bleiben
            if (!$units) continue;
            $series = sec_parse_annual_duration($units);
            if (count($series)) return $series;
        }
        return [];
    };

    $netIncomeSeries = $fetchSeries(['NetIncomeLoss']);
    if (!$netIncomeSeries) return null;
    $dividendsSeries = $fetchSeries(['PaymentsOfDividendsCommonStock', 'PaymentsOfDividends']);
    if (!$dividendsSeries) return null; // keine gezahlten Dividenden gemeldet -> keine Payout-Ratio moeglich
    $ocfSeries = $fetchSeries(['NetCashProvidedByUsedInOperatingActivities']);
    $capexSeries = $fetchSeries(['PaymentsForCapitalExpenditures', 'PaymentsToAcquirePropertyPlantAndEquipment']);

    $byDate = function ($series) {
        $out = [];
        foreach ($series as $e) { $out[$e['date']] = $e['val']; }
        return $out;
    };
    $niByDate = $byDate($netIncomeSeries);
    $ocfByDate = $byDate($ocfSeries);
    $capexByDate = $byDate($capexSeries);

    $years = [];
    foreach ($dividendsSeries as $e) {
        $date = $e['date'];
        if (!isset($niByDate[$date])) continue;
        $ni = $niByDate[$date];
        $divAbs = abs($e['val']);
        $payoutPct = ($ni > 0) ? round($divAbs / $ni * 100, 1) : null;
        $fcfPayoutPct = null;
        if (isset($ocfByDate[$date])) {
            $capex = isset($capexByDate[$date]) ? abs($capexByDate[$date]) : 0;
            $fcf = $ocfByDate[$date] - $capex;
            if ($fcf > 0) $fcfPayoutPct = round($divAbs / $fcf * 100, 1);
        }
        $years[] = ['date' => $date, 'payoutRatioPct' => $payoutPct, 'fcfPayoutRatioPct' => $fcfPayoutPct];
    }
    usort($years, function ($a, $b) { return strcmp($a['date'], $b['date']); });
    $years = array_slice($years, -5); // letzte bis zu 5 Jahre reichen fuer einen Trend
    if (!$years) return null;

    $latest = end($years);
    $trend = null;
    if (count($years) >= 2 && $latest['payoutRatioPct'] !== null) {
        $prev = $years[count($years) - 2];
        if ($prev['payoutRatioPct'] !== null) {
            $delta = $latest['payoutRatioPct'] - $prev['payoutRatioPct'];
            $trend = (abs($delta) < 3) ? 'stable' : ($delta > 0 ? 'rising' : 'falling');
        }
    }

    return [
        'payoutRatioPct' => $latest['payoutRatioPct'],
        'fcfPayoutRatioPct' => $latest['fcfPayoutRatioPct'],
        'payoutTrend' => $trend,
        'payoutYearsCount' => count($years),
    ];
}

// Tilgungsjahre (Nettoschulden / freier Cashflow): wie viele Jahre bräuchte das Unternehmen,
// um seine gesamten Finanzschulden abzüglich liquider Mittel allein aus dem freien Cashflow des
// letzten Geschäftsjahres zu tilgen - ein gängiges, einfaches Verschuldungsmaß (vergleichbar mit
// der "Tilgungsjahre"/"Max. Tilgungsjahre"-Kennzahl bekannter Aktien-Screener). Es gibt bei
// SEC-XBRL keinen einzelnen "Nettoschulden"-Tag, daher Summe aus den gängigsten Einzeltags
// (langfristige + kurzfristige Finanzschulden, abzüglich Zahlungsmittel). Nur für SEC-
// meldepflichtige (US-)Unternehmen verfügbar, darf ruhig scheitern (liefert dann null).
function sec_fetch_debt_payback($symbol, $cikMap) {
    $cik = $cikMap[strtoupper($symbol)] ?? null;
    if (!$cik) return null;

    $fetchInstant = function ($tags) use ($cik) {
        foreach ($tags as $tag) {
            $json = sec_curl_get("https://data.sec.gov/api/xbrl/companyconcept/CIK{$cik}/us-gaap/{$tag}.json");
            $units = $json['units']['USD'] ?? null;
            sec_pause(); // ~9 Anfragen/Sekunde, unter SECs Limit von 10/s bleiben
            if (!$units) continue;
            $series = sec_parse_annual_instant($units);
            if (count($series)) return end($series);
        }
        return null;
    };
    $fetchDuration = function ($tags) use ($cik) {
        foreach ($tags as $tag) {
            $json = sec_curl_get("https://data.sec.gov/api/xbrl/companyconcept/CIK{$cik}/us-gaap/{$tag}.json");
            $units = $json['units']['USD'] ?? null;
            sec_pause();
            if (!$units) continue;
            $series = sec_parse_annual_duration($units);
            if (count($series)) return end($series);
        }
        return null;
    };

    $ltDebtNoncurrent = $fetchInstant(['LongTermDebtNoncurrent']);
    $ltDebtCurrent = $fetchInstant(['LongTermDebtCurrent', 'DebtCurrent']);
    $stBorrowings = $fetchInstant(['ShortTermBorrowings']);
    $cash = $fetchInstant(['CashAndCashEquivalentsAtCarryingValue', 'CashCashEquivalentsRestrictedCashAndRestrictedCashEquivalents']);
    $ocf = $fetchDuration(['NetCashProvidedByUsedInOperatingActivities']);
    $capex = $fetchDuration(['PaymentsForCapitalExpenditures', 'PaymentsToAcquirePropertyPlantAndEquipment']);

    if (!$ocf) return null; // ohne operativen Cashflow keine sinnvolle Aussage moeglich

    $totalDebt = ($ltDebtNoncurrent['val'] ?? 0) + ($ltDebtCurrent['val'] ?? 0) + ($stBorrowings['val'] ?? 0);
    $cashVal = $cash['val'] ?? 0;
    $netDebt = $totalDebt - $cashVal;
    $capexVal = $capex ? abs($capex['val']) : 0;
    $fcf = $ocf['val'] - $capexVal;

    $result = [
        'netDebt' => round($netDebt, 0),
        'fcf' => round($fcf, 0),
        'fiscalYearEnd' => $ocf['end'],
        'paybackYears' => null,
    ];
    if ($netDebt <= 0) {
        $result['paybackYears'] = 0; // mehr Cash als Finanzschulden -> netto schuldenfrei
    } elseif ($fcf > 0) {
        $result['paybackYears'] = round($netDebt / $fcf, 1);
    }
    // sonst: Nettoschulden vorhanden, aber kein positiver FCF -> Tilgung aus FCF aktuell nicht
    // sinnvoll bezifferbar, bleibt null (Frontend zeigt dann eine Warnung statt einer Zahl).
    return $result;
}

// Zwei weitere, gaengige Solvenz-Kennzahlen (inspiriert von der "Solvency"-Kategorie bekannter
// Aktien-Screener, aber mit unseren eigenen kostenlosen SEC-Daten statt lizenzierten): der
// Zinsdeckungsgrad (EBIT / Zinsaufwand - wie oft koennte der operative Gewinn die Zinslast
// decken, je hoeher desto sicherer) und die Current Ratio (kurzfristige Vermoegenswerte /
// kurzfristige Verbindlichkeiten - Mass fuer die kurzfristige Zahlungsfaehigkeit). Ergaenzt die
// Tilgungsjahre (sec_fetch_debt_payback oben) um zwei weitere, unabhaengige Blickwinkel auf die
// finanzielle Stabilitaet. Nur fuer SEC-meldepflichtige (US-)Unternehmen verfuegbar, darf ruhig
// scheitern (liefert dann null je Kennzahl einzeln, blockiert die andere nicht).
function sec_fetch_solvency($symbol, $cikMap) {
    $cik = $cikMap[strtoupper($symbol)] ?? null;
    if (!$cik) return null;

    $fetchInstant = function ($tags) use ($cik) {
        foreach ($tags as $tag) {
            $json = sec_curl_get("https://data.sec.gov/api/xbrl/companyconcept/CIK{$cik}/us-gaap/{$tag}.json");
            $units = $json['units']['USD'] ?? null;
            sec_pause();
            if (!$units) continue;
            $series = sec_parse_annual_instant($units);
            if (count($series)) return end($series);
        }
        return null;
    };
    $fetchDuration = function ($tags) use ($cik) {
        foreach ($tags as $tag) {
            $json = sec_curl_get("https://data.sec.gov/api/xbrl/companyconcept/CIK{$cik}/us-gaap/{$tag}.json");
            $units = $json['units']['USD'] ?? null;
            sec_pause();
            if (!$units) continue;
            $series = sec_parse_annual_duration($units);
            if (count($series)) return end($series);
        }
        return null;
    };

    $ebit = $fetchDuration(['OperatingIncomeLoss']);
    $interestExpense = $fetchDuration(['InterestExpense', 'InterestExpenseDebt', 'InterestAndDebtExpense']);
    $currentAssets = $fetchInstant(['AssetsCurrent']);
    $currentLiabilities = $fetchInstant(['LiabilitiesCurrent']);

    $interestCoverage = null;
    $interestCoverageFiscalYearEnd = null;
    if ($ebit && $interestExpense && $interestExpense['val'] > 0) {
        $interestCoverage = round($ebit['val'] / $interestExpense['val'], 1);
        $interestCoverageFiscalYearEnd = $ebit['end'];
    }

    $currentRatio = null;
    $currentRatioFiscalYearEnd = null;
    if ($currentAssets && $currentLiabilities && $currentLiabilities['val'] > 0) {
        $currentRatio = round($currentAssets['val'] / $currentLiabilities['val'], 2);
        $currentRatioFiscalYearEnd = $currentAssets['end'];
    }

    if ($interestCoverage === null && $currentRatio === null) return null;

    return [
        'interestCoverage' => $interestCoverage,
        'interestCoverageFiscalYearEnd' => $interestCoverageFiscalYearEnd,
        'currentRatio' => $currentRatio,
        'currentRatioFiscalYearEnd' => $currentRatioFiscalYearEnd,
    ];
}

// Piotroski F-Score (0-9) + Altman Z-Score - zwei klassische, oeffentlich dokumentierte
// akademische Kennzahlen (Piotroski 1999 bzw. Altman 1968, keine proprietaeren Formeln irgend-
// eines Anbieters), die zusammen berechnet werden, weil sie sich viele der benoetigten SEC-
// Kennzahlen teilen (Assets, Umlaufvermoegen/-verbindlichkeiten, EBIT, Umsatz) - so muss nicht
// zweimal dieselbe Kennzahl abgerufen werden. Der F-Score prueft 9 Ja/Nein-Kriterien zu
// Profitabilitaet, Verschuldung und Effizienz im Jahresvergleich (aktuelles vs. voriges
// Geschaeftsjahr), der Z-Score kombiniert 5 Bilanzkennzahlen zu einem Insolvenzrisiko-Indikator.
// Beide duerfen ruhig unvollstaendig/null bleiben, wenn zu wenige der noetigen SEC-Tags gemeldet
// werden (z.B. bei nicht-US-Unternehmen oder Firmen mit untypischer Bilanzstruktur).
function sec_fetch_quality_scores($symbol, $cikMap, $currentPrice) {
    $cik = $cikMap[strtoupper($symbol)] ?? null;
    if (!$cik) return null;

    $fetchInstant2 = function ($tags) use ($cik) {
        foreach ($tags as $tag) {
            $json = sec_curl_get("https://data.sec.gov/api/xbrl/companyconcept/CIK{$cik}/us-gaap/{$tag}.json");
            $units = $json['units']['USD'] ?? null;
            sec_pause(); // ~9 Anfragen/Sekunde, unter SECs Limit von 10/s bleiben
            if (!$units) continue;
            $series = sec_parse_annual_instant($units);
            if (count($series)) return array_slice($series, -2);
        }
        return [];
    };
    $fetchDuration2 = function ($tags) use ($cik) {
        foreach ($tags as $tag) {
            $json = sec_curl_get("https://data.sec.gov/api/xbrl/companyconcept/CIK{$cik}/us-gaap/{$tag}.json");
            $units = $json['units']['USD'] ?? null;
            sec_pause();
            if (!$units) continue;
            $series = sec_parse_annual_duration($units);
            if (count($series)) return array_slice($series, -2);
        }
        return [];
    };
    $fetchSharesInstant2 = function ($tags) use ($cik) {
        foreach ($tags as $tag) {
            $json = sec_curl_get("https://data.sec.gov/api/xbrl/companyconcept/CIK{$cik}/us-gaap/{$tag}.json");
            $units = $json['units']['shares'] ?? null;
            sec_pause();
            if (!$units) continue;
            $series = sec_parse_annual_instant($units);
            if (count($series)) return array_slice($series, -2);
        }
        return [];
    };

    $assets = $fetchInstant2(['Assets']);
    $currentAssets = $fetchInstant2(['AssetsCurrent']);
    $currentLiabilities = $fetchInstant2(['LiabilitiesCurrent']);
    $totalLiabilities = $fetchInstant2(['Liabilities']);
    $ltDebt = $fetchInstant2(['LongTermDebtNoncurrent']);
    $retainedEarnings = $fetchInstant2(['RetainedEarningsAccumulatedDeficit']);
    $sharesOut = $fetchSharesInstant2(['CommonStockSharesOutstanding']);
    $netIncome = $fetchDuration2(['NetIncomeLoss']);
    $ocf = $fetchDuration2(['NetCashProvidedByUsedInOperatingActivities']);
    $revenue = $fetchDuration2(['Revenues', 'RevenueFromContractWithCustomerExcludingAssessedTax', 'RevenueFromContractWithCustomerIncludingAssessedTax']);
    $grossProfit = $fetchDuration2(['GrossProfit']);
    $ebit = $fetchDuration2(['OperatingIncomeLoss']);

    $cur = function ($series) { return $series ? end($series)['val'] : null; };
    $prev = function ($series) { return count($series) >= 2 ? $series[count($series) - 2]['val'] : null; };
    $curDate = function ($series) { return $series ? end($series)['date'] : null; };

    $criteria = [];
    $score = 0;
    $maxScore = 0;
    $addCriterion = function ($key, $label, $pass) use (&$criteria, &$score, &$maxScore) {
        $criteria[] = ['key' => $key, 'label' => $label, 'pass' => $pass];
        $score += $pass ? 1 : 0;
        $maxScore++;
    };

    // 1) Gesamtkapitalrendite (ROA) positiv
    $roaCur = ($cur($netIncome) !== null && $cur($assets)) ? $cur($netIncome) / $cur($assets) : null;
    if ($roaCur !== null) $addCriterion('roa_positive', 'Gesamtkapitalrendite (ROA) positiv', $roaCur > 0);

    // 2) Operativer Cashflow positiv
    if ($cur($ocf) !== null) $addCriterion('cfo_positive', 'Operativer Cashflow positiv', $cur($ocf) > 0);

    // 3) ROA gestiegen ggue. Vorjahr
    $roaPrev = ($prev($netIncome) !== null && $prev($assets)) ? $prev($netIncome) / $prev($assets) : null;
    if ($roaCur !== null && $roaPrev !== null) $addCriterion('roa_improving', 'ROA gestiegen ggü. Vorjahr', $roaCur > $roaPrev);

    // 4) Operativer Cashflow > Nettogewinn (Ergebnisqualitaet / Accruals-Test)
    if ($cur($ocf) !== null && $cur($netIncome) !== null) $addCriterion('accruals', 'Operativer Cashflow > Nettogewinn (Ergebnisqualität)', $cur($ocf) > $cur($netIncome));

    // 5) Verschuldungsgrad (langfristige Schulden / Bilanzsumme) gesunken
    $levCur = ($cur($ltDebt) !== null && $cur($assets)) ? $cur($ltDebt) / $cur($assets) : null;
    $levPrev = ($prev($ltDebt) !== null && $prev($assets)) ? $prev($ltDebt) / $prev($assets) : null;
    if ($levCur !== null && $levPrev !== null) $addCriterion('leverage_down', 'Verschuldungsgrad gesunken ggü. Vorjahr', $levCur < $levPrev);

    // 6) Current Ratio gestiegen
    $crCur = ($cur($currentAssets) !== null && $cur($currentLiabilities)) ? $cur($currentAssets) / $cur($currentLiabilities) : null;
    $crPrev = ($prev($currentAssets) !== null && $prev($currentLiabilities)) ? $prev($currentAssets) / $prev($currentLiabilities) : null;
    if ($crCur !== null && $crPrev !== null) $addCriterion('current_ratio_up', 'Current Ratio gestiegen ggü. Vorjahr', $crCur > $crPrev);

    // 7) Keine neuen Aktien ausgegeben (keine Verwaesserung)
    if ($cur($sharesOut) !== null && $prev($sharesOut) !== null) $addCriterion('no_dilution', 'Keine neuen Aktien ausgegeben', $cur($sharesOut) <= $prev($sharesOut));

    // 8) Bruttomarge gestiegen
    $gmCur = ($cur($grossProfit) !== null && $cur($revenue)) ? $cur($grossProfit) / $cur($revenue) : null;
    $gmPrev = ($prev($grossProfit) !== null && $prev($revenue)) ? $prev($grossProfit) / $prev($revenue) : null;
    if ($gmCur !== null && $gmPrev !== null) $addCriterion('gross_margin_up', 'Bruttomarge gestiegen ggü. Vorjahr', $gmCur > $gmPrev);

    // 9) Kapitalumschlag (Umsatz / Bilanzsumme) gestiegen
    $atCur = ($cur($revenue) !== null && $cur($assets)) ? $cur($revenue) / $cur($assets) : null;
    $atPrev = ($prev($revenue) !== null && $prev($assets)) ? $prev($revenue) / $prev($assets) : null;
    if ($atCur !== null && $atPrev !== null) $addCriterion('asset_turnover_up', 'Kapitalumschlag gestiegen ggü. Vorjahr', $atCur > $atPrev);

    // Nur als aussagekraeftig werten, wenn mindestens 6 der 9 Kriterien berechenbar waren
    $piotroski = ($maxScore >= 6) ? ['score' => $score, 'maxScore' => $maxScore, 'criteria' => $criteria] : null;

    // Altman Z-Score (klassische Formel fuer boersennotierte Nicht-Finanzunternehmen):
    // Z = 1.2*(WC/TA) + 1.4*(RE/TA) + 3.3*(EBIT/TA) + 0.6*(Marktkap./Gesamtschulden) + 1.0*(Umsatz/TA)
    $altmanZ = null;
    if ($cur($currentAssets) !== null && $cur($currentLiabilities) !== null && $cur($assets)
        && $cur($retainedEarnings) !== null && $cur($ebit) !== null && $cur($revenue) !== null
        && $currentPrice && $cur($sharesOut)) {
        $wcTa = ($cur($currentAssets) - $cur($currentLiabilities)) / $cur($assets);
        $reTa = $cur($retainedEarnings) / $cur($assets);
        $ebitTa = $cur($ebit) / $cur($assets);
        $marketCap = $currentPrice * $cur($sharesOut);
        $totalLiab = $cur($totalLiabilities);
        $mktLiab = ($totalLiab && $totalLiab > 0) ? $marketCap / $totalLiab : null;
        $saleTa = $cur($revenue) / $cur($assets);
        if ($mktLiab !== null) {
            $altmanZ = round(1.2 * $wcTa + 1.4 * $reTa + 3.3 * $ebitTa + 0.6 * $mktLiab + 1.0 * $saleTa, 2);
        }
    }

    if ($piotroski === null && $altmanZ === null) return null;

    return [
        'piotroski' => $piotroski,
        'altmanZ' => $altmanZ,
        'fiscalYearEnd' => $curDate($assets),
    ];
}

// Cash Conversion Cycle (Days Inventory + Days Sales Outstanding − Days Payable): klassische
// Working-Capital-Effizienzkennzahl - wie viele Tage bindet das Unternehmen im Schnitt Kapital
// im Umlaufvermoegen, bevor aus eingekaufter Ware wieder Bargeld wird. Nur fuer SEC-melde-
// pflichtige (US-)Unternehmen und nur bei Firmen mit nennenswertem Lagerbestand/Wareneinsatz
// sinnvoll (z.B. nicht bei reinen Dienstleistern/Software) - darf ruhig scheitern (liefert dann
// null je Teilkennzahl einzeln, blockiert die anderen nicht).
// Free-Cashflow-Reihe + Nettoschulden + verwaesserte Aktienanzahl fuers DCF-Modell (siehe
// dcf_compute in fundamentals-lib.php) - anders als sec_fetch_debt_payback oben (die nur den
// letzten FCF-Wert braucht) liefert diese Funktion die komplette Jahresreihe, damit daraus eine
// eigene Wachstumsrate geschaetzt werden kann. Klassische, oeffentlich dokumentierte DCF-
// Methodik (Damodaran/John Burr Williams 1938), keine proprietaere Formel.
function sec_fetch_dcf_inputs($symbol, $cikMap) {
    $cik = $cikMap[strtoupper($symbol)] ?? null;
    if (!$cik) return null;

    $fetchDurationSeries = function ($tags) use ($cik) {
        foreach ($tags as $tag) {
            $json = sec_curl_get("https://data.sec.gov/api/xbrl/companyconcept/CIK{$cik}/us-gaap/{$tag}.json");
            $units = $json['units']['USD'] ?? null;
            sec_pause(); // ~9 Anfragen/Sekunde, unter SECs Limit von 10/s bleiben
            if (!$units) continue;
            $series = sec_parse_annual_duration($units);
            if (count($series) >= 2) return $series;
        }
        return [];
    };
    $fetchInstantLatest = function ($tags) use ($cik) {
        foreach ($tags as $tag) {
            $json = sec_curl_get("https://data.sec.gov/api/xbrl/companyconcept/CIK{$cik}/us-gaap/{$tag}.json");
            $units = $json['units']['USD'] ?? null;
            sec_pause();
            if (!$units) continue;
            $series = sec_parse_annual_instant($units);
            if (count($series)) return end($series);
        }
        return null;
    };
    $fetchSharesLatest = function ($tags) use ($cik) {
        foreach ($tags as $tag) {
            $json = sec_curl_get("https://data.sec.gov/api/xbrl/companyconcept/CIK{$cik}/us-gaap/{$tag}.json");
            $units = $json['units']['shares'] ?? null;
            sec_pause();
            if (!$units) continue;
            $series = sec_parse_annual_duration($units);
            if (count($series)) return end($series);
        }
        return null;
    };

    $ocfSeries = $fetchDurationSeries(['NetCashProvidedByUsedInOperatingActivities']);
    if (!$ocfSeries) return null;
    $capexSeries = $fetchDurationSeries(['PaymentsForCapitalExpenditures', 'PaymentsToAcquirePropertyPlantAndEquipment']);

    $capexByDate = [];
    foreach ($capexSeries as $e) { $capexByDate[$e['date']] = abs($e['val']); }

    $fcfSeries = [];
    foreach ($ocfSeries as $e) {
        $capex = $capexByDate[$e['date']] ?? 0;
        $fcfSeries[] = ['date' => $e['date'], 'val' => $e['val'] - $capex];
    }
    if (count($fcfSeries) < 2) return null;

    $ltDebtNoncurrent = $fetchInstantLatest(['LongTermDebtNoncurrent']);
    $ltDebtCurrent = $fetchInstantLatest(['LongTermDebtCurrent', 'DebtCurrent']);
    $stBorrowings = $fetchInstantLatest(['ShortTermBorrowings']);
    $cash = $fetchInstantLatest(['CashAndCashEquivalentsAtCarryingValue', 'CashCashEquivalentsRestrictedCashAndRestrictedCashEquivalents']);
    $netDebt = ($ltDebtNoncurrent['val'] ?? 0) + ($ltDebtCurrent['val'] ?? 0) + ($stBorrowings['val'] ?? 0) - ($cash['val'] ?? 0);

    $dilutedShares = $fetchSharesLatest(['WeightedAverageNumberOfDilutedSharesOutstanding', 'WeightedAverageNumberOfSharesOutstandingBasic']);
    if (!$dilutedShares || $dilutedShares['val'] <= 0) return null;

    return [
        'fcfSeries' => $fcfSeries,
        'netDebt' => round($netDebt, 0),
        'dilutedShares' => $dilutedShares['val'],
        'fiscalYearEnd' => end($fcfSeries)['date'],
    ];
}

// Umsatz je Aktie - reine Hilfsdatenreihe fuer die P/S-Ersatzbewertung (siehe fv_compute_ps in
// fundamentals-lib.php). Wird NUR abgerufen, wenn das normale KGV-Modell mangels positivem
// TTM-Gewinn keinen Fair Value liefern konnte (typisch bei noch nicht profitablen
// Wachstumsfirmen, z.B. Peloton) - Idee angelehnt an SimplyWallSt's "Key Valuation Metric"
// (dort wechselt die Seite bei unprofitablen Firmen automatisch von P/E auf P/S bzw. P/B).
// Gleiche Duration-/Dedup-Logik wie sec_parse_annual_duration, kombiniert Umsatz und
// verwaesserte Aktienanzahl je Geschaeftsjahr.
function sec_fetch_revenue_per_share_series($symbol, $cikMap) {
    $cik = $cikMap[strtoupper($symbol)] ?? null;
    if (!$cik) return [];

    $fetchSeries = function ($tags) use ($cik) {
        foreach ($tags as $tag) {
            $json = sec_curl_get("https://data.sec.gov/api/xbrl/companyconcept/CIK{$cik}/us-gaap/{$tag}.json");
            $units = $json['units']['USD'] ?? $json['units']['shares'] ?? null;
            sec_pause(); // ~9 Anfragen/Sekunde, unter SECs Limit von 10/s bleiben
            if (!$units) continue;
            $series = sec_parse_annual_duration($units);
            if (count($series) >= 2) return $series;
        }
        return [];
    };

    $revenueSeries = $fetchSeries(['Revenues', 'RevenueFromContractWithCustomerExcludingAssessedTax', 'RevenueFromContractWithCustomerIncludingAssessedTax', 'SalesRevenueNet']);
    if (!$revenueSeries) return [];
    $sharesSeries = $fetchSeries(['WeightedAverageNumberOfDilutedSharesOutstanding', 'WeightedAverageNumberOfSharesOutstandingBasic']);
    if (!$sharesSeries) return [];

    $sharesByDate = [];
    foreach ($sharesSeries as $e) { $sharesByDate[$e['date']] = $e['val']; }

    $out = [];
    foreach ($revenueSeries as $e) {
        $shares = $sharesByDate[$e['date']] ?? null;
        if (!$shares || $shares <= 0 || $e['val'] <= 0) continue;
        $out[] = ['date' => $e['date'], 'ts' => strtotime($e['date']), 'rps' => $e['val'] / $shares];
    }
    return $out;
}

function sec_fetch_cash_conversion_cycle($symbol, $cikMap) {
    $cik = $cikMap[strtoupper($symbol)] ?? null;
    if (!$cik) return null;

    $fetchInstant = function ($tags) use ($cik) {
        foreach ($tags as $tag) {
            $json = sec_curl_get("https://data.sec.gov/api/xbrl/companyconcept/CIK{$cik}/us-gaap/{$tag}.json");
            $units = $json['units']['USD'] ?? null;
            sec_pause();
            if (!$units) continue;
            $series = sec_parse_annual_instant($units);
            if (count($series)) return end($series);
        }
        return null;
    };
    $fetchDuration = function ($tags) use ($cik) {
        foreach ($tags as $tag) {
            $json = sec_curl_get("https://data.sec.gov/api/xbrl/companyconcept/CIK{$cik}/us-gaap/{$tag}.json");
            $units = $json['units']['USD'] ?? null;
            sec_pause();
            if (!$units) continue;
            $series = sec_parse_annual_duration($units);
            if (count($series)) return end($series);
        }
        return null;
    };

    $inventory = $fetchInstant(['InventoryNet']);
    $receivables = $fetchInstant(['AccountsReceivableNetCurrent']);
    $payables = $fetchInstant(['AccountsPayableCurrent']);
    $cogs = $fetchDuration(['CostOfGoodsAndServicesSold', 'CostOfRevenue', 'CostOfGoodsSold']);
    $revenue = $fetchDuration(['Revenues', 'RevenueFromContractWithCustomerExcludingAssessedTax', 'RevenueFromContractWithCustomerIncludingAssessedTax']);

    if (!$cogs && !$revenue) return null;

    $daysInventory = ($inventory && $cogs && $cogs['val'] > 0) ? round($inventory['val'] / $cogs['val'] * 365, 1) : null;
    $daysSalesOutstanding = ($receivables && $revenue && $revenue['val'] > 0) ? round($receivables['val'] / $revenue['val'] * 365, 1) : null;
    $daysPayable = ($payables && $cogs && $cogs['val'] > 0) ? round($payables['val'] / $cogs['val'] * 365, 1) : null;

    if ($daysInventory === null && $daysSalesOutstanding === null && $daysPayable === null) return null;

    $ccc = ($daysInventory !== null && $daysSalesOutstanding !== null && $daysPayable !== null)
        ? round($daysInventory + $daysSalesOutstanding - $daysPayable, 1) : null;

    return [
        'daysInventory' => $daysInventory,
        'daysSalesOutstanding' => $daysSalesOutstanding,
        'daysPayable' => $daysPayable,
        'cashConversionCycle' => $ccc,
        'fiscalYearEnd' => $revenue ? $revenue['date'] : ($cogs ? $cogs['date'] : null),
    ];
}

// Rohdaten fuer ROIC/Debt-EBITDA/Revenue-Wachstum (Nutzerwunsch vom 06.09.2026, Screener-Erweiterung).
// NUTZT BEWUSST den "companyfacts"-Bulk-Endpunkt (alle ~700+ Tags einer Firma in EINEM Aufruf)
// statt des sonst in dieser Datei ueblichen "companyconcept"-Einzelabrufs pro Tag: live gegen die
// echte SEC-API getestet (05./06.09.2026, Coca-Cola/CIK 21344) lieferte companyconcept fuer
// mehrere absolut Standard-Tags (Assets, Revenues, OperatingIncomeLoss, sogar das bereits
// produktiv genutzte EarningsPerShareDiluted) wiederholt eine leere "units"-Antwort (200 OK,
// aber {"USD":{}}), waehrend companyfacts fuer DIESELBE Firma/DIESELBEN Tags zuverlaessig die
// vollen Zeitreihen lieferte. Ausserdem effizienter: EIN Request statt bis zu 8 fuer alle hier
// benoetigten Kennzahlen zusammen.
function sec_fetch_quality_inputs($symbol, $cikMap) {
    $cik = $cikMap[strtoupper($symbol)] ?? null;
    if (!$cik) return null;
    $json = sec_curl_get("https://data.sec.gov/api/xbrl/companyfacts/CIK{$cik}.json");
    sec_pause();
    $gaap = $json['facts']['us-gaap'] ?? null;
    if (!$gaap) return null;

    $pick = function ($tags, $parseFn) use ($gaap) {
        foreach ($tags as $tag) {
            $units = $gaap[$tag]['units']['USD'] ?? null;
            if (!$units) continue;
            $series = $parseFn($units);
            if (count($series)) return $series;
        }
        return [];
    };
    $last = function ($series) { return $series ? end($series)['val'] : null; };

    $revenueSeries = $pick(['Revenues', 'RevenueFromContractWithCustomerExcludingAssessedTax', 'RevenueFromContractWithCustomerIncludingAssessedTax', 'SalesRevenueNet'], 'sec_parse_annual_duration');
    $operatingIncome = $last($pick(['OperatingIncomeLoss'], 'sec_parse_annual_duration'));
    $equity = $last($pick(['StockholdersEquity', 'StockholdersEquityIncludingPortionAttributableToNoncontrollingInterest'], 'sec_parse_annual_instant'));
    $ltDebtNoncurrent = $last($pick(['LongTermDebtNoncurrent'], 'sec_parse_annual_instant'));
    $ltDebtCurrent = $last($pick(['LongTermDebtCurrent', 'DebtCurrent'], 'sec_parse_annual_instant'));
    $stBorrowings = $last($pick(['ShortTermBorrowings'], 'sec_parse_annual_instant'));
    $cash = $last($pick(['CashAndCashEquivalentsAtCarryingValue', 'CashCashEquivalentsRestrictedCashAndRestrictedCashEquivalents'], 'sec_parse_annual_instant'));
    $da = $last($pick(['DepreciationDepletionAndAmortization', 'DepreciationAmortizationAndAccretionNet', 'DepreciationAndAmortization'], 'sec_parse_annual_duration'));
    $taxExpense = $last($pick(['IncomeTaxExpenseBenefit'], 'sec_parse_annual_duration'));
    $pretaxIncome = $last($pick(['IncomeLossFromContinuingOperationsBeforeIncomeTaxesExtraordinaryItemsNoncontrollingInterest', 'IncomeLossFromContinuingOperationsBeforeIncomeTaxesMinorityInterestAndIncomeLossFromEquityMethodInvestments'], 'sec_parse_annual_duration'));

    if (!$revenueSeries && $operatingIncome === null) return null;

    $totalDebt = ($ltDebtNoncurrent ?? 0) + ($ltDebtCurrent ?? 0) + ($stBorrowings ?? 0);
    $taxRatePct = ($taxExpense !== null && $pretaxIncome > 0) ? max(0, min(50, $taxExpense / $pretaxIncome * 100)) : null;

    return [
        'revenueSeries' => $revenueSeries,
        'operatingIncome' => $operatingIncome,
        'stockholdersEquity' => $equity,
        'totalDebt' => $totalDebt > 0 ? $totalDebt : null,
        'cash' => $cash,
        'ebitda' => ($operatingIncome !== null) ? $operatingIncome + ($da ?? 0) : null,
        'taxRatePct' => $taxRatePct,
    ];
}

// Batch (Watchlist): nur der haeufigste Tag, um die Anzahl Requests fuer viele Symbole klein
// zu halten. Einzelne Nischentitel liefern dadurch evtl. "n/a" statt Daten - fuer die
// Watchlist-Uebersicht ein akzeptabler Kompromiss, Details gibt's einzeln auf der Bewertungsseite.
function sec_fetch_eps_batch($symbols, $cikMap) {
    $out = [];
    foreach ($symbols as $sym) {
        $cik = $cikMap[strtoupper($sym)] ?? null;
        if (!$cik) continue;
        $json = sec_curl_get("https://data.sec.gov/api/xbrl/companyconcept/CIK{$cik}/us-gaap/EarningsPerShareDiluted.json");
        $units = $json['units']['USD/shares'] ?? null;
        if (!$units) continue;
        $series = sec_parse_annual_eps($units);
        if (count($series) >= 2) $out[$sym] = $series;
        sec_pause(); // ~9 Anfragen/Sekunde, unter SECs Limit von 10/s bleiben
    }
    return $out;
}
