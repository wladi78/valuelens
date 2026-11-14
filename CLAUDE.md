# ValueLens – Hinweise für Claude Code

Lokales Werkzeug (PHP 8 + Vanilla-JS, keine Datenbank). Läuft über den eingebauten PHP-Server nur auf 127.0.0.1,
ohne Login (session-init.php setzt den Nutzer `lokal`). Daten in `data/` (nie committen, siehe .gitignore).
Antworten an den Nutzer auf Deutsch.

## Starten / Aktualisieren
- „Starte ValueLens“: PHP vorhanden? (`php -v`; sonst Windows `winget install PHP.PHP.8.4`, Mac `brew install php`).
  Dann `start.bat` (Windows) bzw. `./start.command` (Mac) im Projektordner ausführen – öffnet http://127.0.0.1:8484.
- „Aktualisiere ValueLens“: `git pull`. `data/` bleibt unberührt. Danach neu starten.
- Unter Windows ohne php.ini schaltet `start.bat` curl/openssl/mbstring ein und exportiert die Windows-Stammzertifikate
  nach `data/cacert.pem` (kein Download). Fehler stehen in `data/php-fehler.log`.

## Aufbau
| Datei | Aufgabe |
|---|---|
| `index.php`, `client/app.js`, `client/valuelens.css` | Oberfläche: Aktie, Watchlist & Filter, Meine Käufe, Alarme & Telegram, Einstellungen |
| `client/chart-modul.js`, `client/hilfsfunktionen.js`, `client/alarm-meldeweg.js`, `client/telegram-einrichtung.*` | Chart und Listen (aus Capolio übernommen) |
| `depot.php` | lokales Depot `data/depot_lokal.json` (Käufe, Watchlist, Alarme) |
| `alarm-pruefen.php` → `check-alerts.php` | Kursalarme, alle 15 Min. aus der offenen Seite |
| `nachtlauf.php` → `bewertung-signale.php` | täglicher Lauf im Hintergrund (Meldungen, mitlaufende Alarme, Fitness-Speicher) |
| `quote.php`, `fundamentals.php`, `bewertung-*.php`, `fitness*.php`, `sector.php` | Datenabrufe (Yahoo, SEC) mit Zwischenspeicher in `data/cache/` |
| `router.php` | sperrt `data/` und Hilfsdateien im eingebauten Server |

## Wichtig
- Die Chart- und Server-Dateien werden aus Capolio erzeugt (`tools/valuelens-bauen.py` dort). Änderungen daran
  besser in Capolio machen, sonst gehen sie beim nächsten Update verloren. Eigene Dateien von ValueLens:
  index.php, client/app.js, client/valuelens.css, depot.php, alarm-pruefen.php, nachtlauf.php, einstellungen.php,
  session-init.php, router.php, start.*, README.md, CLAUDE.md.
- Keine Schlüssel, Tokens oder persönlichen Daten ins Repository; Telegram-Token liegen nur in `data/telegram_*.json`.
- Keine Anlageberatung – Regelausgaben und Rechenhilfen.
