#!/bin/bash
# ValueLens starten (Mac): Doppelklick. Voraussetzung PHP 8:  brew install php
# Laeuft nur auf diesem Rechner (127.0.0.1). Beenden: Terminal-Fenster schliessen oder Ctrl+C.
cd "$(dirname "$0")" || exit 1
# Vom Update abgelegte neue Startdatei uebernehmen
if [ -f "$(basename "$0").neu" ]; then mv -f "$(basename "$0").neu" "$(basename "$0")"; chmod +x "$(basename "$0")"; exec "./$(basename "$0")"; fi
if ! command -v php >/dev/null 2>&1; then
  echo "PHP wurde nicht gefunden. Bitte einmalig installieren:  brew install php"; read -r -p "Enter zum Schliessen"; exit 1
fi
mkdir -p data
PORT="${VALUELENS_PORT:-8484}"
echo; echo " ValueLens laeuft:  http://127.0.0.1:$PORT/   (Beenden: Ctrl+C)"; echo
if [ "$VALUELENS_KEIN_BROWSER" != "1" ]; then (sleep 2; if command -v open >/dev/null; then open "http://127.0.0.1:$PORT/"; else xdg-open "http://127.0.0.1:$PORT/" >/dev/null 2>&1; fi) & fi
php -d max_execution_time=300 -d display_errors=0 -d log_errors=1 -d error_log="$(pwd)/data/php-fehler.log" -d memory_limit=512M -S "127.0.0.1:$PORT" router.php
