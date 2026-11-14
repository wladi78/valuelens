// Meldeweg je Aktie fuer Kursalarme (Telegram an, Mail aus als Standard)
// Benachrichtigung je Aktie (seit 04.10.2026): data.alertNotify[<Symbol>] = {mail, telegram}.
// Ohne Eintrag E-Mail aus, Telegram an - gleiche Vorgabe wie in check-alerts.php.
function alertNotifyOf(symbol){
  const n = (data.alertNotify||{})[symbol] || {};
  return { mail: n.mail === true, telegram: n.telegram !== false };
}
function toggleAlertNotify(symbol, art){
  data.alertNotify = data.alertNotify || {};
  const n = alertNotifyOf(symbol);
  n[art] = !n[art];
  data.alertNotify[symbol] = n;
  saveData();
  render();
  toast(`${symbol}: ${art==='mail'?'E-Mail':'Telegram'} ${n[art]?'an':'aus'}.`);
}
// Zwei kleine Schalter ✉️/📨 - an = gefuellt, aus = blass.
function alertNotifyButtonsHtml(symbol){
  const n = alertNotifyOf(symbol);
  const s = escapeHtml(symbol).replace(/'/g, "\\'");
  const b = (art, ic, an, titel) => `<button class="btn ghost" style="padding:2px 6px;font-size:12px;${an?'':'opacity:.35;filter:grayscale(1);'}" title="${titel}: ${an?'an':'aus'} – antippen zum Umschalten" onclick="event.stopPropagation();toggleAlertNotify('${s}','${art}')">${ic}</button>`;
  return b('mail','✉️',n.mail,'E-Mail') + b('telegram','📨',n.telegram,CAPOLIO_IS_ADMIN?'Telegram':'Telegram (nur Admin-Bot)');
}
