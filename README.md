# ValueLens

Aktien bewerten auf dem eigenen Rechner – ohne Website, ohne Konto, ohne Abo.

- **Bewertung**: Kurs gegen Fair Value (Gewinn × 15 oder normales Vielfaches), Normal-KGV, aktuelle Bewertung,
  Dividende, Schätzungen der Analysten, Prognose mit erwarteter Rendite, Renditerechner per Klick
- **Finanzielle Fitness**: 12 Grafiken aus den SEC-Berichten (Free Cashflow vs. Dividende, Rückkäufe, Margen, ROE, ROIC …),
  Jahr/Quartal, eigene Grafik, alle Jahreszahlen als Tabelle
- **Watchlist & Filter**: Bewertungsliste mit fertigen Filtern (Dividenden-Champions, Good Growth, Qualität …)
- **Meine Käufe**: erscheinen im Chart mit Datum, Preis und Durchschnittspreis
- **Kursalarme mit Telegram**: im Chart setzen, auch mitlaufend an der Fair-Value-Linie

Daten: Kurse und Analystenzahlen von Yahoo, Jahres- und Quartalsberichte von der US-Börsenaufsicht SEC.
Alles bleibt auf deinem Rechner (Ordner `data/`). Keine Anlageberatung.

## Einmalig einrichten

**Windows**
1. PHP installieren: Eingabeaufforderung öffnen und eingeben: `winget install PHP.PHP.8.4`
2. Ordner `valuelens` herunterladen (GitHub: grüner Knopf „Code“ → „Download ZIP“, entpacken) oder mit Git:
   `git clone https://github.com/wladi78/valuelens.git`

**Mac**
1. PHP installieren: `brew install php` (Homebrew: https://brew.sh)
2. Ordner wie oben holen. Einmalig im Terminal: `chmod +x start.command`

## Starten

- **Windows**: Doppelklick auf `start.bat`
- **Mac**: Doppelklick auf `start.command`
- **Linux**: `./start.sh`

Der Browser öffnet sich mit ValueLens (`http://127.0.0.1:8484`). Beenden: das schwarze Fenster schließen.

Beim ersten Start unter **Einstellungen** eine Kontakt-Mail eintragen – die SEC verlangt sie bei Datenabrufen
(sie bleibt auf deinem Rechner und geht nur an die SEC).

## Telegram (für Kursalarme)

1. In Telegram **@BotFather** öffnen → `/newbot` → Namen vergeben → den Token kopieren.
2. Dem neuen Bot einmal **`/start`** schreiben.
3. In ValueLens: **Alarme & Telegram** → Kanal „Kursalarme“ → Token einfügen → **Chat-ID finden** → **Speichern**.
   Es kommt eine Testnachricht.

Kursalarme werden alle 15 Minuten geprüft, solange ValueLens geöffnet ist.

## Aktualisieren

- Mit Git: im Ordner `git pull` (oder Claude Code sagen: „aktualisiere ValueLens“). Deine Daten in `data/` bleiben.
- Ohne Git: neue ZIP holen, entpacken und den alten Ordner `data/` hineinkopieren.

## Probleme

| Meldung | Lösung |
|---|---|
| „PHP wurde nicht gefunden“ | PHP installieren (siehe oben), danach das Fenster neu öffnen |
| Keine Kurse / „Yahoo nicht erreichbar“ | Internet prüfen; unter Windows `start.bat` neu starten (holt die Zertifikate neu) |
| Fitness zeigt „Keine SEC-Daten“ | Nur US-Firmen (und ADRs) berichten an die SEC – europäische Titel haben dort keine Daten |
| Erster Abruf dauert lange | Beim ersten Mal lädt ValueLens die SEC-Berichte (bis ~20 s je Firma), danach 7 Tage gespeichert |
| Port belegt | Anderer Port: `set VALUELENS_PORT=8585` (Windows) bzw. `VALUELENS_PORT=8585 ./start.command` |
