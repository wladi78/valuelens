<?php
// ValueLens - lokales Werkzeug: Aktien bewerten (Fair Value, Normal-KGV, Prognose), Finanzielle Fitness, Watchlist mit
// Filtern, eigene Kaeufe im Chart, Kursalarme mit Telegram. Start ueber start.bat / start.command (php -S 127.0.0.1).
require_once __DIR__ . '/session-init.php';
$csrf = json_encode($_SESSION['csrfToken']);
?><!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>ValueLens</title>
<link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'><circle cx='14' cy='14' r='9' fill='none' stroke='%2310b981' stroke-width='4'/><line x1='21' y1='21' x2='29' y2='29' stroke='%2310b981' stroke-width='4' stroke-linecap='round'/></svg>">
<script src="client/lib/chart.umd.min.js"></script>
<link rel="stylesheet" href="client/valuelens.css">
<meta name="csrf-token" content="<?= htmlspecialchars($_SESSION['csrfToken']) ?>">
<meta name="vl-modus" content="<?= defined('VL_WEB') ? 'web' : 'lokal' ?>">
</head>
<body>
<div class="vl-oben" id="vlOben">
<?php if (function_exists('vl_kopfzeile')) vl_kopfzeile(); /* Web: Kamikaze-Leiste */ ?>
<header class="kopf">
  <div class="marke" data-nur-lokal>🔍 <b>ValueLens</b> <span class="muted">Aktien bewerten<span data-nur-lokal> · lokal</span></span></div>
  <nav>
    <button class="nav active" data-seite="aktie">Aktie</button>
    <button class="nav" data-seite="liste">Watchlist &amp; Filter</button>
    <button class="nav" data-seite="kaeufe">Meine Käufe</button>
    <button class="nav" data-seite="alarme">Alarme &amp; Telegram</button>
    <button class="nav" data-seite="einstellungen" data-nur-lokal>Einstellungen</button>
    <button class="nav" data-seite="hilfe">Hilfe</button>
  </nav>
</header>
</div>
<main id="page-aktienchart">   <!-- id fuer die Klick-Weiche des Chart-Moduls (Liste sortieren, Zeile oeffnen) -->
  <!-- Aktie bewerten -->
  <section class="page active" id="page-aktie">
    <div class="panel" data-nur-lokal style="border-color:#f59e0b;background:#fffbeb;"><div class="innen klein">
      <b>📢 ValueLens zieht um:</b> Die Kamikaze-Trader nutzen ValueLens ab sofort als Webseite im Kamikaze-Bereich – Menü <b>📈 Aktien</b>
      (mit deiner Kamikaze-Anmeldung, nichts zu installieren, Kursalarme auch bei ausgeschaltetem Rechner). Diese lokale Fassung läuft weiter,
      bekommt aber <b>ab 22.11.2026 keine Updates mehr</b>. Deine Käufe kannst du unter <b>Meine Käufe → Als CSV herunterladen</b>
      sichern und auf der Webseite mit <b>CSV einlesen</b> übernehmen.
    </div></div>
    <div class="panel">
      <div class="table-head"><h2>Aktie bewerten</h2></div>
      <div class="innen">
        <div class="muted klein">Symbol eingeben – jede Aktie (US mit bis zu 19 Jahren SEC-Daten, andere Börsen mit Yahoo-Daten, z. B. SAP.DE, NESN.SW).
          Reiter im Chart: Zonen · Bewertung · Fitness · Dividende · Analyse. Mit Enter öffnen.</div>
        <div class="zeile">
          <input id="acSymbol" type="search" autocomplete="off" list="acSymbole" placeholder="z. B. KO, MSFT, O, SAP.DE" style="width:260px;">
          <datalist id="acSymbole"></datalist>
          <button class="btn primary" id="acOeffnen">📈 Bewerten</button>
          <button class="btn ghost" id="acNeuerTab" data-nur-lokal>↗ In eigenem Tab</button>
          <select id="acStart"><option value="bewertung">Start: Bewertung</option><option value="fitness">Start: Fitness</option><option value="zonen">Start: Zonen</option><option value="div">Start: Dividende</option><option value="analyse">Start: Analyse</option></select>
        </div>
        <div id="acZuletzt" class="zeile" style="margin-top:12px;"></div>
        <div id="acListen" style="margin-top:12px;"></div>
      </div>
    </div>
  </section>

  <!-- Watchlist und Bewertungsliste -->
  <section class="page" id="page-liste">
    <div class="panel">
      <div class="table-head"><h2>Watchlist</h2></div>
      <div class="innen">
        <div class="zeile">
          <input id="wlSymbol" type="search" autocomplete="off" placeholder="Symbol, z. B. KO" style="width:140px;">
          <input id="wlName" type="search" autocomplete="off" placeholder="Bezeichnung (optional)" style="width:200px;">
          <button class="btn" id="wlNeu">+ Hinzufügen</button>
          <span class="muted klein">Mehrere auf einmal: Symbole mit Komma trennen (KO, PEP, MSFT).</span>
        </div>
        <div id="wlListe" class="zeile" style="margin-top:10px;"></div>
      </div>
    </div>
    <div class="panel">
      <div class="table-head"><h2>Bewertungsliste</h2>
        <div class="zeile">
          <select id="acListeUmfang"><option value="watch">Watchlist</option><option value="depot">Meine Käufe</option><option value="beide">Beide</option></select>
          <select id="acListeFilter"><option value="">alle</option><option value="unter">unter Fair Value</option><option value="normal">unter Normal-KGV</option><option value="rendite10">erw. Rendite ≥ 10 %</option><option value="div10">Dividende 10+ Jahre erhöht</option><optgroup label="Qualität (SEC-Berichte)"><option value="roe15">ROE ≥ 15 %</option><option value="ldk50">Schulden/Kapital ≤ 50 %</option><option value="fcfdiv">FCF deckt Dividende (5 J.)</option><option value="qualitaet">Qualität: alle drei + unter Normal-KGV</option></optgroup><optgroup label="Fertige Filter"><option value="champ">Dividenden-Champions 25+ J., fair bewertet</option><option value="contender">Dividenden-Contenders 10–24 J., fair</option><option value="challenger">Dividenden-Challengers 5–9 J., fair</option><option value="growth">Good Growth: Wachstum ≥ 10 %, fair, Rendite ≥ 10 %, PEG ≤ 1,5</option><option value="growthret">Growth &amp; Returns: Wachstum ≥ 15 %, fair</option></optgroup></select>
          <button class="btn primary" id="acListeLaden">Liste berechnen</button>
        </div>
      </div>
      <div class="innen">
        <div class="muted klein" id="acListeInfo">Berechnet je Titel Fair Value, Normal-KGV, erwartete Rendite (5 J.), Dividenden-Kennzahlen, ROE, Schulden/Kapital, FCF-Deckung und Analystenstimmung. Erster Lauf dauert (SEC-Daten), danach 7 Tage zwischengespeichert. Klick auf eine Zeile öffnet den Chart.</div>
        <div class="table-scroll"><table class="data-table" id="acListeTab"></table></div>
      </div>
    </div>
  </section>

  <!-- Eigene Kaeufe -->
  <section class="page" id="page-kaeufe">
    <div class="panel">
      <div class="table-head"><h2>Meine Käufe</h2></div>
      <div class="innen">
        <div class="muted klein">Käufe erscheinen im Chart als pinke Punkte mit Datum und Preis, dazu der Durchschnittspreis. Preis in der Währung, in der die Aktie gehandelt wird (US-Aktien: USD); Preis in € ist optional.</div>
        <div class="zeile">
          <input id="kfSymbol" type="search" autocomplete="off" placeholder="Symbol" style="width:100px;">
          <input id="kfDatum" type="date" style="width:150px;">
          <select id="kfArt"><option value="kauf">Kauf</option><option value="verkauf">Verkauf</option></select>
          <input id="kfStueck" autocomplete="off" placeholder="Stück" inputmode="decimal" style="width:80px;">
          <input id="kfPreis" autocomplete="off" placeholder="Preis" inputmode="decimal" style="width:90px;">
          <select id="kfWaehrung"><option>USD</option><option>EUR</option><option>GBP</option><option>CHF</option><option>CAD</option><option>JPY</option></select>
          <input id="kfEur" autocomplete="off" placeholder="Preis in € (optional)" inputmode="decimal" style="width:150px;">
          <button class="btn primary" id="kfNeu">Eintragen</button>
        </div>
        <div class="zeile" style="margin-top:8px;">
          <input type="file" id="kfCsvDatei" accept=".csv,.txt,text/csv" hidden>
          <button class="btn" id="kfCsvImport">📥 CSV einlesen</button>
          <button class="btn ghost" id="kfCsvExport">📤 Als CSV herunterladen</button>
          <span class="muted klein">Spalten (Kopfzeile, Reihenfolge egal): <code>Datum;Symbol;Art;Stück;Preis;Währung;Preis EUR</code> – Art und Preis EUR optional,
            Datum als 15.03.2024 oder 2024-03-15, Komma oder Punkt als Dezimalzeichen. Doppelte Zeilen werden übersprungen.</span>
        </div>
        <div class="table-scroll" style="margin-top:12px;"><table class="data-table" id="kfTab"></table></div>
      </div>
    </div>
  </section>

  <!-- Alarme und Telegram -->
  <section class="page" id="page-alarme">
    <div class="panel">
      <div class="table-head"><h2>Kursalarme</h2>
        <div class="zeile"><button class="btn" id="alPruefen">Jetzt prüfen</button><button class="btn ghost" id="nlStarten" title="Bewertungs-Meldungen, mitlaufende Alarme an der Fair-Value-Linie, Fitness-Daten auffrischen">Täglichen Lauf starten</button></div>
      </div>
      <div class="innen">
        <div class="muted klein">Alarme setzt du im Chart über 🔔 Alarm. Solange ValueLens geöffnet ist, prüft es alle 15 Minuten und meldet per Telegram (Kanal „Kursalarme“), jeder Alarm einmal. Der tägliche Lauf startet beim ersten Öffnen des Tages von selbst.</div>
        <div id="alStatus" class="klein" style="margin:6px 0;"></div>
        <div class="table-scroll"><table class="data-table" id="alTab"></table></div>
      </div>
    </div>
    <div class="panel">
      <div class="table-head"><h2>Telegram</h2></div>
      <div class="innen">
        <div class="muted klein">1. In Telegram <b>@BotFather</b> → /newbot → Namen vergeben → Token kopieren. 2. Dem neuen Bot einmal <b>/start</b> schreiben.
          3. Unten Kanal wählen, Token einfügen, „Chat-ID finden“, „Speichern“ – es kommt eine Testnachricht.</div>
        <div id="tgBox"></div>
      </div>
    </div>
  </section>

  <!-- Einstellungen -->
  <section class="page" id="page-einstellungen">
    <div class="panel">
      <div class="table-head"><h2>Einstellungen</h2></div>
      <div class="innen">
        <div class="muted klein">Die US-Börsenaufsicht SEC verlangt bei Datenabrufen eine Kontakt-Mailadresse. Sie bleibt auf diesem Rechner und geht nur an die SEC.</div>
        <div class="zeile"><input id="esMail" type="email" placeholder="deine@mail.de" style="width:260px;"><button class="btn primary" id="esSpeichern">Speichern</button></div>
        <div id="esInfo" class="klein muted" style="margin-top:10px;"></div>
      </div>
    </div>
    <div class="panel">
      <div class="table-head"><h2>Update</h2></div>
      <div class="innen">
        <div class="zeile"><span id="upInfo" class="klein">Version wird geprüft …</span><button class="btn" id="upPruefen">Jetzt prüfen</button><button class="btn primary" id="upHolen" style="display:none;">Update holen</button>
          <label class="klein"><input type="checkbox" id="upAuto" checked> beim Start automatisch aktualisieren</label></div>
        <div class="muted klein" style="margin-top:6px;">Neue Fassungen kommen von GitHub (wladi78/valuelens). Deine Daten (Käufe, Watchlist, Alarme, Telegram) bleiben erhalten.</div>
      </div>
    </div>
  </section>
  <!-- Hilfe -->
  <section class="page" id="page-hilfe">
    <div class="panel" data-nur-lokal>
      <div class="table-head"><h2>Installation prüfen</h2><button class="btn primary" id="prStarten">Jetzt prüfen</button></div>
      <div class="innen">
        <div class="muted klein">Prüft PHP, Erweiterungen, Zertifikate, Datenordner, Yahoo, SEC, Telegram und Updates. Es wird nichts gesendet.
          Auch ohne Browser: Windows Doppelklick auf <code>pruefen.bat</code>, Mac/Linux <code>./start.command pruefen</code>.</div>
        <div class="table-scroll" style="margin-top:8px;"><table class="data-table" id="prTab"></table></div>
      </div>
    </div>
    <div class="panel">
      <div class="table-head"><h2>Häufige Fragen</h2></div>
      <div class="innen faq">
        <details open><summary>Wie bewerte ich eine Aktie?</summary><p>Reiter <b>Aktie</b>: Symbol eingeben (z. B. KO, MSFT, SAP.DE) und Enter. Der Chart öffnet sich im Reiter <b>Bewertung</b>. Oben wählst du, wonach bewertet wird (Gewinn, Cashflow, EBITDA, Umsatz) und den Faktor für den Fair Value.</p></details>
        <details><summary>Was bedeuten die Linien im Chart?</summary><p><b style="color:#ea580c">Orange</b> = Fair Value (Gewinn × Faktor, grüne Fläche darunter) · <b style="color:#2563eb">Blau</b> = Normal-KGV (übliche Bewertung der letzten Jahre) · <b>Schwarz</b> = aktuelle Bewertung (Gewinn × heutiges KGV) · <b style="color:#ca8a04">Gelb</b> = Dividende × Faktor · <b style="color:#b91c1c">Rot</b> = Dividendenrendite (linke Achse) · <b style="color:#db2777">Pink</b> = deine Käufe. Rechts vom heutigen Tag: Schätzungen der Analysten (heller, gestrichelt). Liegt der Kurs unter Orange, ist die Aktie nach dieser Rechnung günstig.</p></details>
        <details><summary>Was ist der Renditerechner?</summary><p>Im Reiter Bewertung zweimal in den Chart klicken: 1. Klick = Kaufzeitpunkt (1.000 investiert), 2. Klick = Ziel (auch in der Zukunft, dann zum Fair Value). Es erscheinen Kursgewinn, Dividenden und Rendite pro Jahr.</p></details>
        <details><summary>Warum zeigt „Fitness“ bei manchen Aktien nichts?</summary><p>Die Fitness-Daten kommen aus den Jahresberichten der US-Börsenaufsicht SEC. Dort berichten nur US-Firmen und an US-Börsen gelistete ausländische Firmen (ADRs). Europäische Titel wie SAP.DE haben dort keine Daten – Bewertung und Chart gehen trotzdem.</p></details>
        <details><summary>Warum dauert der erste Aufruf so lange?</summary><p>Beim ersten Mal lädt ValueLens bis zu 20 Jahre Berichte von der SEC (bis ~20 Sekunden je Firma). Danach liegen sie 7 Tage auf deinem Rechner, und alles geht schnell.</p></details>
        <details><summary>Wie setze ich einen Kursalarm?</summary><p>Im Chart oben auf <b>🔔 Alarm</b>: fertige Grenzen (Fair Value, Normal-KGV …) oder einen eigenen Preis über/unter. <b>Fair-Value-Linie (mitlaufend)</b> wandert jeden Tag mit der orangen Linie mit – der Alarm kommt, sobald der Kurs die Linie erreicht, auch wenn das erst im nächsten Jahr passiert. Jeder Alarm meldet sich einmal.</p></details>
        <details><summary>Warum kam kein Alarm?</summary><p>Alarme werden geprüft, <b>solange diese Seite geöffnet ist</b> (alle 15 Minuten). Ist der Rechner aus oder ValueLens geschlossen, wird beim nächsten Öffnen nachgeprüft. Außerdem muss Telegram eingerichtet sein (Reiter Alarme &amp; Telegram) – „Installation prüfen“ zeigt, ob der Bot antwortet.</p></details>
        <details><summary>Wie richte ich Telegram ein?</summary><p>In Telegram <b>@BotFather</b> öffnen → <code>/newbot</code> → Namen vergeben → Token kopieren. Dem neuen Bot einmal <code>/start</code> schreiben. In ValueLens: Alarme &amp; Telegram → Kanal „Kursalarme“ → Token einfügen → „Chat-ID finden“ → „Speichern“. Es kommt eine Testnachricht.</p></details>
        <details><summary>Wie trage ich meine Käufe ein?</summary><p>Reiter <b>Meine Käufe</b>: Symbol, Datum, Stück, Preis in der Handelswährung (US-Aktien in USD). Der Preis in € ist optional. Käufe erscheinen im Chart als pinke Punkte mit Datum, Preis und Durchschnittspreis.</p></details>
        <details data-nur-lokal><summary>Wo liegen meine Daten – und wie sichere ich sie?</summary><p>Alles liegt im Ordner <code>data</code> im ValueLens-Ordner (Käufe, Watchlist, Alarme, Telegram, Einstellungen). Nichts davon geht ins Internet außer den Abrufen bei Yahoo, SEC und Telegram. Zum Sichern oder Umziehen einfach den Ordner <code>data</code> kopieren.</p></details>
        <details data-nur-lokal><summary>Wie bekomme ich Updates?</summary><p>Automatisch: Beim Start prüft ValueLens, ob es eine neue Version gibt, und spielt sie ein. Deine Daten bleiben. Abschalten oder von Hand prüfen unter <b>Einstellungen</b>. Was neu ist, steht unten.</p></details>
        <details data-nur-lokal><summary>„PHP wurde nicht gefunden“ / Seite lädt nicht</summary><p>PHP 8 einmalig installieren – Windows: <code>winget install PHP.PHP.8.4</code>, Mac: <code>brew install php</code> – und das Startfenster neu öffnen. Ist der Port belegt, mit anderem Port starten: Windows <code>set VALUELENS_PORT=8585</code> vor <code>start.bat</code>, Mac <code>VALUELENS_PORT=8585 ./start.command</code>.</p></details>
        <details><summary>Ist das eine Anlageberatung?</summary><p>Nein. ValueLens rechnet nach festen Regeln mit öffentlichen Daten (Yahoo, SEC). Daten können fehlen oder falsch sein – Entscheidungen triffst du selbst.</p></details>
      </div>
    </div>
    <div class="panel" data-nur-lokal>
      <div class="table-head"><h2>Was ist neu?</h2></div>
      <div class="innen" id="neuListe"><span class="muted klein">wird geladen …</span></div>
    </div>
  </section>
</main>
<div class="modal-overlay" id="neuOverlay"><div class="modal" style="max-width:560px;"><h2 style="margin-top:0;font-size:17px;" id="neuTitel">Neu in ValueLens</h2><div id="neuInhalt"></div><div style="text-align:right;margin-top:12px;"><button class="btn primary" id="neuZu">Verstanden</button></div></div></div>
<script src="client/app.js"></script>
<script src="client/hilfsfunktionen.js"></script>
<script src="client/chart-modul.js"></script>
<script src="client/alarm-meldeweg.js"></script>
<script src="client/telegram-einrichtung.js"></script>
</body>
</html>
