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
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.4/chart.umd.min.js"></script>
<link rel="stylesheet" href="client/valuelens.css">
</head>
<body>
<header class="kopf">
  <div class="marke">🔍 <b>ValueLens</b> <span class="muted">Aktien bewerten · lokal</span></div>
  <nav>
    <button class="nav active" data-seite="aktie">Aktie</button>
    <button class="nav" data-seite="liste">Watchlist &amp; Filter</button>
    <button class="nav" data-seite="kaeufe">Meine Käufe</button>
    <button class="nav" data-seite="alarme">Alarme &amp; Telegram</button>
    <button class="nav" data-seite="einstellungen">Einstellungen</button>
  </nav>
</header>
<main id="page-aktienchart">   <!-- id fuer die Klick-Weiche des Chart-Moduls (Liste sortieren, Zeile oeffnen) -->
  <!-- Aktie bewerten -->
  <section class="page active" id="page-aktie">
    <div class="panel">
      <div class="table-head"><h2>Aktie bewerten</h2></div>
      <div class="innen">
        <div class="muted klein">Symbol eingeben – jede Aktie (US mit bis zu 19 Jahren SEC-Daten, andere Börsen mit Yahoo-Daten, z. B. SAP.DE, NESN.SW).
          Reiter im Chart: Zonen · Bewertung · Fitness · Dividende · Analyse. Mit Enter öffnen.</div>
        <div class="zeile">
          <input id="acSymbol" list="acSymbole" placeholder="z. B. KO, MSFT, O, SAP.DE" style="width:260px;">
          <datalist id="acSymbole"></datalist>
          <button class="btn primary" id="acOeffnen">📈 Bewerten</button>
          <button class="btn ghost" id="acNeuerTab">↗ In eigenem Tab</button>
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
          <input id="wlSymbol" placeholder="Symbol, z. B. KO" style="width:140px;">
          <input id="wlName" placeholder="Name (optional)" style="width:200px;">
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
          <input id="kfSymbol" placeholder="Symbol" style="width:100px;">
          <input id="kfDatum" type="date" style="width:150px;">
          <select id="kfArt"><option value="kauf">Kauf</option><option value="verkauf">Verkauf</option></select>
          <input id="kfStueck" placeholder="Stück" inputmode="decimal" style="width:80px;">
          <input id="kfPreis" placeholder="Preis" inputmode="decimal" style="width:90px;">
          <select id="kfWaehrung"><option>USD</option><option>EUR</option><option>GBP</option><option>CHF</option><option>CAD</option><option>JPY</option></select>
          <input id="kfEur" placeholder="Preis in € (optional)" inputmode="decimal" style="width:150px;">
          <button class="btn primary" id="kfNeu">Eintragen</button>
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
</main>
<script>window.CSRF_TOKEN = <?= $csrf ?>;</script>
<script src="client/app.js"></script>
<script src="client/hilfsfunktionen.js"></script>
<script src="client/chart-modul.js"></script>
<script src="client/alarm-meldeweg.js"></script>
<script src="client/telegram-einrichtung.js"></script>
<script>valueLensStart();</script>
</body>
</html>
