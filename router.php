<?php
// Weiche fuer den eingebauten PHP-Server (php -S ... router.php): Datenordner und Hilfsdateien nie ausliefern.
$pfad = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
if (preg_match('#^/(data|\.git)(/|$)#i', $pfad) || preg_match('#\.(md|json|log|txt|bat|command|sh|py)$#i', $pfad)) {
    http_response_code(403); echo 'gesperrt'; return true;
}
if ($pfad === '/') { require __DIR__ . '/index.php'; return true; }
return false;   // alles andere liefert der eingebaute Server selbst aus (PHP-Dateien, JS, CSS)
