# Änderungen

## 1.3 – 15.11.2026
- **Bewertung** lädt beim zweiten Öffnen sofort: das Ergebnis wird 3 Stunden zwischengespeichert (vorher bei jeder Aktie 30–40 Sekunden „Lade Gewinne je Aktie …“).

## 1.2 – 15.11.2026
- Neuer Reiter **Hilfe**: häufige Fragen (Linien im Chart, Renditerechner, Alarme, Telegram, Daten sichern, Updates).
- **Installation prüfen** mit Ampel: PHP, Erweiterungen, Zertifikate, Yahoo, SEC, Telegram, Updates – auch ohne Browser per `pruefen.bat` (Windows) bzw. `./start.command pruefen`.
- Nach einem Update zeigt ValueLens, was neu ist.
- Fehler behoben: Grafiken unter **Fitness** blieben leer (die Diagramm-Bibliothek kam nicht mehr vom fremden Server). Beide Diagramm-Bibliotheken liegen jetzt im Ordner `client/lib` – kein fremder Server mehr nötig.

## 1.1 – 14.11.2026
Auto-Update: beim Start neue Fassung von GitHub laden und einspielen (abschaltbar); manuelle Prüfung unter Einstellungen. Daten bleiben erhalten.

## 1.0 – 14.11.2026
Erste Fassung: Bewertung, Finanzielle Fitness, Watchlist & Filter, Meine Käufe, Kursalarme mit Telegram, lokaler Start per start.bat / start.command.
