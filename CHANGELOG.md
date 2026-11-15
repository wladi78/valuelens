# Änderungen

## 1.10 – 15.11.2026
- **Hinweis:** ValueLens gibt es jetzt als Webseite im Kamikaze-Bereich (Menü „📈 Aktien“). Diese lokale Fassung bekommt ab 22.11.2026 keine Updates mehr. Käufe per CSV mitnehmen: Meine Käufe → Als CSV herunterladen, auf der Webseite → CSV einlesen.

## 1.9 – 15.11.2026
- Fehler behoben: Ein ausgelöster Kursalarm wurde in der Seite nicht angezeigt (Meldung „Alarm ausgelöst“ kam nie).
- Eingabefelder: kein Vorschlag des Passwort-Managers mehr (Mailadresse im Feld „Aktie bewerten“).

## 1.8 – 15.11.2026
- **Schneller:** SEC-Zahlen bleiben gespeichert, bis die Firma einen neuen Quartals- oder Jahresbericht einreicht (einmal täglich geprüft, spätestens nach 100 Tagen neu). Fitness danach in unter 1 Sekunde, Bewertung in rund 4 Sekunden.
- Watchlist: Der Browser trägt keine Mailadresse mehr ins Namensfeld ein.

## 1.7 – 15.11.2026
- **Meine Käufe als CSV**: einlesen (Datum;Symbol;Art;Stück;Preis;Währung;Preis EUR – doppelte Zeilen werden übersprungen) und als CSV herunterladen.
- Die Bewertungsliste der Watchlist berechnet sich beim Öffnen von selbst.

## 1.6 – 15.11.2026
- Kopf und Reiter bleiben beim Blättern oben stehen; Webfassung in Kamikaze mit dessen Menüleiste (alles in einer Seite).

## 1.5 – 15.11.2026
- Der Chart öffnet sich als **Seite** (volle Breite, Kennzahlen rechts) statt als Fenster; die Reiter oben bleiben sichtbar und schließen den Chart.

## 1.4 – 15.11.2026
- ValueLens gibt es jetzt auch als Webseite im Kamikaze-Bereich (mit Anmeldung, eigene Daten je Mitglied). Die lokale Fassung bleibt gleich.
- Kursalarm-Knöpfe (Telegram/Mail je Aktie) technisch umgebaut; Start ohne eingebettetes Skript.

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
