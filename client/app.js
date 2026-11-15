/* ValueLens - Verbindung zwischen Seite und Chart-Modul: lokales Depot laden/speichern, Watchlist, eigene Kaeufe,
   Kursalarme (Pruefung alle 15 Minuten, solange die Seite offen ist), taeglicher Lauf, Einstellungen.
   Stellt ausserdem bereit, was chart-modul.js aus der umgebenden App erwartet (escapeHtml, toast, data, saveData ...). */

const CSRF_TOKEN = document.querySelector('meta[name="csrf-token"]')?.content || window.CSRF_TOKEN || '';
const VL_WEB = document.querySelector('meta[name="vl-modus"]')?.content === 'web';   // in Kamikaze: kein Update, kein Nachtlauf, keine Einstellungen
const CAPOLIO_IS_ADMIN = true;   // lokal ist man selbst Admin (Telegram-Meldeweg)
let data = {positions:[], transactions:[], watchlist:[], priceAlerts:[], alertNotify:{}};
let _depotBasis = 0;

function escapeHtml(s){ return String(s ?? '').replace(/[&<>"']/g, c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }
function toast(msg, ms=3200){
  const d = document.createElement('div'); d.className = 'toast'; d.textContent = msg; document.body.appendChild(d);
  setTimeout(()=>d.remove(), ms);
}
function confirmDialog(message){ return Promise.resolve(window.confirm(String(message).replace(/<[^>]*>/g,''))); }
async function fetchYahooQuotes(){ return {}; }   // nur fuer die KI-Analysen-Liste (gibt es in ValueLens nicht)
function render(){ vlZeichnen(); }
function renderKiAnalysenPage(){} function renderDecisionsPage(){}
function kiSektor(a){ return (a && a.ddb && a.ddb.sector) || ''; }
function kiZahlenZelle(){ return ''; } function kiPocZelle(){ return ''; } function kiObZelle(){ return ''; }
async function kiHistorieLaden(){ return []; } async function kiNotizSpeichern(){}
function openPdfViewer(url){ window.open(url, '_blank', 'noopener'); }
const vlZahl = v=>{ const x = Number(String(v ?? '').replace(/\s/g,'').replace(',', '.')); return isFinite(x) ? x : null; };
const vlNum = (v, d=2)=>v==null || !isFinite(v) ? '–' : Number(v).toLocaleString('de-DE', {minimumFractionDigits:d, maximumFractionDigits:d});

// ---------- Depot laden / speichern (data/depot_lokal.json ueber depot.php) ----------
async function vlDepotLaden(){
  try{ const j = await (await fetch('depot.php', {cache:'no-store'})).json();
    data = Object.assign({positions:[], transactions:[], watchlist:[], priceAlerts:[], alertNotify:{}}, j || {});
    _depotBasis = data._updatedAt || 0;
  } catch(e){ toast('Depot konnte nicht geladen werden.'); }
  vlPositionen();
  window._depotGeladen = true;
}
let _vlSpeichern = null;
function saveData(){
  clearTimeout(_vlSpeichern);
  _vlSpeichern = setTimeout(async ()=>{
    try{ const r = await (await fetch('depot.php', {method:'POST', headers:{'Content-Type':'application/json', 'X-CSRF-Token':CSRF_TOKEN},
        body:JSON.stringify({...data, _basis:_depotBasis})})).json();
      if(r && r._updatedAt){ _depotBasis = r._updatedAt; data._updatedAt = r._updatedAt; } else toast('Speichern fehlgeschlagen.');
    } catch(e){ toast('Speichern fehlgeschlagen.'); }
    vlZeichnen();
  }, 250);
}

// Positionen aus den Kaeufen/Verkaeufen: Stueck, Durchschnittspreis in Kurswaehrung und (falls angegeben) in Euro
function vlPositionen(){
  const je = {};
  (data.transactions||[]).slice().sort((a,b)=>String(a.date).localeCompare(String(b.date))).forEach(t=>{
    const s = String(t.ticker||'').toUpperCase(); if(!s) return;
    const p = je[s] = je[s] || {ticker:s, name:t.name||'', sector:'', shares:0, kosten:0, kostenEur:0, mitEur:true, buyCurrency:t.currency||'USD'};
    const n = Number(t.shares)||0;
    if(t.type==='verkauf'){ const anteil = p.shares>0 ? Math.min(1, n/p.shares) : 0; p.kosten *= (1-anteil); p.kostenEur *= (1-anteil); p.shares -= n; return; }
    p.shares += n; p.kosten += n*(Number(t.priceOriginal)||0); if(t.price!=null && t.price!=='') p.kostenEur += n*Number(t.price); else p.mitEur = false;
    if(t.name) p.name = t.name; p.buyCurrency = t.currency || p.buyCurrency;
  });
  const alt = {}; (data.positions||[]).forEach(p=>{ alt[p.ticker] = p; });
  data.positions = Object.values(je).filter(p=>p.shares > 1e-9).map(p=>({ticker:p.ticker, name:p.name || (alt[p.ticker]||{}).name || '', sector:(alt[p.ticker]||{}).sector || '',
    shares:p.shares, buyPriceOriginal:p.kosten/p.shares, buyCurrency:p.buyCurrency, buyPrice: p.mitEur && p.kostenEur>0 ? p.kostenEur/p.shares : null,
    currentPrice:null, currentPriceOriginal:null}));
}

// ---------- Seiten ----------
function vlSeite(name){
  // Web: Menuepunkt der Kamikaze-Leiste (Watchlist = Reiter Watchlist & Filter, sonst Aktien) und Adresse mitfuehren
  document.querySelectorAll('[data-kk-key]').forEach(a=>a.classList.toggle('active', a.dataset.kkKey===(name==='liste' ? 'watchlist' : 'aktien')));
  if(VL_WEB && !location.hash.startsWith('#chart=')) history.replaceState(null, '', location.pathname + '?seite=' + encodeURIComponent(name));
  document.querySelectorAll('.nav').forEach(b=>b.classList.toggle('active', b.dataset.seite===name));
  document.querySelectorAll('.page').forEach(s=>s.classList.toggle('active', s.id==='page-'+name));
  try{ localStorage.setItem('valuelens_seite', name); } catch(e){}
  vlZeichnen();
}
function vlZeichnen(){
  vlPositionen();
  try{ renderAktienChartSeite(); } catch(e){}
  vlWatchlistZeichnen(); vlKaeufeZeichnen(); vlAlarmeZeichnen();
}

// ---------- Watchlist ----------
function vlWatchlistZeichnen(){
  const el = document.getElementById('wlListe'); if(!el) return;
  const wl = data.watchlist||[];
  el.innerHTML = wl.length ? wl.map(w=>`<span class="chip"><a href="#" data-ac-sym="${escapeHtml(w.ticker)}">${escapeHtml(w.ticker)}</a>${w.name ? ` <span class="muted">${escapeHtml(w.name)}</span>` : ''} <a href="#" class="weg" data-wl-weg="${escapeHtml(w.ticker)}" title="entfernen">✕</a></span>`).join('')
    : '<span class="muted klein">Noch leer – Symbole oben eintragen.</span>';
}
function vlWatchlistNeu(){
  const s = document.getElementById('wlSymbol'), n = document.getElementById('wlName');
  const syms = String(s.value||'').split(/[,;\s]+/).map(x=>x.trim().toUpperCase()).filter(x=>/^[A-Z0-9.\-^=]{1,20}$/.test(x));
  if(!syms.length){ toast('Bitte ein Symbol eingeben.'); return; }
  data.watchlist = data.watchlist||[];
  let neu = 0; syms.forEach(y=>{ if(!data.watchlist.some(w=>w.ticker===y)){ data.watchlist.push({ticker:y, symbol:y, name: syms.length===1 ? String(n.value||'').trim() : '', sector:''}); neu++; } });
  s.value = ''; n.value = ''; saveData(); toast(neu + ' Titel aufgenommen.');
}

// ---------- Eigene Kaeufe ----------
function vlKaeufeZeichnen(){
  const tab = document.getElementById('kfTab'); if(!tab) return;
  const tx = (data.transactions||[]).map((t,i)=>({...t, _i:i})).sort((a,b)=>String(b.date).localeCompare(String(a.date)));
  const pos = data.positions||[];
  tab.innerHTML = `<thead><tr><th>Datum</th><th>Symbol</th><th>Art</th><th>Stück</th><th>Preis</th><th>Preis €</th><th></th></tr></thead><tbody>${tx.map(t=>`<tr>
      <td>${escapeHtml(new Date(t.date+'T12:00:00Z').toLocaleDateString('de-DE'))}</td><td><a href="#" data-ac-sym="${escapeHtml(t.ticker)}">${escapeHtml(t.ticker)}</a></td>
      <td>${t.type==='verkauf'?'Verkauf':'Kauf'}</td><td>${vlNum(Number(t.shares), Number(t.shares)%1?4:0)}</td><td>${vlNum(Number(t.priceOriginal))} ${escapeHtml(t.currency||'')}</td>
      <td>${t.price!=null && t.price!=='' ? vlNum(Number(t.price))+' €' : '–'}</td><td><a href="#" class="weg" data-kf-weg="${t._i}" title="löschen">✕</a></td></tr>`).join('')
    || '<tr><td colspan="7" class="muted">Noch keine Käufe.</td></tr>'}</tbody>`
    + (pos.length ? `<tfoot><tr><td colspan="7" class="muted klein">Bestand: ${pos.map(p=>`${escapeHtml(p.ticker)} ${vlNum(p.shares, p.shares%1?4:0)} Stk. Ø ${vlNum(p.buyPriceOriginal)} ${escapeHtml(p.buyCurrency)}`).join(' · ')}</td></tr></tfoot>` : '');
}
function vlKaufNeu(){
  const g = id=>document.getElementById(id);
  const t = {date:g('kfDatum').value, ticker:String(g('kfSymbol').value||'').trim().toUpperCase(), type:g('kfArt').value,
    shares:vlZahl(g('kfStueck').value), priceOriginal:vlZahl(g('kfPreis').value), currency:g('kfWaehrung').value, price:vlZahl(g('kfEur').value)};
  if(!t.date || !/^[A-Z0-9.\-]{1,20}$/.test(t.ticker) || !(t.shares>0) || !(t.priceOriginal>0)){ toast('Bitte Symbol, Datum, Stück und Preis eintragen.'); return; }
  if(t.currency==='EUR' && t.price==null) t.price = t.priceOriginal;
  t.amount = t.shares*(t.price ?? t.priceOriginal);
  data.transactions = data.transactions||[]; data.transactions.push(t);
  ['kfSymbol','kfStueck','kfPreis','kfEur'].forEach(id=>{ g(id).value = ''; });
  saveData(); toast(`${t.type==='verkauf'?'Verkauf':'Kauf'} ${t.ticker} eingetragen.`);
}

// ---------- Kursalarme ----------
function vlAlarmeZeichnen(){
  const tab = document.getElementById('alTab'); if(!tab) return;
  const al = data.priceAlerts||[];
  tab.innerHTML = `<thead><tr><th>Symbol</th><th>Bedingung</th><th>Grenze</th><th>Art</th><th>Stand</th><th>Meldeweg</th><th></th></tr></thead><tbody>${al.map(a=>`<tr>
      <td><a href="#" data-ac-sym="${escapeHtml(a.symbol)}">${escapeHtml(a.symbol)}</a></td><td>${a.condition==='below'?'▼ unter':'▲ über'}</td><td>${vlNum(Number(a.targetPrice))}</td>
      <td class="klein">${escapeHtml(String(a.name||'').split(' · ').slice(-1)[0])}${a.dynamisch?' <span class="muted">(läuft mit)</span>':''}</td>
      <td>${a.fired ? '<span class="neg">ausgelöst</span>' : (a.active===false ? 'aus' : '<span class="pos">scharf</span>')}</td>
      <td>${vlMeldewegKnoepfe(a.symbol)}</td><td><a href="#" class="weg" data-al-weg="${escapeHtml(a.id)}" title="löschen">✕</a></td></tr>`).join('')
    || '<tr><td colspan="7" class="muted">Noch keine Alarme – im Chart über 🔔 Alarm setzen.</td></tr>'}</tbody>`;
}
// Meldeweg-Knoepfe ohne onclick (CSP): Aufruf steht in data-Attributen, Klick faengt valueLensStart ab
function vlMeldewegKnoepfe(sym){
  if(typeof alertNotifyButtonsHtml!=='function') return '';
  return alertNotifyButtonsHtml(sym).replace(/onclick="event\.stopPropagation\(\);toggleAlertNotify\('([^']*)','([^']*)'\)"/g, (m, s, a)=>`data-an-sym="${s}" data-an-art="${a}"`);
}
async function vlAlarmePruefen(still){
  const st = document.getElementById('alStatus');
  try{ const t = await (await fetch('alarm-pruefen.php', {cache:'no-store'})).text();
    const zeit = new Date().toLocaleTimeString('de-DE', {hour:'2-digit', minute:'2-digit'});
    if(st) st.textContent = `Letzte Prüfung ${zeit}: ${t.split('\n').pop() || 'ok'}`;
    try{ localStorage.setItem('valuelens_alarmzeit', String(Date.now())); } catch(e){}
    if(/ausgel[oö]st:\s*[1-9]/.test(t)){ await vlDepotLaden(); vlZeichnen(); if(!still) toast('Alarm ausgelöst – siehe Telegram.'); }
  } catch(e){ if(st) st.textContent = 'Prüfung fehlgeschlagen: ' + e.message; }
}
async function vlNachtlauf(manuell){
  try{ const s = await (await fetch('nachtlauf.php?nur=stand', {cache:'no-store'})).json();
    const heute = new Date().toISOString().slice(0,10);
    if(!manuell && s.letzter && String(s.letzter).slice(0,10)===heute) return;
    const r = await (await fetch('nachtlauf.php', {method:'POST', headers:{'X-CSRF-Token':CSRF_TOKEN}})).json();
    if(manuell) toast(r && r.ok ? 'Täglicher Lauf gestartet (läuft im Hintergrund).' : 'Start fehlgeschlagen.');
  } catch(e){ if(manuell) toast('Start fehlgeschlagen.'); }
}

// ---------- Einstellungen ----------
async function vlEinstellungenLaden(){
  try{ const e = await (await fetch('einstellungen.php', {cache:'no-store'})).json();
    document.getElementById('esMail').value = e.secMail || '';
    document.getElementById('esInfo').innerHTML = `PHP ${escapeHtml(e.php)} · curl ${e.curl?'✔':'✗'} · openssl ${e.openssl?'✔':'✗'}${!e.secMail ? ' · <b class="neg">Bitte Kontakt-Mail für die SEC eintragen.</b>' : ''}`;
    if(!e.secMail) toast('Einmalig: unter Einstellungen eine Kontakt-Mail für die SEC eintragen.', 6000);
  } catch(e){}
}
async function vlEinstellungenSpeichern(){
  const r = await (await fetch('einstellungen.php', {method:'POST', headers:{'Content-Type':'application/json', 'X-CSRF-Token':CSRF_TOKEN},
    body:JSON.stringify({secMail:document.getElementById('esMail').value})})).json();
  toast(r && r.ok ? 'Gespeichert.' : ('Fehler: ' + (r && r.error || '?'))); vlEinstellungenLaden();
}

// ---------- Update (neue Fassung von GitHub) ----------
async function vlUpdatePruefen(meldung){
  const el = document.getElementById('upInfo'), kn = document.getElementById('upHolen');
  try{ const u = await (await fetch('update.php', {cache:'no-store'})).json();
    if(u.fehler){ el.textContent = `Version ${u.lokal} · ${u.fehler}`; return; }
    if(u.update){ el.innerHTML = `Version ${escapeHtml(u.lokal)} · <b class="pos">neue Version ${escapeHtml(u.neu)} verfügbar</b>${u.git ? ' – im Ordner „git pull“ ausführen' : ''}`;
      kn.style.display = u.git ? 'none' : '';
      // Automatisch einspielen (Standard, abschaltbar unter Einstellungen) - wie beim Start ueblich
      if(meldung==='auto' && !u.git && vlAutoUpdate()){ toast(`ValueLens ${u.neu} wird automatisch eingespielt …`, 5000); return vlUpdateHolen(true); }
      if(meldung) toast(`ValueLens ${u.neu} ist verfügbar – Einstellungen → Update holen.`, 7000); }
    else { el.textContent = `Version ${u.lokal} · aktuell`; kn.style.display = 'none'; }
  } catch(e){ el.textContent = 'Update-Prüfung fehlgeschlagen.'; }
}
function vlAutoUpdate(){ try{ return localStorage.getItem('valuelens_autoupdate')!=='0'; } catch(e){ return true; } }
async function vlUpdateHolen(ohneFrage){
  if(ohneFrage!==true && !(await confirmDialog('Neue Fassung von GitHub laden und einspielen? Deine Daten bleiben erhalten.'))) return;
  const el = document.getElementById('upInfo'); el.textContent = 'Lade Update …';
  try{ const r = await (await fetch('update.php', {method:'POST', headers:{'X-CSRF-Token':CSRF_TOKEN}})).json();
    if(r.ok){ el.textContent = `Version ${r.version} eingespielt (${r.dateien} Dateien). ${r.hinweis||''} Seite lädt neu …`; toast(`ValueLens ${r.version} eingespielt.`, 4000); setTimeout(()=>location.reload(), 2500); }
    else el.textContent = 'Update fehlgeschlagen: ' + (r.error || '?');
  } catch(e){ el.textContent = 'Update fehlgeschlagen: ' + e.message; }
}

// ---------- Hilfe: Installation pruefen, Aenderungsliste ----------
async function vlPruefen(){
  const tab = document.getElementById('prTab'); tab.innerHTML = '<tr><td class="muted">prüfe … (bis 30 Sekunden)</td></tr>';
  try{ const r = await (await fetch('pruefen.php', {cache:'no-store'})).json();
    const wort = {gruen:'in Ordnung', gelb:'Hinweis', rot:'Fehler'};
    tab.innerHTML = `<tbody>${r.punkte.map(p=>`<tr><td><span class="ampel ${p.ampel}" title="${wort[p.ampel]}"></span>${escapeHtml(p.name)}</td><td>${escapeHtml(p.text)}</td></tr>`).join('')}</tbody>
      <tfoot><tr><td colspan="2"><span class="ampel ${r.gesamt}"></span><b>Gesamt: ${r.gesamt==='gruen'?'alles in Ordnung':(r.gesamt==='gelb'?'läuft, mit Hinweisen':'es gibt Fehler – siehe rot')}</b></td></tr></tfoot>`;
  } catch(e){ tab.innerHTML = '<tr><td class="neg">Prüfung fehlgeschlagen: ' + escapeHtml(e.message) + '</td></tr>'; }
}
function vlAenderungenHtml(liste){
  return liste.map(a=>`<div style="margin-bottom:10px;"><b>Version ${escapeHtml(a.version)}</b>${a.datum ? ` <span class="muted klein">· ${escapeHtml(a.datum)}</span>` : ''}<ul style="margin:4px 0 0 18px;padding:0;">${a.punkte.map(p=>`<li class="klein" style="margin:2px 0;">${escapeHtml(p).replace(/\*\*(.+?)\*\*/g, '<b>$1</b>').replace(/`(.+?)`/g, '<code>$1</code>')}</li>`).join('')}</ul></div>`).join('') || '<span class="muted klein">keine Einträge</span>';
}
async function vlNeuInVersion(){
  try{ const v = await (await fetch('version.php', {cache:'no-store'})).json();
    document.getElementById('neuListe').innerHTML = vlAenderungenHtml(v.aenderungen || []);
    let gesehen = null; try{ gesehen = localStorage.getItem('valuelens_version_gesehen'); } catch(e){}
    try{ localStorage.setItem('valuelens_version_gesehen', v.version); } catch(e){}
    if(gesehen===null){ vlSeite('hilfe'); toast('Willkommen bei ValueLens – hier die wichtigsten Fragen. Los geht es im Reiter „Aktie“.', 7000); return; }
    // nach einem Update: alles anzeigen, was seit der zuletzt gesehenen Version neu ist
    const neu = (v.aenderungen||[]).filter(a=>vlVersionGroesser(a.version, gesehen));
    if(neu.length){ document.getElementById('neuTitel').textContent = `Neu in ValueLens ${v.version}`;
      document.getElementById('neuInhalt').innerHTML = vlAenderungenHtml(neu); document.getElementById('neuOverlay').classList.add('show'); }
  } catch(e){}
}
function vlVersionGroesser(a, b){ const x = String(a).split('.').map(Number), y = String(b).split('.').map(Number);
  for(let i=0;i<Math.max(x.length,y.length);i++){ const d = (x[i]||0)-(y[i]||0); if(d) return d>0; } return false; }

// Chart als Seite statt Popup (Nutzer 15.11.2026: "Bewertung in die Webseite integrieren, nicht als Popup - so wie
// Capolio"): immer der Seitenmodus von "Eigener Tab" (volle Breite, Kennzahlen rechts); die Kopfleiste bleibt sichtbar,
// das Chartfenster beginnt unter ihr. Klick auf einen Reiter oben schliesst den Chart.
function vlChartAlsSeite(){
  if(typeof kiChartModal!=='function' || kiChartModal._vl) return;
  const orig = kiChartModal; let innen = false;
  kiChartModal = function(){
    const o = orig.apply(this, arguments);
    if(!innen && !o.classList.contains('ki-voll')){ innen = true; try{ kiChartVollbild(); } finally{ innen = false; } }
    return o;
  };
  kiChartModal._vl = true;
  const kopf = document.getElementById('vlOben') || document.querySelector('.kopf');
  const hoehe = ()=>document.documentElement.style.setProperty('--vl-kopf', (kopf ? kopf.offsetHeight : 0) + 'px');
  hoehe(); window.addEventListener('resize', hoehe);
}

// ---------- Start ----------
async function valueLensStart(){
  if(VL_WEB) document.body.classList.add('vl-web');
  vlChartAlsSeite();
  document.addEventListener('click', e=>{
    // Web: Watchlist/Aktien in der Kamikaze-Leiste ohne Neuladen umschalten
    const kk = e.target.closest('[data-kk-seite]'); if(kk && !e.ctrlKey && !e.metaKey){ e.preventDefault(); if(typeof kiChartSchliessen==='function' && document.getElementById('kiChartOverlay')?.classList.contains('show')){ kiChartSchliessen(); history.replaceState(null, '', location.pathname); } vlSeite(kk.dataset.kkSeite); return; }
    const n = e.target.closest('.nav'); if(n){ if(typeof kiChartSchliessen==='function' && document.getElementById('kiChartOverlay')?.classList.contains('show')){ kiChartSchliessen(); if(location.hash.startsWith('#chart=')) history.replaceState(null, '', location.pathname); } vlSeite(n.dataset.seite); return; }
    const w = e.target.closest('[data-wl-weg]'); if(w){ e.preventDefault(); data.watchlist = (data.watchlist||[]).filter(x=>x.ticker!==w.dataset.wlWeg); saveData(); return; }
    const k = e.target.closest('[data-kf-weg]'); if(k){ e.preventDefault(); const i = Number(k.dataset.kfWeg); const t = data.transactions[i];
      confirmDialog(`${t.ticker} vom ${t.date} löschen?`).then(ok=>{ if(ok){ data.transactions.splice(i,1); saveData(); } }); return; }
    const mw = e.target.closest('[data-an-sym]'); if(mw){ e.preventDefault(); e.stopPropagation(); toggleAlertNotify(mw.dataset.anSym, mw.dataset.anArt); vlAlarmeZeichnen(); return; }
    const a = e.target.closest('[data-al-weg]'); if(a){ e.preventDefault(); data.priceAlerts = (data.priceAlerts||[]).filter(x=>x.id!==a.dataset.alWeg); saveData(); return; }
    // Klicks auf [data-ac-sym] (Symbol oeffnen) behandelt das Chart-Modul selbst (Weiche auf <main id="page-aktienchart">)
  });
  document.getElementById('wlNeu').addEventListener('click', vlWatchlistNeu);
  document.getElementById('wlSymbol').addEventListener('keydown', e=>{ if(e.key==='Enter') vlWatchlistNeu(); });
  document.getElementById('kfNeu').addEventListener('click', vlKaufNeu);
  document.getElementById('kfDatum').value = new Date().toISOString().slice(0,10);
  document.getElementById('alPruefen').addEventListener('click', ()=>vlAlarmePruefen(false));
  document.getElementById('nlStarten')?.addEventListener('click', ()=>vlNachtlauf(true));
  document.getElementById('esSpeichern')?.addEventListener('click', vlEinstellungenSpeichern);
  document.getElementById('upHolen')?.addEventListener('click', vlUpdateHolen);
  document.getElementById('prStarten')?.addEventListener('click', vlPruefen);
  document.getElementById('neuZu')?.addEventListener('click', ()=>document.getElementById('neuOverlay').classList.remove('show'));
  // Telegram-Oberflaeche
  try{ const h = await (await fetch('client/telegram-einrichtung.html')).text(); document.getElementById('tgBox').innerHTML = h; telegramEinrichtungStarten(); } catch(e){}
  await vlDepotLaden();
  let s = 'aktie'; try{ s = localStorage.getItem('valuelens_seite') || 'aktie'; } catch(e){}
  const sp = new URLSearchParams(location.search).get('seite'); if(sp) s = sp;
  if(location.hash.startsWith('#chart=')) s = 'aktie';
  vlSeite(document.getElementById('page-'+s) ? s : 'aktie');
  if(!VL_WEB){ vlEinstellungenLaden(); vlNeuInVersion(); }
  // Kursalarme alle 15 Minuten, solange die Seite offen ist; beim Start, wenn die letzte Pruefung laenger her ist
  let letzte = 0; try{ letzte = Number(localStorage.getItem('valuelens_alarmzeit')||0); } catch(e){}
  if(Date.now() - letzte > 15*60*1000) setTimeout(()=>vlAlarmePruefen(true), 4000);
  setInterval(()=>vlAlarmePruefen(true), 15*60*1000);
  if(!VL_WEB) setTimeout(()=>vlNachtlauf(false), 20000);   // einmal am Tag (lokal; im Web laeuft er auf dem Server)
  // Update-Hinweis: hoechstens einmal am Tag als Meldung, Anzeige unter Einstellungen immer
  let upTag = ''; try{ upTag = localStorage.getItem('valuelens_updatetag') || ''; } catch(e){}
  const heute = new Date().toISOString().slice(0,10);
  if(!VL_WEB) setTimeout(()=>{ vlUpdatePruefen(vlAutoUpdate() ? 'auto' : upTag!==heute); try{ localStorage.setItem('valuelens_updatetag', heute); } catch(e){} }, 3000);
  const au = document.getElementById('upAuto'); if(au){ au.checked = vlAutoUpdate(); au.addEventListener('change', ()=>{ try{ localStorage.setItem('valuelens_autoupdate', au.checked ? '1' : '0'); } catch(e){} }); }
  document.getElementById('upPruefen')?.addEventListener('click', ()=>vlUpdatePruefen(true));
}

// Start ohne Inline-Skript (CSP): nach dem Laden aller Skripte
document.addEventListener('DOMContentLoaded', valueLensStart);
