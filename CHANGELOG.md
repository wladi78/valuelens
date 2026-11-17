# Änderungen

## 1.20 – 17.11.2026
- Chart Analyse: Pivots, Tiefs und Order Block mit kleiner Beschriftung im Chart statt an der Preisachse (nichts überlappt mehr); rechts etwas Freiraum.
- Fitness: Aktienzahl in falscher Einheit wird erkannt, fehlende Jahre aus der Aktienzahl zum Jahresende ergänzt (z. B. Hershey: Free Cashflow je Aktie wieder vollständig).

## 1.19 – 17.11.2026
- Chart, Reiter Analyse: **offene Monats-Pivots** – Pivot eines Monats = (Hoch + Tief + Schluss) ÷ 3 des Vormonats; offen, solange der Kurs ihn seither nie berührt hat. Rot unter dem Kurs, grün darüber, je die 5 nächsten.

## 1.18 – 17.11.2026
- Chart: Infozeile oben rechts – zeigt „Gewinndaten werden geladen …“ und danach, woher die Zahlen kommen (SEC oder Yahoo, welche Jahre, ADR, Umrechnung, Analystenschätzungen).

## 1.17 – 17.11.2026
- Bewertung: Alphabet wieder mit allen Jahren seit 2013 (Aktienzahl aus Gewinn und Gewinn je Aktie, wenn die SEC sie nicht direkt liefert); Firmen ohne SEC-Zahlen der letzten zwei Jahre (z. B. Brookfield Asset Management) nehmen die Yahoo-Zahlen.

## 1.16 – 17.11.2026
- Orange Warnung genauer: Die Rendite-Schätzung ist bei sehr hohem Wachstum oder einem Normal-KGV aus Jahren mit kaum Gewinn kein Richtwert.

## 1.15 – 17.11.2026
- Chart: Die Kennzahlen rechts überdecken die Schalter darüber nicht mehr; Warnungen stehen in eigener Zeile unter den Kacheln.
- Warnungen unterschieden: rot = vermutlich Datenfehler, orange = Sonderlage (z. B. früher kaum Gewinn, sehr hohes Normal-KGV) oder bekannter Sonderfall (Brookfield BIPC/BEPC).

## 1.14 – 17.11.2026
- Bewertung: **rote Warnung**, wenn die Zahlen unplausibel sind (z. B. KGV unter 3, Fair Value mehr als das 5-Fache des Kurses) – dann ist meist die Datenquelle falsch, nicht die Aktie billig.
- BlackRock (BLK): wieder alle Jahre seit 2007 (Firma meldet seit 2024 unter neuer SEC-Nummer).
- Prognose-Tabelle: jede Zeile in der Farbe ihrer Linie im Chart.
- Durchblättern: Klick auf den Zähler springt an den Anfang der Liste.

## 1.13 – 17.11.2026
- Bewertung: Firmen, deren SEC-Zahlen veraltet sind (z. B. Baidu, letzte Daten 2010), nehmen jetzt die aktuellen Yahoo-Zahlen; bei Yahoo-Zahlen wird das ADR-Verhältnis nicht mehr doppelt angewandt (Baidu zeigte einen viel zu hohen Fair Value).
- Chart: Kopfzeile bleibt in einer Zeile (bei wenig Platz zeigen Alarm und Eigener Tab nur ihr Zeichen); beim Durchblättern wird die nächste Aktie vorgeladen.

## 1.12 – 17.11.2026
- Chart: Aktien **durchblättern** – oben „Meine Käufe“ oder „Watchlist“ wählen und mit ◀ ▶ (oder den Pfeiltasten links/rechts) Aktie für Aktie durchgehen; der Zähler zeigt die Stelle in der Liste.

## 1.11 – 16.11.2026
- Telegram: Kanäle ohne eigenen Bot zeigen „↪ über …-Bot“ statt „nicht eingerichtet“; die Testnachricht nimmt denselben Weg wie die echten Meldungen.
- Kursalarme: Ist für ein Konto kein Telegram möglich, kommt der Alarm per E-Mail statt gar nicht.
- Gleicher Stand wie die Webfassung (Bewertung, Fitness, Chart unverändert).

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
