<?php
/* Bewertungs-Bot (Nutzerwunsch 06.11.2026: "Bewertungsliste nachts automatisch berechnen und unterbewertete Titel per Telegram
   melden"). Rechnet fuer Depot und Watchlist des lokalen Depots dieselbe Bewertungsliste wie Analyse > Aktie bewerten und meldet
   Titel, deren Kurs mindestens 15 % unter dem Fair Value liegt UND deren erwartete Rendite (5 J., inkl. Dividende) >= 10 % ist.
   Gemeldet wird nur, was NEU auf die Liste kommt (Zustand in data/bewertung_signale_state.json), dazu die Zahl aller Treffer.
   Kanal "analysen" (KI-Analysen-Bot), ersatzweise der Waechter-Bot. Regelausgabe, keine Anlageberatung.

   Aufrufe (nur Kommandozeile):
     php bewertung-signale.php vorschau  -> rechnet und gibt die Nachricht aus, sendet nichts, Zustand bleibt
     php bewertung-signale.php run       -> Cronjob taeglich nachts (Typ "Befehl ausfuehren"); sendet nur bei neuen Treffern */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);
set_time_limit(0);
require_once __DIR__ . '/bewertung-lib.php';
require_once __DIR__ . '/telegram-lib.php';
require_once __DIR__ . '/fitness-lib.php';

const BS_DEPOT = 'depot_lokal.json';   // ValueLens: lokales Depot (Meine Kaeufe + Watchlist)
const BS_ABW = -15.0;      // Kurs mindestens 15 % unter Fair Value
const BS_ROR = 10.0;       // erwartete Rendite p. a. mindestens 10 %
const BS_FVR = 15.0;

$modus = $argv[1] ?? 'vorschau';
$dataDir = __DIR__ . '/data';
$d = json_decode((string) @file_get_contents($dataDir . '/' . BS_DEPOT), true);
if (!$d) { fwrite(STDERR, "Depot nicht lesbar\n"); exit(1); }

$titel = [];   // Symbol => [Sektor, Herkunft]
foreach ($d['positions'] ?? [] as $p) {
    if (empty($p['ticker']) || ($p['shares'] ?? 0) <= 0 || preg_match('/etf/i', $p['sector'] ?? '')) continue;
    $titel[$p['ticker']] = [$p['sector'] ?? '', 'Depot', $p['name'] ?? ''];
}
foreach ($d['watchlist'] ?? [] as $w) {
    $s = $w['ticker'] ?? ($w['symbol'] ?? null);
    if ($s && !isset($titel[$s])) $titel[$s] = [$w['sector'] ?? '', 'Watchlist', $w['name'] ?? ''];
}

// Dividenden-Liste dripinvesting (alle Klassen, woechentlich neu) direkt - war bis 18.11.2026 nur ueber den Watchlist-Import drin
require_once __DIR__ . '/dividend-database-lib.php';
foreach (ddb_get()['stocks'] ?? [] as $r) {
    $s = str_replace('.', '-', strtoupper((string) ($r['symbol'] ?? '')));
    if ($s !== '' && !isset($titel[$s]) && !isset($titel[strtoupper((string) $r['symbol'])])) $titel[$s] = [$r['sector'] ?? '', 'Dividenden-Liste (' . ($r['category'] ?? '') . ')', $r['name'] ?? ''];
}

$treffer = []; $fehler = 0; $n = 0; $bankFv = [];
// Finanzielle Fitness fuer die Bewertungsliste vorbereiten (Filter ROE, Schulden/Kapital, FCF deckt Dividende; Nutzer 13.11.2026):
// SEC-Jahresberichte je US-Titel, Speicher 7 Tage - nachts hoechstens 120 auffrischen, damit der Lauf nicht ausufert
if ($modus === 'run') {
    $fitNeu = 0;
    foreach (array_keys($titel) as $sym) {
        if ($fitNeu >= 120 || strpos($sym, '.') !== false || strpos($sym, '-') !== false) continue;
        $fd = __DIR__ . '/data/cache/fitness/' . preg_replace('/[^A-Z0-9.\-]/', '_', $sym) . '.v2.json';
        if (is_file($fd) && time() - filemtime($fd) < 6 * 86400) continue;
        try { fitness_daten($sym, true); } catch (Throwable $e) {}
        $fitNeu++;
    }
    echo "Fitness-Speicher aufgefrischt: $fitNeu Titel\n";
}
foreach ($titel as $sym => [$sektor, $herkunft, $name]) {
    $n++;
    try { $z = bewertung_zeile($sym, $sektor, BS_FVR); } catch (Throwable $e) { $fehler++; continue; }
    if (!empty($z['fehler'])) { $fehler++; continue; }
    // Banken (bewertung_bank, seit 12.11.2026): keine erwartete Rendite - Treffer, wenn der Kurs unter dem UNTEREN Ende der
    // Fair-Value-Spanne liegt (beide KBV-Wege). Spanne merken, um die Kursalarme "Bank-FV" nachzuziehen.
    if (!empty($z['bank']['fv_unten'])) {
        $bankFv[$sym] = $z['bank'];
        if ($z['kurs'] < $z['bank']['fv_unten']) $treffer[$sym] = $z + ['herkunft' => $herkunft, 'nameListe' => $name, 'ror' => 0,
            'abw' => ($z['kurs'] / $z['bank']['fv_unten'] - 1) * 100, 'fv' => $z['bank']['fv_unten']];
        usleep(150000); continue;
    }
    // Plausibilitaet (07.11.2026, GIS mit FV 579 durch falschen ADR-Faktor): ueber 80 % unter FV oder KGV unter 3 = Datenfehler
    if (isset($z['abw'], $z['ror']) && $z['abw'] <= BS_ABW && $z['abw'] > -80 && ($z['kgv'] ?? 99) >= 3 && !isset($z['einbruch']) && $z['ror'] >= BS_ROR && $z['ror'] < 60) {
        $treffer[$sym] = $z + ['herkunft' => $herkunft, 'nameListe' => $name];
    }
    usleep(150000);   // Yahoo schonen
}

$stateFile = $dataDir . '/bewertung_signale_state.json';
$alt = json_decode((string) @file_get_contents($stateFile), true) ?: [];
$neu = array_diff_key($treffer, $alt['treffer'] ?? []);
$weg = array_diff_key($alt['treffer'] ?? [], $treffer);
uasort($treffer, fn($a, $b) => $b['ror'] <=> $a['ror']);

$f = fn($x, $n = 0) => number_format((float) $x, $n, ',', '.');
$zeile = fn($z) => '<b>' . telegram_escape($z['sym']) . '</b> ' . telegram_escape(mb_substr($z['nameListe'] ?: ($z['name'] ?? ''), 0, 24))
    . ' (' . $z['herkunft'] . ")\n   Kurs " . $f($z['kurs'], 2) . ' · Fair Value ' . $f($z['fv'], 2) . ' (' . $f($z['abw']) . ' %)'
    . (!empty($z['bank']) ? ' · Bank, Spanne ' . $f($z['bank']['fv_unten'], 2) . '–' . $f($z['bank']['fv_oben'], 2) . ' (KBV)' : ' · erw. Rendite ' . $f($z['ror'], 1) . ' % p.a.')
    . ($z['divR'] ? ' · Div. ' . $f($z['divR'], 1) . ' %' : '') . ($z['folge'] ? ' · ' . $z['folge'] . ' J. erhöht' : '') . "\n   nach " . $z['basis'];
$text = "<b>📋 Bewertung: unterbewertete Titel</b>\n" . 'Kurs ≥ 15 % unter Fair Value und erwartete Rendite ≥ 10 % p.a. (Banken: Kurs unter der KBV-Spanne) – ' . count($treffer) . ' von ' . $n . " Titeln\n";
if ($neu) { uasort($neu, fn($a, $b) => $b['ror'] <=> $a['ror']); $text .= "\n<b>Neu dazu:</b>\n" . implode("\n", array_map($zeile, array_slice($neu, 0, 15))) . (count($neu) > 15 ? "\n… und " . (count($neu) - 15) . ' weitere' : '') . "\n"; }
if ($weg) $text .= "\n<b>Nicht mehr auf der Liste:</b> " . telegram_escape(implode(', ', array_keys($weg))) . "\n";
$text .= "\nAlle Treffer: ValueLens → Watchlist & Filter → Bewertungsliste (Filter „unter Fair Value“). Regelausgabe, keine Anlageberatung.";

if ($modus === 'vorschau') { echo strip_tags($text), "\n\nTreffer gesamt: ", implode(', ', array_keys($treffer)), "\nOhne Daten: $fehler\n";
    foreach ($bankFv as $s2 => $b) echo "Bank $s2: Alarm-Ziel ", $b['fv_unten'], ' (', $b['bvps_stand'] ?? '', ")\n";
    exit(0); }
if ($modus !== 'run') { fwrite(STDERR, "Aufruf: php bewertung-signale.php vorschau|run\n"); exit(1); }

// Kursalarme "Bank-FV" (Watchlist-Gruppe Scalable – Banken) auf das aktuelle untere Ende der Spanne setzen - der Buchwert
// kommt aus dem letzten Quartal, so wandern die Alarme jedes Quartal mit. Nur diese Alarme, mit Sperre und _updatedAt.
if ($bankFv) {
    $df = $dataDir . '/' . BS_DEPOT; $fp = fopen($df, 'c+');
    if ($fp && flock($fp, LOCK_EX)) {
        $dd = json_decode(stream_get_contents($fp), true); $geaendert = 0;
        foreach ($dd['priceAlerts'] ?? [] as &$al) {
            $b = $bankFv[$al['symbol'] ?? ''] ?? null;
            if (!$b || strpos($al['name'] ?? '', 'Bank-FV') === false || abs(($al['targetPrice'] ?? 0) - $b['fv_unten']) < 0.01) continue;
            $al['targetPrice'] = $b['fv_unten']; $geaendert++;
        }
        unset($al);
        foreach ($dd['watchlist'] ?? [] as &$w) if (isset($bankFv[$w['symbol'] ?? ''])) { $b = $bankFv[$w['symbol']];
            $w['bankFv'] = ['unten' => $b['fv_unten'], 'oben' => $b['fv_oben'], 'kbvSchnitt' => $b['kbv_schnitt'], 'kbvGerecht' => $b['kbv_gerecht'],
                            'roe' => $b['roe_schnitt'], 'bvps' => $b['bvps'], 'stand' => $b['bvps_stand'] ?? date('Y-m-d')]; }
        unset($w);
        if ($geaendert && $dd) {
            $dd['_updatedAt'] = (int) round(microtime(true) * 1000);
            ftruncate($fp, 0); rewind($fp); fwrite($fp, json_encode($dd, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            echo "Bank-Alarme angepasst: $geaendert\n";
        }
        flock($fp, LOCK_UN); fclose($fp);
    }
}

// Mitlaufende Kursalarme (Chart, "Fair-Value-Linie mitlaufend"): Grenze auf den heutigen Wert der Linie setzen - in allen
// Depots, mit Sperre und _updatedAt. Ein bereits ausgeloester Alarm wird wieder scharf, wenn die Linie unter den Kurs steigt.
foreach (glob($dataDir . '/depot_*.json') ?: [] as $df) {
    if (strpos(basename($df), '_backup') !== false) continue;
    $fp = fopen($df, 'c+'); if (!$fp || !flock($fp, LOCK_EX)) continue;
    $dd = json_decode(stream_get_contents($fp), true); $geaendert = 0;
    foreach ($dd['priceAlerts'] ?? [] as &$al) {
        if (($al['dynamisch'] ?? '') !== 'fv') continue;
        try { $v = bewertung_fv_linie_heute($al['symbol'], $al['basis'] ?? 'operativ', (float) ($al['faktor'] ?? 15)); } catch (Throwable $e) { $v = null; }
        if ($v && abs(($al['targetPrice'] ?? 0) - $v) >= 0.01) { $al['targetPrice'] = $v; $al['dynamischStand'] = date('Y-m-d'); $geaendert++; }
    }
    unset($al);
    if ($geaendert && $dd) {
        $dd['_updatedAt'] = (int) round(microtime(true) * 1000);
        ftruncate($fp, 0); rewind($fp); fwrite($fp, json_encode($dd, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        echo basename($df) . ": $geaendert mitlaufende(r) Alarm(e) angepasst\n";
    }
    flock($fp, LOCK_UN); fclose($fp);
}

// Big Board (Van Knapps Verfahren, bigboard-lib.php) nachts rechnen; am 1. des Monats Best Bets je Sektor melden (Nutzer 18.11.2026).
// Fehler nur ins eigene Protokoll - nie auf die Fehlerausgabe (Cron-Mail).
try {
    require_once __DIR__ . '/bigboard-lib.php';
    $bb = bb_rechnen(1500);
    echo 'Big Board: ', $bb['anzahl'], ' Titel, ', $bb['neuBewertet'], ' neu bewertet, ', count(array_filter($bb['zeilen'], fn($z) => $z['best'])), ' Best Bets', $bb['fmpSperre'] ? ' (FMP: ' . $bb['fmpSperre'] . ')' : '', "\n";
    $bbState = $dataDir . '/bigboard_bericht.json'; $bbAlt = json_decode((string) @file_get_contents($bbState), true) ?: [];
    if ((int) date('j') === 1 && ($bbAlt['monat'] ?? '') !== date('Y-m')) {
        $bbText = bb_bericht_text($bb);
        if ($bbText !== '' && capolio_melden($dataDir, 'analysen', $bbText)) @file_put_contents($bbState, json_encode(['monat' => date('Y-m'), 'stand' => date('c')]), LOCK_EX);
    }
} catch (Throwable $e) { @file_put_contents($dataDir . '/bigboard_fehler.log', date('c') . ' ' . $e->getMessage() . "\n", FILE_APPEND); }

$gesendet = true;
if ($neu || $weg) $gesendet = capolio_melden($dataDir, 'analysen', $text);
if (!$gesendet) { fwrite(STDERR, "Telegram-Versand fehlgeschlagen\n"); exit(1); }
$sp = [];
foreach ($treffer as $s => $z) $sp[$s] = ['abw' => round($z['abw'], 1), 'ror' => round($z['ror'], 1), 'seit' => $alt['treffer'][$s]['seit'] ?? date('Y-m-d')];
file_put_contents($stateFile, json_encode(['stand' => date('c'), 'treffer' => $sp, 'titel' => $n, 'ohneDaten' => $fehler], JSON_UNESCAPED_UNICODE), LOCK_EX);
@chmod($stateFile, 0600);
