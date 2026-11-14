<?php
// Gemeinsamer Yahoo-Chart-Batch-Abruf (curl_multi) - herausgezogen aus urspruenglich dreifach
// fast identischem Code in quote.php/daily-price-sync.php/check-alerts.php (Nutzerwunsch vom
// 09.09.2026, Code-Audit: "bitte alle die du verbessern siehst"). Liefert je Symbol das
// komplette "meta"-Objekt aus Yahoos Chart-API unveraendert zurueck (regularMarketPrice,
// currency, fiftyTwoWeekHigh/-Low, chartPreviousClose, shortName, ...) - welche Felder ein
// Aufrufer davon braucht bzw. wie er sie weiterformatiert, bleibt bewusst bei ihm.
// Seit 24.09.2026 in Paketen zu 25 mit kurzer Pause und einem zweiten Versuch fuer Fehlende:
// alle ~160 Symbole auf einmal (15-Minuten-Takt des Kursabrufs) liess Yahoo zeitweise den ganzen
// Stoss ablehnen (0 von 160 beantwortet).
function yahoo_fetch_chart_meta($symbols, $range = '5d', $interval = '1d') {
    if (!$symbols || !function_exists('curl_multi_init')) return [];
    $symbols = array_values(array_unique($symbols));
    $out = [];
    foreach ([0, 1] as $versuch) {
        $offen = array_values(array_filter($symbols, fn($s) => !isset($out[$s])));
        if (!$offen) break;
        if ($versuch) usleep(1500000);
        foreach (array_chunk($offen, 25) as $i => $paket) {
            if ($i) usleep(400000);
            $out += yahoo_fetch_chart_meta_batch($paket, $range, $interval);
        }
    }
    return $out;
}
function yahoo_fetch_chart_meta_batch($symbols, $range, $interval) {
    $ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36';
    $mh = curl_multi_init();
    $handles = [];
    foreach ($symbols as $sym) {
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
        curl_multi_add_handle($mh, $ch);
        $handles[$sym] = $ch;
    }
    $running = null;
    do {
        $status = curl_multi_exec($mh, $running);
        if ($running) { curl_multi_select($mh, 1.0); }
    } while ($running > 0 && $status === CURLM_OK);
    $out = [];
    foreach ($handles as $sym => $ch) {
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $body = curl_multi_getcontent($ch);
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
        if ($code !== 200 || !$body) continue;
        $json = json_decode($body, true);
        $meta = $json['chart']['result'][0]['meta'] ?? null;
        if (!$meta || !isset($meta['regularMarketPrice'])) continue;
        // Schluss des Vortags aus den Tageskerzen (14.10.2026): chartPreviousClose liegt bei range=5d vor
        // dem ganzen Zeitraum. Ist die letzte Kerze vom aktuellen Handelstag, zaehlt die davor.
        $res = $json['chart']['result'][0];
        $closes = $res['indicators']['quote'][0]['close'] ?? [];
        $tage = [];
        foreach ($res['timestamp'] ?? [] as $i => $ts) {
            if (isset($closes[$i]) && is_numeric($closes[$i])) $tage[gmdate('Y-m-d', $ts + (int) ($meta['gmtoffset'] ?? 0))] = (float) $closes[$i];
        }
        if (isset($meta['regularMarketTime']) && count($tage) >= 1) {
            $heute = gmdate('Y-m-d', $meta['regularMarketTime'] + (int) ($meta['gmtoffset'] ?? 0));
            if (array_key_last($tage) === $heute) array_pop($tage);
            if ($tage) $meta['_vortagSchluss'] = end($tage);
        }
        $out[$sym] = $meta;
    }
    curl_multi_close($mh);
    return $out;
}
