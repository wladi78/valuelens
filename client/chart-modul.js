// Zonen-Chart (Nutzerwunsch 05.11.2026): Monats-, Wochen- oder Tageskerzen mit den Zonen der letzten KI-Analyse,
// gezeichnet mit Lightweight Charts von TradingView (frei, Apache-2.0). Die Bibliothek wird erst beim ersten Oeffnen
// geladen. Kerzen kommen ueber quote.php?ohlc=1 von Yahoo; Zonen, Fair Value, Support/Widerstand und Order Block
// aus der Analyse - immer die aktuellste, ohne Kopieren.
const KI_CHART_LIB = 'https://cdn.jsdelivr.net/npm/lightweight-charts@4.2.3/dist/lightweight-charts.standalone.production.js';
let _kiChart = null, _kiChartLibPromise = null;
// Institutionelle Order Blocks (Smart-Money-Lesart, Nutzerwunsch 05.11.2026), aus den Kerzen berechnet:
// Bullisch = letzte rote Kerze vor einem kraeftigen Anstieg (Displacement: Schluss innerhalb von 3 Kerzen mehr als
// 1 ATR ueber ihrem Hoch) der das Hoch der 10 Kerzen davor bricht (Break of Structure). Bearisch spiegelbildlich.
// "Institutionell" heisst: das Volumen der Ausbruchskerzen liegt mindestens 1,5-mal ueber dem Schnitt der 20 Kerzen
// davor. Ein Block gilt als abgearbeitet, sobald spaeter ein Schluss auf der anderen Seite liegt (bullisch unter dem
// Tief, bearisch ueber dem Hoch). Gezeigt werden die naechsten zwei offenen unter bzw. ueber dem Kurs.
function kiOrderBlocks(k){
  const n = k.length, O=1, H=2, L=3, C=4, V=5, out = [];
  if(n < 15) return out;
  const atr = i=>{ let s=0, m=0; for(let j=Math.max(0,i-14); j<i; j++){ s += k[j][H]-k[j][L]; m++; } return m ? s/m : 0; };
  const vol = (a,b)=>{ let s=0, m=0; for(let j=Math.max(0,a); j<b; j++){ if(k[j][V]>0){ s+=k[j][V]; m++; } } return m ? s/m : 0; };
  for(let i=10; i<n-1; i++){
    const a = atr(i); if(!(a>0)) continue;
    const bis = Math.min(n, i+4);
    const vorHoch = Math.max(...k.slice(i-10, i).map(x=>x[H])), vorTief = Math.min(...k.slice(i-10, i).map(x=>x[L]));
    const nachVol = Math.max(...k.slice(i+1, bis).map(x=>x[V]||0)), schnitt = vol(i-20, i);
    const vf = schnitt>0 ? nachVol/schnitt : null;
    if(k[i][C] < k[i][O]){
      const s = k.slice(i+1, bis);
      if(s.some(x=>x[C]-k[i][H] > a) && Math.max(...s.map(x=>x[C])) > vorHoch)
        out.push({art:'bull', t:k[i][0], i, tief:k[i][L], hoch:k[i][H], vf});
    } else if(k[i][C] > k[i][O]){
      const s = k.slice(i+1, bis);
      if(s.some(x=>k[i][L]-x[C] > a) && Math.min(...s.map(x=>x[C])) < vorTief)
        out.push({art:'bear', t:k[i][0], i, tief:k[i][L], hoch:k[i][H], vf});
    }
  }
  const offen = out.filter(b=>!k.slice(b.i+4).some(x=> b.art==='bull' ? x[C] < b.tief : x[C] > b.hoch));
  const kurs = k[n-1][C];
  const bull = offen.filter(b=>b.art==='bull' && b.hoch<=kurs*1.02).sort((x,y)=>y.hoch-x.hoch).slice(0,2);
  const bear = offen.filter(b=>b.art==='bear' && b.tief>=kurs*0.98).sort((x,y)=>x.tief-y.tief).slice(0,2);
  return [...bull, ...bear].map(b=>({...b, inst: b.vf!=null && b.vf>=1.5}));
}
function kiChartLib(){
  if(window.LightweightCharts) return Promise.resolve();
  if(!_kiChartLibPromise) _kiChartLibPromise = new Promise((ok, fehler)=>{
    const s = document.createElement('script'); s.src = KI_CHART_LIB; s.onload = ok;
    s.onerror = ()=>{ _kiChartLibPromise = null; fehler(new Error('Chart-Bibliothek nicht geladen')); };
    document.head.appendChild(s);
  });
  return _kiChartLibPromise;
}
// Ebenen des Charts, je einzeln ein-/ausschaltbar (Nutzerwunsch 05.11.2026: "im Chart Ein/Aus fuer jeden, mehrere Tabs").
// Die drei Tabs setzen nur sinnvolle Voreinstellungen, jede Ebene laesst sich danach frei schalten.
const KI_EBENEN = [
  ['kz',     'KI-Kaufzonen'], ['sb', 'KI Strong Buy'], ['fv', 'KI Fair Value'], ['sr', 'Support/Widerstand'], ['ob', 'Order Blocks'],
  ['kauf',   'Käufe + Einstand'], ['fvl', 'Fair Value KGV 15'], ['npe', 'Normal-KGV'], ['divfl', 'Dividendenfläche'], ['cvr', 'Eigenes KGV'], ['por', 'Ausschüttungsquote %'], ['split', 'Splits'], ['divdekl', 'Dividendenmarken'],
  ['prog',   'Schätzung'], ['faecher', 'Prognose-Fächer'], ['rendite', 'Div.-Rendite %'], ['div3', '3 %-Linie'], ['divhist', 'Dividendenbalken'], ['rez', 'Rezessionen'],
  ['struktur', 'Struktur HH/HL'], ['pd', 'Premium/Discount'], ['tiefs', 'Monatstiefs'], ['pivots', 'Pivots'], ['elder', 'Elder Impulse'], ['osz', 'Oszillatoren'],
];
const KI_TABS = {
  zonen: {name:'Zonen',     an:['kz','sb','fv','sr','ob','kauf','fvl']},
  bewertung: {name:'Bewertung', an:['fvl','npe','divfl','prog','rez','kauf','rendite','fv','sb','split']},   // Faecher aus nach Vorlage (Uebersicht)
  fitness: {name:'Fitness', an:[]},   // Finanzielle Fitness + Finanzzahlen ohne Kurschart nach Vorlage "Fiscal fitness")
  div:   {name:'Dividende', an:['div3','divhist','rendite','kauf','divfl']},
  analyse: {name:'Analyse', an:['ob','struktur','pd','tiefs','pivots','osz','kauf']},
};
// Wendepunkte: Hoch/Tief, das n Kerzen links und rechts uebertrifft. Beschriftung gegenueber dem vorigen gleichen Typ:
// HH/LH (hoeheres/tieferes Hoch), HL/LL (hoeheres/tieferes Tief).
function kiSwings(k, n){
  const out = []; let vorH = null, vorL = null;
  for(let i=n; i<k.length-n; i++){
    let hoch = true, tief = true;
    for(let j=i-n; j<=i+n; j++){ if(j===i) continue; if(k[j][2] >= k[i][2]) hoch = false; if(k[j][3] <= k[i][3]) tief = false; }
    if(hoch){ out.push({i, t:k[i][0], typ:'H', preis:k[i][2], label: vorH==null ? 'H' : (k[i][2]>vorH ? 'HH' : 'LH')}); vorH = k[i][2]; }
    if(tief){ out.push({i, t:k[i][0], typ:'L', preis:k[i][3], label: vorL==null ? 'L' : (k[i][3]>vorL ? 'HL' : 'LL')}); vorL = k[i][3]; }
  }
  return out;
}
function kiEma(werte, n){ const a = 2/(n+1); let e = null; return werte.map(v=>{ if(v==null) return e; e = e==null ? v : v*a + e*(1-a); return e; }); }
let _kiTab = 'zonen', _kiEbene = {}, _kiFcModus = 'schaetzung';
// Eigenes KGV (Vorlage "Custom Val. Ratio") und eigene Prognose (Vorlage Forecasting "Custom"), im Browser gemerkt
let _kiCvr = (()=>{ try{ return Number(localStorage.getItem('kiCvr')) || 20; } catch(e){ return 20; } })();
let _kiFcEigen = (()=>{ try{ const x = JSON.parse(localStorage.getItem('kiFcEigen')||'null'); return x && x.pe>0 ? x : {g:8, pe:18}; } catch(e){ return {g:8, pe:18}; } })();
let _kiDivMarker = [], _kiSplitMarker = [];
let _kiVergleich = (()=>{ try{ return localStorage.getItem('kiVergleich') || 'SPY'; } catch(e){ return 'SPY'; } })();
document.addEventListener('change', e=>{
  const t = e.target; if(!t || !t.matches) return;
  const zahl = v=>Number(String(v).replace(',', '.'));
  if(t.matches('[data-ki-vergleich]')){ const v = String(t.value||'').trim().toUpperCase().replace(/[^A-Z0-9.\-^=]/g,'') || 'SPY'; _kiVergleich = v; try{ localStorage.setItem('kiVergleich', v); } catch(x){}
    if(_kiPerfArg) kiPerfZeichnen(..._kiPerfArg); return; }
  if(t.matches('[data-ki-cvr]')){ const v = zahl(t.value); if(v>0){ _kiCvr = v; try{ localStorage.setItem('kiCvr', v); } catch(x){} } }
  else if(t.matches('[data-ki-fc-g],[data-ki-fc-pe]')){ const v = zahl(t.value); if(!isFinite(v)) return; if(t.matches('[data-ki-fc-g]')) _kiFcEigen.g = v; else if(v>0) _kiFcEigen.pe = v; try{ localStorage.setItem('kiFcEigen', JSON.stringify(_kiFcEigen)); } catch(x){} }
  else return;
  if(_kiChart && _kiChart.ctx) chartZeichnen(_kiChart.ctx, _kiChart.interval || '1mo');
});
// Marken auf der oberen Kurslinie im Bewertungs-Reiter: Kaeufe, Dividenden, Splits (sortiert, LWC verlangt aufsteigende Zeit)
function kiObenMarker(){
  if(!_kiKursOben) return;
  const m = [..._kiKaufMarker, ..._kiDivMarker, ..._kiSplitMarker].sort((a,b)=>a.time<b.time?-1:(a.time>b.time?1:0));
  try{ _kiKursOben.setMarkers(m); } catch(e){}
  kiKaufBoxen();
}
// Eigene Kaeufe als kleine Kaesten im Bewertungs-Chart (Nutzer 14.11.2026: "Fenster mit Datum und Preis und Durchschnitt
// aus allen"): je Kauf Datum + Preis in Kurswaehrung unter dem Punkt, oben links der Durchschnittspreis aller Kaeufe.
// Die Kaesten folgen dem Zeitraum (Neuzeichnen bei jeder Bereichsaenderung); nahe beieinander werden sie gestaffelt.
let _kiEinstand = null, _kiKursChart = null, _kiKursAm = {}, _kiKaufAnker = null; const _kiBoxAbo = new WeakSet();
// Wechselkurse je Monat (EURUSD=X usw.) fuer Euro-Buchungen ohne Originalpreis: Preis zum Kaufdatum statt zum heutigen Kurs
const _kiFxMonat = {};
function kiFxLaden(w){
  if(_kiFxMonat[w]) return _kiFxMonat[w];
  _kiFxMonat[w] = {laedt:true};
  fetch('quote.php?ohlc=1&interval=1mo&range=max&symbols=' + encodeURIComponent('EUR' + w + '=X'), {cache:'no-store'}).then(r=>r.json()).then(q=>{
    const m = {}; (q && q.kerzen || []).forEach(k=>{ m[k[0].slice(0,7)] = k[4]; }); _kiFxMonat[w] = {m}; kiKaufBoxen(); }).catch(()=>{ _kiFxMonat[w] = {m:{}}; });
  return _kiFxMonat[w];
}
// Kaufpreis in Kurswaehrung: Originalpreis, sonst Euro-Preis x Wechselkurs des Kaufmonats (sobald geladen)
function kiKaufPreisKurs(k){
  if(k.preis!=null) return k.preis;
  const w = (_kiEinstand && _kiEinstand.w) || ''; if(k.eur==null || !/^[A-Z]{3}$/.test(w) || w==='EUR') return null;
  const fx = kiFxLaden(w); if(!fx || !fx.m) return null;
  const mon = String(k.date).slice(0,7); let kurs = fx.m[mon];
  if(kurs==null){ const v = Object.keys(fx.m).filter(x=>x<=mon).sort().pop(); kurs = v ? fx.m[v] : null; }
  return kurs>0 ? k.eur*kurs : null;
}
function kiKaufBoxen(){
  const cont = document.getElementById('kiChartBox'); if(!cont) return;
  cont.querySelectorAll('.ki-kaufbox').forEach(e=>e.remove());
  // Anker: im Reiter Bewertung die obere Kurslinie, sonst die Kursreihe (Kerzen/Linie) - Nutzer 14.11.2026 "in allen Reitern"
  const anker = _kiTab==='bewertung' ? _kiKursOben : _kiKaufAnker;
  if(!anker || !_kiKursChart || _kiTab==='fitness' || !_kiEbene.kauf) return;
  const ts = _kiKursChart.timeScale(), wRoh = (_kiEinstand && _kiEinstand.w) || '', wk = escapeHtml(wRoh);
  const fxW = /^[A-Z]{3}$/.test(wRoh) && wRoh!=='EUR' ? wRoh : null;
  const fx = fxW && Object.values(_kiKaufInfo).some(l=>l.some(k=>k.preis==null && k.eur!=null)) ? kiFxLaden(fxW) : null;
  const umr = (eur, datum)=>{ if(!fx || !fx.m || eur==null) return null; const mon = String(datum).slice(0,7); let k = fx.m[mon];
    if(k==null){ const v = Object.keys(fx.m).filter(x=>x<=mon).sort().pop(); k = v ? fx.m[v] : null; } return k>0 ? eur*k : null; };
  if(!_kiBoxAbo.has(_kiKursChart)){ _kiBoxAbo.add(_kiKursChart); try{ ts.subscribeVisibleLogicalRangeChange(()=>requestAnimationFrame(kiKaufBoxen)); ts.subscribeSizeChange(()=>requestAnimationFrame(kiKaufBoxen)); } catch(e){} }
  const stil = 'position:absolute;z-index:4;pointer-events:none;font-size:10.5px;line-height:1.3;padding:2px 6px;background:var(--card);border-radius:6px;box-shadow:0 1px 4px rgba(0,0,0,.18);white-space:nowrap;';
  const eintr = Object.entries(_kiKaufInfo).sort((a,b)=>a[0]<b[0]?-1:1);
  let letzteX = -999, letzteUnten = -999, n = 0;
  eintr.forEach(([t, l])=>{
    n += l.length;
    // Preis in Kurswaehrung; bei Euro-Buchungen ohne Originalpreis der Euro-Preis, Kasten dann am Monatskurs
    const pr = l.map(k=>k.preis!=null ? k.preis : umr(k.eur, k.date)).filter(v=>v!=null), pe = l.map(k=>k.eur).filter(v=>v!=null);
    const preis = pr.length ? pr.reduce((a,b)=>a+b,0)/pr.length : null, eur = pe.length ? pe.reduce((a,b)=>a+b,0)/pe.length : null;
    const ausEur = l.every(k=>k.preis==null) && preis!=null;   // in Kurswaehrung umgerechnet (Wechselkurs des Kaufmonats)
    if(preis==null && eur==null) return;
    const x = ts.timeToCoordinate(t), y = anker.priceToCoordinate(preis!=null ? preis : (_kiKursAm[t] ?? 0));
    if(x==null || y==null || x < 0 || x > cont.clientWidth - 40) return;
    const nah = x - letzteX < 140; letzteX = x;
    const d = document.createElement('div'); d.className = 'ki-kaufbox';
    const datum = l.map(k=>new Date(k.date+'T12:00:00Z').toLocaleDateString('de-DE')).join(', ');
    d.innerHTML = `<b style="color:#db2777;">Kauf</b> ${escapeHtml(datum)}<br>${preis!=null ? kiNum(preis) + ' ' + wk + (ausEur && eur!=null ? ' <span class="muted">(' + kiNum(eur) + ' €)</span>' : '') : kiNum(eur) + ' €'}${l.length>1 ? ' · ' + l.length + ' Käufe' : ''}`;
    // dicht beieinander: unter den vorigen Kasten statt darueber (Nutzer 14.11.2026, AMGN 10/2021 + 01/2022 + 02/2022)
    const oben = Math.round(Math.min(nah ? Math.max(y + 10, letzteUnten + 3) : y + 10, cont.clientHeight - 36));
    d.style.cssText = stil + `border:1px solid #db2777;left:${Math.round(x - 8)}px;top:${oben}px;`;
    cont.appendChild(d); letzteUnten = oben + d.offsetHeight;
    // Zeichenflaeche endet vor der Kursachse (ts.width()); ragt der Kasten hinein, links neben den Punkt setzen
    const flaeche = (()=>{ try{ return ts.width(); } catch(e){ return cont.clientWidth - 70; } })();
    if(x - 8 + d.offsetWidth > flaeche - 4) d.style.left = Math.max(4, Math.round(x - d.offsetWidth - 10)) + 'px';
  });
  if(_kiEinstand && _kiEinstand.wert!=null){
    const d = document.createElement('div'); d.className = 'ki-kaufbox';
    // Euro-Buchungen: Durchschnitt nach Stueckzahl mit dem Wechselkurs des jeweiligen Kaufmonats (statt heutigem Kurs)
    let wert = _kiEinstand.wert, hinweis = _kiEinstand.umgerechnet ? ' (umgerechnet)' : '';
    if(_kiEinstand.umgerechnet && fx && fx.m){ let st = 0, su = 0, ok = true;
      Object.values(_kiKaufInfo).forEach(l=>l.forEach(k=>{ const u = k.preis!=null ? k.preis : umr(k.eur, k.date); if(u==null || !(k.shares>0)) ok = false; else { st += k.shares; su += k.shares*u; } }));
      if(ok && st>0){ wert = su/st; hinweis = ' (Kurs zum Kaufdatum)'; } }
    d.innerHTML = `<b style="color:#ea580c;">Ø Kaufpreis</b> ${kiNum(wert)} ${wk}${_kiEinstand.eur!=null ? ' · ' + kiNum(_kiEinstand.eur) + ' €' : ''}${hinweis} <span class="muted">· aus ${n} Käufen</span>`;
    d.style.cssText = stil + 'border:1px solid #ea580c;left:8px;top:8px;';
    cont.appendChild(d);
  }
}
let _kiStil = 'kerzen', _kiJahre = null, _kiBasis = 'operativ', _kiBasisManuell = false, _kiSbcRaus = true, _kiFvr = 15;
// Bezugsgroessen fuer den Bewertungs-Chart, alle aus freien Daten (bewertung-reihen.php). Bereinigte Gewinne gibt es frei
// nicht - "operativ" ist die eigene Naeherung daran.
const KI_BASIS = {
  operativ: {kurz:'Gewinn operativ', lang:'operativer Gewinn je Aktie', info:'(Betriebsergebnis − Zinsen) × (1 − normaler Steuersatz) / Aktien – ohne Sondereffekte unterhalb des Betriebsergebnisses, Näherung an bereinigte Gewinne'},
  gaap:     {kurz:'Gewinn GAAP', lang:'Gewinn je Aktie (GAAP)', info:'Gewinn je Aktie laut Jahresbericht, inkl. Sondereffekte wie Abschreibungen'},
  fcf:      {kurz:'Free Cashflow', lang:'Free Cashflow je Aktie', info:'(operativer Cashflow − Investitionen) / Aktien'},
  ocf:      {kurz:'Op. Cashflow', lang:'operativer Cashflow je Aktie', info:'operativer Cashflow / Aktien'},
  ebitda:   {kurz:'EBITDA', lang:'EBITDA je Aktie', info:'(Betriebsergebnis + Abschreibungen) / Aktien, SEC-Jahresberichte; Fair-Value-Faktor = normales Vielfaches'},
  ebit:     {kurz:'EBIT', lang:'EBIT (Betriebsergebnis) je Aktie', info:'Betriebsergebnis / Aktien, SEC-Jahresberichte; Fair-Value-Faktor = normales Vielfaches'},
  umsatz:   {kurz:'Umsatz', lang:'Umsatz je Aktie', info:'Umsatz / Aktien, SEC-Jahresberichte; Fair-Value-Faktor = normales Kurs-Umsatz-Verhältnis'},
};
const _kiReihenCache = {};
async function kiReihen(sym){
  if(_kiReihenCache[sym]) return _kiReihenCache[sym];
  const j = await (await fetch('bewertung-reihen.php?symbol=' + encodeURIComponent(sym), {cache:'no-store'})).json();
  _kiReihenCache[sym] = j; return j;
}
function kiTabSetzen(tab){ _kiTab = tab; _kiEbene = {}; KI_EBENEN.forEach(([k])=>_kiEbene[k] = KI_TABS[tab].an.includes(k)); _kiStil = tab==='bewertung' ? 'linie' : 'kerzen'; _kiJahre = tab==='bewertung' ? 'gewinn' : null; }
kiTabSetzen('zonen');
function kiChartModal(){
  let o = document.getElementById('kiChartOverlay');
  if(o) return o;
  o = document.createElement('div'); o.className = 'modal-overlay'; o.id = 'kiChartOverlay';
  o.innerHTML = `<div class="modal" style="max-width:1180px;width:97vw;">
    <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:6px;">
      <b id="kiChartTitel" style="font-size:15px;"></b>
      <input id="kiChartSuche" list="kiChartSymbole" placeholder="Andere Aktie: Symbol oder Name …" title="Jede Aktie: Depot, KI-Analysen, Watchlist oder ein beliebiges Yahoo-Symbol (z. B. KO, SIE.DE)" style="width:220px;padding:4px 8px;border:1px solid var(--border);border-radius:8px;background:var(--card);color:var(--text);font-size:12.5px;">
      <datalist id="kiChartSymbole"></datalist>
      <span style="flex:1;"></span>
      ${Object.entries(KI_TABS).map(([k,t])=>`<button type="button" class="range-btn" data-ki-tab="${k}">${t.name}</button>`).join('')}
      <span style="width:10px;"></span>
      <button type="button" class="range-btn" data-ki-iv="1mo">Monat</button>
      <button type="button" class="range-btn" data-ki-iv="1wk">Woche</button>
      <button type="button" class="range-btn" data-ki-iv="1d">Tag</button>
      <button type="button" class="btn ghost" data-ki-alarm-menue="1" title="Kursalarm für diese Aktie setzen (bleibt gespeichert, Meldung per Telegram)" style="padding:4px 10px;">🔔 Alarm</button>
      <button type="button" class="btn ghost" data-ki-neu-tab="1" title="Chart als ganze Seite in einem neuen Browser-Tab öffnen" style="padding:4px 10px;">↗ Eigener Tab</button>
      <button type="button" class="btn ghost" data-ki-chart-zu="1" style="padding:4px 10px;">✕</button>
    </div>
    <div id="kiAlarmPanel" style="display:none;border:1px solid var(--border);border-radius:8px;padding:8px 10px;margin-bottom:8px;font-size:12.5px;"></div>
    <div id="kiChartLeiste" style="display:flex;flex-wrap:wrap;gap:4px;align-items:center;margin-bottom:8px;">
      <button type="button" class="range-btn" data-ki-stil="kerzen">Kerzen</button><button type="button" class="range-btn" data-ki-stil="linie">Linie</button>
      <span style="width:10px;"></span>
      <!-- Klappmenue nach Vorlage "Price Correlated With" (Nutzer 07.11.2026): Automatik-Schalter, Gruppen Gewinn/Cashflow -->
      <span style="position:relative;display:inline-block;">
        <button type="button" class="range-btn" data-ki-basis-menue="1" style="padding:5px 10px;"><span class="muted">Bewertet nach:</span> <b id="kiBasisName">Gewinn operativ</b> ▾</button>
        <div id="kiBasisMenue" style="display:none;position:absolute;z-index:60;top:calc(100% + 4px);left:0;min-width:290px;background:var(--card);color:var(--text);border:1px solid var(--border);border-radius:10px;box-shadow:0 8px 24px rgba(0,0,0,.18);padding:6px 0;font-size:12.5px;">
          <label style="display:flex;justify-content:space-between;align-items:center;gap:10px;padding:7px 14px;cursor:pointer;"><span><b>Automatisch</b> <span class="muted">(passend zur Branche)</span></span><input type="checkbox" data-ki-basis-auto="1"></label>
          <div class="ki-bm-gruppe">GEWINN</div>
          <div class="ki-bm-zeile" data-ki-basis="operativ" title="${KI_BASIS.operativ.info}">Gewinn operativ (bereinigt)</div>
          <div class="ki-bm-zeile" data-ki-basis="gaap" title="${KI_BASIS.gaap.info}">Gewinn laut Bericht (GAAP, verwässert)</div>
          <div class="ki-bm-gruppe">CASHFLOW</div>
          <div class="ki-bm-zeile" data-ki-basis="ocf" title="${KI_BASIS.ocf.info}">Operativer Cashflow (≈ FFO bei REITs)</div>
          <div class="ki-bm-zeile" data-ki-basis="fcf" title="${KI_BASIS.fcf.info}">Free Cashflow (≈ AFFO bei REITs)</div>
          <div class="ki-bm-gruppe">WEITERE</div>
          <div class="ki-bm-zeile" data-ki-basis="ebitda" title="${KI_BASIS.ebitda.info}">EBITDA je Aktie</div>
          <div class="ki-bm-zeile" data-ki-basis="ebit" title="${KI_BASIS.ebit.info}">EBIT je Aktie</div>
          <div class="ki-bm-zeile" data-ki-basis="umsatz" title="${KI_BASIS.umsatz.info}">Umsatz je Aktie</div>
        </div>
      </span>
      <label style="font-size:11.5px;display:inline-flex;align-items:center;gap:3px;margin-left:6px;" title="Faktor für die Fair-Value-Linie (Standard 15 = Kurs-Gewinn-Verhältnis, das langfristig als fair gilt)">Fair Value = <input type="number" data-ki-fvr="1" value="15" min="3" max="60" step="0.5" style="width:52px;padding:1px 4px;border:1px solid var(--border);border-radius:6px;background:var(--card);color:var(--text);">×</label>
      <label style="font-size:11.5px;display:inline-flex;align-items:center;gap:3px;margin-left:4px;" title="Aktienvergütung ist ein echter Kostenfaktor; bereinigte Gewinne (Analysten, Datendienste) rechnen sie meist heraus"><input type="checkbox" data-ki-sbc="1" checked> ohne Aktienvergütung</label>
      <span style="width:10px;"></span>
      ${[['max','MAX'],['gewinn','Gewinn-Zeitraum'],[20,'20J'],[15,'15J'],[10,'10J'],[8,'8J'],[5,'5J'],[3,'3J'],[2,'2J'],[1,'1J']].map(([w,t])=>`<button type="button" class="range-btn" data-ki-jahre="${w}">${t}</button>`).join('')}
    </div>
    <div id="kiChartKacheln" style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:8px;"><span id="kiKachelKi" style="display:contents;"></span><span id="kiKachelBew" style="display:contents;"></span><span id="kiKachelDiv" style="display:contents;"></span></div>
    <div id="kiChartEbenen" style="display:flex;flex-wrap:wrap;gap:4px 12px;font-size:12px;margin-bottom:6px;"></div>
    <div id="kiChartInfo" class="muted" style="font-size:12px;margin-bottom:6px;"></div>
    <div id="kiChartJahre" style="overflow-x:auto;"></div>
    <div id="kiChartBox" style="height:min(60vh,540px);width:100%;position:relative;"></div>
    <div id="kiChartOsz"></div>
    <div id="kiChartLegende" style="font-size:11.5px;margin-top:8px;display:flex;flex-wrap:wrap;gap:12px;"></div>
    <div id="kiChartBew"></div>
    <div id="kiFitness"></div>
  </div>`;
  document.body.appendChild(o);
  // Vorschlagsliste: Depot, KI-Analysen, Watchlist
  const vorschlag = new Map();
  (data.positions||[]).filter(p=>p.ticker).forEach(p=>vorschlag.set(p.ticker, `${p.displaySymbol||p.ticker} · ${p.name||''} (Depot)`));
  (_ki.analysen||[]).forEach(a=>{ const y = kiSymbol(a); if(!vorschlag.has(y)) vorschlag.set(y, `${a.ticker} · ${a.name||''} (KI-Analyse)`); });
  (data.watchlist||[]).forEach(w=>{ const y = w.ticker||w.symbol; if(y && !vorschlag.has(y)) vorschlag.set(y, `${w.displaySymbol||y} · ${w.name||''} (Watchlist)`); });
  document.getElementById('kiChartSymbole').innerHTML = [...vorschlag].map(([y,t])=>`<option value="${escapeHtml(y)}">${escapeHtml(t)}</option>`).join('');
  const suche = document.getElementById('kiChartSuche');
  const oeffne = ()=>{ const v = suche.value.trim(); if(!v) return; suche.value = ''; kiChartSymbolOeffnen(v); };
  suche.addEventListener('change', oeffne);
  suche.addEventListener('keydown', e=>{ if(e.key==='Enter') oeffne(); });
  o.addEventListener('click', e=>{
    if(e.target===o || e.target.closest('[data-ki-chart-zu]')){ kiChartSchliessen(); return; }
    if(!_kiChart) return;
    if(e.target.closest('[data-ki-neu-tab]')){
      const c = _kiChart.ctx; const ziel = c.pos ? 'pos:'+c.posSym : (c.analyse ? 'ki:'+c.analyse.ticker : 'sym:'+c.sym);
      window.open(location.pathname + '#chart=' + encodeURIComponent(ziel) + '&tab=' + _kiTab + '&iv=' + (_kiChart.interval||'1mo'), '_blank', 'noopener');
      return;
    }
    if(e.target.closest('[data-ki-alarm-menue]')){ const pnl = document.getElementById('kiAlarmPanel'); if(pnl){ pnl.style.display = pnl.style.display==='none' ? '' : 'none'; if(pnl.style.display!=='none') kiAlarmPanelZeichnen(); } return; }
    const as = e.target.closest('[data-ki-alarm-setzen]'); if(as){ kiAlarmNeu(Number(as.dataset.preis), as.dataset.name, as.dataset.bed || 'below',
      as.dataset.dyn ? {dynamisch:'fv', basis:_kiChart.fvBasis||'operativ', faktor:_kiChart.fvFaktor||15} : null); return; }
    if(e.target.closest('[data-ki-alarm-eigen]')){ const pr = Number(String(document.getElementById('kiAlarmPreis')?.value||'').replace(',', '.')); const bed = document.getElementById('kiAlarmBed')?.value || 'below';
      if(!(pr>0)){ toast('Bitte einen Preis eingeben.'); return; } kiAlarmNeu(pr, 'Eigener Alarm', bed); return; }
    const aw = e.target.closest('[data-ki-alarm-weg]'); if(aw){ data.priceAlerts = (data.priceAlerts||[]).filter(x=>x.id!==aw.dataset.kiAlarmWeg); saveData(); toast('Alarm gelöscht.'); kiAlarmPanelZeichnen(); chartZeichnen(_kiChart.ctx, _kiChart.interval || '1mo'); return; }
    const iv = e.target.closest('[data-ki-iv]'); if(iv){ chartZeichnen(_kiChart.ctx, iv.dataset.kiIv); return; }
    const tb = e.target.closest('[data-ki-tab]'); if(tb){ kiTabSetzen(tb.dataset.kiTab); chartZeichnen(_kiChart.ctx, _kiChart.interval || '1mo'); return; }
    const fcb = e.target.closest('[data-ki-fc]'); if(fcb){ _kiFcModus = fcb.dataset.kiFc; chartZeichnen(_kiChart.ctx, _kiChart.interval || '1mo'); return; }
    const bm = e.target.closest('[data-ki-basis-menue]'); if(bm){ const m = document.getElementById('kiBasisMenue'); if(m) m.style.display = m.style.display==='none' ? '' : 'none'; return; }
    const ba = e.target.closest('[data-ki-basis-auto]'); if(ba){ _kiBasisManuell = !ba.checked; document.getElementById('kiBasisMenue').style.display='none'; chartZeichnen(_kiChart.ctx, _kiChart.interval || '1mo'); return; }
    const bb = e.target.closest('[data-ki-basis]'); if(bb){ _kiBasis = bb.dataset.kiBasis; _kiBasisManuell = true; const m = document.getElementById('kiBasisMenue'); if(m) m.style.display='none'; chartZeichnen(_kiChart.ctx, _kiChart.interval || '1mo'); return; }
    if(!e.target.closest('#kiBasisMenue')){ const m = document.getElementById('kiBasisMenue'); if(m) m.style.display='none'; }
    const st = e.target.closest('[data-ki-stil]'); if(st){ _kiStil = st.dataset.kiStil; chartZeichnen(_kiChart.ctx, _kiChart.interval || '1mo'); return; }
    const jb = e.target.closest('[data-ki-jahre]'); if(jb){ const w = jb.dataset.kiJahre; _kiJahre = (w==='max' ? null : (w==='gewinn' ? 'gewinn' : +w));
      o.querySelectorAll('[data-ki-jahre]').forEach(b=>b.classList.toggle('active', b===jb)); kiZeitraumAnwenden(); return; }
  });
  o.addEventListener('change', e=>{
    if(e.target.closest('[data-ki-fvr]') && _kiChart){ const w = parseFloat(String(e.target.value).replace(',','.')); if(w>=3 && w<=60){ _kiFvr = w; chartZeichnen(_kiChart.ctx, _kiChart.interval || '1mo'); } return; }
    if(e.target.closest('[data-ki-sbc]') && _kiChart){ _kiSbcRaus = e.target.checked; chartZeichnen(_kiChart.ctx, _kiChart.interval || '1mo'); return; }
    const cb = e.target.closest('[data-ki-ebene]');
    if(cb && _kiChart){ _kiEbene[cb.dataset.kiEbene] = cb.checked; chartZeichnen(_kiChart.ctx, _kiChart.interval || '1mo'); }
  });
  return o;
}
function kiZeitraumAnwenden(){
  const c = _kiChart; if(!c || !c.chart || !c.zeiten) return;
  const z = c.zeiten, ts = c.chart.timeScale();
  const bis = c.bisZukunft || z[z.length-1];
  let von = null;
  if(_kiJahre==='gewinn') von = c.gewinnVon || null;
  // Bewertungs-Tab: MAX = ganzer Zeitraum MIT Gewinndaten nach Vorlage, nicht Kurse ab 1986 ohne Fair Value
  else if(!_kiJahre && _kiTab==='bewertung' && c.gewinnVon){ const d = new Date(c.gewinnVon+'T12:00:00Z'); d.setUTCFullYear(d.getUTCFullYear()-1); von = d.toISOString().slice(0,10); }
  else if(_kiJahre){ const d = new Date(z[z.length-1]+'T12:00:00Z'); d.setUTCFullYear(d.getUTCFullYear()-_kiJahre); von = d.toISOString().slice(0,10); }
  // Ende = letzte Schaetzung (bisZukunft), nach Vorlage; die Linien-Namen stehen im Bewertungs-Reiter in der Legende
  // (kiBewOhneTitel), daher kein zusaetzlicher Rand noetig (Nutzer 12.11.2026). LWC 4.2.3 kennt kein timeToIndex.
  if(von){ const ab = z.find(t=>t>=von) || z[0]; ts.setVisibleRange({from:ab, to:bis}); }
  else if(c.interval!=='1mo' && z.length>260) ts.setVisibleRange({from:z[z.length-260], to:bis});
  else if(c.bisZukunft) ts.setVisibleRange({from:z[0], to:bis});
  else ts.fitContent();
}
// Chart als eigene Seite (Nutzerwunsch 05.11.2026: "als eigenen Tab in Chrome fuer bessere Auswertung"): Link
// index.php#chart=pos:MO&tab=bewertung&iv=1mo oeffnet nach dem Laden der Daten den Chart bildschirmfuellend.
function kiChartVollbild(){
  const o = kiChartModal(); o.classList.add('ki-voll');
  if(!document.getElementById('kiVollStil')){
    const st = document.createElement('style'); st.id = 'kiVollStil';
    st.textContent = '#kiChartOverlay.ki-voll{padding:0;background:var(--bg,#f5f6f8);backdrop-filter:none;}#kiChartOverlay.ki-voll .modal{max-width:1800px!important;width:100%!important;margin:0 auto;max-height:100vh;height:100vh;border-radius:0;}#kiChartOverlay.ki-voll #kiChartBox{height:auto!important;aspect-ratio:2.3/1;max-height:calc(100vh - 300px);min-height:340px;}';
    document.head.appendChild(st);
  }
}
async function kiChartAusLink(){
  const m = /^#chart=([^&]+)(?:&tab=(\w+))?(?:&iv=(\w+))?/.exec(location.hash||'');
  if(!m) return;
  const ziel = decodeURIComponent(m[1]), tab = KI_TABS[m[2]] ? m[2] : 'zonen', iv = ['1mo','1wk','1d'].includes(m[3]) ? m[3] : '1mo';
  // bis 60 s auf die Depotdaten warten (14.11.2026: das Laden dauerte ueber 15 s, danach ging der Chart nicht auf bzw. ohne Kaeufe)
  for(let i=0; i<240 && !window._depotGeladen && !(typeof data!=='undefined' && data && Array.isArray(data.positions) && data.positions.length); i++) await new Promise(r=>setTimeout(r,250));
  kiTabSetzen(tab); kiChartVollbild();
  document.title = ziel.replace(/^(pos|ki|sym):/,'') + ' – Chart';
  let ctx = null;
  if(ziel.startsWith('pos:')) ctx = await chartKontextPos(ziel.slice(4));
  else if(ziel.startsWith('sym:')){ await kiChartSymbolOeffnen(ziel.slice(4)); return; }
  else { const a = await kiAnalyseFuer(ziel.slice(3)); ctx = a ? {sym:kiSymbol(a), titel:`${a.ticker} · ${a.name||''}`, analyse:a, pos:null} : {sym:ziel.slice(3), titel:ziel.slice(3), analyse:null, pos:null}; }
  // Im neuen Tab kann ein anderes Depot aktiv sein -> Sektor fehlt; dann wie kiChartSymbolOeffnen aus Watchlist/Yahoo holen,
  // damit die automatische Bezugsgroesse (REIT = Op. Cashflow, Finanzen = GAAP) gleich wie im Fenster ist (Nutzer 12.11.2026)
  if(ctx && !(ctx.pos && (ctx.pos.sector||ctx.pos.sektor)) && !(ctx.analyse && ctx.analyse.ddb && ctx.analyse.ddb.sector)){
    const v = String(ctx.sym||'').toUpperCase();
    const p2 = (data.positions||[]).find(p=>String(p.ticker||'').toUpperCase()===v);
    const w = (data.watchlist||[]).find(x=>String(x.ticker||x.symbol||'').toUpperCase()===v);
    ctx.sektor = (p2 && p2.sector) || (w && w.sector) || '';
    if(!ctx.sektor){ try{ const sj = await (await fetch('sector.php?symbol=' + encodeURIComponent(ctx.sym), {cache:'no-store'})).json(); ctx.sektor = [sj.sector, sj.industry].filter(Boolean).join(' '); } catch(e){} }
  }
  if(ctx) chartZeichnen(ctx, iv);
}
setTimeout(kiChartAusLink, 0);
function kiChartAufraeumen(){
  if(_kiChart && _kiChart.chart) _kiChart.chart.remove();
  if(_kiChart && _kiChart.osz) _kiChart.osz.forEach(c=>c.remove());
  const oz = document.getElementById('kiChartOsz'); if(oz) oz.innerHTML = '';
}
function kiChartSchliessen(){
  document.getElementById('kiChartOverlay')?.classList.remove('show');
  kiChartAufraeumen();
  _kiChart = null;
}
// Kontext eines Charts: KI-Titel (Zonen der Analyse) oder eigene Position (Kaeufe, Einstand, dazu die KI-Zonen,
// falls es eine Analyse gibt).
function chartKontextKi(ticker){
  const a = (_ki.analysen||[]).find(x=>x.ticker===ticker);
  return a ? {sym:kiSymbol(a), titel:`${a.ticker} · ${a.name||''}`, analyse:a, pos:null} : null;
}
async function kiAnalyseFuer(sym){
  const passt = x=>String(x.ticker||'').toUpperCase()===String(sym||'').toUpperCase();
  if(_ki.geladen) return (_ki.analysen||[]).find(passt) || null;
  try{
    const j = await (await fetch('ki-analysen-api.php?action=liste', {cache:'no-store'})).json();
    return (j.analysen||[]).find(passt) || null;
  } catch(e){ return null; }
}
async function chartKontextPos(sym){
  const p = (data.positions||[]).find(x=>(x.displaySymbol||x.ticker||x.symbol)===sym);
  if(!p || !p.ticker){ toast('Kein Yahoo-Symbol für diese Position hinterlegt.'); return null; }
  return {sym:p.ticker, titel:`${p.name||sym} (${sym}) · im Depot`, analyse: await kiAnalyseFuer(sym), pos:p, posSym:sym};
}
// Seite "Aktie bewerten": Eingabe, zuletzt angesehene Titel (nur in diesem Browser), Schnellwahl Depot und Watchlist
function acZuletzt(){ try{ return JSON.parse(localStorage.getItem('valuelens_zuletzt')||'[]'); } catch(e){ return []; } }
function acMerken(sym){ try{ const l = [sym, ...acZuletzt().filter(x=>x!==sym)].slice(0,12); localStorage.setItem('valuelens_zuletzt', JSON.stringify(l)); } catch(e){} }
function acOeffnen(sym, neuerTab){
  sym = String(sym||'').trim(); if(!sym) return;
  const tab = document.getElementById('acStart')?.value || 'bewertung';
  acMerken(sym.toUpperCase());
  if(neuerTab){ window.open(location.pathname + '#chart=sym:' + encodeURIComponent(sym.toUpperCase()) + '&tab=' + tab + '&iv=1mo', '_blank', 'noopener'); return; }
  kiTabSetzen(tab); kiChartSymbolOeffnen(sym);
  setTimeout(renderAktienChartSeite, 300);
}
// Bewertungsliste (eigene Version der Filterlisten, 06.11.2026): je Titel dieselbe Rechnung wie im Bewertungs-Chart
let _acListe = [], _acSort = {k:'abw', auf:true}; const _acFx = {};
async function acBewertungZeile(sym, name, sektor){
  const [rh, q] = await Promise.all([
    fetch('bewertung-reihen.php?symbol=' + encodeURIComponent(sym), {cache:'no-store'}).then(r=>r.json()).catch(()=>null),
    fetch('quote.php?ohlc=1&interval=1mo&range=10y&symbols=' + encodeURIComponent(sym), {cache:'no-store'}).then(r=>r.json()).catch(()=>null)]);
  const z = {sym, name, sektor};
  if(!q || !Array.isArray(q.kerzen) || !q.kerzen.length){ z.fehler = 'keine Kurse'; return z; }
  const k = q.kerzen, kurs = k[k.length-1][4]; z.kurs = kurs; z.waehrung = q.currency;
  // Banken nach Kurs-Buchwert statt KGV (Nutzer 12.11.2026, bewertung-bank.php): FV = gerechtfertigtes KBV, "Normal" = 5J-KBV
  if(/financ|finanz|bank/i.test(sektor||'')){
    const b = await fetch('bewertung-bank.php?symbol=' + encodeURIComponent(sym), {cache:'no-store'}).then(r=>r.json()).catch(()=>null);
    if(b && b.bank){
      const divsB = Array.isArray(q.dividenden) ? q.dividenden : [];
      const d12B = divsB.reduce((a,[d,v])=>new Date(d+'T12:00:00Z').getTime() > Date.now()-365*86400000 ? a+Number(v) : a, 0);
      z.divR = kurs>0 && d12B>0 ? d12B/kurs*100 : 0; const dkB = kiDivKennzahlen(divsB, k); z.folge = dkB ? dkB.folge : 0;
      if(b.fehler){ z.fehler = 'Bank: ' + b.fehler; return z; }
      z.bank = b; z.basis = 'Buchwert (Bank)'; z.kbv = b.kbv; z.npe = b.kbv_schnitt;
      z.fv = b.fv_gerecht; z.abw = b.fv_gerecht>0 ? (kurs/b.fv_gerecht-1)*100 : null;
      if(b.fv_historisch){ z.normWert = b.fv_historisch; z.abwN = (kurs/b.fv_historisch-1)*100; }
      return z;
    }
  }
  const divs = Array.isArray(q.dividenden) ? q.dividenden : [];
  const d12 = divs.reduce((a,[d,b])=>new Date(d+'T12:00:00Z').getTime() > Date.now()-365*86400000 ? a+Number(b) : a, 0);
  z.divR = kurs>0 && d12>0 ? d12/kurs*100 : 0;
  const dk = kiDivKennzahlen(divs, k); z.folge = dk ? dk.folge : 0; z.divWachstum = dk ? dk.cagr5 : null;
  if(!rh || !rh.reihen){ z.fehler = 'keine Gewinndaten'; return z; }
  const roh = String(q.currency||''), ziel = (roh==='GBp'||roh==='GBX') ? 'GBP' : roh.toUpperCase();
  // fremde Berichtswaehrung: mit dem Wechselkurs je Geschaeftsjahresende umrechnen (wie im Chart)
  let fxAm = null;
  if(rh.waehrung && ziel && String(rh.waehrung).toUpperCase()!==ziel){
    const paar = String(rh.waehrung).toUpperCase() + ziel + '=X';
    _acFx[paar] = _acFx[paar] || fetch('quote.php?ohlc=1&interval=1mo&range=max&symbols=' + encodeURIComponent(paar), {cache:'no-store'}).then(r=>r.json()).catch(()=>null);
    const fx = await _acFx[paar]; const fk = fx && Array.isArray(fx.kerzen) ? fx.kerzen : [];
    if(!fk.length){ z.fehler = 'Berichtswährung '+rh.waehrung+' – Kurs fehlt'; return z; }
    fxAm = d=>{ const m = String(d).slice(0,7); const x = fk.filter(y=>y[0].slice(0,7)<=m).pop() || fk[0]; return x[4]; };
    z.fx = rh.waehrung;
  }
  let basis = 'operativ';
  if(/real estate|reit|immobil/i.test(sektor)) basis = (rh.reihen.ocf||[]).length ? 'ocf' : 'gaap';
  else if(/financ|utilit|energy|versorg|finanz|energie|bank|insur/i.test(sektor)) basis = 'gaap';
  else if(!(rh.reihen.operativ||[]).length) basis = (rh.reihen.gaap||[]).length ? 'gaap' : 'ocf';
  const sbcAm = {}; (rh.reihen.sbc||[]).forEach(e=>sbcAm[e.date] = e.v);
  const r = (rh.reihen[basis]||[]).map(e=>({date:e.date, v: ((basis==='operativ' && !_kiSbcRaus) ? e.v-(sbcAm[e.date]||0) : e.v) * (fxAm ? fxAm(e.date) : 1)}));
  if(!r.length){ z.fehler = 'keine Werte'; return z; }
  // Veraltete Daten (07.11.2026: Baidu endet bei der SEC 2010, Brookfield Asset Mgmt. 2023) nicht bewerten - sonst FV 1.790 bei Kurs 82
  const letztJ = +String(r[r.length-1].date).slice(0,4);
  if(letztJ < new Date().getFullYear() - 2){ z.fehler = 'Gewinndaten enden '+letztJ+' – nicht bewertet'; return z; }
  z.basis = KI_BASIS[basis].kurz;
  const pence = (roh==='GBp'||roh==='GBX') ? 100 : 1;
  const wert = r[r.length-1].v * pence; z.wert = wert;
  // Gewinneinbruch im letzten Jahr (GPC 2025: 2,73 nach 8,45-10,35) - meist Sonderlasten, Bewertung dann unsicher
  const vor = r.slice(-4,-1).map(e=>e.v*pence).sort((a,b)=>a-b); const mv = vor.length>=2 ? (vor.length%2 ? vor[(vor.length-1)/2] : (vor[vor.length/2-1]+vor[vor.length/2])/2) : null;
  if(mv>0 && wert < 0.5*mv) z.einbruch = Math.round((wert/mv-1)*100);
  const schluss = d=>{ const m = String(d).slice(0,7); const x = k.filter(y=>y[0].slice(0,7)<=m).pop(); return x ? x[4] : null; };
  const vielf = r.slice(-10).filter(e=>e.v>0 && schluss(e.date)).map(e=>schluss(e.date)/(e.v*pence)).sort((a,b)=>a-b);
  const npe = vielf.length ? (vielf.length%2 ? vielf[(vielf.length-1)/2] : (vielf[vielf.length/2-1]+vielf[vielf.length/2])/2) : null;
  // Wachstum nur ab einem Ausgangswert von mind. 10 % des letzten Werts (VICI: 0,03 -> 2,11 ergab 133 % p. a.)
  const pos = r.slice(-11).filter(e=>e.v>0 && e.v >= 0.1*r[r.length-1].v);
  const g = pos.length>=3 ? (Math.pow(pos[pos.length-1].v/pos[0].v, 1/((new Date(pos[pos.length-1].date)-new Date(pos[0].date))/31557600000))-1)*100 : null;
  z.g = g; z.npe = npe;
  if(!/[.\-]/.test(sym)){ try{ let fk = await (await fetch('fitness.php?kennzahlen=1&symbol=' + encodeURIComponent(sym), {cache:'no-store'})).json();
      if(fk && fk.fehlt) fk = await (await fetch('fitness.php?kennzahlen=1&laden=1&symbol=' + encodeURIComponent(sym), {cache:'no-store'})).json();
      if(fk && !fk.fehler && !fk.fehlt){ z.roe = fk.roe; z.ldk = fk.ldk; z.fcfDiv5 = fk.fcfDiv5; z.marge = fk.marge; } } catch(e){} }
  try{ const fi = await (await fetch('fitness.php?info=1&symbol=' + encodeURIComponent(sym), {cache:'no-store'})).json();
    const st = fi && (fi.stimmung||[]).find(x=>x.periode==='0m');
    if(st){ const n = st.starkKaufen+st.kaufen+st.halten+st.verkaufen+st.starkVerkaufen; if(n>0){ z.kaufPct = (st.starkKaufen+st.kaufen)/n*100; z.kaufN = n; } } } catch(e){}
  if(wert>0){
    z.fv = wert*_kiFvr; z.abw = (kurs/z.fv-1)*100; z.kgv = kurs/wert;
    if(npe){ z.normWert = wert*npe; z.abwN = (kurs/z.normWert-1)*100; }
    if(npe && g!=null && isFinite(g)) z.ror = (Math.pow(wert*Math.pow(1+Math.max(-20,Math.min(30,g))/100,5)*npe/kurs, 1/5)-1)*100 + z.divR;
  }
  return z;
}
// Erklaerung der Bank-Bewertung als Tooltip (Spanne, EK-Rendite, EK-Kosten, Empfindlichkeit, materieller Buchwert)
function acBankTitel(b){
  const n = (v,s=2)=> v==null ? '–' : kiNum(v,s);
  let t = `Bank nach Buchwert: Buchwert/Aktie ${n(b.bvps)} (${b.bvps_stand||''})\n`
    + `5J-KBV ${n(b.kbv_schnitt)} → ${n(b.fv_historisch)} · gerechtfertigtes KBV ${n(b.kbv_gerecht)} → ${n(b.fv_gerecht)}\n`
    + `EK-Rendite Ø ${n(b.roe_schnitt,1)} % · EK-Kosten ${n(b.ek_kosten,0)} % (±1: ${n(b.fv_ek_kosten_plus1)}–${n(b.fv_ek_kosten_minus1)}) · g ${n(b.g,0)} %`;
  if(b.materiell) t += `\nMateriell (ohne Goodwill): ${n(b.materiell.tbvps)}/Aktie, Rendite Ø ${n(b.materiell.rotce_schnitt,1)} % → ${n(b.materiell.fv_gerecht)}`;
  return t;
}
function acListeZeichnen(){
  const tab = document.getElementById('acListeTab'); if(!tab) return;
  const f = document.getElementById('acListeFilter')?.value || '';
  const fair = z=>z.abw!=null && z.abw<=0, deckt = z=>z.fcfDiv5==null ? !(z.divR>0) : z.fcfDiv5>=1;
  const F = {unter:z=>z.abw<0, normal:z=>z.abwN<0, rendite10:z=>z.ror>=10, div10:z=>z.folge>=10,
    roe15:z=>z.roe>=15, ldk50:z=>z.ldk!=null && z.ldk<=50, fcfdiv:z=>z.divR>0 && z.fcfDiv5>=1,
    qualitaet:z=>z.roe>=15 && z.ldk!=null && z.ldk<=50 && deckt(z) && z.abwN<0,
    champ:z=>z.folge>=25 && fair(z), contender:z=>z.folge>=10 && z.folge<25 && fair(z), challenger:z=>z.folge>=5 && z.folge<10 && fair(z),
    growth:z=>z.g>=10 && fair(z) && z.ror>=10 && z.kgv>0 && z.kgv/z.g<=1.5, growthret:z=>z.g>=15 && fair(z)};
  let l = _acListe.filter(z=>!f || (F[f] && !z.fehler && F[f](z)));
  const {k, auf} = _acSort;
  l = l.slice().sort((a,b)=>{ const x = a[k], y = b[k]; if(x==null && y==null) return 0; if(x==null) return 1; if(y==null) return -1; return (typeof x==='string' ? x.localeCompare(y) : x-y) * (auf?1:-1); });
  const spalten = [['sym','Symbol'],['name','Name'],['basis','Bewertet nach'],['kurs','Kurs'],['kgv','KGV'],['fv','Fair Value'],['abw','Kurs vs. FV'],['npe','Normal-KGV'],['normWert','Wert bei Normal'],['abwN','Kurs vs. Normal'],['g','Wachstum'],['ror','Erw. Rendite p. a.'],['divR','Div.-Rendite'],['folge','Erhöhungen'],['roe','ROE'],['ldk','Schulden/Kap.'],['fcfDiv5','FCF/Div. 5J'],['kaufPct','Kaufempf.']];
  const pr = v=> v==null ? '–' : `<span class="${v<-10?'pos':(v>10?'neg':'')}">${v>0?'+':''}${kiNum(v,0)} %</span>`;
  tab.innerHTML = `<thead><tr>${spalten.map(([s2,t])=>`<th data-ac-sort="${s2}" style="cursor:pointer;white-space:nowrap;">${t}${_acSort.k===s2?(auf?' ▲':' ▼'):''}</th>`).join('')}</tr></thead><tbody>${l.map(z=>`<tr data-ac-sym="${escapeHtml(z.sym)}" style="cursor:pointer;">
    <td><b>${escapeHtml(z.sym)}</b></td><td>${escapeHtml((z.name||'').slice(0,28))}</td>
    ${z.fehler ? `<td colspan="16" class="muted">${escapeHtml(z.fehler)}</td>` : `<td>${escapeHtml(z.basis||'')}${z.einbruch!=null?` <span title="Letzter Wert ${z.einbruch} % unter dem Median der 3 Vorjahre – meist Sonderlasten. Bewertung unsicher, im Chart prüfen." style="color:#d97706;cursor:help;">⚠ ${z.einbruch} %</span>`:''}${z.fx?` <span class="muted" title="aus ${escapeHtml(z.fx)} umgerechnet">(${escapeHtml(z.fx)})</span>`:''}</td><td>${kiNum(z.kurs)}</td><td>${z.bank ? `<span title="Kurs-Buchwert-Verhältnis (Bank)">KBV ${kiNum(z.kbv,2)}</span>` : (z.kgv!=null?kiNum(z.kgv,1):'–')}</td><td>${z.fv!=null?kiNum(z.fv):'–'}</td><td>${pr(z.abw)}</td>
    <td>${z.bank ? `<span title="${escapeHtml(acBankTitel(z.bank))}" style="cursor:help;">KBV ${z.npe!=null?kiNum(z.npe,2):'–'}</span>` : (z.npe!=null?kiNum(z.npe,1):'–')}</td><td>${z.normWert!=null?kiNum(z.normWert):'–'}</td><td>${pr(z.abwN)}</td><td>${z.g!=null&&isFinite(z.g)?kiNum(z.g,1)+' %':'–'}</td>
    <td class="${z.ror==null?'':(z.ror>=10?'pos':(z.ror<0?'neg':''))}">${z.ror!=null?kiNum(z.ror,1)+' %':'–'}</td><td>${z.divR?kiNum(z.divR,2)+' %':'–'}</td><td>${z.folge||0}</td><td class="${z.roe==null?'':(z.roe>=15?'pos':(z.roe<5?'neg':''))}" title="Gewinn ÷ Ø Eigenkapital (4 Quartale)">${z.roe!=null?kiNum(z.roe,1)+' %':'–'}</td><td class="${z.ldk==null?'':(z.ldk<=50?'pos':(z.ldk>80?'neg':''))}" title="Langfr. Schulden ÷ (Schulden + Eigenkapital), letztes Quartal">${z.ldk!=null?kiNum(z.ldk,0)+' %':'–'}</td><td class="${z.fcfDiv5==null?'':(z.fcfDiv5>=1.2?'pos':(z.fcfDiv5<1?'neg':''))}" title="Free Cashflow ÷ gezahlte Dividenden, Summe 5 Jahre (≥ 1 = gedeckt)">${z.fcfDiv5!=null?kiNum(z.fcfDiv5,2)+'×':'–'}</td><td class="${z.kaufPct==null?'':(z.kaufPct>=60?'pos':(z.kaufPct<30?'neg':''))}" title="Anteil Kaufempfehlungen (stark kaufen + kaufen) der Analysten, Yahoo">${z.kaufPct!=null?kiNum(z.kaufPct,0)+' % <span class="muted">('+z.kaufN+')</span>':'–'}</td>`}</tr>`).join('')}</tbody>`;
}
async function acListeLaden(){
  const umfang = document.getElementById('acListeUmfang').value;
  const m = new Map();
  if(umfang==='depot' || umfang==='beide') (data.positions||[]).filter(p=>p.ticker && (p.shares||0)>0 && !/etf/i.test(p.sector||'')).forEach(p=>m.set(p.ticker, [p.name, p.sector||'']));
  if(umfang==='watch' || umfang==='beide') (data.watchlist||[]).forEach(w=>{ const y = w.ticker||w.symbol; if(y && !m.has(y)) m.set(y, [w.name, w.sector||'']); });
  if(umfang==='ki'){ if(!_ki.geladen) await kiLaden(); (_ki.analysen||[]).forEach(a=>m.set(kiSymbol(a), [a.name, (a.ddb&&a.ddb.sector)||''])); }
  const liste = [...m]; _acListe = [];
  const info = document.getElementById('acListeInfo'), knopf = document.getElementById('acListeLaden');
  knopf.disabled = true;
  let fertig = 0;
  const arbeiter = async ()=>{ while(liste.length){ const [sym, [name, sek]] = liste.shift(); try{ _acListe.push(await acBewertungZeile(sym, name, sek)); } catch(e){ _acListe.push({sym, name, fehler:e.message}); }
    fertig++; info.textContent = `Berechne … ${fertig} von ${m.size}`; if(fertig%5===0) acListeZeichnen(); } };
  await Promise.all([arbeiter(), arbeiter(), arbeiter()]);
  knopf.disabled = false;
  info.textContent = `${_acListe.length} Titel berechnet · Fair Value = Wert × ${kiNum(_kiFvr,1)} · grün = günstig (mehr als 10 % unter), rot = teuer · Spaltenkopf sortiert, Zeile öffnet den Chart.`;
  acListeZeichnen();
}
function renderAktienChartSeite(){
  const inp = document.getElementById('acSymbol'); if(!inp) return;
  if(!inp.dataset.verbunden){
    inp.dataset.verbunden = '1';
    inp.addEventListener('keydown', e=>{ if(e.key==='Enter') acOeffnen(inp.value); });
    document.getElementById('acOeffnen').addEventListener('click', ()=>acOeffnen(inp.value));
    document.getElementById('acNeuerTab').addEventListener('click', ()=>acOeffnen(inp.value, true));
    document.getElementById('page-aktienchart').addEventListener('click', e=>{
      const th = e.target.closest('[data-ac-sort]'); if(th){ const k = th.dataset.acSort; _acSort = {k, auf: _acSort.k===k ? !_acSort.auf : true}; acListeZeichnen(); return; }
      const b = e.target.closest('[data-ac-sym]'); if(b){ e.preventDefault(); acOeffnen(b.dataset.acSym, e.ctrlKey||e.metaKey); } });
    document.getElementById('acListeLaden').addEventListener('click', acListeLaden);
    document.getElementById('acListeFilter').addEventListener('change', acListeZeichnen);
  }
  const pos = (data.positions||[]).filter(p=>p.ticker && (p.shares||0)>0).sort((a,b)=>String(a.displaySymbol||a.ticker).localeCompare(String(b.displaySymbol||b.ticker)));
  const wl = (data.watchlist||[]).filter(w=>w.ticker||w.symbol);
  const opt = new Map();
  pos.forEach(p=>opt.set(p.ticker, `${p.name||''} (Depot)`));
  wl.forEach(w=>{ const y = w.ticker||w.symbol; if(!opt.has(y)) opt.set(y, `${w.name||''} (Watchlist)`); });
  (_ki.analysen||[]).forEach(a=>{ const y = kiSymbol(a); if(!opt.has(y)) opt.set(y, `${a.name||''} (KI-Analyse)`); });
  document.getElementById('acSymbole').innerHTML = [...opt].map(([y,t])=>`<option value="${escapeHtml(y)}">${escapeHtml(t)}</option>`).join('');
  const knopf = (y, t)=>`<button type="button" class="range-btn" data-ac-sym="${escapeHtml(y)}" title="${escapeHtml(t||'')} – Strg+Klick: eigener Tab">${escapeHtml(y)}</button>`;
  const zl = acZuletzt();
  document.getElementById('acZuletzt').innerHTML = zl.length ? `<span class="muted" style="font-size:12px;align-self:center;">Zuletzt:</span>${zl.map(y=>knopf(y, opt.get(y))).join('')}` : '';
  document.getElementById('acListen').innerHTML =
    `<div class="muted" style="font-size:12px;margin:6px 0 4px;">Depot (${pos.length})</div><div style="display:flex;flex-wrap:wrap;gap:4px;">${pos.map(p=>knopf(p.ticker, p.name)).join('')}</div>`
    + (wl.length ? `<details style="margin-top:10px;"><summary class="muted" style="font-size:12px;cursor:pointer;">Watchlist (${wl.length}) – aufklappen</summary><div style="display:flex;flex-wrap:wrap;gap:4px;margin-top:4px;">${wl.map(w=>knopf(w.ticker||w.symbol, w.name)).join('')}</div></details>` : '');
}
// Beliebige Aktie im Chart oeffnen: eigene Position (mit Kaeufen), sonst KI-Analyse falls vorhanden, sonst nur Kurs + Bewertung
async function kiChartSymbolOeffnen(eingabe){
  const roh = String(eingabe).trim(); const v = roh.toUpperCase();
  const pos = (data.positions||[]).find(p=>String(p.ticker||'').toUpperCase()===v || String(p.displaySymbol||'').toUpperCase()===v || String(p.name||'').toUpperCase()===v);
  let ctx;
  if(pos) ctx = await chartKontextPos(pos.displaySymbol||pos.ticker);
  else {
    const a = await kiAnalyseFuer(v);
    const w = (data.watchlist||[]).find(x=>String(x.ticker||x.symbol||'').toUpperCase()===v);
    ctx = a ? {sym:kiSymbol(a), titel:`${a.ticker} · ${a.name||''}`, analyse:a, pos:null} : {sym:v, titel: w && w.name ? `${w.name} (${v})` : v, analyse:null, pos:null};
    // Branche fuer die automatische Bezugsgroesse (REIT, Finanzen ...) - bei Titeln ausserhalb des Depots von Yahoo
    ctx.sektor = (w && w.sector) || '';
    if(!ctx.sektor){ try{ const sj = await (await fetch('sector.php?symbol=' + encodeURIComponent(ctx.sym), {cache:'no-store'})).json(); ctx.sektor = [sj.sector, sj.industry].filter(Boolean).join(' '); } catch(e){} }
  }
  if(ctx) chartZeichnen(ctx, (_kiChart && _kiChart.interval) || '1mo');
}
async function kiChartZeichnen(ticker, interval){
  const ctx = chartKontextKi(ticker);
  if(ctx) return chartZeichnen(ctx, interval);
}
// Bewertungsteil (Kurs gegen Gewinn, klassische Methode mit KGV 15): Gewinne je Aktie aus fundamentals.php (SEC 10-K, sonst Yahoo) - dieselben Zahlen wie auf der
// Bewertungsseite. Laedt nach den Kerzen, damit der Chart sofort steht.
const _kiBewCache = {};
const US_REZESSIONEN = [['1980-01','1980-07'],['1981-07','1982-11'],['1990-07','1991-03'],['2001-03','2001-11'],['2007-12','2009-06'],['2020-02','2020-04']];
// Dividenden-Kennzahlen aus den Zahlungen (ohne Sonderausschuettungen): Summe je Kalenderjahr (nur volle Jahre),
// Wachstum p. a. ueber 5 und 10 Jahre, Jahre mit Erhoehung in Folge, normale Rendite = Schnitt der Jahresrenditen (5 J.).
function kiKachel(titel, wert, unter, farbe){ return `<div style="border:1px solid var(--border);border-radius:10px;padding:6px 12px;min-width:120px;background:var(--surface-hover);"><div class="muted" style="font-size:11px;">${titel}</div><div style="font-size:18px;font-weight:700;${farbe?'color:'+farbe+';':''}">${wert}</div>${unter?`<div class="muted" style="font-size:10.5px;">${unter}</div>`:''}</div>`; }
function kiDivKennzahlen(divs, kerzen){
  if(!divs.length) return null;
  const jahrJetzt = new Date().getFullYear();
  const jeJahr = {};
  divs.forEach(([d,b])=>{ const y = +d.slice(0,4); if(y<jahrJetzt) jeJahr[y] = (jeJahr[y]||0) + Number(b); });
  const jahre = Object.keys(jeJahr).map(Number).sort((a,b)=>a-b);
  if(!jahre.length) return null;
  const letzt = jahre[jahre.length-1];
  const cagr = n=>{ const a = jeJahr[letzt-n], e = jeJahr[letzt]; return (a>0 && e>0) ? (Math.pow(e/a, 1/n)-1)*100 : null; };
  let folge = 0;
  for(let y=letzt; jeJahr[y-1]!=null && jeJahr[y] > jeJahr[y-1]*1.0001; y--) folge++;
  const schlussJahr = y=>{ const k = kerzen.filter(x=>+x[0].slice(0,4)===y); return k.length ? k[k.length-1][4] : null; };
  const renditen = [];
  for(let y=letzt-4; y<=letzt; y++){ const c = schlussJahr(y); if(jeJahr[y] && c) renditen.push(jeJahr[y]/c*100); }
  let bezahlt = 0; for(let y=letzt; jeJahr[y]>0; y--) bezahlt++;
  return {bezahlt, cagr5:cagr(5), cagr10:cagr(10), folge, normalRendite: renditen.length ? renditen.reduce((a,b)=>a+b,0)/renditen.length : null, jahre:jahre.length, letztesJahr:letzt, jeJahr};
}
// Eigenes Score-Netz (Capolio-Formel, 0-100 je Bereich) aus den Kennzahlen der Bewertungsseite - grobe Schwellen, keine
// Kopie fremder Scores. Fehlende Werte zaehlen nicht mit.
function kiScoreNetz(f, g, pkt){
  const skal = (v, schlecht, gut)=> v==null || !isFinite(v) ? null : Math.max(0, Math.min(100, (v-schlecht)/(gut-schlecht)*100));
  const mittel = arr=>{ const x = arr.filter(v=>v!=null); return x.length ? Math.round(x.reduce((a,b)=>a+b,0)/x.length) : null; };
  const steig = pkt.length>2 ? pkt.slice(1).filter((p,i)=>p.eps>=pkt[i].eps).length/(pkt.length-1)*100 : null;
  return [
    ['Profitabilität', mittel([skal(f.netMarginPct,0,20), skal(f.roePct,0,25), skal(f.roicPct,0,20)])],
    ['Cashflow', mittel([skal(f.fcfPayoutRatioPct!=null ? 100-f.fcfPayoutRatioPct : null, -20, 60), skal(f.debtPaybackYears!=null ? -f.debtPaybackYears : null, -10, 0)])],
    ['Finanzkraft', mittel([skal(f.altmanZ,1.1,3), skal(f.interestCoverage,1.5,10), skal(f.currentRatio,0.8,2), skal(f.debtEbitdaX!=null ? -f.debtEbitdaX : null, -5, 0)])],
    ['Wachstum', mittel([skal(g,-5,12), skal(f.dividendGrowthRatePct,-5,12)])],
    ['Vorhersagbarkeit', mittel([steig, f.piotroski && typeof f.piotroski==='object' ? f.piotroski.score/f.piotroski.maxScore*100 : null])],
  ];
}
// Bewertungs-Reiter nach Vorlage (Nutzer 12.11.2026): an der Achse nur der farbige Wert, die Namen der Linien in einer
// Legende unter dem Chart. Faengt die Titel aller Serien und Preislinien ab und baut daraus die Legende (#kiFastLegende).
// Kennzahlen-Box ("Graph Key" nach Vorlage im Bewertungs-Reiter rechts neben den Hauptchart (Nutzer 12.11.2026: Seite ist
// schmaler, rechts ist Platz). Chart wird 280 px schmaler; FY-Tabelle, Legende und Prognose bleiben darunter.
function kiGraphKeySetzen(key){
  const cb = document.getElementById('kiChartBox'); document.getElementById('kiGraphKey')?.remove();
  if(!cb) return;
  if(!key || _kiTab!=='bewertung' || cb.parentElement.clientWidth < 900){ cb.style.width = '100%'; return; }
  const eltern = cb.parentElement; if(getComputedStyle(eltern).position==='static') eltern.style.position = 'relative';
  cb.style.width = 'calc(100% - 280px)';
  const side = document.createElement('div'); side.id = 'kiGraphKey';
  // Hoehe = Chart + Jahreszeile darunter, Rest scrollt (Nutzer 13.11.2026: Firmenangaben ragten in den Prognose-Chart)
  const unten = document.getElementById('kiChartFy') || cb;
  side.style.cssText = `position:absolute;right:${parseFloat(getComputedStyle(eltern).paddingRight)||0}px;top:${cb.offsetTop}px;width:268px;max-height:${Math.max(340, unten.offsetTop + unten.offsetHeight - cb.offsetTop)}px;overflow-y:auto;overflow-x:hidden;`;
  key.style.flex = ''; side.appendChild(key); eltern.appendChild(side);
  // Chart ist jetzt schmaler - LWC behaelt den rechten Rand und schneidet links ab -> Zeitraum neu setzen
  [60, 300, 1000].forEach(ms=>setTimeout(()=>{ try{ kiZeitraumAnwenden(); } catch(e){} }, ms));   // autoSize passt die Breite verzoegert an
}
// Fenstergroesse geaendert (Nutzer 12.11.2026: "Tab soll sich an das Fenster anpassen"): Chart passt sich per autoSize an,
// LWC haelt dabei aber den rechten Rand fest -> Zeitraum (z. B. Gewinn-Zeitraum ab 2010) nach dem Ziehen neu setzen.
{ let tr = null; window.addEventListener('resize', ()=>{ clearTimeout(tr); tr = setTimeout(()=>{ try{ if(_kiChart && _kiChart.chart) kiZeitraumAnwenden(); } catch(e){} }, 250); }); }
let _kiRenditeSpaet = null;
let _kiKaufMarker = [], _kiKursOben = null, _kiKaufInfo = {};   // Kaufpunkte fuer die obere Kurslinie im Bewertungs-Reiter   // Div.-Rendite erst nach den Bewertungsflaechen zeichnen (liegt sonst dahinter)
function kiBewOhneTitel(chart){
  if(chart._ohneTitel) return; chart._ohneTitel = true;
  const liste = []; let t = null;
  const zeigen = ()=>{ clearTimeout(t); t = setTimeout(()=>{
    if(_kiChart && _kiChart.chart && _kiChart.chart!==chart) return;   // veralteter Chart (neu gezeichnet) - keine zweite Legende
    document.querySelectorAll('#kiFastLegende').forEach(x=>x.remove());
    const seen = new Set(), eintraege = liste.filter(e=>e.t && !seen.has(e.t) && seen.add(e.t));
    if(!eintraege.length) return;
    const d = document.createElement('div'); d.id = 'kiFastLegende';
    d.style.cssText = 'display:flex;flex-wrap:wrap;gap:4px 14px;font-size:11.5px;margin:6px 0 2px;color:var(--text-dim);';
    d.innerHTML = eintraege.map(e=>`<span style="white-space:nowrap;"><span style="display:inline-block;width:14px;height:3px;border-radius:2px;background:${escapeHtml(String(e.c||'#888'))};vertical-align:middle;margin-right:5px;"></span>${escapeHtml(e.t)}</span>`).join('');
    const nach = document.getElementById('kiChartFy') || document.getElementById('kiChartBox');
    if(nach) nach.after(d);
  }, 400); };
  const merke = (titel, farbe)=>{ if(titel){ liste.push({t:String(titel), c:farbe}); zeigen(); } };
  ['addLineSeries','addAreaSeries','addBaselineSeries','addHistogramSeries','addCandlestickSeries','addBarSeries'].forEach(m=>{
    const orig = chart[m] && chart[m].bind(chart); if(!orig) return;
    chart[m] = (o={})=>{
      merke(o.title, o.color || o.lineColor || o.topLineColor || o.upColor);
      const serie = orig({...o, title:''});
      const pl = serie.createPriceLine.bind(serie);
      serie.createPriceLine = (po={})=>{ merke(po.title, po.color); return pl({...po, title:''}); };
      const ao = serie.applyOptions.bind(serie);
      serie.applyOptions = (x={})=>{ if('title' in x){ merke(x.title, x.color); x = {...x, title:''}; } return ao(x); };
      return serie;
    };
  });
}
// ===== Kursalarme direkt im Chart (Nutzer 12.11.2026: "im Chart gleich Alarme setzen, die dauerhaft bleiben") =====
// Mehrere Alarme je Aktie (anders als addPriceAlert, das je Symbol ersetzt). Gespeichert in data.priceAlerts des aktiven
// Depots; check-alerts.php prueft alle Depots und meldet einmal per Telegram/Mail (alertNotify je Symbol).
function kiAlarmSym(){ return _kiChart && _kiChart.ctx ? String(_kiChart.ctx.sym||'').toUpperCase() : ''; }
function kiAlarmNeu(preis, name, bed, extra){
  const sym = kiAlarmSym(); if(!sym || !(preis>0)) return;
  data.priceAlerts = data.priceAlerts || [];
  if(data.priceAlerts.some(x=>x.symbol===sym && x.condition===bed && Math.abs(Number(x.targetPrice)-preis) < 0.005)){ toast('Diesen Alarm gibt es schon.'); return; }
  const titel = (_kiChart.ctx.titel || sym).replace(/<[^>]*>/g,'');
  data.priceAlerts.push({id:'al_' + Math.random().toString(36).slice(2,10), symbol:sym, name: titel + ' · ' + name, condition:bed,
    targetPrice: Math.round(preis*100)/100, active:true, lastTriggeredAt:null, auto:false, fired:false, quelle:'chart', createdAt:new Date().toISOString().slice(0,10), ...(extra||{})});
  saveData();
  const n = alertNotifyOf(sym);
  toast(`Alarm ${sym} ${bed==='below'?'unter':'über'} ${kiNum(preis)} gesetzt – Meldung: ${[n.telegram?'Telegram':'', n.mail?'E-Mail':''].filter(Boolean).join(' + ') || 'keine (in der Watchlist einschalten)'}`);
  kiAlarmPanelZeichnen(); chartZeichnen(_kiChart.ctx, _kiChart.interval || '1mo');
}
async function kiAlarmPanelZeichnen(){
  const pnl = document.getElementById('kiAlarmPanel'); if(!pnl || !_kiChart) return;
  const sym = kiAlarmSym(), a = _kiChart.ctx.analyse || null;
  const vor = [];
  if(a && a.strongBuy!=null) vor.push(['KI Strong Buy', a.strongBuy]);
  if(a && a.fvBase!=null) vor.push(['KI Fair Value', a.fvBase]);
  if(_kiChart.fvHeute>0) vor.push(['Fair-Value-Linie (mitlaufend)', _kiChart.fvHeute, true]);
  if(_kiChart.fvWert>0) vor.push(['Fair Value', _kiChart.fvWert]);
  if(_kiChart.npeWert>0) vor.push(['Normal-KGV', _kiChart.npeWert]);
  const knopf = ([n,v,dyn])=>`<button type="button" class="range-btn" data-ki-alarm-setzen="1" data-preis="${Number(v)}" data-name="${escapeHtml(n)}" data-bed="below"${dyn?' data-dyn="1" title="Grenze läuft mit der orangen Fair-Value-Linie mit (nachts neu berechnet)"':''}>${escapeHtml(n)} ${kiNum(v)}</button>`;
  const liste = (data.priceAlerts||[]).filter(x=>x.symbol===sym);
  pnl.innerHTML = `<div style="display:flex;flex-wrap:wrap;gap:6px;align-items:center;">
      <b>🔔 Alarm ${escapeHtml(sym)}</b><span class="muted">Kurs fällt unter:</span>${vor.map(knopf).join('') || '<span class="muted">keine Bewertungswerte</span>'}<span id="kiAlarmBank"></span>
      <span style="width:10px;"></span><span class="muted">eigener:</span>
      <select id="kiAlarmBed" style="padding:2px 4px;"><option value="below">unter</option><option value="above">über</option></select>
      <input id="kiAlarmPreis" type="text" inputmode="decimal" placeholder="Preis" style="width:80px;padding:2px 6px;">
      <button type="button" class="btn" data-ki-alarm-eigen="1" style="padding:3px 10px;">Setzen</button></div>
    <div style="margin-top:6px;">${liste.length ? liste.map(x=>`<span style="display:inline-block;margin:2px 10px 2px 0;">${x.condition==='below'?'▼ unter':'▲ über'} <b>${kiNum(x.targetPrice)}</b> <span class="muted">${escapeHtml(String(x.name||'').split(' · ').slice(-1)[0])}${x.dynamisch?' · läuft mit (Stand '+escapeHtml(x.dynamischStand||x.createdAt||'')+')':''}${x.fired?' · ausgelöst':''}</span> <a href="#" data-ki-alarm-weg="${escapeHtml(x.id)}" title="Alarm löschen">✕</a></span>`).join('') : '<span class="muted">Noch kein Alarm für diese Aktie.</span>'}
      <span class="muted" style="margin-left:6px;">· Prüfung durch den Server, Meldung einmal je Alarm.</span></div>`;
  try{ const b = await (await fetch('bewertung-bank.php?symbol=' + encodeURIComponent(sym), {cache:'no-store'})).json();
    if(b && b.bank && b.fv_unten>0){ const el = document.getElementById('kiAlarmBank'); if(el) el.innerHTML = knopf(['Bank-Fair-Value (unten)', b.fv_unten]); } } catch(e){}
}
// ===== Finanzielle Fitness nach Vorlage "Fiscal fitness" (Nutzer 13.11.2026: "es fehlt noch viel", Vorlage wird abgeschafft) =====
// 12 Balkengrafiken je Geschaeftsjahr aus den SEC-Jahresberichten (fitness.php), Auswahl links, Werte + CAGR darunter.
let _kiFitLog = false, _kiFitProz = false, _kiFitEigen = ['umsatz','gewinn','fcf'];
// Kennzahlen fuer die eigene Grafik (Vorlage "Fungraphs"): [Schluessel, Name, Wert je Zeitraum, Einheit]
const KI_FIT_KATALOG = [
  ['umsatz','Umsatz (Mio.)', y=>y.umsatz!=null ? y.umsatz/1e6 : null, 'Mio.'], ['brutto','Bruttoergebnis (Mio.)', y=>y.brutto!=null ? y.brutto/1e6 : null, 'Mio.'],
  ['betrieb','Betriebsergebnis (Mio.)', y=>y.betrieb!=null ? y.betrieb/1e6 : null, 'Mio.'], ['gewinn','Gewinn (Mio.)', y=>y.gewinn!=null ? y.gewinn/1e6 : null, 'Mio.'],
  ['ocf','Op. Cashflow (Mio.)', y=>y.ocf!=null ? y.ocf/1e6 : null, 'Mio.'], ['fcf','Free Cashflow (Mio.)', y=>y.ocf!=null ? (y.ocf-(y.capex||0))/1e6 : null, 'Mio.'],
  ['capex','Investitionen (Mio.)', y=>y.capex!=null ? y.capex/1e6 : null, 'Mio.'], ['divGezahlt','Dividenden gezahlt (Mio.)', y=>y.divGezahlt!=null ? y.divGezahlt/1e6 : null, 'Mio.'],
  ['rueckkauf','Aktienrückkäufe (Mio.)', y=>y.rueckkauf!=null ? y.rueckkauf/1e6 : null, 'Mio.'], ['aktien','Aktien (Mio.)', y=>y.aktien ? y.aktien/1e6 : null, 'Mio.'],
  ['vermoegen','Vermögen (Mio.)', y=>y.vermoegen!=null ? y.vermoegen/1e6 : null, 'Mio.'], ['ek','Eigenkapital (Mio.)', y=>y.ek!=null ? y.ek/1e6 : null, 'Mio.'],
  ['schulden','Finanzschulden (Mio.)', y=>y.schulden!=null ? y.schulden/1e6 : null, 'Mio.'],
  ['eps','Gewinn je Aktie', y=>y.gewinn!=null && y.aktien ? y.gewinn/y.aktien : null, 'je'], ['fcfje','FCF je Aktie', y=>y.ocf!=null && y.aktien ? (y.ocf-(y.capex||0))/y.aktien : null, 'je'],
  ['divje','Dividende je Aktie', y=>y.divJe, 'je'], ['bvje','Buchwert je Aktie', y=>y.ek!=null && y.aktien ? y.ek/y.aktien : null, 'je'],
  ['bmarge','Bruttomarge %', y=>y.brutto!=null && y.umsatz>0 ? y.brutto/y.umsatz*100 : null, '%'], ['omarge','Operative Marge %', y=>y.betrieb!=null && y.umsatz>0 ? y.betrieb/y.umsatz*100 : null, '%'],
  ['nmarge','Nettomarge %', y=>y.gewinn!=null && y.umsatz>0 ? y.gewinn/y.umsatz*100 : null, '%'], ['roe','ROE %', y=>{ const ek = y._ekQ>0 ? y._ekQ : y.ek; return y.gewinn!=null && ek>0 ? y.gewinn*(y._f||1)/ek*100 : null; }, '%'],
  ['payout','Ausschüttungsquote %', y=>y.divGezahlt!=null && y.gewinn>0 ? y.divGezahlt/y.gewinn*100 : null, '%'],
];
let _kiFitFeld = 'fcfdiv', _kiFitJahre = 0, _kiFitDaten = {}, _kiFitChart = null, _kiFitDivs = null, _kiFitQ = false;   // _kiFitQ = Quartale
const KI_FIT_FELDER = [
  ['fcfdiv', 'Free Cashflow > Dividende', 'Free Cash Flow > Dividend', 'je Aktie', y=>[['Free Cashflow je Aktie', y.ocf!=null && y.aktien ? (y.ocf-(y.capex||0))/y.aktien : null, '#f4511e'], ['Dividende je Aktie', y.divJe!=null ? y.divJe : (y.divGezahlt!=null && y.aktien ? y.divGezahlt/y.aktien : null), '#7b1fa2']]],
  ['ocfw', 'Wachstum op. Cashflow', 'Operating Cash Flow Growth', 'je Aktie', y=>[['Op. Cashflow je Aktie', y.ocf!=null && y.aktien ? y.ocf/y.aktien : null, '#2563eb']]],
  ['rueck', 'Aktienrückkäufe', 'Share Buybacks', 'Mio.', y=>[['Rückkäufe', y.rueckkauf!=null ? y.rueckkauf/1e6 : null, '#16a34a'], ['Aktien (verwässert)', y.aktien ? y.aktien/1e6 : null, '#64748b']]],
  ['ocfgew', 'Op. Cashflow > Gewinn', 'OCF > Net Income', 'Mio.', y=>[['Op. Cashflow', y.ocf!=null ? y.ocf/1e6 : null, '#2563eb'], ['Gewinn', y.gewinn!=null ? y.gewinn/1e6 : null, '#f59e0b']]],
  ['umsatz', 'Umsatz', 'Sales Revenue', 'Mio.', y=>[['Umsatz', y.umsatz!=null ? y.umsatz/1e6 : null, '#0891b2']]],
  ['gewinn', 'Gewinn', 'Net Income', 'Mio.', y=>[['Gewinn', y.gewinn!=null ? y.gewinn/1e6 : null, '#16a34a']]],
  ['de', 'Schulden zu Eigenkapital', 'Debt to Equity', '%', y=>[['Langfr. Schulden / EK', (y.schuldenLang ?? y.schulden)!=null && y.ek>0 ? (y.schuldenLang ?? y.schulden)/y.ek*100 : null, '#dc2626']]],
  ['marge', 'Margen', 'Margins', '%', y=>[['Bruttomarge', y.brutto!=null && y.umsatz>0 ? y.brutto/y.umsatz*100 : null, '#16a34a'], ['Operative Marge', y.betrieb!=null && y.umsatz>0 ? y.betrieb/y.umsatz*100 : null, '#2563eb'], ['Nettomarge', y.gewinn!=null && y.umsatz>0 ? y.gewinn/y.umsatz*100 : null, '#f59e0b']]],
  ['at', 'Kapitalumschlag', 'Asset Turnover (Sales/Assets)', '×', y=>[['Umsatz / Vermögen', y.umsatz!=null && y.vermoegen>0 ? y.umsatz*(y._f||1)/y.vermoegen : null, '#0891b2']]],
  ['roa', 'Gesamtkapitalrendite', 'Return on Assets', '%', y=>[['ROA', y.gewinn!=null && y.vermoegen>0 ? y.gewinn*(y._f||1)/y.vermoegen*100 : null, '#7c3aed']]],
  ['roe', 'Eigenkapitalrendite', 'Return on Equity', '%', y=>{ const ek = y._ekQ>0 ? y._ekQ : ((y.ek>0 && y._ekVor>0) ? (y.ek+y._ekVor)/2 : y.ek); return [['ROE (Ø Eigenkapital)', y.gewinn!=null && ek>0 ? y.gewinn*(y._f||1)/ek*100 : null, '#7c3aed']]; }],
  ['roic', 'Rendite auf investiertes Kapital', 'Return on Invested Capital', '%', y=>[['ROIC', y.betrieb!=null && ((y.ek||0)+(y.schulden||0))>0 ? y.betrieb*(y._f||1)*(1-(y.steuersatz!=null ? Math.min(0.35, Math.max(0.1, y.steuersatz)) : 0.21))/((y.ek||0)+(y.schulden||0))*100 : null, '#7c3aed']]],
  ['eigen', 'Eigene Grafik', 'bis 3 Kennzahlen frei wählbar', '', y=>_kiFitEigen.map((k,i)=>{ const z = KI_FIT_KATALOG.find(x=>x[0]===k) || KI_FIT_KATALOG[0]; const v = z[2](y); return [z[1], v, ['#2563eb','#ea580c','#16a34a'][i], z[3]]; })],
  ['tabelle', 'Alle Jahreszahlen (Tabelle)', 'GuV, Cashflow, Bilanz', 'Mio.', y=>[]],
];
// Wie Vorlage "Financials" (dort Premium): alle Jahreszahlen untereinander mit Mini-Verlauf und CAGR, neueste Jahre links
function kiFitPeriodeKnopf(d){
  if(!(d.quartale||[]).length) return '';
  return `<span style="margin-left:6px;"></span><button type="button" class="range-btn${!_kiFitQ?' active':''}" data-ki-fit-q="0">Jahr</button><button type="button" class="range-btn${_kiFitQ?' active':''}" data-ki-fit-q="1">Quartal</button>`;
}
function kiFitPeriodeBinden(el, sym){ el.querySelectorAll('[data-ki-fit-q]').forEach(b=>b.addEventListener('click', ()=>{ _kiFitQ = b.dataset.kiFitQ==='1'; kiFitnessZeichnen(sym); })); }
function kiFinanzTabelle(el, d, sym){
  if(_kiFitChart){ try{ _kiFitChart.destroy(); } catch(e){} _kiFitChart = null; }
  let jahre = kiFitReihe(d, sym); if(_kiFitJahre) jahre = jahre.slice(-_kiFitJahre * (_kiFitQ ? 4 : 1));
  const mio = v=>v==null ? null : v/1e6, je = (a,b)=>a!=null && b ? a/b : null, pz = (a,b)=>a!=null && b ? a/b*100 : null;
  const Z = [
    ['GuV'],
    ['Umsatz', y=>mio(y.umsatz)], ['Bruttoergebnis', y=>mio(y.brutto)], ['Betriebsergebnis', y=>mio(y.betrieb)], ['Gewinn', y=>mio(y.gewinn)],
    ['Bruttomarge %', y=>pz(y.brutto, y.umsatz), '%'], ['Operative Marge %', y=>pz(y.betrieb, y.umsatz), '%'], ['Nettomarge %', y=>pz(y.gewinn, y.umsatz), '%'], ['Steuersatz %', y=>y.steuersatz!=null ? y.steuersatz*100 : null, '%'],
    ['Cashflow'],
    ['Op. Cashflow', y=>mio(y.ocf)], ['Investitionen', y=>mio(y.capex)], ['Free Cashflow', y=>y.ocf!=null ? mio(y.ocf-(y.capex||0)) : null], ['Dividenden gezahlt', y=>mio(y.divGezahlt)], ['Aktienrückkäufe', y=>mio(y.rueckkauf)],
    ['Bilanz'],
    ['Vermögen', y=>mio(y.vermoegen)], ['Eigenkapital', y=>mio(y.ek)], ['Finanzschulden', y=>mio(y.schulden)], ['Langfr. Schulden / EK %', y=>pz(y.schuldenLang ?? y.schulden, y.ek>0 ? y.ek : null), '%'],
    ['Je Aktie'],
    ['Aktien (Mio., verwässert)', y=>mio(y.aktien)], ['Gewinn je Aktie', y=>je(y.gewinn, y.aktien), 'je'], ['Op. Cashflow je Aktie', y=>je(y.ocf, y.aktien), 'je'], ['Free Cashflow je Aktie', y=>y.ocf!=null ? je(y.ocf-(y.capex||0), y.aktien) : null, 'je'], ['Buchwert je Aktie', y=>je(y.ek, y.aktien), 'je'],
    ['Renditen'],
    ['ROE % (Ø EK)', y=>{ const ek = y._ekQ>0 ? y._ekQ : ((y.ek>0 && y._ekVor>0) ? (y.ek+y._ekVor)/2 : (y.ek>0 ? y.ek : null)); return pz(y.gewinn!=null ? y.gewinn*y._f : null, ek); }, '%'], ['ROA %', y=>pz(y.gewinn!=null ? y.gewinn*y._f : null, y.vermoegen), '%'], ['Kapitalumschlag', y=>je(y.umsatz!=null ? y.umsatz*y._f : null, y.vermoegen), '×'],
  ];
  const fmt = (v,e)=>v==null || !isFinite(v) ? '–' : kiNum(v, e==='%'||e==='×'||e==='je' ? 2 : 0);
  const spark = w=>{ const v = w.filter(x=>x!=null && isFinite(x)); if(v.length<2) return ''; const mn = Math.min(...v), mx = Math.max(...v), sp = (mx-mn)||1;
    const pts = w.map((x,i)=>x==null ? null : `${(i/(w.length-1)*60).toFixed(1)},${(18-(x-mn)/sp*16).toFixed(1)}`).filter(Boolean).join(' ');
    return `<svg width="62" height="20" style="vertical-align:middle;"><polyline points="${pts}" fill="none" stroke="#2563eb" stroke-width="1.5"/></svg>`; };
  const cagr = w=>{ let a = -1, b = -1; w.forEach((x,i)=>{ if(x!=null && x>0){ if(a<0) a = i; b = i; } }); if(a<0 || b<=a) return '–';
    const n = (new Date(jahre[b].date)-new Date(jahre[a].date))/31557600000; return n>0.5 ? kiNum((Math.pow(w[b]/w[a], 1/n)-1)*100, 2)+' %' : '–'; };
  const neu = jahre.slice().reverse();
  const kn = (k,t)=>`<button type="button" class="range-btn${_kiFitJahre===k?' active':''}" data-ki-fit-j="${k}">${t}</button>`;
  el.innerHTML = `<div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:6px;"><b style="font-size:15px;">Finanzzahlen</b>
      ${kn(0,'MAX')}${kn(15,'15J')}${kn(10,'10J')}${kn(5,'5J')}${kiFitPeriodeKnopf(d)}<button type="button" class="range-btn" data-ki-fit-zurueck="1">← Grafiken</button>
      <span class="muted" style="font-size:11.5px;">Mio. ${escapeHtml(d.waehrung||'')} · Quelle ${escapeHtml(d.quelle||'SEC')} · CAGR über den gewählten Zeitraum</span></div>
    <div style="overflow-x:auto;"><table style="min-width:0;font-size:11.5px;border-collapse:collapse;white-space:nowrap;">
      <tr style="position:sticky;top:0;background:var(--card);"><td style="padding:3px 8px;font-weight:700;">Kennzahl</td><td style="padding:3px 6px;" class="muted">Verlauf</td>${neu.map(y=>`<td style="padding:3px 6px;text-align:right;font-weight:700;">${_kiFitQ ? '' : 'GJ '}${y.date.slice(5,7)}/${y.date.slice(0,4)}</td>`).join('')}<td style="padding:3px 8px;text-align:right;font-weight:700;">CAGR</td></tr>
      ${Z.map(z=>{ if(z.length===1) return `<tr><td colspan="${neu.length+3}" style="padding:7px 8px 2px;font-weight:700;border-bottom:1px solid var(--border);">${escapeHtml(z[0])}</td></tr>`;
        const w = jahre.map(y=>{ const v = z[1](y); return (v==null || !isFinite(v)) ? null : v; });
        return `<tr><td style="padding:2px 8px;">${escapeHtml(z[0])}</td><td style="padding:2px 6px;">${spark(w)}</td>${w.slice().reverse().map(v=>`<td style="padding:2px 6px;text-align:right;${v!=null&&v<0?'color:#dc2626;':''}">${fmt(v,z[2])}</td>`).join('')}<td style="padding:2px 8px;text-align:right;font-weight:700;">${(z[2]==='%'||z[2]==='×') ? '' : cagr(w)}</td></tr>`; }).join('')}
    </table></div>`;
  el.querySelector('[data-ki-fit-zurueck]').addEventListener('click', ()=>{ _kiFitFeld = 'fcfdiv'; kiFitnessZeichnen(sym); });
  kiFitPeriodeBinden(el, sym);
  el.querySelectorAll('[data-ki-fit-j]').forEach(b=>b.addEventListener('click', ()=>{ _kiFitJahre = Number(b.dataset.kiFitJ); kiFitnessZeichnen(sym); }));
}
// Jahres- oder Quartalsreihe fuer Grafiken und Tabelle: Dividende je Aktie aus den Yahoo-Ausschuettungen des Zeitraums nach Vorlage,
// Eigenkapital der Vorperiode (fuer Ø-ROE), Hochrechnungsfaktor 4 fuer Quartalsrenditen
function kiFitReihe(d, sym){
  const roh = (_kiFitQ && (d.quartale||[]).length) ? d.quartale : d.jahre;
  const r = roh.map(y=>({...y, _f: roh===d.jahre ? 1 : 4}));
  r.forEach((y,i)=>{ y._ekVor = i ? r[i-1].ek : null;
    if(y._f===1 && (d.quartale||[]).length){ const von = i ? r[i-1].date : ''; const q = d.quartale.filter(x=>x.date>von && x.date<=y.date && x.ek!=null);
      if(von && q.length===4) y._ekQ = q.reduce((a,x)=>a+x.ek,0)/4; } });
  if(_kiFitDivs && _kiFitDivs.sym===sym && _kiFitDivs.divs.length){
    const erst = _kiFitDivs.divs[0][0];
    r.forEach((y,i)=>{ const von = i ? r[i-1].date : (()=>{ const x = new Date(y.date+'T12:00:00Z'); if(y._f===4) x.setUTCMonth(x.getUTCMonth()-3); else x.setUTCFullYear(x.getUTCFullYear()-1); return x.toISOString().slice(0,10); })();
      if(von < erst) return; const yh = _kiFitDivs.divs.reduce((a,[t,b])=>(t>von && t<=y.date) ? a+Number(b) : a, 0);
      // Yahoo-Liste hat Luecken (AAPL: 08/2012-05/2013 fehlen) -> liegt sie unter 75 % des SEC-Werts, gilt der SEC-Wert
      if(!(y.divJe>0 && yh < y.divJe*0.75)) y.divJe = yh; });
  }
  return r;
}
async function kiFitnessZeichnen(sym){
  const el = document.getElementById('kiFitness'); if(!el || _kiTab!=='fitness') return;
  el.style.cssText = 'margin-top:16px;border-top:1px solid var(--border);padding-top:10px;';
  if(!_kiFitDaten[sym] || _kiFitDaten[sym].laedt){
    el.innerHTML = '<b style="font-size:15px;">Finanzielle Fitness</b> <span class="muted">· Fiscal fitness – lade SEC-Jahresberichte …</span>';
    try{ _kiFitDaten[sym] = await (await fetch('fitness.php?symbol=' + encodeURIComponent(sym), {cache:'no-store'})).json(); } catch(e){ _kiFitDaten[sym] = {fehler:e.message}; }
    if(_kiFirmaArg && _kiFirmaArg[0]===sym && _kiFirmaArg[1].isConnected) kiFirmenInfo(..._kiFirmaArg);   // Schulden/Kapital + Splits nachtragen
  }
  if(!_kiChart || String(_kiChart.ctx?.sym||'')!==sym || _kiTab!=='fitness') return;
  const el2 = document.getElementById('kiFitness'); if(!el2) return; if(el2!==el) return kiFitnessZeichnen(sym);   // Fenster wurde neu aufgebaut
  el2.style.cssText = el.style.cssText;
  const d = _kiFitDaten[sym];
  if(!(_kiFitDivs && _kiFitDivs.sym===sym)){
    _kiFitDivs = {sym, divs:[]};
    fetch('quote.php?ohlc=1&symbols=' + encodeURIComponent(sym) + '&interval=1mo&range=max', {cache:'no-store'}).then(r=>r.json()).then(q=>{
      const roh = Array.isArray(q && q.dividenden) ? q.dividenden : [];
      _kiFitDivs = {sym, divs: roh.filter(([t,b], i)=>{ const vor = roh.slice(Math.max(0,i-8), i).map(x=>Number(x[1])).sort((x,y)=>x-y); if(vor.length>=3){ const med = vor[Math.floor(vor.length/2)]; if(med>0 && Number(b) > 3*med) return false; } return true; })};
      if(_kiTab==='fitness' && _kiFitDivs.divs.length) kiFitnessZeichnen(sym);
    }).catch(()=>{});
  }
  if(!d || d.fehler || !(d.jahre||[]).length){ el.innerHTML = `<b style="font-size:15px;">Finanzielle Fitness</b> <span class="muted">· Fiscal fitness – ${escapeHtml((d && (d.fehler||d.error)) || 'keine Daten')}</span>`; return; }
  const feld = KI_FIT_FELDER.find(f=>f[0]===_kiFitFeld) || KI_FIT_FELDER[0];
  if(feld[0]==='tabelle') return kiFinanzTabelle(el, d, sym);
  // Dividende je Aktie = Summe der Yahoo-Ausschuettungen (Ex-Tag) im Geschaeftsjahr - nach Vorlage (AMGN 2024: 4 x 2,25 = 9,00;
  // die SEC meldet dort die erklaerte Dividende 9,13). Nur fuer Jahre, die die Yahoo-Historie abdeckt.
  let jahre = kiFitReihe(d, sym);
  if(_kiFitJahre) jahre = jahre.slice(-_kiFitJahre * (_kiFitQ ? 4 : 1));
  let reihen = feld[4](jahre[0]).map((r,i)=>({name:r[0], farbe:r[2], einheit:r[3]||feld[3], werte:jahre.map(y=>{ const v = feld[4](y)[i][1]; return (v==null || !isFinite(v)) ? null : v; })}));
  // Vorlage "Labels: Absolute / Change": Veraenderung zum Vorzeitraum in % (bei Quartalen zum Vorjahresquartal)
  if(_kiFitProz){ const ab = _kiFitQ ? 4 : 1; reihen = reihen.map(r=>({...r, einheit:'%', werte:r.werte.map((v,i)=>{ const a = r.werte[i-ab]; return (v!=null && a!=null && a>0) ? (v/a-1)*100 : null; })})); }
  const lab = jahre.map(y=>y.date.slice(5,7)+'/'+y.date.slice(2,4));
  const einh = _kiFitProz ? '%' : (feld[0]==='eigen' ? (reihen.every(r=>r.einheit===reihen[0].einheit) ? reihen[0].einheit : 'gemischt') : feld[3]), dez = (einh==='Mio.') ? 0 : 2;
  // Einheit je Zeile (eigene Grafik mischt Mio., je Aktie und %)
  const fmt = (v, u=einh)=>v==null ? '–' : kiNum(v, u==='Mio.' ? 0 : 2) + (u==='%' ? ' %' : (u==='×' ? '×' : ''));
  const cagr = w=>{ const i0 = w.findIndex(v=>v!=null && v>0); let i1 = -1; for(let i=w.length-1;i>=0;i--) if(w[i]!=null && w[i]>0){ i1 = i; break; }
    if(i0<0 || i1<=i0) return null; const n = (new Date(jahre[i1].date)-new Date(jahre[i0].date))/31557600000; return n>0.5 ? (Math.pow(w[i1]/w[i0], 1/n)-1)*100 : null; };
  const schnitt = w=>{ const v = w.filter(x=>x!=null); return v.length ? v.reduce((a,b)=>a+b,0)/v.length : null; };
  const zusatz = r=>{ const u = einh==='gemischt' ? r.einheit : einh; return (u==='%' || u==='×') ? `Ø ${fmt(schnitt(r.werte), u)}` : null; };
  const zusatzAlt = r=>(einh==='%' || einh==='×') ? `Ø ${fmt(schnitt(r.werte))}` : (()=>{ const c = cagr(r.werte); return c==null ? '–' : `${kiNum(c,2)} %`; })();
  const kn = (k,t)=>`<button type="button" class="range-btn${_kiFitJahre===k?' active':''}" data-ki-fit-j="${k}">${t}</button>`;
  el.innerHTML = `<div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:6px;"><b style="font-size:15px;">Finanzielle Fitness</b>
      ${kn(0,'MAX')}${kn(15,'15J')}${kn(10,'10J')}${kn(5,'5J')}${kiFitPeriodeKnopf(d)}
      <span style="margin-left:6px;"></span><button type="button" class="range-btn${!_kiFitLog?' active':''}" data-ki-fit-log="0" title="lineare Skala">Linear</button><button type="button" class="range-btn${_kiFitLog?' active':''}" data-ki-fit-log="1" title="logarithmische Skala">Log</button>
      <span style="margin-left:6px;"></span><button type="button" class="range-btn${!_kiFitProz?' active':''}" data-ki-fit-proz="0" title="absolute Werte">Werte</button><button type="button" class="range-btn${_kiFitProz?' active':''}" data-ki-fit-proz="1" title="Veränderung zum Vorzeitraum">Veränd. %</button>
      <span class="muted" style="font-size:11.5px;">Quelle: ${escapeHtml(d.quelle||'SEC')} · ${escapeHtml(d.waehrung||'')} · Stand ${escapeHtml(d.stand||'')}${_kiFitQ ? ' · Quartale: Renditen und Kapitalumschlag ×4 aufs Jahr hochgerechnet' : ''}</span></div>
    <div style="display:flex;gap:14px;flex-wrap:wrap;align-items:flex-start;">
      <div style="flex:0 0 230px;font-size:12.5px;">${KI_FIT_FELDER.map(f=>`<label style="display:flex;gap:6px;align-items:flex-start;padding:3px 0;cursor:pointer;"><input type="radio" name="kiFitFeld" data-ki-fit="${f[0]}" ${f[0]===feld[0]?'checked':''}><span>${escapeHtml(f[1])}</span></label>`).join('')}</div>
      <div style="flex:1 1 520px;min-width:300px;">${feld[0]==='eigen' ? `<div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:6px;font-size:12px;">${[0,1,2].map(i=>`<select data-ki-fit-eigen="${i}" style="padding:2px 4px;">${['<option value="">–</option>', ...KI_FIT_KATALOG.map(z=>`<option value="${z[0]}" ${_kiFitEigen[i]===z[0]?'selected':''}>${escapeHtml(z[1])}</option>`)].join('')}</select>`).join('')}</div>` : ''}<div class="muted" style="font-size:12px;margin-bottom:4px;"><b style="color:var(--text);">${escapeHtml(feld[1])}</b> (${escapeHtml(einh==='je Aktie' ? 'je Aktie, ' + (d.waehrung||'') : einh==='Mio.' ? 'Mio. ' + (d.waehrung||'') : einh)})</div>
        <div style="height:300px;position:relative;"><canvas id="kiFitCanvas"></canvas></div>
        <div style="overflow-x:auto;"><table style="min-width:0;font-size:11px;border-collapse:collapse;margin-top:6px;white-space:nowrap;">
          <tr><td></td>${lab.map(l=>`<td style="padding:1px 5px;text-align:right;" class="muted">${l}</td>`).join('')}<td style="padding:1px 6px;text-align:right;font-weight:700;">${(einh==='%'||einh==='×') ? 'Schnitt' : 'CAGR'}</td></tr>
          ${reihen.map(r=>`<tr><td style="padding:1px 6px;"><span style="color:${r.farbe}">■</span> ${escapeHtml(r.name)}</td>${r.werte.map(v=>`<td style="padding:1px 5px;text-align:right;">${fmt(v, einh==='gemischt' ? r.einheit : einh)}</td>`).join('')}<td style="padding:1px 6px;text-align:right;font-weight:700;">${zusatz(r) ?? zusatzAlt(r)}</td></tr>`).join('')}
        </table></div>
        <div class="muted" style="font-size:11px;margin-top:4px;">Werte aus den Jahresberichten bei der SEC (nicht bereinigt). Aktienzahl verwässert im Jahresdurchschnitt; ROIC = op. Ergebnis nach Steuern ÷ (Eigenkapital + Finanzschulden).</div>
      </div></div>`;
  el.querySelectorAll('[data-ki-fit]').forEach(r=>r.addEventListener('change', ()=>{ _kiFitFeld = r.dataset.kiFit; kiFitnessZeichnen(sym); }));
  kiFitPeriodeBinden(el, sym);
  el.querySelectorAll('[data-ki-fit-log]').forEach(b=>b.addEventListener('click', ()=>{ _kiFitLog = b.dataset.kiFitLog==='1'; kiFitnessZeichnen(sym); }));
  el.querySelectorAll('[data-ki-fit-proz]').forEach(b=>b.addEventListener('click', ()=>{ _kiFitProz = b.dataset.kiFitProz==='1'; kiFitnessZeichnen(sym); }));
  el.querySelectorAll('[data-ki-fit-eigen]').forEach(sl=>sl.addEventListener('change', ()=>{ const i = Number(sl.dataset.kiFitEigen); _kiFitEigen[i] = sl.value; _kiFitEigen = _kiFitEigen.filter((v,j)=>v || j<1); if(!_kiFitEigen.length) _kiFitEigen = ['umsatz']; kiFitnessZeichnen(sym); }));
  el.querySelectorAll('[data-ki-fit-j]').forEach(b=>b.addEventListener('click', ()=>{ _kiFitJahre = Number(b.dataset.kiFitJ); kiFitnessZeichnen(sym); }));
  if(_kiFitChart){ try{ _kiFitChart.destroy(); } catch(e){} _kiFitChart = null; }
  if(typeof Chart==='undefined') return;
  const linie = feld[0]==='rueck' && !_kiFitProz;   // Aktienzahl als Linie auf eigener Achse
  const zweiAchsen = feld[0]==='eigen' && !_kiFitProz && reihen.some(r=>r.einheit==='%') && reihen.some(r=>r.einheit!=='%');
  _kiFitChart = new Chart(document.getElementById('kiFitCanvas'), {data:{labels:lab, datasets:reihen.map((r,i)=>({type:(linie && i===1) ? 'line' : 'bar', label:r.name, data:r.werte, backgroundColor:r.farbe, borderColor:r.farbe, yAxisID:((linie && i===1) || (zweiAchsen && reihen[i].einheit==='%')) ? 'y2' : 'y', pointRadius:3, borderWidth:(linie && i===1) ? 2 : 0}))},
    options:{responsive:true, maintainAspectRatio:false, animation:false, plugins:{legend:{position:'bottom', labels:{boxWidth:10, font:{size:11}}}, tooltip:{callbacks:{label:c=>`${c.dataset.label}: ${fmt(c.parsed.y)}`}}},
      scales:{y:{type:_kiFitLog && !_kiFitProz ? 'logarithmic' : 'linear', beginAtZero:!_kiFitLog, ticks:{callback:v=>kiNum(v, einh==='Mio.'?0:1)}}, ...((linie || zweiAchsen) ? {y2:{position:'right', grid:{drawOnChartArea:false}, ticks:{callback:v=>kiNum(v,0) + (zweiAchsen ? ' %' : '')}}} : {})}}});
}
// Firmenangaben rechts unter dem Graph Key nach Vorlage "Kurzfakten" + "Company Info" (Nutzer 13.11.2026). Analysten: statt FASTs
// Scorecard (Jahresschaetzungen der Vergangenheit, frei nicht verfuegbar) die Gewinnueberraschungen der letzten 4 Quartale.
let _kiFirmaArg = null;
// Performance-Grafiken nach Vorlage "Performance": seit Beginn der Gewinndaten (hoechstens 20 J.), Monatswerte
let _kiPerfArg = null; const _kiPerfCharts = [];
async function kiPerfZeichnen(j, pkt, FVR, npe){
  if(typeof Chart==='undefined') return;
  _kiPerfArg = [j, pkt, FVR, npe];
  const c1 = document.getElementById('kiPerfKum'), c2 = document.getElementById('kiPerfKgv'); if(!c1 || !c2) return;
  _kiPerfCharts.splice(0).forEach(c=>{ try{ c.destroy(); } catch(e){} });
  const li = pkt.filter(p=>p.eps>0).map(p=>[p.t, p.eps]); if(li.length<2) return;
  const lin = t=>{ if(t<=li[0][0]) return null; if(t>=li[li.length-1][0]) return li[li.length-1][1];
    for(let i=0;i<li.length-1;i++) if(t>=li[i][0] && t<=li[i+1][0]){ const f = (new Date(t)-new Date(li[i][0]))/Math.max(1,(new Date(li[i+1][0])-new Date(li[i][0]))); return li[i][1]+(li[i+1][1]-li[i][1])*f; } return null; };
  const ab = (()=>{ const d = new Date(); d.setUTCFullYear(d.getUTCFullYear()-20); const g = d.toISOString().slice(0,10); return li[0][0] > g ? li[0][0] : g; })();
  const k = j.kerzen.filter(x=>x[0] >= ab && lin(x[0])!=null); if(k.length<6) return;
  let sp = null; try{ sp = await (await fetch('quote.php?ohlc=1&symbols=' + encodeURIComponent(_kiVergleich) + '&interval=1mo&range=max', {cache:'no-store'})).json(); } catch(e){}
  const spAm = {}; (sp && sp.kerzen || []).forEach(x=>{ spAm[x[0].slice(0,7)] = x[4]; });
  const k0 = k[0][4], e0 = lin(k[0][0]), s0 = spAm[k[0][0].slice(0,7)];
  const lab = k.map(x=>x[0].slice(0,7));
  const opt = (fmt)=>({responsive:true, maintainAspectRatio:false, animation:false, interaction:{mode:'index', intersect:false}, elements:{point:{radius:0}},
    plugins:{legend:{position:'bottom', labels:{boxWidth:10, font:{size:11}}}, tooltip:{callbacks:{label:c=>`${c.dataset.label}: ${fmt(c.parsed.y)}`}}},
    scales:{x:{ticks:{maxTicksLimit:8, font:{size:10}}}, y:{ticks:{callback:v=>fmt(v), font:{size:10}}}}});
  const pz = v=>v==null ? '–' : (v>=0?'+':'') + kiNum(v,0) + ' %';
  _kiPerfCharts.push(new Chart(c1, {type:'line', data:{labels:lab, datasets:[
    {label:'Kurs', data:k.map(x=>(x[4]/k0-1)*100), borderColor:'#111', borderWidth:2},
    {label:'Gewinn je Aktie', data:k.map(x=>(lin(x[0])/e0-1)*100), borderColor:'#ea580c', borderWidth:2},
    ...(s0 ? [{label:'Kurs ' + _kiVergleich, data:k.map(x=>{ const v = spAm[x[0].slice(0,7)]; return v ? (v/s0-1)*100 : null; }), borderColor:'#2563eb', borderWidth:1.5, borderDash:[4,3]}] : [])]}, options:opt(pz)}));
  const kgv = k.map(x=>{ const e = lin(x[0]); return e>0 ? Math.min(80, x[4]/e) : null; });
  _kiPerfCharts.push(new Chart(c2, {type:'line', data:{labels:lab, datasets:[
    {label:'KGV (Kurs ÷ Gewinn)', data:kgv, borderColor:'#111', borderWidth:2},
    {label:`Fair-Value-KGV ${kiNum(FVR,1)}`, data:k.map(()=>FVR), borderColor:'#ea580c', borderWidth:1.5},
    ...(npe ? [{label:`Normal-KGV ${kiNum(npe,1)}`, data:k.map(()=>npe), borderColor:'#2563eb', borderWidth:1.5, borderDash:[4,3]}] : [])]}, options:opt(v=>v==null ? '–' : kiNum(v,1)+'×')}));
}
async function kiFirmenInfo(sym, keyEl, x){
  if(!sym || !keyEl) return; _kiFirmaArg = [sym, keyEl, x];
  let f = null; try{ f = await (await fetch('fitness.php?info=1&symbol=' + encodeURIComponent(sym), {cache:'no-store'})).json(); } catch(e){}
  if(!keyEl.isConnected) return;
  const fit = _kiFitDaten[sym], reihe = fit ? ((fit.quartale||[]).length ? fit.quartale : (fit.jahre||[])) : [], lj = reihe.length ? reihe[reihe.length-1] : null;
  const lsch = lj ? (lj.schuldenLang ?? lj.schulden) : null;
  const ldk = lj && lsch!=null && lj.ek!=null && (lsch+lj.ek)>0 ? lsch/(lsch+lj.ek)*100 : null;
  const gross = v=>v==null ? '–' : (Math.abs(v)>=1e12 ? kiNum(v/1e12,2)+' Bio.' : Math.abs(v)>=1e9 ? kiNum(v/1e9,2)+' Mrd.' : kiNum(v/1e6,0)+' Mio.');
  const zeile = (t, v, tip)=>`<tr${tip?` title="${escapeHtml(tip)}"`:''}><td style="padding:2px 6px;white-space:normal;overflow-wrap:anywhere;">${t}</td><td style="padding:2px 6px;text-align:right;font-weight:700;width:104px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">${v}</td></tr>`;
  const urteile = {strong_buy:'Starker Kauf', buy:'Kaufen', hold:'Halten', underperform:'Unterdurchschnittlich', sell:'Verkaufen', none:'–'};
  const w = escapeHtml(f && f.waehrung || x.w || '');
  let h = `<div data-ki-firma="1" style="margin-top:10px;font-size:12px;">
    <div style="font-weight:700;margin:4px 0;">Kurzfakten</div><table style="width:100%;min-width:0;table-layout:fixed;border-collapse:collapse;">
    ${zeile('Vortageskurs', f && f.vortag!=null ? kiNum(f.vortag)+' '+w : '–')}
    ${zeile('Gewinnrendite', x.epsRendite!=null ? kiNum(x.epsRendite,2)+' %' : '–', 'Gewinn je Aktie (gemischt) ÷ Kurs = Kehrwert des KGV')}
    ${zeile('Div.-Rendite', x.divRendite!=null ? kiNum(x.divRendite,2)+' %' : '–')}
    ${zeile('Erhöhungen in Folge', x.folge!=null ? x.folge : '–')}
    ${zeile('Jahre gezahlt', x.bezahlt!=null ? x.bezahlt : '–')}
    ${zeile('Typ', escapeHtml(f && f.typ || '–'))}</table>
    <div style="font-weight:700;margin:8px 0 4px;">Firma</div><table style="width:100%;min-width:0;table-layout:fixed;border-collapse:collapse;">
    ${zeile('Branche', `<span style="font-weight:400;white-space:normal;">${escapeHtml(f && f.branche || '–')}</span>`)}
    ${zeile('Land', escapeHtml(f && f.land || '–'))}
    ${zeile('Marktwert', f ? gross(f.marktwert) : '–', w)}
    ${zeile('Unternehmenswert', f ? gross(f.unternehmenswert) : '–', 'Marktwert + Schulden − Kasse, ' + w)}
    ${zeile('Lfr. Schulden/Kapital', ldk!=null ? kiNum(ldk,2)+' %' : '–', 'Langfristige Finanzschulden ÷ (langfr. Schulden + Eigenkapital), letzter Quartalsbericht (SEC)')}
    ${zeile('Kreditrating', '<span class="muted" style="font-weight:400;">nicht frei</span>', 'S&P-Ratings sind nicht frei abrufbar')}
    ${zeile('Splits', fit && fit.splits && fit.splits.length ? fit.splits.slice(-3).map(s=>s.date.slice(0,4)+' '+kiNum(s.faktor,s.faktor%1?1:0)+':1').join(', ') : '–')}</table>`;
  if(f && (f.analysten || (f.quartale||[]).length || (f.stimmung||[]).length)){
    h += `<div style="font-weight:700;margin:8px 0 4px;">Analysten</div><table style="width:100%;min-width:0;table-layout:fixed;border-collapse:collapse;">
      ${zeile('Urteil', f.urteil ? escapeHtml(urteile[f.urteil]||f.urteil) + (f.urteilWert ? ' ('+kiNum(f.urteilWert,1)+')' : '') : '–', '1 = starker Kauf … 5 = Verkaufen')}
      ${zeile('Kursziel Ø', f.kursziel ? kiNum(f.kursziel)+' '+w + (x.kurs ? ` <span style="color:${f.kursziel>=x.kurs?'#16a34a':'#dc2626'}">${f.kursziel>=x.kurs?'+':''}${kiNum((f.kursziel/x.kurs-1)*100,1)} %</span>` : '') : '–', f.kurszielTief ? `Spanne ${kiNum(f.kurszielTief)} – ${kiNum(f.kurszielHoch)}, ${f.analysten||'?'} Analysten` : '')}`;
    // Analystenstimmung jetzt und vor 3 Monaten (Yahoo recommendationTrend)
    const st0 = (f.stimmung||[]).find(x=>x.periode==='0m'), st3 = (f.stimmung||[]).find(x=>x.periode==='-3m');
    if(st0){ const sum = x=>x.starkKaufen+x.kaufen+x.halten+x.verkaufen+x.starkVerkaufen, kauf = x=>x.starkKaufen+x.kaufen;
      const balken = `<span style="display:inline-flex;height:8px;width:96px;border-radius:3px;overflow:hidden;vertical-align:middle;">${[['starkKaufen','#15803d'],['kaufen','#4ade80'],['halten','#facc15'],['verkaufen','#f87171'],['starkVerkaufen','#b91c1c']].map(([k,c])=>`<span style="flex:${st0[k]};background:${c};"></span>`).join('')}</span>`;
      h += zeile('Stimmung', balken, `stark kaufen ${st0.starkKaufen} · kaufen ${st0.kaufen} · halten ${st0.halten} · verkaufen ${st0.verkaufen} · stark verkaufen ${st0.starkVerkaufen}`);
      h += zeile('Kaufempfehlungen', `${kauf(st0)} von ${sum(st0)}${st3 && sum(st3) ? ` <span class="muted" style="font-weight:400;">(vor 3 M. ${kauf(st3)}/${sum(st3)})</span>` : ''}`); }
    // Schaetzungsaenderungen der letzten 30 Tage je Geschaeftsjahr (Yahoo epsRevisions)
    (f.trend||[]).filter(t=>t.rauf30!=null || t.runter30!=null).forEach(t=>{ const r = t.rauf30||0, d = t.runter30||0;
      h += zeile(`Schätzung ${t.periode==='0y' ? 'lfd. GJ' : 'nächstes GJ'} 30 T.`, `<span style="color:#16a34a">▲ ${r}</span> <span style="color:#dc2626">▼ ${d}</span>`, `Analysten, die ihre Gewinnschätzung in 30 Tagen angehoben/gesenkt haben (7 Tage: ▲ ${t.rauf7||0} ▼ ${t.runter7||0})`); });
    const qs = (f.quartale||[]).filter(q=>q.abweichung!=null);
    if(qs.length){ const getr = qs.filter(q=>q.abweichung>=0).length;
      h += zeile(`Gewinn übertroffen (${qs.length} Quartale)`, `${getr} von ${qs.length}`, 'Ist-Gewinn je Aktie gegen Analystenschätzung');
      h += qs.map(q=>zeile(`<span class="muted">${escapeHtml(q.quartal||'')}: ${kiNum(q.ist,2)} statt ${kiNum(q.schaetzung,2)}</span>`, `<span style="color:${q.abweichung>=0?'#16a34a':'#dc2626'}">${q.abweichung>=0?'+':''}${kiNum(q.abweichung*100,1)} %</span>`)).join('');
    }
    h += `</table>`;
  }
  h += `<div class="muted" style="font-size:10.5px;margin-top:4px;">Quelle: Yahoo${f && f.stand ? ' ('+escapeHtml(f.stand)+')' : ''}, Schulden/Kapital SEC.</div></div>`;
  keyEl.querySelector('[data-ki-firma]')?.remove();
  keyEl.insertAdjacentHTML('beforeend', h);
  if(!_kiFitDaten[sym]){ _kiFitDaten[sym] = {laedt:true, jahre:[]};
    try{ _kiFitDaten[sym] = await (await fetch('fitness.php?symbol=' + encodeURIComponent(sym), {cache:'no-store'})).json(); } catch(e){ delete _kiFitDaten[sym]; return; }
    if(keyEl.isConnected) kiFirmenInfo(sym, keyEl, x); }
}
async function kiBewDaten(sym){
  if(_kiBewCache[sym]) return _kiBewCache[sym];
  const j = await (await fetch('fundamentals.php?symbol=' + encodeURIComponent(sym), {cache:'no-store'})).json();
  _kiBewCache[sym] = j;
  return j;
}
async function kiBewZeichnen(chart, j, interval, zeiten, kerzeZu, divs, meins){
  // Wie Vorlage (Nutzer 07.11.2026, Video: "beim Bewegen funktioniert es nicht sauber"): kein Ziehen/Zoomen mit Maus
  // oder Touch, der Zeitraum kommt nur ueber die Knoepfe (MAX, Gewinn-Zeitraum, 20J ...). Und die Kursachse richtet sich
  // nach dem Kurs: Bewertungs-, Prognose- und Faecherlinien duerfen sie hoechstens bis zum 2,5-fachen Kurshoch ziehen
  // (ADM nach Op. Cashflow: Faecher bis 4.000 $, der Kurs war nur noch eine flache Linie am Boden).
  // Nur im Tab Bewertung fest nach Vorlage; Zonen/Dividende/Analyse bleiben frei verschiebbar wie TradingView (Nutzer 07.11.2026)
  if(_kiTab==='bewertung'){ chart.applyOptions({handleScroll:false, handleScale:false}); kiBewOhneTitel(chart); }
  const kursHoch = Math.max(...j.kerzen.map(k=>k[2]).filter(Number.isFinite));
  const kappe = orig=>{ const r = orig(); if(r && r.priceRange && isFinite(kursHoch)){ r.priceRange.maxValue = Math.min(r.priceRange.maxValue, kursHoch*2.5); r.priceRange.minValue = Math.max(r.priceRange.minValue, -kursHoch*0.1); if(r.priceRange.minValue > r.priceRange.maxValue) return null; } return r; };
  for(const m of ['addLineSeries','addAreaSeries','addBaselineSeries']){
    const o = chart[m].bind(chart);
    chart[m] = (opt={})=>o((opt.priceScaleId && opt.priceScaleId!=='right') || opt.autoscaleInfoProvider ? opt : Object.assign({autoscaleInfoProvider:kappe}, opt));
  }
  const E = _kiEbene, box = document.getElementById('kiChartBew');
  if(!box) return;
  const brauchtGewinne = E.fvl || E.npe || E.divfl || E.prog;
  const LS = LightweightCharts.LineStyle;
  const tag = s=>new Date(s+'T12:00:00Z').getTime();
  const ttmBis = ende=>divs.reduce((s,[x,b])=>{ const t = tag(x); return (t<=ende && t>ende-365*86400000) ? s+Number(b) : s; }, 0);
  const wKurs = (j.currency||'').toUpperCase();
  // Dividendenrendite auf eigener Achse links (in %) - braucht keine Gewinne
  if(E.rendite && divs.length){
    const rendite = j.kerzen.map(k=>{ const d = ttmBis(interval==='1mo' ? tag(k[0])+30*86400000 : tag(k[0])); return d>0 && k[4]>0 ? {time:k[0], value:d/k[4]*100} : null; }).filter(Boolean);
    if(rendite.length){
      // Wie Vorlage (Nutzer 12.11.2026): ein Punkt je Jahr (letzter Monat), dunkelrot mit Punkten und UEBER den gruenen
      // Flaechen - sonst verschwand die Linie dahinter. Mit Gewinndaten erst zusammen mit dem Kurs obenauf zeichnen.
      const jahr = {}; rendite.forEach(r=>{ jahr[String(r.time).slice(0,4)] = r; });
      const pkteJ = Object.values(jahr).sort((a,b)=>a.time<b.time?-1:1);
      const zeichneRendite = ()=>{ const rs = chart.addLineSeries({priceScaleId:'left', color:'#b91c1c', lineWidth:2, pointMarkersVisible:true, priceLineVisible:false, lastValueVisible:true, title:'Div.-Rendite',
        crosshairMarkerVisible:false, priceFormat:{type:'custom', formatter:v=>kiNum(v,1)+' %'}}); rs.setData(pkteJ); };
      chart.applyOptions({leftPriceScale:{visible:true, borderColor:getComputedStyle(document.documentElement).getPropertyValue('--border').trim()||'#ddd', scaleMargins:{top:0.1, bottom:0.25}}});
      if(brauchtGewinne){ _kiRenditeSpaet = zeichneRendite; setTimeout(()=>{ if(_kiRenditeSpaet===zeichneRendite){ _kiRenditeSpaet = null; zeichneRendite(); } }, 20000); }
      else zeichneRendite();
    }
  }
  if(E.rez){
    const rez = chart.addHistogramSeries({priceScaleId:'rez', color:'rgba(148,163,184,.18)', priceLineVisible:false, lastValueVisible:false});
    chart.priceScale('rez').applyOptions({scaleMargins:{top:0, bottom:0}, visible:false});
    rez.setData(zeiten.filter(t=>US_REZESSIONEN.some(([a,b])=>t.slice(0,7)>=a && t.slice(0,7)<=b)).map(t=>({time:t, value:1})));
  }
  box.innerHTML = '<span class="muted" style="font-size:12px;">Lade Gewinne je Aktie für Fair Value und Kennzahlen …</span>';
  let f, rh;
  try{ [f, rh] = await Promise.all([kiBewDaten(j.symbol), kiReihen(j.symbol).catch(()=>null)]); } catch(e){ box.innerHTML = '<span class="muted">Gewinndaten nicht geladen.</span>'; return; }
  if(!_kiChart || _kiChart.chart!==chart) return;
  // Berichtswaehrung != Kurswaehrung (China-ADRs in Yuan, IFRS-Melder in Euro, London in Pence): Werte je Geschaeftsjahr mit
  // dem Wechselkurs am Jahresende umrechnen (Yahoo-Devisenkurse, Monatsschluss)
  let fxHinweis = '';
  if(rh && rh.reihen && rh.waehrung){
    const roh = String(j.currency||''), pence = roh==='GBp' || roh==='GBX', ziel = pence ? 'GBP' : roh.toUpperCase(), von = String(rh.waehrung).toUpperCase();
    if(ziel && von && von!==ziel){
      try{
        const fx = await (await fetch('quote.php?ohlc=1&interval=1mo&range=max&symbols=' + encodeURIComponent(von+ziel+'=X'), {cache:'no-store'})).json();
        const fk = Array.isArray(fx.kerzen) ? fx.kerzen : [];
        if(fk.length){
          const kursAm = d=>{ const m = String(d).slice(0,7); const k = fk.filter(x=>x[0].slice(0,7)<=m).pop() || fk[0]; return k[4]; };
          const umr = {}; Object.entries(rh.reihen).forEach(([k,a])=>umr[k] = (a||[]).map(e=>({date:e.date, v:e.v*kursAm(e.date)*(pence?100:1)})));
          rh = {...rh, reihen:umr};
          fxHinweis = ` · umgerechnet von ${escapeHtml(von)} in ${escapeHtml(pence?'Pence':ziel)} (Kurs am Geschäftsjahresende, jetzt ${kiNum(fk[fk.length-1][4],4)})`;
        }
      } catch(e){}
    } else if(pence && von==='GBP'){
      const umr = {}; Object.entries(rh.reihen).forEach(([k,a])=>umr[k] = (a||[]).map(e=>({date:e.date, v:e.v*100}))); rh = {...rh, reihen:umr}; fxHinweis = ' · Pfund in Pence umgerechnet';
    }
  }
  let basis = _kiBasis;
  const sektor = String((_kiChart.ctx.pos && (_kiChart.ctx.pos.sector||_kiChart.ctx.pos.sektor)) || _kiChart.ctx.sektor || (_kiChart.ctx.analyse && _kiChart.ctx.analyse.ddb && _kiChart.ctx.analyse.ddb.sector) || '');
  let autoHinweis = '';
  // REITs bewertet man nach FFO/AFFO; der operative Cashflow je Aktie liegt nah daran (O 2025: 4,40 gegen AFFO 4,28)
  // Bezug je Branche (Abgleich mit 65 Depottiteln am 05.11.2026): REITs op. Cashflow ≈ AFFO (O +3 %, ADC +5 %, OHI −3 %),
  // Finanzen/Versorger/Energie GAAP (Betriebsergebnis dort nicht sinnvoll; NEE −11 %, CVX −9 %, MAIN 0 %), sonst operativ.
  if(!_kiBasisManuell){
    if(/real estate|reit|immobil/i.test(sektor)){
      basis = 'ocf'; autoHinweis = ' – automatisch für Immobilien/REITs: operativer Cashflow ≈ FFO/AFFO';
    } else if(/financ|utilit|energy|versorg|finanz|energie|bank|insur/i.test(sektor)){
      basis = 'gaap'; autoHinweis = ' – automatisch für Finanzen/Versorger/Energie: Gewinn laut Bericht';
    } else if(basis==='operativ' && rh && rh.reihen && !(rh.reihen.operativ||[]).length){
      basis = (rh.reihen.gaap||[]).length ? 'gaap' : 'ocf'; autoHinweis = ' – kein Betriebsergebnis gemeldet';
    }
  }
  // Umsatz/EBIT/EBITDA je Aktie aus den Fitness-Daten (SEC); fehlen die Abschreibungen im Speicher, einmal neu abrufen
  if(['umsatz','ebit','ebitda'].includes(basis) && rh && rh.reihen && !fxHinweis){
    const symB = String(_kiChart.ctx.sym||'');
    try{ let fd = (_kiFitDaten[symB] && !_kiFitDaten[symB].laedt) ? _kiFitDaten[symB] : await (await fetch('fitness.php?symbol=' + encodeURIComponent(symB), {cache:'no-store'})).json();
      if(basis==='ebitda' && fd && (fd.jahre||[]).length && !('abschreibung' in fd.jahre[0])) fd = await (await fetch('fitness.php?neu=1&symbol=' + encodeURIComponent(symB), {cache:'no-store'})).json();
      _kiFitDaten[symB] = fd;
      rh = {...rh, reihen:{...rh.reihen, [basis]: (fd.jahre||[]).filter(y=>y.aktien>0).map(y=>({date:y.date, v: basis==='umsatz' ? (y.umsatz!=null ? y.umsatz/y.aktien : null)
        : basis==='ebit' ? (y.betrieb!=null ? y.betrieb/y.aktien : null) : (y.betrieb!=null ? (y.betrieb + (y.abschreibung||0))/y.aktien : null)})).filter(e=>e.v!=null && isFinite(e.v))}};
    } catch(e){}
  }
  const sbcAm = {}; ((rh && rh.reihen && rh.reihen.sbc) || []).forEach(e=>sbcAm[e.date] = e.v);
  const reihe = rh && rh.reihen && Array.isArray(rh.reihen[basis]) ? rh.reihen[basis].filter(e=>e.v!=null).map(e=>({date:e.date, eps: (basis==='operativ' && !_kiSbcRaus) ? e.v - (sbcAm[e.date]||0) : e.v, year:e.date.slice(0,4)})) : [];
  let basisHinweis = '';
  const histGaap = (f && Array.isArray(f.epsHistory)) ? f.epsHistory.filter(e=>e.eps!=null) : [];
  let hist = reihe.length ? reihe : (basis==='gaap' ? histGaap : []);
  if(!hist.length && histGaap.length){ hist = histGaap; basisHinweis = ` (${KI_BASIS[basis].kurz} nicht verfügbar – GAAP verwendet)`; basis = 'gaap'; }
  const BN = KI_BASIS[basis];
  document.querySelectorAll('#kiChartOverlay [data-ki-basis]').forEach(b=>b.classList.toggle('active', b.dataset.kiBasis===basis));
  { const n = document.getElementById('kiBasisName'); if(n) n.textContent = KI_BASIS[basis].kurz + (!_kiBasisManuell ? ' · auto' : '');
    const a = document.querySelector('#kiChartOverlay [data-ki-basis-auto]'); if(a) a.checked = !_kiBasisManuell;
    if(!document.getElementById('kiBasisStil')){ const st = document.createElement('style'); st.id = 'kiBasisStil';
      st.textContent = '.ki-bm-gruppe{font-size:10.5px;letter-spacing:.06em;color:var(--text-dim);padding:8px 14px 3px;border-top:1px solid var(--border);margin-top:4px;}.ki-bm-zeile{padding:6px 14px;cursor:pointer;}.ki-bm-zeile:hover{background:var(--surface-hover);}.ki-bm-zeile.active{color:#2563eb;font-weight:700;}.ki-bm-zeile.active::after{content:" ✓";float:right;}.ki-bm-en{font-size:11px;color:var(--text-dim);font-weight:400;}';
      document.head.appendChild(st); } }
  if(!hist.length){ box.innerHTML = `<span class="muted" style="font-size:12px;">Keine Gewinne je Aktie verfügbar${f && f.error ? ' ('+escapeHtml(f.error)+')' : ''} – Fair Value und Kennzahlen entfallen.</span>`; return; }
  let FVR = _kiFvr;
  const pkt = [];
  hist.forEach(e=>{ const t = kerzeZu(e.date); if(t && !pkt.some(p=>p.t===t)) pkt.push({t, eps:Number(e.eps), date:e.date, div:ttmBis(tag(e.date))}); });
  // Normales Vielfaches = Median aus Kurs am Geschaeftsjahresende / Wert (letzte 10 Jahre, nur positive Werte); bei GAAP der
  // Wert der Bewertungsseite. Wachstum = jaehrliche Rate zwischen erstem und letztem positiven Wert (bis 10 Jahre).
  const schlussAm = t=>{ const k = j.kerzen.find(x=>x[0]===t); return k ? k[4] : null; };
  const vielf = pkt.slice(-10).filter(p=>p.eps>0 && schlussAm(p.t)).map(p=>schlussAm(p.t)/p.eps).sort((a,b)=>a-b);
  const medV = vielf.length ? (vielf.length%2 ? vielf[(vielf.length-1)/2] : (vielf[vielf.length/2-1]+vielf[vielf.length/2])/2) : null;
  if(['umsatz','ebit','ebitda'].includes(basis) && medV) FVR = Math.round(medV*100)/100;   // nach Vorlage: normales Vielfaches statt KGV 15
  const ausFund = hist===histGaap;   // nur dann passen Normal-KGV/Wachstum/EPS der Bewertungsseite (gleiche Reihe, gleiche Waehrung)
  const npe = ausFund ? (Number(f.normalPE) || medV) : medV;
  const posP = pkt.slice(-11).filter(p=>p.eps>0);
  const gBerech = posP.length>=3 ? (Math.pow(posP[posP.length-1].eps/posP[0].eps, 1/((tag(posP[posP.length-1].date)-tag(posP[0].date))/31557600000))-1)*100 : NaN;
  const g = ausFund && isFinite(Number(f.growthRatePct)) ? Number(f.growthRatePct) : gBerech;
  const letzt = pkt[pkt.length-1];
  // Zukunft mit leeren Zeitpunkten im Takt der Kerzen auffuellen: die Zeitachse verteilt nach PUNKTEN, nicht nach Zeit -
  // 5 Prognosejahre mit je 1 Punkt schrumpften neben Monatskerzen auf 5 Monatsbreiten, die Faecherlinien standen senkrecht
  // am rechten Rand (Nutzer 07.11.2026, ADM: "es sieht Katastrophe aus").
  if(letzt && (E.faecher || E.prog) && zeiten && zeiten.length){
    const ende = new Date(tag(letzt.date)); ende.setUTCFullYear(ende.getUTCFullYear() + (E.faecher ? 5 : 2)); ende.setUTCDate(ende.getUTCDate() + 45);
    const d = new Date(zeiten[zeiten.length-1] + 'T12:00:00Z'); const leer = [];
    for(let i=0; i<4000; i++){
      if(interval==='1mo') d.setUTCMonth(d.getUTCMonth()+1); else if(interval==='1wk') d.setUTCDate(d.getUTCDate()+7); else d.setUTCDate(d.getUTCDate()+1);
      if(d > ende) break; leer.push({time:d.toISOString().slice(0,10)});
    }
    if(leer.length){ const ws = chart.addLineSeries({lastValueVisible:false, priceLineVisible:false, crosshairMarkerVisible:false}); ws.setData(leer); }
  }
  if(_kiChart && _kiChart.chart===chart) _kiChart.bew = {npe, epsAm: t=>{ const v = pkt.filter(p=>p.t<=t).pop(); return v ? v.eps : null; },
    // Gewinn-Linie inkl. Schaetzung, zwischen den Jahrespunkten linear (fuer den Renditerechner auch in der Zukunft)
    epsLin: t=>{ const li = [...pkt.filter(p=>p.eps>0), ...(prog||[])].map(p=>[String(p.t), p.eps]).sort((a,b)=>a[0]<b[0]?-1:1);
      if(!li.length) return null; if(t<=li[0][0]) return li[0][1]; if(t>=li[li.length-1][0]) return li[li.length-1][1];
      for(let i=0;i<li.length-1;i++) if(t>=li[i][0] && t<=li[i+1][0]){ const f = (new Date(t)-new Date(li[i][0]))/Math.max(1,(new Date(li[i+1][0])-new Date(li[i][0]))); return li[i][1]+(li[i+1][1]-li[i][1])*f; }
      return null; }};
  // Schaetzung: Analystenkonsens aus Yahoo (wie die "E"-Jahre bei Vorlage), nur fuer Gewinn-Bezuege ohne Waehrungsumrechnung;
  // sonst wie bisher das bisherige Wachstum fortgeschrieben.
  const prog = []; let progQuelle = 'Fortschreibung';
  const schaetz = (rh && Array.isArray(rh.schaetzung) && !fxHinweis && (basis==='operativ' || basis==='gaap')) ? rh.schaetzung.filter(x=>letzt && x.ende > letzt.date && x.eps > 0) : [];
  if(schaetz.length){ schaetz.forEach(x=>prog.push({t:x.ende, eps:x.eps, n:x.analysten})); progQuelle = 'Analystenkonsens (Yahoo, ' + schaetz.map(x=>x.analysten).join('/') + ' Analysten)';
    // Yahoo liefert 2 Jahre, Vorlage 3: drittes Jahr mit dem Wachstum zwischen den beiden Konsensjahren (-10 bis +15 %) fortschreiben,
    // sonst wird die Rendite p. a. auf ~1 Jahr hochgerechnet (ADM 07.11.2026: 24 % statt Vorlage 14 %)
    if(prog.length===2){ const gq = Math.max(-0.10, Math.min(0.15, prog[1].eps/prog[0].eps - 1)); const d = new Date(prog[1].t+'T12:00:00Z'); d.setUTCFullYear(d.getUTCFullYear()+1);
      prog.push({t:d.toISOString().slice(0,10), eps:prog[1].eps*(1+gq), n:null, fort:true}); progQuelle += ', 3. Jahr mit ' + kiNum(gq*100,1) + ' % fortgeschrieben'; } }
  else if(letzt && isFinite(g) && letzt.eps>0){
    for(let k=1; k<=2; k++){ const d = new Date(tag(letzt.date)); d.setUTCFullYear(d.getUTCFullYear()+k); prog.push({t:d.toISOString().slice(0,10), eps:letzt.eps*Math.pow(1+g/100, k)}); }
  }
  if(_kiChart && _kiChart.chart===chart) _kiChart.progQuelle = progQuelle;
  if(brauchtGewinne && pkt.length>=2){
    // Ebenen nach Vorlage (Nutzer 07.11.2026 mit Bildern GIS/ADM): hinten hellgruen = (Wert + Dividende) x Faktor,
    // davor dunkelgruen = Fair Value (orange Linie mit Punkten), gelb = Dividende x Faktor, blau = Normal-KGV mit Punkten,
    // Zukunft (Schaetzung) heller. Der Kurs wird am Ende noch einmal obenauf gezeichnet.
    const divLetzt = [...pkt].reverse().find(p=>p.div>0);
    // Wie Vorlage (Nutzer 12.11.2026): das laufende Geschaeftsjahr gehoert noch zum dunklen Teil - hell/gestrichelt erst ab
    // der ersten Schaetzung NACH dem heutigen Kurs. Sonst lief die Kurslinie fast ein Jahr in die Analystenflaeche hinein.
    const heuteT = j.kerzen.length ? j.kerzen[j.kerzen.length-1][0] : '';
    const kHeute = (E.prog && prog.length) ? prog.findIndex(p=>p.t >= heuteT) : -1;
    const dvR = pkt.filter(p=>p.div>0), dvG = dvR.length>5 ? Math.max(0, Math.min(0.15, Math.pow(dvR[dvR.length-1].div/dvR[dvR.length-6].div, 1/5)-1)) : 0;
    const divZuk = t=>divLetzt ? divLetzt.div * Math.pow(1+dvG, Math.max(0,(new Date(t)-new Date(divLetzt.t))/31557600000)) : 0;   // Nutzer 12.11.2026 nach Vorlage
    const istPlus = (E.prog && prog.length) ? (kHeute>=0 ? prog.slice(0, kHeute+1) : prog.slice()).map(p=>({t:p.t, eps:p.eps, div:divZuk(p.t)})) : [];
    const pktX = [...pkt, ...istPlus];
    const zuk = (E.prog && prog.length && letzt && kHeute>=0 && kHeute < prog.length-1) ? prog.slice(kHeute).map(p=>({t:p.t, eps:p.eps, div:divZuk(p.t)})) : [];
    const flaeche = (daten, oben, linie, breite, titel, lastVal, punkte)=>{ const s = chart.addAreaSeries({lineColor:linie, topColor:oben, bottomColor:oben, lineWidth:breite, pointMarkersVisible:!!punkte,
      priceLineVisible:false, lastValueVisible:!!lastVal, crosshairMarkerVisible:false, title:titel||''}); s.setData(daten); return s; };
    if(E.divfl){
      flaeche(pktX.map(p=>p.eps>0 ? {time:p.t, value:(p.eps + (p.div||0))*FVR} : {time:p.t}), 'rgba(134,239,150,.85)', 'rgba(74,222,128,.9)', 1, '', false);
      if(zuk.length) flaeche(zuk.map(p=>({time:p.t, value:(p.eps + (p.div||0))*FVR})), 'rgba(134,239,150,.45)', 'rgba(74,222,128,.6)', 1, '', false);
    }
    if(E.fvl){
      flaeche(pktX.map(p=>p.eps>0 ? {time:p.t, value:p.eps*FVR} : {time:p.t}), 'rgba(22,128,61,.82)', 'rgba(234,88,12,1)', 2, 'Fair Value ' + kiNum(FVR,0), true, true);
      if(zuk.length) flaeche(zuk.map(p=>({time:p.t, value:p.eps*FVR})), 'rgba(22,128,61,.38)', 'rgba(234,88,12,.9)', 2, '', false, true);
    }
    if(E.divfl){
      const dd = pktX.filter(p=>p.div>0).map(p=>({time:p.t, value:p.div*FVR}));   // inkl. laufendes Jahr (keine Luecke)
      if(dd.length>1){ const s = chart.addLineSeries({color:'#eab308', lineWidth:2, pointMarkersVisible:true, priceLineVisible:false, lastValueVisible:false, crosshairMarkerVisible:false, title:'Dividende × ' + kiNum(FVR,0)}); s.setData(dd);
        if(zuk.length && divLetzt){ const s2 = chart.addLineSeries({color:'#eab308', lineWidth:2, lineStyle:LS.Dashed, pointMarkersVisible:true, priceLineVisible:false, lastValueVisible:false, crosshairMarkerVisible:false}); s2.setData(zuk.map(p=>({time:p.t, value:(p.div||0)*FVR}))); } }
    }
    if(E.npe && npe){
      const nl = chart.addLineSeries({color:'#2563eb', lineWidth:2, pointMarkersVisible:true, priceLineVisible:false, lastValueVisible:true, title:`Normal-KGV ${kiNum(npe,1)}`});
      nl.setData(pktX.filter(p=>p.eps>0).map(p=>({time:p.t, value:p.eps*npe})));
      if(zuk.length){ const pn = chart.addLineSeries({color:'#2563eb', lineWidth:2, lineStyle:LS.Dashed, pointMarkersVisible:true, priceLineVisible:false, lastValueVisible:false, crosshairMarkerVisible:false}); pn.setData(zuk.map(p=>({time:p.t, value:p.eps*npe}))); }
    }
    if(E.npe){   // Vorlage "Current Valuation": Gewinn x heutiges KGV (Kurs / Gewinn-Linie heute), schwarz, Zukunft gestrichelt
      const li = [...pktX.filter(p=>p.eps>0), ...zuk.slice(1)].map(p=>[p.t, p.eps]);
      const hT = j.kerzen.length ? j.kerzen[j.kerzen.length-1][0] : ''; let eH = null;
      for(let i=0;i<li.length-1;i++) if(hT>=li[i][0] && hT<=li[i+1][0]){ const f=(new Date(hT)-new Date(li[i][0]))/Math.max(1,(new Date(li[i+1][0])-new Date(li[i][0]))); eH = li[i][1]+(li[i+1][1]-li[i][1])*f; }
      const kursH = j.kerzen.length ? j.kerzen[j.kerzen.length-1][4] : null, kgvH = (eH>0 && kursH>0) ? kursH/eH : null;
      if(kgvH && kgvH < 80){
        const cv = chart.addLineSeries({color:'#111', lineWidth:1, pointMarkersVisible:true, priceLineVisible:false, lastValueVisible:false, crosshairMarkerVisible:false, title:`Aktuelle Bewertung ${kiNum(kgvH,1)}×`});
        cv.setData(pktX.filter(p=>p.eps>0).map(p=>({time:p.t, value:p.eps*kgvH})));
        if(zuk.length){ const cz = chart.addLineSeries({color:'#111', lineWidth:2, lineStyle:LS.Dashed, pointMarkersVisible:true, priceLineVisible:false, lastValueVisible:false, crosshairMarkerVisible:false}); cz.setData(zuk.map(p=>({time:p.t, value:p.eps*kgvH}))); }
        if(_kiChart && _kiChart.chart===chart) _kiChart.kgvHeute = kgvH;
      }
    }
    if(E.cvr && _kiCvr>0){   // Vorlage "Custom Val. Ratio": Gewinn x eigenes KGV, lila
      const cs = chart.addLineSeries({color:'#c026d3', lineWidth:2, pointMarkersVisible:true, priceLineVisible:false, lastValueVisible:false, crosshairMarkerVisible:false, title:`Eigenes KGV ${kiNum(_kiCvr,1)}`});
      cs.setData([...pktX.filter(p=>p.eps>0), ...zuk.slice(1)].map(p=>({time:p.t, value:p.eps*_kiCvr})));
    }
    if(E.por){   // Vorlage "Dividends POR": Ausschuettungsquote = Dividende / Gewinn, linke Achse in %
      const pq = pktX.filter(p=>p.eps>0 && p.div>0).map(p=>({time:p.t, value:Math.min(200, p.div/p.eps*100)}));
      if(pq.length){ const ps = chart.addLineSeries({priceScaleId:'left', color:'#0f766e', lineWidth:2, lineStyle:LS.Dotted, pointMarkersVisible:true, priceLineVisible:false, lastValueVisible:true,
        crosshairMarkerVisible:false, title:'Ausschüttungsquote', priceFormat:{type:'custom', formatter:v=>kiNum(v,0)+' %'}}); ps.setData(pq); chart.applyOptions({leftPriceScale:{visible:true}}); }
    }
    if(_kiChart && _kiChart.chart===chart){ _kiChart.fvWert = letzt && letzt.eps>0 ? letzt.eps*FVR : null; _kiChart.npeWert = (letzt && letzt.eps>0 && npe) ? letzt.eps*npe : null; _kiChart.fvBasis = basis; _kiChart.fvFaktor = FVR;
      { const li = [...pkt.filter(p=>p.eps>0).map(p=>[p.t, p.eps*FVR]), ...((E.prog && prog.length) ? prog.map(p=>[p.t, p.eps*FVR]) : [])].sort((x,y)=>x[0]<y[0]?-1:1);
        const hT = new Date().toISOString().slice(0,10); let fvH = null;
        for(let i=0;i<li.length-1;i++){ if(hT>=li[i][0] && hT<=li[i+1][0]){ const f = (new Date(hT)-new Date(li[i][0]))/Math.max(1,(new Date(li[i+1][0])-new Date(li[i][0]))); fvH = li[i][1] + (li[i+1][1]-li[i][1])*f; } }
        _kiChart.fvHeute = fvH; } if(document.getElementById('kiAlarmPanel')?.style.display===''){ kiAlarmPanelZeichnen(); } _kiChart.gewinnVon = pkt[0].t; if(E.prog && prog.length) _kiChart.bisZukunft = prog[prog.length-1].t; kiZeitraumAnwenden(); }
    if(E.faecher && letzt && letzt.eps>0 && isFinite(g)){
      // Prognose-Faecher: Kurs bei KGV 7,5 / 10,5 / 15 / 18 / 21 fuer 5 Jahre mit dem eingestellten Wachstum
      const jahre5 = [0,1,2,3,4,5].map(k=>{ const d = new Date(tag(letzt.date)); d.setUTCFullYear(d.getUTCFullYear()+k); return {t:k===0 ? letzt.t : d.toISOString().slice(0,10), eps:letzt.eps*Math.pow(1+g/100, k)}; });
      const faktoren = [[7.5,'rgba(37,99,235,.55)'],[10.5,'rgba(37,99,235,.75)'],[15,'rgba(234,88,12,.85)'],[18,'rgba(37,99,235,.75)'],[21,'rgba(37,99,235,.55)']];
      faktoren.forEach(([f2,farbe])=>{ const ls = chart.addLineSeries({color:farbe, lineWidth:1, lineStyle:LS.Dotted, priceLineVisible:false, lastValueVisible:true, title:kiNum(f2,1)+'×', crosshairMarkerVisible:false});
        ls.setData(jahre5.map(p=>({time:p.t, value:p.eps*f2}))); });
      if(_kiChart) _kiChart.bisZukunft = jahre5[5].t;
    }
    // Kurs obenauf (die Flaechen sind jetzt deckend nach Vorlage und wuerden ihn sonst verdecken)
    if(_kiTab==='bewertung'){ if(typeof _kiRenditeSpaet==='function'){ const f = _kiRenditeSpaet; _kiRenditeSpaet = null; f(); }
      const ko = chart.addLineSeries({color:'#111', lineWidth:2, priceLineVisible:false, lastValueVisible:false, crosshairMarkerVisible:false}); ko.setData(j.kerzen.map(k=>({time:k[0], value:k[4]})));
      _kiKursOben = ko; _kiKursChart = chart; _kiKursAm = {}; j.kerzen.forEach(k=>{ _kiKursAm[k[0]] = k[4]; }); kiObenMarker(); }
  }
  // Zeitraum NACH Faecher/Schaetzung erneut setzen: vorher endete die Achse am letzten Kurs, die 5 Prognosejahre klebten am
  // rechten Rand und liessen sich seit dem festen Bewertungs-Tab auch nicht mehr hinschieben (Nutzer 07.11.2026, ADC-Bild).
  if(_kiChart && _kiChart.chart===chart) kiZeitraumAnwenden();
  // Geschaeftsjahres-Tabelle unter dem Chart nach Vorlage (Nutzer 07.11.2026 mit Bild): je Geschaeftsjahr Datum,
  // Wert der gewaehlten Bezugsgroesse, Veraenderung zum Vorjahr, Dividende 12 M.; Schaetzungen kursiv mit "S".
  document.getElementById('kiChartFy')?.remove();
  if(brauchtGewinne && pkt.length>=2){
    const fyBox = document.createElement('div'); fyBox.id = 'kiChartFy';
    document.getElementById('kiChartBox').after(fyBox);
    const fyPkt = [...pkt.map(p=>({t:p.t, eps:p.eps, date:String(p.date), div:p.div, s:false})), ...(E.prog ? prog.map(p=>({t:p.t, eps:p.eps, date:p.t, div:null, s:true})) : [])];
    fyBox.style.cssText = 'position:relative;height:66px;overflow:hidden;font-size:10.5px;border-top:1px solid var(--border);';
    fyBox.innerHTML = `<div class="muted" style="position:absolute;left:0;top:3px;line-height:15px;background:var(--card);z-index:1;padding-right:6px;">GJ<br>${escapeHtml(BN.kurz)}<br>Veränd.<br>Div.</div>`
      + fyPkt.map((p,i)=>{ const v = i>0 && fyPkt[i-1].eps>0 ? (p.eps/fyPkt[i-1].eps-1)*100 : null;
        return `<div data-fy="${i}" style="position:absolute;top:3px;line-height:15px;text-align:center;transform:translateX(-50%);white-space:nowrap;display:none;${p.s?'font-style:italic;color:var(--text-dim);':''}">${p.date.slice(5,7)}/${p.date.slice(2,4)}<br><b>${kiNum(p.eps)}${p.s?'S':''}</b><br><span style="color:${v==null?'inherit':(v<0?'#dc2626':'#16a34a')}">${v==null?'–':kiNum(v,0)+' %'}</span><br>${p.div>0?kiNum(p.div):'–'}</div>`; }).join('');
    const fyLage = ()=>{
      if(!fyBox.isConnected) return;
      const ts = chart.timeScale(); let off = 0; try{ off = chart.priceScale('left').width() || 0; }catch(e){}
      const pane = ts.width(); const els = [...fyBox.querySelectorAll('[data-fy]')];
      const xs = els.map(el=>ts.timeToCoordinate(fyPkt[+el.dataset.fy].t));
      const sicht = xs.filter(x=>x!=null && x>45 && x<pane-20); let schritt = 1;
      if(sicht.length>1){ const abst = Math.abs(sicht[sicht.length-1]-sicht[0])/(sicht.length-1); schritt = Math.max(1, Math.ceil(46/Math.max(abst,1))); }
      els.forEach((el,i)=>{ const x = xs[i]; const zeigen = x!=null && x>45 && x<pane-20 && ((els.length-1-i) % schritt === 0);
        el.style.display = zeigen ? '' : 'none'; if(zeigen) el.style.left = (off + x) + 'px'; });
    };
    chart.timeScale().subscribeVisibleLogicalRangeChange(fyLage);
    try{ chart.timeScale().subscribeSizeChange(fyLage); }catch(e){}
    setTimeout(fyLage, 0); setTimeout(fyLage, 400);
  }
  // ===== Forecasting nach Vorlage (Nutzer 07.11.2026): vom letzten Geschaeftsjahr bis zum letzten Schaetzungsjahr,
  // Fair-Value-Flaeche, Dividendenflaeche, gestrichelt vom heutigen Kurs zum kuenftigen Kurs (Faktor = Fair Value oder
  // Normal-KGV der letzten 5 Jahre), rechts erwartete Rendite p. a. Schaetzungen = Analystenkonsens (Yahoo), sonst Fortschreibung.
  document.getElementById('kiForecast')?.remove();
  if(_kiTab==='bewertung' && letzt && letzt.eps>0 && prog.length){
    const kursJetzt = j.kerzen[j.kerzen.length-1][4];
    const divL = [...pkt].reverse().find(p=>p.div>0); const dk = kiDivKennzahlen(divs, j.kerzen);
    const divG = dk && isFinite(dk.cagr5) ? Math.max(-10, Math.min(15, dk.cagr5)) : 0;
    const jahre = [{t:letzt.t, date:letzt.date, eps:letzt.eps, div:divL ? divL.div : 0, n:null, ist:true},
      ...prog.map((p,i)=>({t:p.t, date:p.t, eps:p.eps, div:divL ? divL.div*Math.pow(1+divG/100, i+1) : 0, n:p.fort ? 'fortg.' : (p.n ?? null), ist:false}))];
    const npe5 = (()=>{ const v = pkt.slice(-5).filter(p=>p.eps>0 && schlussAm(p.t)).map(p=>schlussAm(p.t)/p.eps).sort((a,b)=>a-b); return v.length ? (v.length%2 ? v[(v.length-1)/2] : (v[v.length/2-1]+v[v.length/2])/2) : npe; })();
    const modus = _kiFcModus || 'schaetzung';
    let fi = null; try{ fi = await (await fetch('fitness.php?info=1&symbol=' + encodeURIComponent(String(_kiChart?.ctx?.sym||'')), {cache:'no-store'})).json(); } catch(e){}
    if(meins && _kiChart!==meins && _kiChart && _kiChart.chart!==chart) return;
    let gMod = null, gText = '';
    if(modus==='lt'){ if(fi && fi.ltg!=null){ gMod = fi.ltg*100; gText = 'Langfrist-Wachstum der Analysten (Yahoo, 5 J.)'; } else gText = 'kein Langfrist-Wachstum bei Yahoo – Schätzungen'; }
    if(modus==='hist'){ const h = pkt.filter(p=>p.eps>0).slice(-11); if(h.length>=3){ const n = (tag(h[h.length-1].t)-tag(h[0].t))/31557600000; gMod = (Math.pow(h[h.length-1].eps/h[0].eps, 1/n)-1)*100; gText = `Gewinnwachstum der letzten ${kiNum(n,0)} Jahre`; } }
    if(modus==='eigen'){ gMod = _kiFcEigen.g; gText = 'eigene Annahme'; }
    if(gMod!=null){ gMod = Math.max(-20, Math.min(40, gMod)); jahre.forEach((x,i)=>{ if(i){ x.eps = letzt.eps*Math.pow(1+gMod/100, (tag(x.date)-tag(letzt.date))/31557600000); x.n = null; } }); }
    const faktor = modus==='normal' && npe5 ? npe5 : (modus==='eigen' && _kiFcEigen.pe>0 ? _kiFcEigen.pe : FVR);
    // Schaetzungs-Historie (Vorlage: Estimates current / 1-3 months ago): Yahoo epsTrend je Geschaeftsjahr
    const trendAm = x=>(fi && fi.trend || []).find(t=>t.ende && Math.abs(tag(t.ende)-tag(x.date)) < 20*86400000);
    const ende = jahre[jahre.length-1];
    const jahreBis = Math.max(0.25, (tag(ende.date) - Date.now()) / 31557600000);
    const kursZiel = ende.eps * faktor;
    const divSumme = jahre.filter(x=>!x.ist).reduce((a,x)=>a+x.div, 0);
    const ror = (Math.pow((kursZiel + divSumme) / kursJetzt, 1/jahreBis) - 1) * 100;
    const wachstum = (Math.pow(ende.eps/letzt.eps, 1/Math.max(0.5, (tag(ende.date)-tag(letzt.date))/31557600000)) - 1) * 100;
    const epsBlend = (()=>{ const n = jahre[1]; if(!n) return letzt.eps; const a = Math.max(0, Math.min(1, (Date.now()-tag(letzt.date))/(tag(n.date)-tag(letzt.date)))); return letzt.eps*(1-a) + n.eps*a; })();
    const fvJetzt = epsBlend * FVR;
    const box = document.createElement('div'); box.id = 'kiForecast';
    box.style.cssText = 'margin-top:14px;border-top:1px solid var(--border);padding-top:10px;';
    const knopf = (m, t)=>`<button type="button" class="range-btn${modus===m?' active':''}" data-ki-fc="${m}">${t}</button>`;
    const zeile = (t, v, f)=>`<tr><td style="padding:3px 6px;">${f?`<span style="color:${f};">●</span> `:''}${t}</td><td style="padding:3px 6px;text-align:right;font-weight:700;width:86px;white-space:nowrap;">${v}</td></tr>`;
    box.innerHTML = `<div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:6px;"><b style="font-size:15px;">Prognose</b>
        ${knopf('schaetzung','Schätzungen')}${knopf('normal','Normal-KGV 5 J.')}${knopf('lt','LT-Wachstum')}${knopf('hist','Hist. Wachstum')}${knopf('eigen','Eigene')}
        ${modus==='eigen' ? `<label style="font-size:11.5px;">Wachstum <input data-ki-fc-g type="text" inputmode="decimal" value="${kiNum(_kiFcEigen.g,1)}" style="width:46px;padding:1px 4px;"> %</label><label style="font-size:11.5px;">KGV <input data-ki-fc-pe type="text" inputmode="decimal" value="${kiNum(_kiFcEigen.pe,1)}" style="width:46px;padding:1px 4px;"></label>` : ''}
        <span class="muted" style="font-size:11.5px;">${gMod!=null ? `Gewinn wächst ${kiNum(gMod,1)} % p. a. (${escapeHtml(gText)})` : (gText ? escapeHtml(gText)+' · ' : '') + 'Schätzung: ' + escapeHtml(progQuelle)}</span></div>
      <div style="display:flex;gap:14px;flex-wrap:wrap;align-items:flex-start;">
        <div style="flex:1 1 520px;min-width:300px;"><div id="kiFcChart" style="height:300px;"></div><div id="kiFcTab" style="overflow-x:auto;"></div></div>
        <div data-fc-key="1" style="flex:0 0 270px;">
          <div style="display:flex;gap:8px;margin-bottom:8px;">
            <div class="panel" style="flex:1;text-align:center;padding:8px;"><div style="font-size:19px;font-weight:800;color:${ror>=10?'#16a34a':(ror>=0?'#ca8a04':'#dc2626')};">${kiNum(ror,2)} %</div><div class="muted" style="font-size:11px;">Erw. Rendite p. a.</div></div>
            <div class="panel" style="flex:1;text-align:center;padding:8px;"><div style="font-size:19px;font-weight:800;color:${wachstum>=0?'#16a34a':'#dc2626'};">${kiNum(wachstum,2)} %</div><div class="muted" style="font-size:11px;">Wachstum p. a.</div></div>
          </div>
          <table style="width:100%;min-width:0;table-layout:fixed;font-size:12px;border-collapse:collapse;">
            ${zeile('Normal-KGV', npe5 ? kiNum(npe5,2)+'×' : '–')}
            ${zeile('Fair-Value-Faktor', kiNum(FVR,2)+'×')}
            ${zeile('KGV gemischt', epsBlend>0 ? kiNum(kursJetzt/epsBlend,2)+'×' : '–')}
            ${zeile('Kurs heute', kiNum(kursJetzt)+' '+escapeHtml(wKurs))}
            ${zeile('Fair Value heute', kiNum(fvJetzt)+' '+escapeHtml(wKurs), '#ea580c')}
            ${zeile('Kurs ' + ende.date.slice(0,7) + ' (' + kiNum(faktor,1) + '×)', kiNum(kursZiel)+' '+escapeHtml(wKurs), '#111')}
            ${zeile('Dividenden bis dahin', kiNum(divSumme)+' '+escapeHtml(wKurs), '#eab308')}
          </table>
          <div class="muted" style="font-size:11px;margin-top:6px;">Rendite = (künftiger Kurs + Dividenden) ÷ Kurs heute, auf ${kiNum(jahreBis,1)} Jahre umgerechnet. Dividenden mit ${kiNum(divG,1)} % p. a. fortgeschrieben. Keine Anlageberatung.</div>
        </div>
      </div>`;
    (document.getElementById('kiChartFy') || document.getElementById('kiChartBox')).after(box);
    const fcKey = box.querySelector('[data-fc-key]');   // vor dem Verschieben merken - kiGraphKeySetzen haengt es nach rechts um
    kiGraphKeySetzen(fcKey);
    { const dTtm = ttmBis(Date.now()); kiFirmenInfo(String(_kiChart?.ctx?.sym||''), fcKey, {epsRendite: epsBlend>0 ? epsBlend/kursJetzt*100 : null, divRendite: dTtm>0 ? dTtm/kursJetzt*100 : null, folge: dk ? dk.folge : null, bezahlt: dk ? dk.bezahlt : null, kurs: kursJetzt, w: wKurs}); }
    // Chart: Monatskurse ab dem letzten Geschaeftsjahr, Zukunft im Monatstakt aufgefuellt
    const v = n=>getComputedStyle(document.documentElement).getPropertyValue(n).trim();
    const fc = LightweightCharts.createChart(document.getElementById('kiFcChart'), {autoSize:true, handleScroll:false, handleScale:false,
      layout:{background:{type:'solid', color:v('--card')||'#fff'}, textColor:v('--text')||'#111', fontSize:11},
      grid:{vertLines:{color:v('--border')||'#eee'}, horzLines:{color:v('--border')||'#eee'}},
      rightPriceScale:{borderColor:v('--border')||'#ddd', scaleMargins:{top:0.08, bottom:0.02}}, timeScale:{borderColor:v('--border')||'#ddd'},
      localization:{locale:'de-DE', priceFormatter:p=>kiNum(p)}});
    const kAb = j.kerzen.filter(k=>k[0] >= letzt.t);
    const zeitLeer = []; { const d = new Date((kAb.length ? kAb[kAb.length-1][0] : letzt.t)+'T12:00:00Z'); for(let i=0;i<120;i++){ d.setUTCMonth(d.getUTCMonth()+1); if(d > new Date(tag(ende.date)+40*86400000)) break; zeitLeer.push({time:d.toISOString().slice(0,10)}); } }
    const fl = (daten, farbe, linie, breite, punkte)=>{ const s = fc.addAreaSeries({lineColor:linie, topColor:farbe, bottomColor:farbe, lineWidth:breite, pointMarkersVisible:!!punkte, priceLineVisible:false, lastValueVisible:false, crosshairMarkerVisible:false}); s.setData(daten); return s; };
    fc.addLineSeries({lastValueVisible:false, priceLineVisible:false}).setData(zeitLeer);
    fl(jahre.map(x=>({time:x.t, value:(x.eps+x.div)*FVR})), 'rgba(134,239,150,.8)', 'rgba(74,222,128,.9)', 1);
    fl(jahre.map(x=>({time:x.t, value:x.eps*FVR})), 'rgba(22,128,61,.82)', '#ea580c', 2, true);
    const dl = fc.addLineSeries({color:'#eab308', lineWidth:2, pointMarkersVisible:true, priceLineVisible:false, lastValueVisible:false}); dl.setData(jahre.map(x=>({time:x.t, value:x.div*FVR})));
    if(npe5){ const nl = fc.addLineSeries({color:'#f8fafc', lineWidth:2, pointMarkersVisible:true, priceLineVisible:false, lastValueVisible:false}); nl.setData(jahre.map(x=>({time:x.t, value:x.eps*npe5}))); }
    const kl = fc.addLineSeries({color:'#111', lineWidth:2, priceLineVisible:false, lastValueVisible:true, title:'Kurs'}); kl.setData(kAb.map(k=>({time:k[0], value:k[4]})));
    if(kAb.length){ const zl = fc.addLineSeries({color:'#111', lineWidth:2, lineStyle:LS.Dashed, priceLineVisible:false, lastValueVisible:true, title:kiNum(faktor,1)+'×'}); zl.setData([{time:kAb[kAb.length-1][0], value:kursJetzt}, {time:ende.t, value:kursZiel}]); }
    fc.timeScale().fitContent();
    // Tabelle unter dem Prognose-Chart
    document.getElementById('kiFcTab').innerHTML = `<table style="font-size:11.5px;border-collapse:collapse;width:100%;min-width:0;table-layout:fixed;margin-top:4px;"><tr><td class="muted" style="padding:2px 6px;">GJ</td>${jahre.map(x=>`<td style="padding:2px 6px;text-align:right;">${x.date.slice(5,7)}/${x.date.slice(2,4)}</td>`).join('')}</tr>
      <tr><td class="muted" style="padding:2px 6px;">${escapeHtml(BN.kurz)}</td>${jahre.map(x=>`<td style="padding:2px 6px;text-align:right;font-weight:700;">${kiNum(x.eps)}${x.ist?'':'E'}</td>`).join('')}</tr>
      <tr><td class="muted" style="padding:2px 6px;">Veränd.</td>${jahre.map((x,i)=>{ const p = i ? jahre[i-1].eps : null; const c = p>0 ? (x.eps/p-1)*100 : null; return `<td style="padding:2px 6px;text-align:right;color:${c==null?'inherit':(c<0?'#dc2626':'#16a34a')};">${c==null?'–':kiNum(c,1)+' %'}</td>`; }).join('')}</tr>
      <tr><td class="muted" style="padding:2px 6px;">Analysten</td>${jahre.map(x=>`<td style="padding:2px 6px;text-align:right;">${x.n ?? '–'}</td>`).join('')}</tr>
      ${gMod==null ? [['vor30','Schätzung vor 1 Mon.'], ['vor90','vor 3 Mon.']].map(([k2,t2])=>`<tr><td class="muted" style="padding:2px 6px;">${t2}</td>${jahre.map(x=>{ const tr = x.ist ? null : trendAm(x); const v = tr ? tr[k2] : null;
        return `<td style="padding:2px 6px;text-align:right;">${v!=null ? kiNum(v)+(x.eps>0 && v>0 ? ` <span style="font-size:10px;color:${x.eps>=v?'#16a34a':'#dc2626'}">${x.eps>=v?'▲':'▼'}</span>` : '') : '–'}</td>`; }).join('')}</tr>`).join('')
        + `<tr><td class="muted" style="padding:2px 6px;">Spanne tief–hoch</td>${jahre.map(x=>{ const tr = x.ist ? null : trendAm(x); return `<td style="padding:2px 6px;text-align:right;font-size:10.5px;">${tr && tr.tief!=null ? kiNum(tr.tief)+'–'+kiNum(tr.hoch) : '–'}</td>`; }).join('')}</tr>` : ''}
      <tr><td class="muted" style="padding:2px 6px;">Dividende</td>${jahre.map(x=>`<td style="padding:2px 6px;text-align:right;">${x.div>0?kiNum(x.div)+(x.ist?'':'E'):'–'}</td>`).join('')}</tr></table>`;
  }
  // Bewertungs-Kennzahlen und Jahrestabelle - immer sichtbar
  const kurs = j.kerzen[j.kerzen.length-1][4];
  const letztW = letzt ? letzt.eps : null;
  const gL = rh && rh.reihen && (rh.reihen.gaap||[]).length ? rh.reihen.gaap[rh.reihen.gaap.length-1].v : null;
  const opWarn = (basis==='operativ' && letztW>0 && gL>0 && gL > letztW*1.8) ? `<div style="color:#d97706;font-size:12px;font-weight:700;margin-top:4px;">Hinweis: Gewinn laut Bericht ist ${kiNum(gL/letztW,1)}-mal so hoch wie der operative – der Gewinn kommt zu großen Teilen aus Zins-, Beteiligungs- oder Sondererträgen (z. B. JD). Für diesen Titel „Gewinn GAAP“ prüfen.</div>` : '';
  const adrWarn = opWarn + (rh && rh.adrPruefen ? `<div style="color:#d97706;font-size:12px;font-weight:700;margin-top:4px;">Hinweis: Gewinn je ADR laut Yahoo ist ${kiNum(rh.adrPruefen,1)}-mal so hoch wie je Stammaktie – vermutlich ADR mit mehreren Aktien, Verhältnis unbekannt. Werte prüfen.</div>` : '');
  const altJ = hist.length ? +String(hist[hist.length-1].date||hist[hist.length-1].time||'').slice(0,4) : 0;
  const altWarn = altJ && altJ < new Date().getFullYear() - 2 ? `<div style="color:#dc2626;font-size:12px;font-weight:700;margin-top:4px;">Achtung: Die freien Gewinndaten enden ${altJ} (z. B. Wechsel der Berichtsform bei der SEC). Fair Value und Prognose sind veraltet – nicht verwenden.</div>` : '';
  const wHinweis = altWarn + adrWarn + (!fxHinweis && letztW>0 && (kurs/letztW < 2 || kurs/letztW > 250) ? `<div style="color:#dc2626;font-size:12px;font-weight:700;margin-top:4px;">Achtung: Kurs / Wert = ${kiNum(kurs/letztW,1)} – vermutlich berichtet die Firma in anderer Währung als der Kurs (z. B. Yuan bei China-ADRs). Bewertung nicht verwenden.</div>` : '');
  const epsT = ausFund ? (Number(f.epsTTM) || (letzt ? letzt.eps : null)) : (letzt ? letzt.eps : null);
  const divT = divs.length ? ttmBis(Date.now()) : 0;
  const fv = epsT>0 ? epsT*FVR : null;
  const ror = (npe && epsT>0 && isFinite(g)) ? (Math.pow(epsT*Math.pow(1+g/100,5)*npe / kurs, 1/5) - 1)*100 + (divT/kurs*100) : null;
  const pio = f.piotroski && typeof f.piotroski==='object' ? `${f.piotroski.score}/${f.piotroski.maxScore}` : (f.piotroski ?? '–');
  const kz = [
    [`Wachstum ${BN.kurz}/Jahr`, isFinite(g) ? kiNum(g,1)+' %' : '–'],
    // Bei EBITDA/EBIT/Umsatz ist der Faktor das normale Vielfache, nicht KGV 15 (Fehler 14.11.2026: Beschriftung "KGV 15")
    [`Fair Value (${['umsatz','ebit','ebitda'].includes(basis) ? 'Vielfaches' : 'KGV'} ${kiNum(FVR,FVR%1?1:0)})`, fv!=null ? kiNum(fv)+' '+escapeHtml(wKurs) : '–'],
    [['umsatz','ebit','ebitda'].includes(basis) ? 'Normales Vielfaches' : 'Normal-KGV', npe ? kiNum(npe,1) : '–'],
    [['umsatz','ebit','ebitda'].includes(basis) ? 'Vielfaches jetzt' : 'KGV jetzt', epsT>0 ? kiNum(kurs/epsT,1) : '–'],
    ['Gewinnrendite', epsT>0 ? kiNum(epsT/kurs*100,1)+' %' : '–'],
    ['Div.-Rendite', divT>0 ? kiNum(divT/kurs*100,2)+' %' : '–'],
    ['Ausschüttungsquote', f.payoutRatioPct!=null ? kiNum(f.payoutRatioPct,0)+' %' : (divT>0 && epsT>0 ? kiNum(divT/epsT*100,0)+' %' : '–')],
    ['Kurs vs. Fair Value', fv ? kiProzent((kurs/fv-1)*100) : '–'],
    ['Erw. Rendite p. a. (5 J.)', ror!=null ? kiNum(ror,1)+' %' : '–'],
    ['Piotroski / Altman Z', `${pio} / ${f.altmanZ!=null?kiNum(f.altmanZ,1):'–'}`],
  ];
  // Kennzahl-Kacheln oben im Chart (Nutzerwunsch 05.11.2026)
  const an = _kiChart && _kiChart.ctx && _kiChart.ctx.analyse;
  const score = an && an.qualityScore!=null ? [an.qualityScore+' / 100', 'Qualität (KI)'] : (f.piotroski && typeof f.piotroski==='object' ? [f.piotroski.score+' / '+f.piotroski.maxScore, 'Piotroski'] : ['–', 'Qualität']);
  const kachel = kiKachel; const _ungenutzt = (titel, wert, unter, farbe)=>`<div style="border:1px solid var(--border);border-radius:10px;padding:6px 12px;min-width:120px;background:var(--surface-hover);"><div class="muted" style="font-size:11px;">${titel}</div><div style="font-size:18px;font-weight:700;${farbe?'color:'+farbe+';':''}">${wert}</div>${unter?`<div class="muted" style="font-size:10.5px;">${unter}</div>`:''}</div>`;
  const abw = fv ? (kurs/fv-1)*100 : null;
  const kach = document.getElementById('kiKachelBew');
  if(kach) kach.innerHTML = kachel(score[1], score[0], '')
    + kachel('KGV', epsT>0 ? kiNum(kurs/epsT,1)+'×' : '–', npe ? 'normal '+kiNum(npe,1)+'×' : '')
    + kachel('Div.-Rendite', divT>0 ? kiNum(divT/kurs*100,2)+' %' : '–', f.payoutRatioPct!=null ? 'Ausschüttung '+kiNum(f.payoutRatioPct,0)+' %' : '')
    + kachel(`Fair Value (${kiNum(FVR,FVR%1?1:0)} × ${BN.kurz})`, fv!=null ? kiNum(fv) : '–', abw!=null ? (abw>=0 ? kiNum(abw,0)+' % überbewertet' : kiNum(-abw,0)+' % unterbewertet') : '', abw==null ? '' : (abw>10 ? '#dc2626' : (abw<-10 ? '#16a34a' : '')))
    + kachel('Erw. Rendite p. a.', ror!=null ? kiNum(ror,1)+' %' : '–', '5 Jahre, Normal-KGV')
    + kachel(`Wachstum ${BN.kurz}`, isFinite(g) ? kiNum(g,1)+' %' : '–', 'pro Jahr');
  const zeilen = pkt.slice(-12);
  const zelle = (inhalt, extra='')=>`<td style="padding:2px 6px;text-align:right;${extra}">${inhalt}</td>`;
  const tabelle = `<table style="font-size:11.5px;border-collapse:collapse;margin-top:6px;white-space:nowrap;">
    <tr><td class="muted" style="padding:2px 8px 2px 0;">Geschäftsjahr</td>${zeilen.map(p=>zelle(escapeHtml(String(p.date).slice(2,7).replace('-','/')))).join('')}${prog.map(p=>zelle(escapeHtml(p.t.slice(2,7).replace('-','/'))+' S','color:#ea580c;')).join('')}</tr>
    <tr><td class="muted" style="padding:2px 8px 2px 0;">${escapeHtml(BN.kurz)} je Aktie</td>${zeilen.map(p=>zelle('<b>'+kiNum(p.eps)+'</b>')).join('')}${prog.map(p=>zelle(kiNum(p.eps),'color:#ea580c;')).join('')}</tr>
    <tr><td class="muted" style="padding:2px 8px 2px 0;">Veränderung</td>${zeilen.map(p=>{ const i = pkt.indexOf(p), v = i>0 ? pkt[i-1] : null; const c = v && v.eps>0 ? (p.eps/v.eps-1)*100 : null; return `<td style="padding:2px 6px;text-align:right;" class="${c==null?'':(c>=0?'pos':'neg')}">${c==null?'–':kiNum(c,0)+' %'}</td>`; }).join('')}${prog.map(()=>zelle(isFinite(g)?kiNum(g,0)+' %':'','color:#ea580c;')).join('')}</tr>
    <tr><td class="muted" style="padding:2px 8px 2px 0;">Dividende</td>${zeilen.map(p=>zelle(p.div>0?kiNum(p.div):'–')).join('')}${prog.map(()=>'<td></td>').join('')}</tr>
  </table>`;
  // Prognose-Rechner: Kursziel bei verschiedenen KGV in 1-5 Jahren, Gesamtrendite p. a. inkl. Dividende. Wachstum frei einstellbar.
  const divWachstum = (()=>{ const dk = kiDivKennzahlen(divs, j.kerzen); return dk && dk.cagr5!=null ? dk.cagr5 : (isFinite(g) ? g : 0); })();
  const progHtml = gw=>{
    if(!(epsT>0)) return '<span class="muted">Ohne positiven Gewinn keine Prognose.</span>';
    const kgvs = [7.5, 10.5, 15, 18, 21]; if(npe && !kgvs.some(x=>Math.abs(x-npe)<0.3)) kgvs.push(npe); kgvs.sort((a,b)=>a-b);
    const zelleP = (kgv, y)=>{ const ziel = epsT*Math.pow(1+gw/100, y)*kgv; let dv = 0; for(let i=1;i<=y;i++) dv += divT*Math.pow(1+divWachstum/100, i);
      const r = (Math.pow((ziel+dv)/kurs, 1/y)-1)*100; return `<td style="padding:2px 6px;text-align:right;"><b>${kiNum(ziel)}</b><br><span class="${r>=0?'pos':'neg'}">${kiNum(r,1)} %</span></td>`; };
    return `<table style="font-size:11.5px;border-collapse:collapse;white-space:nowrap;"><tr><td class="muted" style="padding:2px 8px 2px 0;">KGV / Jahre</td>${[1,2,3,4,5].map(y=>`<td class="muted" style="padding:2px 6px;text-align:right;">${y} J.</td>`).join('')}</tr>
      ${kgvs.map(kgv=>`<tr><td style="padding:2px 8px 2px 0;${npe&&Math.abs(kgv-npe)<0.01?'color:#2563eb;font-weight:700;':(kgv===15?'color:#ea580c;font-weight:700;':'')}">${kiNum(kgv,1)}×${npe&&Math.abs(kgv-npe)<0.01?' (normal)':(kgv===15?' (Fair Value)':'')}</td>${[1,2,3,4,5].map(y=>zelleP(kgv,y)).join('')}</tr>`).join('')}</table>`;
  };
  // Kursrendite gegen den S&P 500 (SPY), ohne Dividenden
  const spyHtml = async ()=>{
    try{
      const sp = await (await fetch('quote.php?ohlc=1&symbols=SPY&interval=1mo&range=max', {cache:'no-store'})).json();
      const ret = (kerzen, n)=>{ if(!kerzen || kerzen.length<=n*12) return null; const w = k=>(k[6]!=null ? k[6] : k[4]); const e = w(kerzen[kerzen.length-1]), a = w(kerzen[kerzen.length-1-n*12]); return a>0 ? (Math.pow(e/a, 1/n)-1)*100 : null; };
      const jj = interval==='1mo' ? j.kerzen : null;
      if(!jj) return '<span class="muted">Vergleich mit dem S&amp;P 500 im Monatschart.</span>';
      const ns = [1,3,5,10,15,20];
      const zeile = (name, k)=>`<tr><td style="padding:2px 8px 2px 0;">${name}</td>${ns.map(n=>{ const r = ret(k,n); return `<td style="padding:2px 6px;text-align:right;" class="${r==null?'':(r>=0?'pos':'neg')}">${r==null?'–':kiNum(r,1)+' %'}</td>`; }).join('')}</tr>`;
      // 1.000 investiert vor 10 Jahren: Kurs allein, mit Dividenden (bar) und mit Wiederanlage (bereinigter Kurs)
      const sim = (()=>{ const n = Math.min(120, jj.length-1); if(n<12) return ''; const a = jj[jj.length-1-n], e = jj[jj.length-1];
        const stueck = 1000/a[4]; const kursWert = stueck*e[4];
        const bar = divs.filter(([d])=>d>a[0]).reduce((s2,[,b])=>s2+Number(b)*stueck, 0);
        const wieder = (a[6]!=null && e[6]!=null) ? 1000*e[6]/a[6] : null;
        return `<div style="font-size:12px;margin-top:6px;">1.000 ${escapeHtml(String(j.currency||''))} investiert am ${escapeHtml(a[0])} (${kiNum(n/12,0)} J.): Kurswert <b>${kiNum(kursWert,0)}</b> + Dividenden bar <b>${kiNum(bar,0)}</b> = <b>${kiNum(kursWert+bar,0)}</b>${wieder!=null ? ` · mit Wiederanlage der Dividenden <b>${kiNum(wieder,0)}</b>` : ''} (${kiNum(stueck,2)} Stück zu ${kiNum(a[4])})</div>`; })();
      return sim + `<table style="font-size:11.5px;border-collapse:collapse;white-space:nowrap;"><tr><td class="muted" style="padding:2px 8px 2px 0;">Gesamtrendite p. a. (inkl. Dividenden)</td>${ns.map(n=>`<td class="muted" style="padding:2px 6px;text-align:right;">${n} J.</td>`).join('')}</tr>${zeile(escapeHtml(j.symbol), jj)}${zeile('S&amp;P 500 (SPY)', sp.kerzen)}</table>`;
    } catch(e){ return '<span class="muted">S&amp;P-500-Vergleich nicht geladen.</span>'; }
  };
  const netz = kiScoreNetz(f, g, pkt);
  box.innerHTML = wHinweis + `<div style="display:flex;flex-wrap:wrap;gap:6px 16px;font-size:12px;margin-top:4px;">${kz.map(([n,w])=>`<span><span class="muted">${n}</span> <b>${w}</b></span>`).join('')}</div>
    <div style="overflow-x:auto;">${tabelle}</div>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:14px;margin-top:10px;">
      <div><b style="font-size:12.5px;">Prognose: Kursziel und Rendite p. a. inkl. Dividende</b>
        <div style="font-size:11.5px;margin:4px 0;">Gewinnwachstum <input id="kiProgG" type="number" step="0.5" value="${isFinite(g)?Math.round(g*10)/10:5}" style="width:64px;padding:2px 4px;border:1px solid var(--border);border-radius:6px;background:var(--card);color:var(--text);"> % p. a. · Dividende wächst mit ${kiNum(divWachstum,1)} %</div>
        <div id="kiProgTab" style="overflow-x:auto;">${progHtml(isFinite(g)?g:5)}</div></div>
      <div><b style="font-size:12.5px;">Qualitäts-Score (eigene Formel, 0–100)</b><div style="position:relative;height:220px;"><canvas id="kiScoreNetz"></canvas></div>
        <div class="muted" style="font-size:10.5px;">${netz.map(([n,w])=>`${n} ${w==null?'–':w}`).join(' · ')}</div></div>
    </div>
    <div style="margin-top:10px;"><b style="font-size:12.5px;">Rendite gegen den S&amp;P 500</b><div id="kiSpyTab" style="overflow-x:auto;"><span class="muted">lade …</span></div></div>
    <div class="muted" style="font-size:11px;margin-top:4px;"><b>Bewertet nach ${escapeHtml(BN.lang)}${basisHinweis}${autoHinweis}${basis==='operativ' ? (_kiSbcRaus ? ', ohne Aktienvergütung' : ', mit Aktienvergütung als Kosten') : ''}</b>: ${escapeHtml(BN.info)}${basis==='operativ' && rh && rh.steuersatz ? ' (Steuersatz '+kiNum(rh.steuersatz*100,0)+' %)' : ''}. Fair Value = Wert × ${kiNum(FVR,1)} (orange, grüne Fläche), Prognose-Fächer = Kurs bei KGV 7,5–21 in 5 Jahren, Normal-Vielfaches = Wert × durchschnittliches Vielfaches ${npe?kiNum(npe,1):''} (blau), Dividende × 15 (dunkelgrün), Div.-Rendite auf der linken Achse, grau = US-Rezession. „S“, gestrichelt und heller = Schätzung: ${escapeHtml(progQuelle)}. Quelle ${rh && rh.quelle==='sec' ? 'SEC 10-K' : (rh && rh.quelle==='sec-ifrs' ? 'SEC 20-F (IFRS)' : (rh && rh.quelle==='yahoo' ? 'Yahoo (meist nur 4 Jahre)' : 'SEC/Yahoo'))}${rh && rh.firma ? ' – '+escapeHtml(rh.firma) : ''}${rh && rh.adrFaktor!=1 ? ' · je ADR (= '+kiNum(rh.adrFaktor,1)+' Aktien)' : ''}, ${hist.length} Jahre${fxHinweis}. Prognose-Fächer rechnet mit dem eingestellten Wachstum.</div>`;
  const gIn = document.getElementById('kiProgG');
  if(gIn) gIn.addEventListener('input', ()=>{ const w = parseFloat(String(gIn.value).replace(',','.')); if(isFinite(w)) document.getElementById('kiProgTab').innerHTML = progHtml(w); });
  const cv = document.getElementById('kiScoreNetz');
  if(cv && typeof Chart!=='undefined'){
    const werte = netz.map(([,w])=>w==null?0:w);
    new Chart(cv, {type:'radar', data:{labels:netz.map(([n])=>n), datasets:[{data:werte, backgroundColor:'rgba(37,99,235,.18)', borderColor:'#2563eb', pointBackgroundColor:'#2563eb', borderWidth:2}]},
      options:{responsive:true, maintainAspectRatio:false, plugins:{legend:{display:false}}, scales:{r:{min:0, max:100, ticks:{stepSize:25, display:false}, pointLabels:{font:{size:11}}}}}});
  }
  spyHtml().then(h=>{ const el = document.getElementById('kiSpyTab'); if(!el) return; el.innerHTML = h + `<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:14px;margin-top:10px;">
      <div><b style="font-size:12.5px;">Kumulierte Änderung: Kurs und Gewinn gegen</b> <input data-ki-vergleich type="text" value="${escapeHtml(_kiVergleich)}" title="Vergleichsaktie oder Index-ETF (Yahoo-Symbol), z. B. SPY, QQQ, KO, SAP.DE" style="width:80px;padding:1px 4px;font-size:12px;"><div style="position:relative;height:240px;"><canvas id="kiPerfKum"></canvas></div></div>
      <div><b style="font-size:12.5px;">Bewertung im Zeitverlauf: KGV gegen Fair Value</b><div style="position:relative;height:240px;"><canvas id="kiPerfKgv"></canvas></div></div></div>`;
    kiPerfZeichnen(j, pkt, FVR, npe); });
}
async function chartZeichnen(ctx, interval){
  try{ await chartZeichnenIntern(ctx, interval); }
  catch(e){ console.error('Chart', e); const i = document.getElementById('kiChartInfo'); if(i) i.textContent = 'Chart-Fehler: ' + e.message; }
}
async function chartZeichnenIntern(ctx, interval){
  const a = ctx.analyse, E = _kiEbene;
  const o = kiChartModal(); o.classList.add('show');
  o.querySelectorAll('[data-ki-iv]').forEach(b=>b.classList.toggle('active', b.dataset.kiIv===interval));
  o.querySelectorAll('[data-ki-tab]').forEach(b=>b.classList.toggle('active', b.dataset.kiTab===_kiTab));
  document.getElementById('kiChartEbenen').innerHTML = KI_EBENEN.map(([k,n])=>`<label style="display:inline-flex;align-items:center;gap:4px;cursor:pointer;"><input type="checkbox" data-ki-ebene="${k}" ${E[k]?'checked':''}>${n}</label>`).join('')
    + (_kiTab==='bewertung' ? `<label style="display:inline-flex;align-items:center;gap:4px;" title="Faktor für die Linie „Eigenes KGV“">KGV <input data-ki-cvr type="text" inputmode="decimal" value="${kiNum(_kiCvr,1)}" style="width:46px;padding:1px 4px;"></label>` : '');
  document.getElementById('kiChartTitel').textContent = ctx.titel;
  const info = document.getElementById('kiChartInfo'), box = document.getElementById('kiChartBox');
  info.textContent = 'Lade Kerzen …';
  document.getElementById('kiChartLegende').innerHTML = '';
  document.getElementById('kiChartBew').innerHTML = '';
  { const kf = document.getElementById('kiFitness'); if(kf){ kf.innerHTML = ''; kf.style.cssText = ''; } }
  // Reiter Fitness: kein Kurschart, nur Finanzielle Fitness / Finanzzahlen (Kursleiste, Kacheln, Ebenen, Chart ausblenden)
  { const nurFit = _kiTab==='fitness';
    // Anzeige merken (die Leisten sind display:flex) - '' haette sie untereinander gestellt (Fehler 13.11.2026)
    ['kiChartLeiste','kiChartKacheln','kiChartEbenen','kiChartJahre','kiChartBox','kiChartOsz','kiChartLegende','kiChartBew'].forEach(id=>{ const e = document.getElementById(id); if(!e) return;
      if(nurFit){ if(e.style.display!=='none'){ e.dataset.anzeige = e.style.display; e.style.display = 'none'; } }
      else if(e.style.display==='none') e.style.display = e.dataset.anzeige || ''; });
    o.querySelectorAll('[data-ki-iv]').forEach(b=>b.style.display = nurFit ? 'none' : '');
    if(nurFit){ document.getElementById('kiForecast')?.remove(); kiGraphKeySetzen(null); document.getElementById('kiChartFy')?.remove(); kiChartAufraeumen();
      document.getElementById('kiChartInfo').textContent = 'Finanzielle Fitness aus den SEC-Jahres- und Quartalsberichten (US-Firmen). Kurschart: Reiter „Bewertung“.';
      _kiChart = {ctx, interval}; kiFitnessZeichnen(String(ctx.sym||'')); return; } }
  document.getElementById('kiForecast')?.remove(); kiGraphKeySetzen(null); _kiKursOben = null; _kiKaufAnker = null; _kiKaufMarker = []; _kiKaufInfo = {}; _kiDivMarker = []; _kiSplitMarker = [];   // Kennzahlen-Box rechts / Kaufpunkte beim Neuzeichnen zuruecksetzen
  document.getElementById('kiChartFy')?.remove();
  document.getElementById('kiKachelBew').innerHTML = ''; document.getElementById('kiKachelDiv').innerHTML = ''; document.getElementById('kiKachelKi').innerHTML = ''; document.getElementById('kiChartJahre').innerHTML = '';
  kiChartAufraeumen();
  _kiChart = {ctx, interval};
  const meins = _kiChart;
  box.innerHTML = '';
  let j;
  try{
    await kiChartLib();
    const range = interval==='1mo' ? 'max' : (interval==='1wk' ? '10y' : '2y');
    const res = await fetch('quote.php?ohlc=1&symbols=' + encodeURIComponent(ctx.sym) + '&interval=' + interval + '&range=' + range, {cache:'no-store'});
    j = await res.json();
  } catch(e){ info.textContent = 'Chart konnte nicht geladen werden: ' + e.message; return; }
  if(_kiChart!==meins) return;
  if(!j || !Array.isArray(j.kerzen) || !j.kerzen.length){ info.textContent = (j && j.error) || 'Keine Kursdaten.'; return; }
  const css = getComputedStyle(document.documentElement), v = n=>css.getPropertyValue(n).trim();
  const chart = LightweightCharts.createChart(box, {
    autoSize: true,
    layout: {background:{type:'solid', color: v('--card') || '#fff'}, textColor: v('--text') || '#111', fontSize: 11},
    grid: {vertLines:{color: v('--border') || '#eee'}, horzLines:{color: v('--border') || '#eee'}},
    rightPriceScale: {borderColor: v('--border') || '#ddd'}, timeScale: {borderColor: v('--border') || '#ddd'},
    localization: {locale:'de-DE', priceFormatter: p=>kiNum(p)},
  });
  if(_kiTab==='bewertung') kiBewOhneTitel(chart);   // vor der Kurslinie und den KI-Preislinien, sonst behalten sie ihren Namen
  const serie = _kiStil==='linie'
    ? chart.addLineSeries({color: v('--text') || '#111', lineWidth:2, priceLineVisible:true, lastValueVisible:true, title:'Kurs'})
    : chart.addCandlestickSeries({upColor:'#16a34a', downColor:'#dc2626', borderVisible:false, wickUpColor:'#16a34a', wickDownColor:'#dc2626'});
  const schluss = j.kerzen.map(k=>k[4]);
  const ema12 = kiEma(schluss, 12), ema26 = kiEma(schluss, 26);
  const macd = schluss.map((_,i)=>ema12[i]-ema26[i]), signal = kiEma(macd, 9), histo = macd.map((m,i)=>m-signal[i]);
  const ema13 = kiEma(schluss, 13);
  // Elder Impulse: gruen, wenn EMA 13 und MACD-Histogramm steigen, rot, wenn beide fallen, sonst blau
  const elderFarbe = i=> i<1 ? null : (ema13[i]>ema13[i-1] && histo[i]>histo[i-1] ? '#16a34a' : (ema13[i]<ema13[i-1] && histo[i]<histo[i-1] ? '#dc2626' : '#3b82f6'));
  if(_kiStil==='linie') serie.setData(j.kerzen.map(k=>({time:k[0], value:k[4]})));
  else serie.setData(j.kerzen.map((k,i)=>{ const d = {time:k[0], open:k[1], high:k[2], low:k[3], close:k[4]};
    if(_kiEbene.elder){ const f = elderFarbe(i); if(f){ d.color = f; d.wickColor = f; } } return d; }));
  const zeiten = j.kerzen.map(k=>k[0]);
  const kerzeZu = d=>{ if(!d) return null; const z = interval==='1mo' ? String(d).slice(0,7)+'-01' : d; return zeiten.filter(t=>t<=z).pop() || null; };
  const wKurs = (j.currency||'').toUpperCase();
  const linien = [];
  const LS = LightweightCharts.LineStyle;
  const linie = (preis, farbe, titel, stil)=>{
    if(preis==null || !isFinite(Number(preis))) return;
    serie.createPriceLine({price:Number(preis), color:farbe, lineWidth: stil==='fett'?2:1,
      lineStyle: stil==='punkt' ? LS.Dotted : (stil==='strich' ? LS.Dashed : LS.Solid), axisLabelVisible:true, title:titel});
    linien.push([farbe, titel, Number(preis)]);
  };
  // Order Block als hellblaue Flaeche: Baseline-Serie, gefuellt zwischen Unterkante (Basis) und Oberkante
  const obBlock = (von, bis, tief, hoch, kraeftig)=>{
    if(!(hoch>tief) || !von || !bis || von>=bis) return;
    const bs = chart.addBaselineSeries({baseValue:{type:'price', price:Number(tief)},
      topLineColor: kraeftig ? 'rgba(59,130,246,.9)' : 'rgba(96,165,250,.6)', topFillColor1: kraeftig ? 'rgba(96,165,250,.32)' : 'rgba(147,197,253,.22)',
      topFillColor2: kraeftig ? 'rgba(96,165,250,.32)' : 'rgba(147,197,253,.22)', bottomLineColor:'rgba(0,0,0,0)',
      bottomFillColor1:'rgba(0,0,0,0)', bottomFillColor2:'rgba(0,0,0,0)', lineWidth:1,
      priceLineVisible:false, lastValueVisible:false, crosshairMarkerVisible:false});
    bs.setData([{time:von, value:Number(hoch)}, {time:bis, value:Number(hoch)}]);
  };
  // Allgemeines farbiges Band zwischen zwei Preisen (fuer Premium/Discount)
  const band = (von, bis, tief, hoch, fuell, rand)=>{
    if(!(hoch>tief) || !von || !bis || von>=bis) return;
    const bs = chart.addBaselineSeries({baseValue:{type:'price', price:Number(tief)}, topLineColor:rand, topFillColor1:fuell, topFillColor2:fuell,
      bottomLineColor:'rgba(0,0,0,0)', bottomFillColor1:'rgba(0,0,0,0)', bottomFillColor2:'rgba(0,0,0,0)', lineWidth:1,
      priceLineVisible:false, lastValueVisible:false, crosshairMarkerVisible:false});
    bs.setData([{time:von, value:Number(hoch)}, {time:bis, value:Number(hoch)}]);
  };
  const oszCharts = [];
  const marker = [];
  // gesetzte Kursalarme dieser Aktie als lila Linie (Chart-Alarme, Nutzer 12.11.2026)
  (data.priceAlerts||[]).filter(x=>x.symbol===String(ctx.sym||'').toUpperCase() && x.active!==false && Number(x.targetPrice)>0)
    .forEach(x=>linie(Number(x.targetPrice), '#9333ea', '🔔 ' + (x.condition==='below'?'unter ':'über ') + kiNum(x.targetPrice), 'punkt'));
  // Banken: Fair-Value-Spanne nach Kurs-Buchwert (bewertung-bank.php) als zwei Linien im Bewertungs-Reiter (Nutzer 12.11.2026)
  if(_kiTab==='bewertung') fetch('bewertung-bank.php?symbol=' + encodeURIComponent(ctx.sym), {cache:'no-store'}).then(r=>r.json()).then(b=>{
    if(!b || !b.bank || b.fehler || !(b.fv_unten>0) || !_kiChart || _kiChart.chart!==chart) return;
    linie(b.fv_unten, '#0f766e', 'Bank-FV unten (KBV)', 'fett');
    if(b.fv_oben > b.fv_unten) linie(b.fv_oben, '#0f766e', 'Bank-FV oben (KBV)', 'strich');
  }).catch(()=>{});
  if(a){
    const zone = (b, farbe, name)=>{ if(!Array.isArray(b)) return; if(b[0]!=null) linie(b[0], farbe, name+' unten', 'fett'); if(b[1]!=null) linie(b[1], farbe, name+' oben', 'fett'); };
    if(E.kz){ zone(a.kaufzone1, '#2563eb', 'KZ1'); zone(a.kaufzone2, '#7c3aed', 'KZ2'); }
    if(E.sb && a.strongBuy!=null) linie(a.strongBuy, '#16a34a', 'KI Strong Buy', 'fett');
    if(E.fv && a.fvBase!=null) linie(a.fvBase, '#d97706', 'KI Fair Value', 'strich');
    if(E.sr){
      const arr = (x, e)=> Array.isArray(x) ? x : [a[e+'1'], a[e+'2'], a[e+'3']];
      arr(a.support,'support').filter(n=>n!=null).forEach((n,i)=>linie(n, '#0891b2', 'S'+(i+1), 'punkt'));
      arr(a.resistance,'resistance').filter(n=>n!=null).forEach((n,i)=>linie(n, '#e11d48', 'R'+(i+1), 'punkt'));
    }
    const ob = a.vp && a.vp.ob && a.vp.ob.groesster;
    if(E.ob && ob){ linie(ob.tief, '#3b82f6', 'OB unten', 'punkt'); linie(ob.hoch, '#3b82f6', 'OB oben', 'punkt');
      obBlock(kerzeZu(ob.monat ? String(ob.monat).slice(0,7)+'-01' : null) || zeiten[0], zeiten[zeiten.length-1], ob.tief, ob.hoch, true); }
    const m = kerzeZu(a.datum);
    if(m) marker.push({time:m, position:'aboveBar', color:'#d97706', shape:'arrowDown', text:'KI ' + a.datum});
  }
  // Eigene Kaeufe: Pfeil je Kerze (mehrere Kaeufe einer Kerze zusammengefasst) und Einstand als Linie
  let kaufInfo = '';
  if(ctx.pos && E.kauf){
    const p = ctx.pos, sym = ctx.posSym;
    const kaeufe = (data.transactions||[]).filter(t=>t.type==='kauf' && ((t.displaySymbol||t.ticker)===sym || (p.ticker && t.ticker===p.ticker)));
    const preisIn = t=>{
      if(wKurs==='EUR') return t.price!=null ? Number(t.price) : null;
      if(t.priceOriginal!=null && String(t.currency||p.buyCurrency||'').toUpperCase()===wKurs) return Number(t.priceOriginal);
      return null;
    };
    const jeKerze = {};
    kaeufe.forEach(t=>{ const k = kerzeZu(t.date); if(!k) return; (jeKerze[k] = jeKerze[k] || []).push(t); });
    _kiKaufInfo = {}; Object.entries(jeKerze).forEach(([k, l])=>{ _kiKaufInfo[k] = l.map(t=>({date:t.date, shares:Number(t.shares)||0, preis:preisIn(t), eur:Number(t.price)||null, betrag:Number(t.amount)||null})); });
    Object.entries(jeKerze).forEach(([k, liste])=>{
      const pr = liste.map(preisIn).filter(x=>x!=null);
      const schnitt = pr.length ? pr.reduce((s,x)=>s+x,0)/pr.length : null;
      marker.push({time:k, position:'belowBar', color:'#2563eb', shape:'arrowUp', kauf:true, preis:schnitt, text:`Kauf${liste.length>1?' '+liste.length+'×':''}${schnitt!=null?' '+kiNum(schnitt):''}`});
    });
    let einstand = null, umgerechnet = false;
    if(wKurs==='EUR') einstand = p.buyPrice;
    else if(p.buyPriceOriginal!=null && String(p.buyCurrency||'').toUpperCase()===wKurs) einstand = p.buyPriceOriginal;
    else if(p.buyPrice!=null && p.currentPrice>0 && p.currentPriceOriginal>0){ einstand = p.buyPrice * p.currentPriceOriginal / p.currentPrice; umgerechnet = true; }
    _kiEinstand = einstand!=null ? {wert:einstand, w:wKurs, eur:(wKurs!=='EUR' ? p.buyPrice : null), umgerechnet} : null;
    if(einstand!=null){ if(_kiTab==='bewertung') linie(einstand, '#ea580c', 'Ø Einstand ' + kiNum(einstand) + ' ' + (wKurs||'') + (wKurs!=='EUR' && p.buyPrice!=null ? ' · ' + kiNum(p.buyPrice) + ' €' : '') + (umgerechnet ? ' (umgerechnet)' : ''), 'strich');   // nach Vorlage "Average Cost", in Kurswaehrung und EUR
      else linie(einstand, '#0f172a', umgerechnet ? 'Einstand (umgerechnet)' : 'Einstand', 'fett'); }
    kaufInfo = ` · ${kaeufe.length} Käufe`;
  }
  // Institutionelle Order Blocks aus den Kerzen
  const obListe = [];
  if(E.ob){
    const ende = zeiten[zeiten.length-1];
    kiOrderBlocks(j.kerzen).forEach(b=>{ obBlock(b.t, ende, b.tief, b.hoch, b.inst); obListe.push(b); });
  }
  // Marktstruktur aus Wendepunkten (Monat: 3 Kerzen links/rechts, sonst 5)
  const anaLeg = [];
  if(E.struktur || E.pivots || E.tiefs || E.pd){
    const sw = kiSwings(j.kerzen, interval==='1mo' ? 3 : 5);
    if(E.struktur || E.pivots){
      sw.slice(-18).forEach(p=>{
        const text = [E.struktur ? p.label : '', E.pivots ? '('+kiNum(p.preis)+')' : ''].filter(Boolean).join(' ');
        marker.push({time:p.t, position: p.typ==='H' ? 'aboveBar' : 'belowBar', color: (p.label==='HH'||p.label==='HL') ? '#16a34a' : ((p.label==='LH'||p.label==='LL') ? '#dc2626' : '#64748b'), shape:'circle', size:0.4, text});
      });
    }
    const kurs = j.kerzen[j.kerzen.length-1][4];
    if(E.tiefs){
      // Fruehere Tiefs unter dem Kurs, die seither nicht per Schluss unterschritten wurden - die naechsten drei
      const offen = sw.filter(p=>p.typ==='L' && p.preis<kurs && !j.kerzen.slice(p.i+1).some(k=>k[4]<p.preis));
      offen.sort((x,y)=>y.preis-x.preis).slice(0,3).forEach(p=>linie(p.preis, '#dc2626', 'Tief '+p.t.slice(5,7)+'/'+p.t.slice(0,4), 'strich'));
    }
    if(E.pd){
      // Spanne der letzten 60 Kerzen: obere Haelfte Premium (teuer), untere Discount (guenstig), Mitte Gleichgewicht
      const n = Math.min(j.kerzen.length, 60), teil = j.kerzen.slice(-n);
      let iH = 0, iL = 0; teil.forEach((k,i)=>{ if(k[2]>teil[iH][2]) iH = i; if(k[3]<teil[iL][3]) iL = i; });
      const hoch = teil[iH][2], tief = teil[iL][3], mitte = (hoch+tief)/2, von = teil[Math.min(iH,iL)][0], bis = zeiten[zeiten.length-1];
      band(von, bis, mitte, hoch, 'rgba(239,68,68,.10)', 'rgba(239,68,68,.35)');
      band(von, bis, tief, mitte, 'rgba(34,197,94,.10)', 'rgba(34,197,94,.35)');
      linie(mitte, '#64748b', 'Gleichgewicht', 'strich');
      anaLeg.push(`<span><span style="display:inline-block;width:12px;height:10px;background:rgba(239,68,68,.25);vertical-align:middle;margin-right:4px;"></span>Premium ${kiNum(mitte)}–${kiNum(hoch)}</span>`,
        `<span><span style="display:inline-block;width:12px;height:10px;background:rgba(34,197,94,.25);vertical-align:middle;margin-right:4px;"></span>Discount ${kiNum(tief)}–${kiNum(mitte)} · Kurs ${kurs>=mitte?'im Premium':'im Discount'}</span>`);
    }
  }
  // Oszillatoren in eigenen, zeitgleich mitlaufenden Feldern unter dem Chart: MACD (12/26/9) und Williams %R (14)
  if(E.osz){
    const oz = document.getElementById('kiChartOsz');
    oz.innerHTML = '<div class="muted" style="font-size:11px;margin-top:4px;">MACD 12/26/9</div><div id="kiOszMacd" style="height:110px;"></div><div class="muted" style="font-size:11px;">Williams %R 14 (über −20 überkauft, unter −80 überverkauft)</div><div id="kiOszWr" style="height:100px;"></div>';
    const optOsz = {autoSize:true, layout:{background:{type:'solid', color: v('--card') || '#fff'}, textColor: v('--text') || '#111', fontSize:10},
      grid:{vertLines:{color: v('--border') || '#eee'}, horzLines:{color: v('--border') || '#eee'}}, rightPriceScale:{borderColor: v('--border') || '#ddd', minimumWidth:70},
      timeScale:{visible:false}, localization:{locale:'de-DE', priceFormatter:p=>kiNum(p)}, handleScroll:false, handleScale:false};
    const c1 = LightweightCharts.createChart(document.getElementById('kiOszMacd'), optOsz);
    const h1 = c1.addHistogramSeries({priceLineVisible:false, lastValueVisible:false});
    h1.setData(zeiten.map((t,i)=>i<26 ? {time:t} : {time:t, value:histo[i], color: histo[i]>=0 ? (i>0&&histo[i]>=histo[i-1]?'#16a34a':'#86efac') : (i>0&&histo[i]<=histo[i-1]?'#dc2626':'#fca5a5')}));
    const l1 = c1.addLineSeries({color:'#2563eb', lineWidth:1, priceLineVisible:false, lastValueVisible:false}); l1.setData(zeiten.map((t,i)=>i<26 ? {time:t} : {time:t, value:macd[i]}));
    const l2 = c1.addLineSeries({color:'#ea580c', lineWidth:1, priceLineVisible:false, lastValueVisible:false}); l2.setData(zeiten.map((t,i)=>i<34 ? {time:t} : {time:t, value:signal[i]}));
    const c2 = LightweightCharts.createChart(document.getElementById('kiOszWr'), optOsz);
    const wr = c2.addLineSeries({color:'#7c3aed', lineWidth:1, priceLineVisible:false, lastValueVisible:true});
    wr.setData(j.kerzen.map((k,i)=>{ if(i<13) return {time:k[0]}; const f = j.kerzen.slice(i-13, i+1); const hh = Math.max(...f.map(x=>x[2])), ll = Math.min(...f.map(x=>x[3]));
      return hh>ll ? {time:k[0], value:(hh-k[4])/(hh-ll)*-100} : {time:k[0]}; }));
    wr.createPriceLine({price:-20, color:'#dc2626', lineWidth:1, lineStyle:LS.Dashed, axisLabelVisible:false});
    wr.createPriceLine({price:-80, color:'#16a34a', lineWidth:1, lineStyle:LS.Dashed, axisLabelVisible:false});
    chart.applyOptions({rightPriceScale:{minimumWidth:70}});
    const sync = r=>{ if(r){ c1.timeScale().setVisibleLogicalRange(r); c2.timeScale().setVisibleLogicalRange(r); } };
    chart.timeScale().subscribeVisibleLogicalRangeChange(sync);
    oszCharts.push(c1, c2);
    setTimeout(()=>sync(chart.timeScale().getVisibleLogicalRange()), 0);
  }
  // Dividenden: Sonderausschuettungen (z. B. Abspaltungen, von Yahoo als Dividende gefuehrt) heraus - eine Zahlung ueber
  // dem 3-Fachen des Medians der 8 vorigen faellt raus.
  const divsRoh = Array.isArray(j.dividenden) ? j.dividenden : [];
  const sonder = [];
  const divs = divsRoh.filter(([d,b], i)=>{
    const vor = divsRoh.slice(Math.max(0,i-8), i).map(x=>Number(x[1])).sort((x,y)=>x-y);
    if(vor.length>=3){ const med = vor[Math.floor(vor.length/2)]; if(med>0 && Number(b) > 3*med){ sonder.push([d,b]); return false; } }
    return true;
  });
  _kiFitDivs = {sym:String(ctx.sym||''), divs};   // fuer die Finanzielle Fitness: gezahlte Dividende je Geschaeftsjahr nach Vorlage
  if(_kiTab==='bewertung' && _kiFitDaten[String(ctx.sym||'')]) setTimeout(()=>kiFitnessZeichnen(String(ctx.sym||'')), 0);
  let divInfo = '';
  if(divs.length && (E.divhist || E.div3)){
    const tag = s=>new Date(s+'T12:00:00Z').getTime();
    const ttm = t=>{ const ende = interval==='1mo' ? tag(t) + 30*86400000 : tag(t); return divs.reduce((s,[d,b])=>{ const x = tag(d); return (x<=ende && x>ende-365*86400000) ? s+Number(b) : s; }, 0); };
    if(E.divhist){
      const proKerze = {};
      divs.forEach(([d, betrag])=>{ const k = kerzeZu(d); if(k) proKerze[k] = (proKerze[k]||0) + Number(betrag); });
      const hist = chart.addHistogramSeries({priceScaleId:'div', color:'rgba(202,138,4,.55)', lastValueVisible:false, priceLineVisible:false, priceFormat:{type:'price', precision:2, minMove:0.01}});
      chart.priceScale('div').applyOptions({scaleMargins:{top:0.84, bottom:0}});
      hist.setData(zeiten.filter(t=>proKerze[t]).map(t=>({time:t, value:proKerze[t]})));
      divInfo += `<span><span style="display:inline-block;width:10px;height:8px;background:rgba(202,138,4,.55);vertical-align:middle;margin-right:4px;"></span>Dividendenbalken (je ${interval==='1mo'?'Monat':(interval==='1wk'?'Woche':'Tag')})</span>`;
    }
    if(E.div3){
      const drei = []; zeiten.forEach(t=>{ const s = ttm(t); if(s>0) drei.push({time:t, value:s/0.03}); });
      if(drei.length){
        const ls = chart.addLineSeries({color:'#ca8a04', lineWidth:2, priceLineVisible:false, lastValueVisible:true, title:'3 % Rendite'});
        ls.setData(drei);
        const letzt = ttm(zeiten[zeiten.length-1]);
        divInfo += `<span><span style="display:inline-block;width:10px;height:3px;background:#ca8a04;vertical-align:middle;margin-right:4px;"></span>3 %-Rendite-Kurs ${kiNum(letzt/0.03)} (Dividende 12 M ${kiNum(letzt)} ${escapeHtml(wKurs)})</span>`;
      }
    }
    if(sonder.length) divInfo += `<span class="muted">ohne ${sonder.length} Sonderausschüttung${sonder.length>1?'en':''} (${sonder.map(x=>escapeHtml(x[0].slice(0,7))).join(', ')})</span>`;
  }
  marker.sort((x,y)=>x.time<y.time?-1:(x.time>y.time?1:0));
  { const m2 = [...marker.filter(m=>!m.kauf), ...(_kiTab==='bewertung' ? [] : marker.filter(m=>m.kauf).map(m=>({time:m.time, position:'inBar', color:'#db2777', shape:'circle', size:2, text:''})))]
      .sort((x,y)=>x.time<y.time?-1:(x.time>y.time?1:0));
    if(m2.length) serie.setMarkers(m2);   // Kaeufe ueberall als pinke Punkte + Kaesten (kiKaufBoxen) statt Pfeil mit Text
    if(_kiTab!=='bewertung'){ _kiKaufAnker = serie; _kiKursChart = chart; _kiKursAm = {}; j.kerzen.forEach(k=>{ _kiKursAm[k[0]] = k[4]; }); kiKaufBoxen(); } }
  // Bewertungs-Reiter: Kaeufe nach Vorlage als gruene Punkte auf der oberen Kurslinie (Nutzer 12.11.2026)
  // Pink statt Gruen (Nutzer 14.11.2026: gruene Punkte auf der gruenen Flaeche kaum zu sehen); Farbe gibt es sonst im Chart nicht
  _kiKaufMarker = marker.filter(m=>m.kauf).map(m=>({time:m.time, position:'inBar', color:'#db2777', shape:'circle', size:2, text:''}));   // Beschriftung macht kiKaufBoxen()
  { const zeitAm = d=>{ let t = null; for(const k of j.kerzen){ if(k[0] <= d) t = k[0]; else break; } return t || j.kerzen[0][0]; };
    _kiDivMarker = (_kiTab==='bewertung' && E.divdekl) ? divs.map(([d,b])=>({time:zeitAm(d), position:'belowBar', color:'#ca8a04', shape:'circle', size:0.5, text:''})) : [];
    _kiSplitMarker = [];
    kiObenMarker();   // obere Kurslinie war ggf. schon da
    if(_kiTab==='bewertung' && E.split){ const symS = String(ctx.sym||'');
      fetch('bewertung-reihen.php?symbol=' + encodeURIComponent(symS), {cache:'no-store'}).then(r=>r.json()).then(rh=>{
        if(String(_kiChart?.ctx?.sym||'')!==symS) return;
        _kiSplitMarker = (rh && rh.splits || []).filter(x=>x.date >= j.kerzen[0][0]).map(x=>({time:zeitAm(x.date), position:'aboveBar', color:'#111', shape:'circle', size:1,
          text:'Split ' + (x.faktor>=1 ? kiNum(x.faktor, x.faktor%1?1:0)+':1' : '1:'+kiNum(1/x.faktor, (1/x.faktor)%1?1:0))}));
        kiObenMarker(); }).catch(()=>{}); } }
  // Jahres-Hoch/Tief ueber dem Chart nach Vorlage: jede Spalte steht ueber ihrem Jahr und laeuft beim Verschieben/Zoomen
  // mit (Nutzer 07.11.2026: "beim Bewegen werden die Linien nicht mitbewegt"). Bei engem Zoom nur jedes n-te Jahr.
  const jhl = {}, jIdx = {};
  j.kerzen.forEach((k,i)=>{ const y = k[0].slice(0,4); const e = jhl[y] || (jhl[y] = {h:-Infinity, l:Infinity}); e.h = Math.max(e.h, k[2]); e.l = Math.min(e.l, k[3]);
    const r = jIdx[y] || (jIdx[y] = {a:k[0], b:k[0]}); r.b = k[0]; });
  const jBox = document.getElementById('kiChartJahre');
  jBox.style.cssText = 'position:relative;height:48px;overflow:hidden;font-size:11px;margin-bottom:2px;';
  jBox.innerHTML = `<div class="muted" style="position:absolute;left:0;top:1px;line-height:15px;background:var(--card);z-index:1;padding-right:6px;">Jahr<br>Hoch<br>Tief</div>`
    + Object.keys(jhl).sort().map(y=>`<div data-jahr="${y}" style="position:absolute;top:1px;line-height:15px;text-align:center;transform:translateX(-50%);white-space:nowrap;display:none;"><b>${y}</b><br>${kiNum(jhl[y].h)}<br>${kiNum(jhl[y].l)}</div>`).join('');
  const jahreLage = ()=>{
    if(!jBox.isConnected) return;
    const ts = chart.timeScale(); let off = 0; try{ off = chart.priceScale('left').width() || 0; }catch(e){}
    const pane = ts.width(); const els = [...jBox.querySelectorAll('[data-jahr]')];
    const pos = els.map(el=>{ const r = jIdx[el.dataset.jahr]; const x1 = ts.timeToCoordinate(r.a), x2 = ts.timeToCoordinate(r.b); return (x1==null||x2==null) ? null : (x1+x2)/2; });
    const xs = pos.filter(x=>x!=null); let schritt = 1;
    if(xs.length > 1){ const proJahr = Math.abs(xs[xs.length-1]-xs[0])/(xs.length-1); schritt = Math.max(1, Math.ceil(46/Math.max(proJahr,1))); }
    els.forEach((el,i)=>{ const x = pos[i]; const y = +el.dataset.jahr;
      const zeigen = x!=null && x > 40 && x < pane-22 && (schritt===1 || y % schritt === 0);
      el.style.display = zeigen ? '' : 'none'; if(zeigen) el.style.left = (off + x) + 'px'; });
  };
  chart.timeScale().subscribeVisibleLogicalRangeChange(jahreLage);
  try{ chart.timeScale().subscribeSizeChange(jahreLage); }catch(e){}
  setTimeout(jahreLage, 0); setTimeout(jahreLage, 300);
  // KI-Analyse als Kacheln: Fair Value und Strong Buy mit Abstand zum Kurs (Nutzerwunsch 05.11.2026)
  if(a && (a.fvBase!=null || a.strongBuy!=null)){
    const kk = j.kerzen[j.kerzen.length-1][4];
    const abst = z=> z>0 ? (kk/z-1)*100 : null;
    const fvA = abst(a.fvBase), sbA = abst(a.strongBuy);
    document.getElementById('kiKachelKi').innerHTML =
      (a.fvBase!=null ? kiKachel('KI Fair Value', kiNum(a.fvBase), `${fvA>=0 ? kiNum(fvA,0)+' % darüber' : kiNum(-fvA,0)+' % darunter'} · Analyse ${escapeHtml(a.datum||'')}`, fvA==null?'':(fvA>10?'#dc2626':(fvA<-10?'#16a34a':''))) : '')
      + (a.strongBuy!=null ? kiKachel('KI Strong Buy', '≤ '+kiNum(a.strongBuy), sbA<=0 ? 'erreicht – Kurs im Strong-Buy-Bereich' : `noch ${kiNum(sbA,0)} % bis dahin`, sbA<=0 ? '#16a34a' : '') : '');
  }
  // Dividenden-Kennzahlen als Kacheln (immer, wenn es Dividenden gibt)
  const dk = kiDivKennzahlen(divs, j.kerzen);
  if(dk){
    const kursJ = j.kerzen[j.kerzen.length-1][4];
    const divJetzt = divs.reduce((s2,[d,b])=>{ const t = new Date(d+'T12:00:00Z').getTime(); return t>Date.now()-365*86400000 ? s2+Number(b) : s2; }, 0);
    const rJetzt = kursJ>0 ? divJetzt/kursJ*100 : null;
    const verg = (rJetzt!=null && dk.normalRendite) ? (rJetzt>dk.normalRendite*1.1 ? ['über dem Schnitt → eher günstig', '#16a34a'] : (rJetzt<dk.normalRendite*0.9 ? ['unter dem Schnitt → eher teuer', '#dc2626'] : ['etwa im Schnitt', ''])) : ['', ''];
    document.getElementById('kiKachelDiv').innerHTML =
      kiKachel('Dividenden-Wachstum', dk.cagr5!=null ? kiNum(dk.cagr5,1)+' % p. a.' : '–', `5 J.${dk.cagr10!=null?' · 10 J. '+kiNum(dk.cagr10,1)+' %':''}`)
      + kiKachel('Erhöhungen in Folge', dk.folge+' Jahre', `bis ${dk.letztesJahr} · ${dk.bezahlt} Jahre ohne Unterbrechung gezahlt`)
      + kiKachel('Normale Rendite', dk.normalRendite!=null ? kiNum(dk.normalRendite,2)+' %' : '–', rJetzt!=null ? `jetzt ${kiNum(rJetzt,2)} % – ${verg[0]}` : '', verg[1]);
  }
  // Infofeld beim Ueberfahren: Datum, Kurs, Rendite zu dem Zeitpunkt, KGV und Abweichung zum Fair Value (sobald Gewinne geladen)
  const tip = document.createElement('div');
  tip.style.cssText = 'position:absolute;top:8px;left:8px;z-index:5;pointer-events:none;display:none;background:var(--card);border:1px solid var(--border);border-radius:8px;padding:6px 9px;font-size:11.5px;box-shadow:0 4px 14px rgba(0,0,0,.15);';
  box.appendChild(tip);
  const kerzeAm = {}; j.kerzen.forEach(k=>kerzeAm[k[0]] = k);
  const tagMs = s2=>new Date(s2+'T12:00:00Z').getTime();
  const hPkte = document.createElement('div'); hPkte.style.cssText = 'position:absolute;inset:0;pointer-events:none;z-index:4;'; box.appendChild(hPkte);
  const hPunkte = (x, werte)=>{ const ser = _kiKursOben; if(!ser){ hPkte.innerHTML = ''; return; }
    hPkte.innerHTML = werte.filter(([v])=>v>0).map(([v, f])=>{ const y = ser.priceToCoordinate(v); return (y==null || !isFinite(y)) ? ''
      : `<span style="position:absolute;left:${x-5}px;top:${y-5}px;width:10px;height:10px;border-radius:50%;background:${f};border:2px solid #fff;box-shadow:0 0 0 1px rgba(0,0,0,.35);"></span>`; }).join(''); };
  chart.subscribeCrosshairMove(par=>{
    if(!par || !par.time || !par.point || !kerzeAm[par.time]){ tip.style.display = 'none'; hPkte.innerHTML = ''; return; }
    const k = kerzeAm[par.time], c = k[4];
    const ende = interval==='1mo' ? tagMs(par.time)+30*86400000 : tagMs(par.time);
    const d12 = divs.reduce((s2,[d,b])=>{ const t = tagMs(d); return (t<=ende && t>ende-365*86400000) ? s2+Number(b) : s2; }, 0);
    const bw0 = _kiChart && _kiChart.bew;
    if(_kiTab==='bewertung' && bw0){   // Infobox nach Vorlage (Nutzer 12.11.2026, AMGN-Bilder)
      const e = (bw0.epsLin && bw0.epsLin(String(par.time))) || bw0.epsAm(par.time), fvr = _kiFvr || 15, kgvH = _kiChart.kgvHeute, pr = v=>v>0 ? `<span style="color:${c<=v?'#16a34a':'#dc2626'}">${v>=c?'+':''}${kiNum((v/c-1)*100,1)} %</span>` : '';
      const datum = new Date(par.time+'T12:00:00Z').toLocaleDateString('de-DE', {day:'2-digit', month:'short', year:'numeric'});
      let h = `<div style="display:flex;justify-content:space-between;gap:14px;"><b>${datum}</b><span>● ${kiNum(c)}${e>0 ? ' · KGV '+kiNum(c/e,2)+'×' : ''}</span></div>`;
      if(e>0){
        h += `<table style="min-width:0;width:100%;margin-top:4px;border-collapse:collapse;"><tr class="muted"><td></td><td style="text-align:right;">KGV</td><td style="text-align:right;">Wert</td><td style="text-align:right;">zum Kurs</td></tr>
          <tr><td><span style="color:#ea580c">●</span> Fair Value</td><td style="text-align:right;">${kiNum(fvr,2)}×</td><td style="text-align:right;">${kiNum(e*fvr)}</td><td style="text-align:right;">${pr(e*fvr)}</td></tr>`
          + (bw0.npe ? `<tr><td><span style="color:#2563eb">●</span> Normal-KGV</td><td style="text-align:right;">${kiNum(bw0.npe,2)}×</td><td style="text-align:right;">${kiNum(e*bw0.npe)}</td><td style="text-align:right;">${pr(e*bw0.npe)}</td></tr>` : '')
          + (kgvH ? `<tr><td><span style="color:#111">●</span> Akt. Bewertung</td><td style="text-align:right;">${kiNum(kgvH,2)}×</td><td style="text-align:right;">${kiNum(e*kgvH)}</td><td style="text-align:right;">${pr(e*kgvH)}</td></tr>` : '') + `</table>`;
        h += `<div style="display:grid;grid-template-columns:auto auto auto auto;gap:2px 10px;margin-top:4px;border-top:1px solid var(--border);padding-top:3px;">
          <span class="muted">EPS</span><b>${kiNum(e,2)}</b><span class="muted"><span style="color:#b91c1c">●</span> Div.-Rendite</span><b>${d12>0 ? kiNum(d12/c*100,2)+' %' : '–'}</b>
          <span class="muted"><span style="color:#eab308">●</span> Dividende</span><b>${d12>0 ? kiNum(d12,2) : '–'}</b><span class="muted">Ausschüttung</span><b>${d12>0 ? kiNum(d12/e*100,1)+' %' : '–'}</b></div>`;
      }
      const kauf = _kiKaufInfo[par.time];
      if(kauf) kauf.forEach(t=>{ h += `<div style="margin-top:5px;border-top:1px solid var(--border);padding-top:3px;"><b style="color:#db2777;">KAUF</b> ${escapeHtml(new Date(t.date+'T12:00:00Z').toLocaleDateString('de-DE'))}<br>
          Stück <b>${kiNum(t.shares, t.shares%1?4:0)}</b> · Preis <b>${(()=>{ const pk = kiKaufPreisKurs(t); return pk!=null ? kiNum(pk) + (t.preis==null && t.eur!=null ? ' (' + kiNum(t.eur) + ' €)' : '') : (t.eur!=null ? kiNum(t.eur)+' €' : '–'); })()}</b>${t.betrag ? ' · Gesamt <b>'+kiNum(t.betrag)+' €</b>' : ''}${(e>0 && t.preis!=null) ? ' · KGV '+kiNum(t.preis/e,2)+'×' : ''}</div>`; });
      hPunkte(par.point.x, e>0 ? [[e*fvr,'#ea580c'], [bw0.npe ? e*bw0.npe : 0,'#2563eb'], [kgvH ? e*kgvH : 0,'#111'], [d12*fvr,'#eab308'], [c,'#16a34a']] : [[c,'#16a34a']]);
      tip.innerHTML = h; tip.style.display = 'block'; tip.style.minWidth = '250px';
      const bx = box.clientWidth, x = par.point.x; tip.style.left = (x + 280 > bx ? Math.max(4, x - 270) : x + 16) + 'px'; tip.style.top = Math.max(4, par.point.y - 60) + 'px';
      return;
    }
    hPkte.innerHTML = ''; tip.style.minWidth = ''; tip.style.left = '8px'; tip.style.top = '8px';
    let z = `<b>${escapeHtml(par.time)}</b><br>Kurs ${kiNum(c)} (H ${kiNum(k[2])} / T ${kiNum(k[3])})`;
    if(d12>0) z += `<br>Div.-Rendite ${kiNum(d12/c*100,2)} %`;
    const bw = _kiChart && _kiChart.bew;
    if(bw){ const e = bw.epsAm(par.time); if(e>0){ z += `<br>KGV ${kiNum(c/e,1)} · Fair Value ${kiNum(e*15)} (${kiProzent((c/(e*15)-1)*100)})`; if(bw.npe) z += `<br>Wert bei Normal-KGV ${kiNum(e*bw.npe)}`; } }
    tip.innerHTML = z; tip.style.display = 'block';
  });
  // Renditerechner nach Vorlage (Nutzer 12.11.2026, AMGN-Bild): 1. Klick = Start (1.000 investiert), 2. Klick = Ziel
  // (Kurs, in der Zukunft Fair Value = Gewinn x Faktor), Dividenden dazwischen (Zukunft: letzte 12-Monats-Dividende je Jahr).
  { let rr = null; const boxen = [];
    const weg = ()=>{ boxen.splice(0).forEach(b=>b.remove()); rr = null; };
    const kasten = (x, y, html)=>{ const d = document.createElement('div');
      d.style.cssText = `position:absolute;z-index:6;left:${Math.min(Math.max(4, x+10), box.clientWidth-230)}px;top:${Math.max(4, y-20)}px;width:220px;background:var(--card);border:1px solid var(--border);border-radius:8px;padding:6px 9px;font-size:11.5px;box-shadow:0 4px 14px rgba(0,0,0,.15);`;
      d.innerHTML = `<a href="#" style="position:absolute;right:6px;top:2px;text-decoration:none;" title="schließen">×</a>` + html;
      d.querySelector('a').addEventListener('click', ev=>{ ev.preventDefault(); weg(); });
      box.appendChild(d); boxen.push(d); };
    const zeile = (t, v, f)=>`<div style="display:flex;justify-content:space-between;"><span class="muted">${t}</span><b${f?` style="color:${f}"`:''}>${v}</b></div>`;
    const pz = v=>`${v>=0?'+':''}${kiNum(v,2)} %`, farbe = v=>v>=0?'#16a34a':'#dc2626';
    const tagD = t=>new Date(t+'T12:00:00Z').toLocaleDateString('de-DE');
    const divZwischen = (t0, t2)=>{ let s = 0; const a = tagMs(t0), b = tagMs(t2); divs.forEach(([d,v])=>{ const t = tagMs(d); if(t>a && t<=b) s += Number(v); });
      const letzt = divs.length ? tagMs(divs[divs.length-1][0]) : a;
      if(b > letzt && b > Date.now()){ const d12 = divs.reduce((s2,[d,v])=>{ const t = tagMs(d); return (t>letzt-365*86400000 && t<=letzt) ? s2+Number(v) : s2; }, 0); s += d12 * (b - Math.max(letzt, a)) / (365.25*86400000); }
      return s; };
    chart.subscribeClick(par=>{
      if(_kiTab!=='bewertung' || !par || !par.time || !par.point) return;
      const bw = _kiChart && _kiChart.bew; if(!bw) return;
      const t = String(par.time), fvr = _kiFvr || 15;
      if(!rr || rr.fertig){
        weg(); const k = kerzeAm[t]; if(!k) return;
        const e0 = bw.epsLin(t); rr = {t0:t, p0:k[4], e0, n:1000/k[4]};
        kasten(par.point.x, par.point.y, `<div style="text-align:center;margin-bottom:3px;"><b>${tagD(t)}</b></div>` + zeile('Investiert', kiNum(1000,2)) + zeile('Kurs', kiNum(k[4])) + zeile('Stück', kiNum(rr.n,2)) + (e0>0 ? zeile('KGV', kiNum(k[4]/e0,2)+'×', '#ea580c') : '')
          + `<div class="muted" style="margin-top:3px;">Jetzt zweiten Punkt anklicken (auch in der Zukunft).</div>`);
        return;
      }
      if(t <= rr.t0) return;
      const k = kerzeAm[t], e2 = bw.epsLin(t);
      const p2 = k ? k[4] : (e2>0 ? e2*fvr : null); if(!(p2>0)) return;
      const jahre = (tagMs(t)-tagMs(rr.t0))/(365.25*86400000), mv = rr.n*p2, div = rr.n*divZwischen(rr.t0, t);
      const wachs = (p2/rr.p0-1)*100, annO = (Math.pow(p2/rr.p0, 1/Math.max(jahre,0.01))-1)*100, ges = ((mv+div)/1000-1)*100, annG = (Math.pow((mv+div)/1000, 1/Math.max(jahre,0.01))-1)*100;
      const epsC = (rr.e0>0 && e2>0) ? (Math.pow(e2/rr.e0, 1/Math.max(jahre,0.01))-1)*100 : null;
      kasten(par.point.x, par.point.y, `<div style="text-align:center;margin-bottom:3px;"><b>${tagD(t)}</b></div>` + zeile(k ? 'Schlusskurs' : 'Fair Value (Ziel)', kiNum(p2)) + (e2>0 ? zeile('KGV', kiNum(p2/e2,2)+'×', '#ea580c') : '')
        + `<div style="text-align:center;margin:3px 0;border-top:1px solid var(--border);padding-top:3px;">Rendite · Rate of Return (${kiNum(jahre,1)} J.)</div>`
        + zeile('Marktwert', kiNum(mv,2)) + zeile('Kursgewinn', pz(wachs), farbe(wachs)) + zeile('p. a. ohne Div.', pz(annO), farbe(annO))
        + zeile('Dividenden', kiNum(div,2)) + zeile('Wert + Div.', kiNum(mv+div,2)) + zeile('Gesamtrendite', pz(ges), farbe(ges)) + zeile('Gesamtrendite p. a.', pz(annG), farbe(annG))
        + (epsC!=null ? `<div style="margin-top:3px;padding:2px 5px;border-radius:5px;background:rgba(234,88,12,.1);">${zeile('EPS-Wachstum p. a.', pz(epsC), farbe(epsC))}</div>` : ''));
      rr.fertig = true;
    });
  }
  _kiChart = {ctx, interval, chart, osz:oszCharts, zeiten};
  kiZeitraumAnwenden();
  o.querySelectorAll('[data-ki-stil]').forEach(b=>b.classList.toggle('active', b.dataset.kiStil===_kiStil));
  o.querySelectorAll('[data-ki-basis]').forEach(b=>b.classList.toggle('active', b.dataset.kiBasis===_kiBasis));
  const sbcCb = o.querySelector('[data-ki-sbc]'); if(sbcCb) sbcCb.checked = _kiSbcRaus;
  const fvrIn = o.querySelector('[data-ki-fvr]'); if(fvrIn && document.activeElement!==fvrIn) fvrIn.value = _kiFvr;
  o.querySelectorAll('[data-ki-jahre]').forEach(b=>b.classList.toggle('active', String(_kiJahre==null?'max':_kiJahre)===b.dataset.kiJahre));
  const wAna = a ? (a.waehrung||'').toUpperCase() : '';
  const warnung = (wAna && wKurs && wAna!==wKurs) ? ` · <b style="color:#dc2626;">Achtung: Analyse in ${escapeHtml(wAna)}, Kurse in ${escapeHtml(wKurs)} – Zonen passen nicht zum Chart</b>` : '';
  info.innerHTML = `Kerzen ${escapeHtml(j.symbol)} (${escapeHtml(wKurs||'?')}, Yahoo)${a ? ` · Zonen aus der KI-Analyse vom ${escapeHtml(a.datum||'–')}${a.rating?' · '+escapeHtml(a.rating):''}` : ' · <b>keine KI-Analyse</b> zu diesem Titel – Kaufzonen erst nach einer Analyse, Fair Value kommt aus den Gewinnen (KGV 15)'}${kaufInfo}${warnung}`;
  const leg = linien.map(([f,t,p])=>`<span><span style="display:inline-block;width:10px;height:3px;background:${f};vertical-align:middle;margin-right:4px;"></span>${escapeHtml(t)} ${kiNum(p)}</span>`).join('');
  const obLeg = E.ob ? (obListe.length
    ? obListe.map(b=>`<span><span style="display:inline-block;width:12px;height:10px;background:${b.inst?'rgba(96,165,250,.55)':'rgba(147,197,253,.4)'};border:1px solid #60a5fa;vertical-align:middle;margin-right:4px;"></span>${b.inst?'Inst. ':''}OB ${b.art==='bull'?'bullisch':'bearisch'} ${escapeHtml(b.t.slice(0, interval==='1mo'?7:10))}: ${kiNum(b.tief)}–${kiNum(b.hoch)}${b.vf!=null?` · Vol. ${kiNum(b.vf,1)}×`:''}</span>`).join('')
    : '<span class="muted">Kein offener Order Block nahe am Kurs.</span>') : '';
  document.getElementById('kiChartLegende').innerHTML = leg + obLeg + anaLeg.join('') + divInfo
    + (E.struktur ? '<span class="muted">HH/HL grün = Aufwärtsstruktur, LH/LL rot = Abwärtsstruktur</span>' : '')
    + (E.elder ? '<span class="muted">Kerzenfarbe Elder Impulse: grün Kauf-Impuls, rot Verkauf-Impuls, blau neutral</span>' : '');
  kiBewZeichnen(chart, j, interval, zeiten, kerzeZu, divs, _kiChart);
}
document.addEventListener('click', e=>{
  const b = e.target.closest('[data-ki-chart]');
  if(b){ e.preventDefault(); e.stopPropagation(); kiChartZeichnen(b.dataset.kiChart, '1mo'); }
  const pb = e.target.closest('[data-pos-chart]');
  if(pb){ e.preventDefault(); e.stopPropagation(); chartKontextPos(pb.dataset.posChart).then(ctx=>{ if(ctx) chartZeichnen(ctx, '1mo'); }); }
});
document.addEventListener('keydown', e=>{ if(e.key==='Escape' && document.getElementById('kiChartOverlay')?.classList.contains('show')) kiChartSchliessen(); });
