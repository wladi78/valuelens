<?php
/* Big Board nach Dave Van Knapps Verfahren ("Dividends & Income Select", gelesen 18.11.2026: Staples, Utilities, Insurance,
   Banks, Health Care, Real Estate, Industrials) - nur freie Daten. Nutzer 18.11.2026: "alles lesen und uebernehmen".
   Server rechnet nachts (bewertung-signale.php run, ValueLens nachtlauf.php, Kamikaze cron-nutzer.php), die Seite zeigt das Ergebnis.

   Q  Qualitaet 5 x 0-5 = 25: Profitabilitaet (ROE, Kapitalrendite, Nettomarge gegen Sektor-Massstab - seine DVK-Note nutzt dazu
      op. Marge und FCF-Marge, die im freien Datensatz fehlen), Finanzkraft (Schulden/Kapital, Liquiditaet), Dividendensicherheit
      (Ausschuettung auf Gewinn und Cashflow), Erhoehungs-Historie (King 5 ... Challenger 2), Wachstum.
      Ab 15 investierbar; 0 Punkte auf Profitabilitaet, Finanzkraft oder Sicherheit scheidet aus (er: "red score on a single metric").
   D  Dividenden-Kraft: Rendite x 5-J.-Wachstum; orange auch, wenn die letzte Erhoehung < 50 % des 5-J.-Schnitts ist.
   V  Bewertung, Kurs/fairer Preis (<0,90 gruen, bis 1,10 gelb, darueber orange) als Mittel aus (1) 5-J.-Schnittrendite / Rendite
      (wie Simply Safe Dividends), (2) Kurs / Wert beim Normal-KGV (bewertung_zeile), (3) Kurs / DCF von FMP, wenn der Schluessel
      das im Tarif erlaubt (statt Morningstar-DCF).
   R  Empfehlungen: Yahoo-Analystenschnitt, auf seine 5er-Skala gedreht (5 = starker Kauf); < 2,75 orange, ab 3,75 gruen.
   Best Bet = Q >= 15, nicht ausgeschieden, nirgends orange (R zaehlt, wenn vorhanden). Regelausgabe, keine Anlageberatung. */
require_once __DIR__ . '/dividend-database-lib.php';
require_once __DIR__ . '/bewertung-lib.php';
require_once __DIR__ . '/fitness-lib.php';

const BB_MASS = [   // Sektor => [ROE, Kapitalrendite (ROTC), Nettomarge] in %
    'Utilities' => [9, 5, 10], 'Real Estate' => [6, 4, 20], 'Financial Services' => [11, 0, 18], 'Energy' => [12, 8, 8],
    'Consumer Defensive' => [15, 10, 7], 'Industrials' => [15, 10, 8], 'Healthcare' => [15, 10, 10], 'Technology' => [18, 12, 15],
    'Basic Materials' => [12, 8, 8], 'Consumer Cyclical' => [15, 10, 7], 'Communication Services' => [12, 7, 10]];

function bb_datei() { return __DIR__ . '/data/cache/bigboard.json'; }
function bb_note($r) { return $r === null ? null : ($r >= 1.5 ? 5 : ($r >= 1.1 ? 4 : ($r >= 0.8 ? 3 : ($r >= 0.5 ? 2 : 0)))); }

function bb_qualitaet(array $s, ?array $marge = null): array {
    $sek = $s['sector'] ?? ''; $m = BB_MASS[$sek] ?? [15, 10, 10];
    $fin = $sek === 'Financial Services'; $reit = $sek === 'Real Estate'; $ver = $sek === 'Utilities';
    $roe = $s['roe'] ?? null; $roeOk = $roe !== null && $roe > 0 && $roe < 100;   // negatives/riesiges EK verzerrt (CLX 534 %)
    // op. Marge und FCF-Marge (SEC, fitness_kennzahlen) wie in seiner DVK-Note; Massstab aus der Nettomarge des Sektors
    // (op. Marge ~ 1,5 x, FCF-Marge ~ 1 x). Banken/Versicherer ohne - Umsatz und Cashflow sagen dort wenig.
    $opM = $fin ? null : ($marge['opM'] ?? null); $fcfM = $fin ? null : ($marge['fcfM'] ?? null);
    $noten = array_values(array_filter([$roeOk ? bb_note($roe / $m[0]) : null, (!$fin && ($s['rotc'] ?? null) !== null && $m[1]) ? bb_note($s['rotc'] / $m[1]) : null,
        ($s['npm'] ?? null) !== null ? bb_note($s['npm'] / $m[2]) : null,
        $opM !== null ? ($opM <= 0 ? 0 : bb_note($opM / ($m[2] * 1.5))) : null,
        ($fcfM !== null && !$ver) ? ($fcfM <= 0 ? 0 : bb_note($fcfM / $m[2])) : null], fn($v) => $v !== null));   // Versorger: Investitionen machen FCF dauerhaft klein
    $prof = $noten ? (int) round(array_sum($noten) / count($noten)) : null;
    $finK = null;
    if ($fin) $finK = 3;
    elseif (($s['debtCapital'] ?? null) !== null) {
        $dc = $s['debtCapital'] - (($ver || $reit) ? 0.15 : 0);
        $finK = $dc <= 0.3 ? 5 : ($dc <= 0.45 ? 4 : ($dc <= 0.6 ? 3 : ($dc <= 0.75 ? 2 : 1)));
        if (!$ver && !$reit && ($s['currentRatio'] ?? null) !== null && $s['currentRatio'] < 0.8) $finK = max(1, $finK - 1);
    }
    $epsQ = ($s['divYield'] ?? 0) > 0 && ($s['pe'] ?? 0) > 0 ? $s['divYield'] * $s['pe'] / 100 : null;
    $cfQ = ($s['annualDividend'] ?? 0) > 0 && ($s['cfPerShare'] ?? 0) > 0 ? $s['annualDividend'] / $s['cfPerShare'] : null;
    $q = ($reit || $fin) ? ($cfQ ?? $epsQ) : (($cfQ !== null && $epsQ !== null) ? max($cfQ, $epsQ * 0.6) : ($cfQ ?? $epsQ));
    $sich = $q === null ? null : ($q <= 0.4 ? 5 : ($q <= 0.55 ? 4 : ($q <= 0.7 ? 3 : ($q <= 0.85 ? 2 : ($q <= 1.0 ? 1 : 0)))));
    if ($sich !== null && !$reit && !$ver && $epsQ !== null && $epsQ > 1.2) $sich = max(0, $sich - 1);
    $hist = ['King' => 5, 'Champion' => 4, 'Contender' => 3, 'Challenger' => 2][$s['category'] ?? ''] ?? null;
    $w = 0; $g5 = $s['dgr5y'] ?? null;
    if ($g5 >= 8) $w += 2; elseif ($g5 >= 4) $w += 1;
    if (($s['dgr10y'] ?? null) >= 6) $w++;
    if (($s['dgr1y'] ?? null) !== null && $g5 > 0 && $s['dgr1y'] >= 0.5 * $g5) $w++;
    if (($s['revenue1y'] ?? 0) > 0) $w++;
    $w = min(5, $w);
    $teile = [$prof, $finK, $sich, $hist, $w];
    return ['prof' => $prof, 'finK' => $finK, 'sich' => $sich, 'hist' => $hist, 'wachs' => $w, 'summe' => array_sum(array_map('intval', $teile)),
        'aus' => $prof === 0 || $finK === 0 || $sich === 0, 'epsQ' => $epsQ, 'cfQ' => $cfQ];
}
function bb_kraft(array $s): array {
    $y = $s['divYield'] ?? null;
    // Wachstum = Median aus 3/5/10 J.: Sonderdividenden verzerren einzelne Zeitraeume (ORI 5 J. 2,1 %, AFG -24 % bei stetig steigender
    // Regeldividende; Abgleich mit Van Knapp, Insurance 7.10.2026)
    $gs = array_values(array_filter([$s['dgr3y'] ?? null, $s['dgr5y'] ?? null, $s['dgr10y'] ?? null], fn($v) => $v !== null)); sort($gs);
    $g = $gs ? (count($gs) % 2 ? $gs[intdiv(count($gs), 2)] : ($gs[count($gs) / 2 - 1] + $gs[count($gs) / 2]) / 2) : null;
    if (bb_sonder($s) && ($s['dgr10y'] ?? null) !== null) $g = $s['dgr10y'];
    if ($y === null || $g === null) return ['farbe' => null, 'bremse' => false, 'g' => null];
    // eigene Umsetzung seiner Matrix (niedrige Rendite braucht schnelles Wachstum); 2-3 %: ab 4 % gelb (PG-Abgleich 16.11.2026)
    $st = $y >= 6 ? [0, -1] : ($y >= 4 ? [2, 0] : ($y >= 3 ? [5, 3] : ($y >= 2 ? [8, 4] : ($y >= 1 ? [12, 8] : [15, 12]))));
    $farbe = $g >= $st[0] ? 'gruen' : ($g >= $st[1] ? 'gelb' : 'orange');
    // Bremse (letzte Erhoehung < 50 % des Schnitts) nur unter 4,5 % Rendite - Hochzinser mit langsamem Wachstum laesst er gelten (AVA,
    // Utilities 23.10.2026). Negative 1-J.-Werte im Datensatz sind oft Zahlungs-Zeitpunkte (XOM -22,75 % trotz Erhoehung) - kein Sonderfall.
    $g1 = $s['dgr1y'] ?? null;
    $bremse = $g1 !== null && $g > 0 && $g1 < 0.5 * $g && $y < 4.5;
    return ['farbe' => $bremse ? 'orange' : $farbe, 'bremse' => $bremse, 'g' => round($g, 2), 'sonder' => bb_sonder($s)];
}
// Sonderdividenden erkennen: 5-J.-Wachstum weit unter 1 J. und 10 J. (ORI, AFG) - dann 10-J.-Wachstum und keine Renditebewertung
function bb_sonder(array $s): bool {
    $g1 = $s['dgr1y'] ?? null; $g5 = $s['dgr5y'] ?? null; $g10 = $s['dgr10y'] ?? null;
    if ($g5 === null || $g10 === null) return false;
    return ($g5 < 0 && $g10 > 0) || ($g1 !== null && $g1 > 10 && $g10 > 8 && $g5 < $g10 / 3);
}
function bb_farbe($p) { return $p === null ? null : ($p < 0.9 ? 'gruen' : ($p <= 1.1 ? 'gelb' : 'orange')); }
function bb_pfp(array $z) { $w = array_values(array_filter([$z['r1'] ?? null, $z['r2'] ?? null, $z['r3'] ?? null], fn($v) => $v !== null && $v > 0)); return $w ? array_sum($w) / count($w) : null; }
function bb_rfarbe($r) { return $r === null ? null : ($r < 2.75 ? 'orange' : ($r >= 3.75 ? 'gruen' : 'gelb')); }
function bb_best(array $z): bool {
    $p = bb_pfp($z);
    return $z['q']['summe'] >= 15 && !$z['q']['aus'] && $z['k']['farbe'] && $z['k']['farbe'] !== 'orange'
        && $p !== null && bb_farbe($p) !== 'orange' && ($z['r2'] !== null || ($z['r3'] ?? null) !== null) && bb_rfarbe($z['rec'] ?? null) !== 'orange';
}

// DCF von FMP (nur mit Schluessel in data/price_api_keys.json und wenn der Tarif es liefert); 7 Tage je Titel zwischengespeichert
function bb_fmp_dcf(string $sym, &$gesperrt) {
    static $key = null; if ($key === null) { $k = json_decode((string) @file_get_contents(__DIR__ . '/data/price_api_keys.json'), true); $key = (string) ($k['fmp'] ?? ''); }
    if ($key === '' || $gesperrt) return null;
    $dir = __DIR__ . '/data/cache/fmp-dcf'; $f = $dir . '/' . preg_replace('/[^A-Z0-9.\-]/', '_', $sym) . '.json';
    if (is_file($f) && time() - filemtime($f) < 7 * 86400) { $c = json_decode((string) file_get_contents($f), true); return $c['dcf'] ?? null; }
    $ch = curl_init('https://financialmodelingprep.com/stable/discounted-cash-flow?symbol=' . rawurlencode($sym) . '&apikey=' . rawurlencode($key));
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 12, CURLOPT_CONNECTTIMEOUT => 6]);
    $body = (string) curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    $j = json_decode($body, true);
    if ($code === 402 || $code === 403 || $code === 401 || (is_array($j) && isset($j['Error Message']))) { $gesperrt = 'FMP ' . $code . ': ' . substr((string) ($j['Error Message'] ?? $body), 0, 120); return null; }
    if ($code === 429) { $gesperrt = 'FMP Tageslimit'; return null; }
    $dcf = (is_array($j) && isset($j[0]['dcf']) && is_numeric($j[0]['dcf'])) ? (float) $j[0]['dcf'] : null;
    if (!is_dir($dir)) @mkdir($dir, 0700, true);
    @file_put_contents($f, json_encode(['dcf' => $dcf, 'stand' => date('c')]));
    return $dcf;
}

// Bestand der State-Street-Sektor-ETFs (XLP, XLU ...) - damit startet Van Knapp jeden Sektor-Artikel ("XLP has 34 companies").
// Frei als Excel bei ssga.com, woechentlich geholt. Ergebnis: Symbol => ETF. Ohne ZipArchive oder bei Fehler: alter Stand bzw. leer.
const BB_ETFS = ['XLP', 'XLU', 'XLV', 'XLRE', 'XLF', 'XLI', 'XLE', 'XLK', 'XLB', 'XLY', 'XLC'];
function bb_sektor_etfs(): array {
    $f = __DIR__ . '/data/cache/sektor-etfs.json';
    $alt = json_decode((string) @file_get_contents($f), true) ?: [];
    if (!empty($alt['mitglieder']) && strtotime($alt['stand'] ?? '2000-01-01') > time() - 7 * 86400) return $alt['mitglieder'];
    if (!class_exists('ZipArchive') || !function_exists('curl_init')) return $alt['mitglieder'] ?? [];
    $m = []; $anz = [];
    foreach (BB_ETFS as $etf) {
        $ch = curl_init('https://www.ssga.com/us/en/intermediary/library-content/products/fund-data/etfs/us/holdings-daily-us-en-' . strtolower($etf) . '.xlsx');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 20, CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; Capolio/1.0)']);
        $body = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        if ($code !== 200 || !$body || substr($body, 0, 2) !== 'PK') continue;
        $tmp = tempnam(sys_get_temp_dir(), 'etf'); file_put_contents($tmp, $body);
        $z = new ZipArchive();
        if ($z->open($tmp) === true) {
            $ss = []; if (preg_match_all('#<si>(.*?)</si>#s', (string) $z->getFromName('xl/sharedStrings.xml'), $mm)) foreach ($mm[1] as $si) { preg_match_all('#<t[^>]*>([^<]*)</t>#', $si, $tt); $ss[] = html_entity_decode(implode('', $tt[1])); }
            $sh = (string) $z->getFromName('xl/worksheets/sheet1.xml'); $nach = false; $n = 0;
            if (preg_match_all('#<c r="([A-Z]+)(\d+)"([^>]*)>(?:<f>[^<]*</f>)?<v>([^<]*)</v>#', $sh, $cc, PREG_SET_ORDER)) foreach ($cc as $c) {
                if ($c[1] !== 'B') continue;
                $wert = strpos($c[3], 't="s"') !== false ? ($ss[(int) $c[4]] ?? '') : $c[4];
                if ($wert === 'Ticker') { $nach = true; continue; }
                if ($nach && preg_match('/^[A-Z][A-Z0-9.]{0,6}$/', trim($wert))) { $m[str_replace('.', '-', trim($wert))] = $etf; $n++; }
            }
            $z->close(); $anz[$etf] = $n;
        }
        @unlink($tmp);
    }
    if (count($m) < 300) return $alt['mitglieder'] ?? $m;   // unvollstaendig - alten Stand behalten
    if (!is_dir(dirname($f))) @mkdir(dirname($f), 0700, true);
    @file_put_contents($f, json_encode(['stand' => date('c'), 'anzahl' => $anz, 'mitglieder' => $m]));
    return $m;
}

// Auslaendische Dividendenwerte als ADR (Nutzer 18.11.2026: "ja" - die Dividend-Champions-Liste fuehrt nur US-Werte; Van Knapp
// nimmt z. B. Sanofi). Erhoehungen und Wachstum aus der Heimatboerse in Landeswaehrung (wie Simply Safe Dividends, sonst verfaelscht der
// Wechselkurs die Serie), Rendite/Margen/Schulden aus Yahoo. Aufnahme wie bei ihm erst ab 5 Jahren Erhoehung. 7 Tage zwischengespeichert.
const BB_ADR = ['SNY' => 'SAN.PA', 'NVS' => 'NOVN.SW', 'AZN' => 'AZN.L', 'GSK' => 'GSK.L', 'NVO' => 'NOVO-B.CO', 'UL' => 'ULVR.L', 'DEO' => 'DGE.L',
    'BTI' => 'BATS.L', 'TTE' => 'TTE.PA', 'SHEL' => 'SHEL.L', 'BP' => 'BP.L', 'BHP' => 'BHP.AX', 'RIO' => 'RIO.L', 'TD' => 'TD.TO', 'RY' => 'RY.TO',
    'BNS' => 'BNS.TO', 'BMO' => 'BMO.TO', 'CM' => 'CM.TO', 'SAP' => 'SAP.DE', 'NGG' => 'NG.L', 'BCE' => 'BCE.TO', 'TU' => 'T.TO', 'MFC' => 'MFC.TO',
    'SLF' => 'SLF.TO', 'CNI' => 'CNR.TO', 'CP' => 'CP.TO', 'ASML' => 'ASML.AS', 'TSM' => '2330.TW', 'NSRGY' => 'NESN.SW', 'RHHBY' => 'RO.SW'];   // Roche: Genussschein ROG.SW hat bei Yahoo keine Dividenden, Inhaberaktie RO.SW dieselbe
// Sonderdividenden, die die Erhoehungsreihe sonst brechen (Heimatboerse, Zahljahr => Betrag). Nutzer 18.11.2026: SAP von Hand freigeben.
const BB_ADR_SONDER = ['SAP.DE' => [2022 => 0.50, 2012 => 0.35]];   // 50 bzw. 40 Jahre SAP
function bb_adr_stocks(int $budget, array $schonDa): array {
    $f = __DIR__ . '/data/cache/bb-adr.json';
    $alt = json_decode((string) @file_get_contents($f), true) ?: [];
    if (!empty($alt['stocks']) && ($alt['v'] ?? 1) >= 2 && strtotime($alt['stand'] ?? '2000-01-01') > time() - 7 * 86400) return $alt['stocks'];
    if ($budget <= 0) return $alt['stocks'] ?? [];
    $start = time(); $out = []; $fertig = true;
    foreach (BB_ADR as $adr => $heim) {
        if (isset($schonDa[$adr])) continue;
        if (time() - $start > $budget) { $fertig = false; foreach ($alt['stocks'] ?? [] as $s) if ($s['symbol'] === $adr) $out[] = $s; continue; }
        try {
            $q = bewertung_ohlc($heim, 16); $i = firmen_info($adr);
            if (!$q || !empty($i['fehler'])) continue;
            $jahr = []; foreach ($q['dividenden'] as [$dt, $b]) { $y = (int) substr($dt, 0, 4); if ($y < (int) date('Y')) $jahr[$y] = ($jahr[$y] ?? 0) + $b; }
            foreach (BB_ADR_SONDER[$heim] ?? [] as $y => $b) if (isset($jahr[$y])) $jahr[$y] -= $b;
            if (count($jahr) < 3) continue;
            ksort($jahr); $ys = array_keys($jahr); $last = end($ys);
            $folge = 0; $y = $last; while (isset($jahr[$y - 1]) && $jahr[$y] > $jahr[$y - 1] * 1.0001) { $folge++; $y--; }
            if ($folge < 5) continue;
            $dgr = fn($n) => (isset($jahr[$last - $n]) && $jahr[$last - $n] > 0) ? (pow($jahr[$last] / $jahr[$last - $n], 1 / $n) - 1) * 100 : null;
            $de = $i['schuldenEK'] ?? null;
            $out[] = ['symbol' => $adr, 'name' => $i['name'] ?? $adr, 'sector' => $i['sektor'] ?? '', 'industry' => $i['branche'] ?? '',
                'category' => $folge >= 50 ? 'King' : ($folge >= 25 ? 'Champion' : ($folge >= 10 ? 'Contender' : 'Challenger')),
                'price' => $i['kurs'] ?? $i['vortag'] ?? null, 'divYield' => isset($i['divRendite']) ? $i['divRendite'] * 100 : null,
                'avgYield5y' => $i['fuenfJRendite'] ?? null, 'annualDividend' => $i['divRate'] ?? null, 'pe' => $i['kgv'] ?? null,
                'dgr1y' => $dgr(1), 'dgr3y' => $dgr(3), 'dgr5y' => $dgr(5), 'dgr10y' => $dgr(10),
                'roe' => isset($i['roe']) ? $i['roe'] * 100 : null, 'npm' => isset($i['nettomarge']) ? $i['nettomarge'] * 100 : null, 'rotc' => null,
                'debtCapital' => is_numeric($de) && $de >= 0 ? $de / (100 + $de) : null, 'currentRatio' => $i['liquiditaet'] ?? null, 'cfPerShare' => null,
                'revenue1y' => isset($i['umsatzWachstum']) ? $i['umsatzWachstum'] * 100 : null, 'adr' => $heim, 'folge' => $folge];
        } catch (Throwable $e) {}
    }
    if ($fertig) { if (!is_dir(dirname($f))) @mkdir(dirname($f), 0700, true); @file_put_contents($f, json_encode(['stand' => date('c'), 'v' => 2, 'stocks' => $out], JSON_UNESCAPED_UNICODE)); }
    return $out;
}

// Rechnet alle Titel der Dividenden-Liste. Bewertung (2)/(3) und Empfehlung nur fuer Q >= 15 und nicht ausgeschieden; Zeitbudget
// in Sekunden - was nicht drankommt, behaelt die Werte des letzten Laufs.
function bb_rechnen(int $budget = 1500): array {
    $start = time();
    // nur ein Lauf zugleich (Nachtlauf und Seiten-Schritte); wer warten muesste, bekommt den letzten Stand
    $lk = @fopen(__DIR__ . '/data/cache/bigboard.lock', 'c'); if (!$lk && !is_dir(__DIR__ . '/data/cache')) { @mkdir(__DIR__ . '/data/cache', 0700, true); $lk = @fopen(__DIR__ . '/data/cache/bigboard.lock', 'c'); }
    if ($lk && !flock($lk, LOCK_EX | LOCK_NB)) { $d = json_decode((string) @file_get_contents(bb_datei()), true); if (is_array($d)) { $d['beschaeftigt'] = true; return $d; } flock($lk, LOCK_EX); }
    $alt = json_decode((string) @file_get_contents(bb_datei()), true) ?: [];
    $altZ = []; foreach ($alt['zeilen'] ?? [] as $z) $altZ[$z['sym']] = $z;
    $ddb = ddb_get(); $zeilen = []; $fmpSperre = $alt['fmpSperre'] ?? null;
    if ($fmpSperre && strtotime($alt['stand'] ?? '2000-01-01') < time() - 7 * 86400) $fmpSperre = null;   // woechentlich neu versuchen
    $gesperrt = $fmpSperre; $neuBewertet = 0;
    $etfs = bb_sektor_etfs();
    $quellen = $ddb['stocks'] ?? []; $da = []; foreach ($quellen as $s) $da[str_replace('.', '-', strtoupper((string) ($s['symbol'] ?? '')))] = true;
    foreach (bb_adr_stocks(max(0, min(60, $budget)), $da) as $s) $quellen[] = $s;
    $offen = 0;
    foreach ($quellen as $s) {
        $sym = str_replace('.', '-', strtoupper((string) ($s['symbol'] ?? ''))); if ($sym === '') continue;
        $marge = isset($altZ[$sym]['marge']) ? $altZ[$sym]['marge'] : null;
        $q = bb_qualitaet($s, $marge); $k = bb_kraft($s);
        $r1 = !bb_sonder($s) && ($s['divYield'] ?? 0) > 0 && ($s['avgYield5y'] ?? 0) > 0 ? $s['avgYield5y'] / $s['divYield'] : null;
        $z = ['sym' => $sym, 'name' => $s['name'] ?? $sym, 'sector' => $s['sector'] ?? '', 'industry' => $s['industry'] ?? '', 'category' => $s['category'] ?? '',
            'price' => $s['price'] ?? null, 'divYield' => $s['divYield'] ?? null, 'avgYield5y' => $s['avgYield5y'] ?? null, 'dgr1y' => $s['dgr1y'] ?? null,
            'dgr5y' => $s['dgr5y'] ?? null, 'dgr10y' => $s['dgr10y'] ?? null, 'etf' => $etfs[$sym] ?? null, 'q' => $q, 'k' => $k, 'r1' => $r1,
            'r2' => $altZ[$sym]['r2'] ?? null, 'r3' => $altZ[$sym]['r3'] ?? null, 'basis' => $altZ[$sym]['basis'] ?? null, 'rec' => $altZ[$sym]['rec'] ?? null,
            'bewertet' => $altZ[$sym]['bewertet'] ?? null, 'adr' => $s['adr'] ?? null, 'marge' => $marge];
        $kandidat = $q['summe'] >= ($marge === null && empty($s['adr']) ? 13 : 15) && !$q['aus'];   // ohne Margen knapp darunter mitnehmen - sie koennen heben
        $frisch = $z['bewertet'] && strtotime($z['bewertet']) > time() - 20 * 3600;
        if ($kandidat && !$frisch && time() - $start < $budget) {
            try {
                $b = bewertung_zeile($sym, (string) ($s['sector'] ?? ''));
                $z['r2'] = (isset($b['abwN']) && is_finite($b['abwN'])) ? 1 + $b['abwN'] / 100 : null;
                if ($z['r2'] !== null && ($z['r2'] < 0.2 || $z['r2'] > 5)) $z['r2'] = null;   // kaputte Gewinnreihe (19.11.2026: BMI Normal-KGV 0,04 -> r2 662) nicht mitteln $z['basis'] = $b['basis'] ?? ($b['fehler'] ?? null);
                $kurs = $b['kurs'] ?? ($s['price'] ?? null);
                $dcf = bb_fmp_dcf($sym, $gesperrt); $z['r3'] = ($dcf > 0 && $kurs > 0 && $kurs / $dcf > 0.2 && $kurs / $dcf < 5) ? $kurs / $dcf : null;
                $i = firmen_info($sym); $mw = $i['urteilWert'] ?? null; $z['rec'] = is_numeric($mw) ? round(6 - (float) $mw, 2) : null;
                if (empty($s['adr'])) { $fk = fitness_kennzahlen($sym);   // SEC, gilt bis zur naechsten 10-K
                    if (isset($fk['opMarge']) || isset($fk['fcfMarge5'])) { $z['marge'] = ['opM' => $fk['opMarge'] ?? null, 'fcfM' => $fk['fcfMarge5'] ?? null]; $z['q'] = bb_qualitaet($s, $z['marge']); } }
                $z['bewertet'] = date('c'); $neuBewertet++;
            } catch (Throwable $e) {}
        } elseif ($kandidat && !$frisch) $offen++;
        $z['pfp'] = bb_pfp($z); $z['best'] = bb_best($z);
        $zeilen[] = $z;
    }
    $out = ['stand' => date('c'), 'ddbStand' => $ddb['updatedAt'] ?? null, 'anzahl' => count($zeilen), 'neuBewertet' => $neuBewertet,
        'fmpSperre' => $gesperrt ?: null, 'etfMitglieder' => count($etfs), 'offen' => $offen, 'zeilen' => $zeilen];
    if (!is_dir(dirname(bb_datei()))) @mkdir(dirname(bb_datei()), 0700, true);
    $tmp = bb_datei() . '.tmp'; if (@file_put_contents($tmp, json_encode($out, JSON_UNESCAPED_UNICODE)) !== false) @rename($tmp, bb_datei());
    if ($offen === 0) bb_verlauf_sichern($out);
    if ($lk) { flock($lk, LOCK_UN); fclose($lk); }
    return $out;
}

/* Verlauf (Nutzer 18./19.11.2026, "mach 10"): einmal je Monat die Best Bets mit Kurs festhalten, dazu SPY als S&P-500-Massstab.
   Nach 12 Monaten zeigt bb_verlauf_auswertung(), ob die Auswahl den Index geschlagen hat (Kursentwicklung, ohne Dividenden). */
function bb_verlauf_ordner() { return __DIR__ . '/data/cache/bigboard_verlauf'; }
function bb_yahoo_kurs(string $sym) {
    $ch = curl_init('https://query1.finance.yahoo.com/v8/finance/chart/' . rawurlencode($sym) . '?range=5d&interval=1d');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_USERAGENT => 'Mozilla/5.0 (Capolio)']);
    $j = json_decode((string) curl_exec($ch), true); curl_close($ch);
    $k = $j['chart']['result'][0]['meta']['regularMarketPrice'] ?? null;
    return is_numeric($k) && $k > 0 ? (float) $k : null;
}
function bb_verlauf_sichern(array $d): void {
    $dir = bb_verlauf_ordner(); $f = $dir . '/' . date('Y-m') . '.json';
    if (is_file($f)) return;
    $best = [];
    foreach ($d['zeilen'] ?? [] as $z) if (!empty($z['best']) && ($z['price'] ?? 0) > 0)
        $best[] = ['sym' => $z['sym'], 'sector' => $z['sector'], 'price' => $z['price'], 'divYield' => $z['divYield'], 'q' => $z['q']['summe'], 'pfp' => $z['pfp']];
    if (!$best) return;
    $spy = bb_yahoo_kurs('SPY'); if ($spy === null) return;   // ohne Massstab nicht speichern, naechste Nacht neu
    if (!is_dir($dir)) @mkdir($dir, 0700, true);
    @file_put_contents($f, json_encode(['stand' => date('c'), 'spy' => $spy, 'anzahl' => count($best), 'best' => $best], JSON_UNESCAPED_UNICODE), LOCK_EX);
}
// Auswertung eines gespeicherten Monats: gleich gewichtete Kursentwicklung der damaligen Best Bets gegen SPY (Kurse aus dem aktuellen Big Board)
function bb_verlauf_auswertung(string $monat, ?array $jetzt = null): ?array {
    $v = json_decode((string) @file_get_contents(bb_verlauf_ordner() . '/' . $monat . '.json'), true); if (!is_array($v)) return null;
    $jetzt = $jetzt ?? bb_laden(); $kurs = []; foreach ($jetzt['zeilen'] ?? [] as $z) $kurs[$z['sym']] = $z['price'] ?? null;
    $r = []; foreach ($v['best'] as $b) if (($kurs[$b['sym']] ?? 0) > 0) $r[] = $kurs[$b['sym']] / $b['price'] - 1;
    $spy = bb_yahoo_kurs('SPY');
    return ['monat' => $monat, 'anzahl' => count($v['best']), 'mitKurs' => count($r), 'bestBets' => $r ? array_sum($r) / count($r) * 100 : null,
        'spy' => ($spy && $v['spy']) ? ($spy / $v['spy'] - 1) * 100 : null];
}
function bb_laden(): array {
    $d = json_decode((string) @file_get_contents(bb_datei()), true);
    // erster Aufruf bzw. Datei aus aelterer Fassung (ohne ETF-Bestand): Qualitaet/Kraft sofort neu, Bewertungen bleiben, Rest kommt nachts
    return (is_array($d) && isset($d['etfMitglieder'])) ? $d : bb_rechnen(0);
}

// Monatsbericht fuer Telegram (1. des Monats): Best Bets je Sektor, kurz
function bb_bericht_text(array $d, int $jeSektor = 4): string {
    $nach = []; foreach ($d['zeilen'] ?? [] as $z) if (!empty($z['best'])) $nach[$z['sector']][] = $z;
    if (!$nach) return '';
    ksort($nach);
    $t = "🏛️ <b>Big Board – Best Bets " . date('m/Y') . "</b>\n(Dividendenwachstum nach Van Knapps Verfahren, freie Daten; Qualität ≥ 15, nirgends orange)\n";
    foreach ($nach as $sek => $l) {
        usort($l, fn($a, $b) => ($a['pfp'] ?? 9) <=> ($b['pfp'] ?? 9));
        $t .= "\n<b>" . htmlspecialchars($sek) . '</b> (' . count($l) . "): " . implode(', ', array_map(fn($z) => $z['sym'] . ' ' . number_format((float) $z['divYield'], 1, ',', '') . ' % · Q' . $z['q']['summe'] . ' · ' . number_format((float) $z['pfp'], 2, ',', ''), array_slice($l, 0, $jeSektor)));
    }
    $vj = bb_verlauf_auswertung(date('Y-m', strtotime('first day of -12 months')), $d);   // Abrechnung nach 12 Monaten
    if ($vj && $vj['bestBets'] !== null && $vj['spy'] !== null)
        $t .= "\n\n📊 Best Bets von " . $vj['monat'] . ' (' . $vj['mitKurs'] . ' Titel): ' . number_format($vj['bestBets'], 1, ',', '') . ' % gegen S&P 500 (SPY) ' . number_format($vj['spy'], 1, ',', '') . ' % (Kurs, ohne Dividenden).';
    return $t . "\n\nZahl hinter Q = Kurs ÷ fairer Preis. Regelausgabe, keine Anlageberatung.";
}
