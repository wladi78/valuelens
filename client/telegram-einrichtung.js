// Admin-Oberflaeche Telegram (gehoert zu telegram-einrichtung.html). Nach dem Laden einmal aufrufen: telegramEinrichtungStarten();
function telegramEinrichtungStarten(){
  // --- Telegram-Einrichtung (telegram-api.php) ---
  const TG_ERROR_TEXT = {
    invalid_token: 'Der Token sieht nicht richtig aus - bitte komplett aus @BotFather kopieren.',
    invalid_chat_id: 'Chat-ID fehlt oder ist ungültig - bitte "Chat-ID ermitteln" drücken.',
    write_failed: 'Konnte nicht gespeichert werden (Datenordner nicht beschreibbar).',
    not_configured: 'Telegram ist noch nicht eingerichtet.',
  };
  const TG_CHANNELS = {kursalarm:'Kursalarme', analysen:'Bewertungs-Meldungen (unterbewertete Titel)', waechter:'Allgemein (Ersatz, wenn ein Kanal fehlt)'};
  const tgChannel = () => document.getElementById('tgChannel')?.value || 'kursalarm';
  async function tgCall(action, params, channel){
    const ch = channel || tgChannel();
    const body = new URLSearchParams({action, channel: ch, csrf: CSRF_TOKEN, ...(params||{})});
    let data;
    try { data = await (await fetch(action==='get' ? 'telegram-api.php?action=get&channel=' + encodeURIComponent(ch) : 'telegram-api.php', {method: action==='get' ? 'GET' : 'POST', body: action==='get' ? undefined : body})).json(); }
    catch(e){ data = {error:'parse_error'}; }
    if(data.error){ toast(data.message || TG_ERROR_TEXT[data.error] || adminErrorText(data.error)); return null; }
    return data;
  }
  async function tgRefreshStatus(){
    const el = document.getElementById('tgStatus');
    if(!el) return;
    const zeilen = await Promise.all(Object.entries(TG_CHANNELS).map(async ([ch, label]) => {
      const d = await tgCall('get', null, ch);
      return `<b>${escapeHtml(label)}:</b> ` + (!d ? '–' : (d.configured ? `✅ verbunden (Chat ${escapeHtml(String(d.chatId))})` : d.ersatz ? `↪ über ${escapeHtml(d.ersatz.label.replace(/ \(.*$/,''))}-Bot (Chat ${escapeHtml(String(d.ersatz.chatId))})` : '✗ nicht eingerichtet'));
    }));
    el.innerHTML = zeilen.join('<br>');
  }
  document.getElementById('tgFindBtn')?.addEventListener('click', async ()=>{
    const d = await tgCall('find_chat', {botToken: document.getElementById('tgToken').value.trim()});
    if(d){ document.getElementById('tgChatId').value = d.chatId; toast(`Chat gefunden${d.name ? ': ' + d.name : ''}`); }
  });
  document.getElementById('tgSaveBtn')?.addEventListener('click', async ()=>{
    const d = await tgCall('save', {botToken: document.getElementById('tgToken').value.trim(), chatId: document.getElementById('tgChatId').value.trim()});
    if(d){
      document.getElementById('tgToken').value = '';
      toast(d.testSent ? 'Gespeichert - Testnachricht ist unterwegs.' : ('Gespeichert, aber Test fehlgeschlagen: ' + (d.message || '')));
      tgRefreshStatus();
    }
  });
  document.getElementById('tgTestBtn')?.addEventListener('click', async ()=>{ if(await tgCall('test')) toast('Testnachricht gesendet.'); });
  document.getElementById('tgDeleteBtn')?.addEventListener('click', async ()=>{
    if(!(await confirmDialog(`Telegram-Zugang für „${TG_CHANNELS[tgChannel()]}" wirklich entfernen? Es kommen dann dort keine Signale mehr.`, {danger:true, okLabel:'Entfernen'}))) return;
    if(await tgCall('delete')){ toast('Entfernt.'); tgRefreshStatus(); }
  });
  tgRefreshStatus();
}
