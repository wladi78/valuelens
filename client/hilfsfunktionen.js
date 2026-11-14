let _ki = {analysen:[], ausgeblendet:[], favoriten:[], notizen:{}, istAdmin:false, letzterAbruf:null, kurse:{}, geladen:false, laedt:false};
let _kiAnsicht = 'liste';
let _kiSort = {spalte:'datum', ab:false};   // innerhalb der Sektoren neueste zuerst (wie die fruehere Capolio-Seite)
let _kiQuick = 'alle';                      // alle | fav | neu | depot
let _kiZone = '';                           // sb | kz2 | kz1 | nah | warten
let _kiSektorWahl = '';
let _kiKatWahl = '';
let _kiBewWahl = '';                        // unter | fair | ueber (Bewertung aus dem MoS)
let _kiOffen = new Set();                   // aufgeklappte Titel
let _kiHistorie = {};                       // Ticker -> geladene Historie
const KI_KAT_RANG = {King:0, Champion:1, Contender:2, Challenger:3};
const KI_NEU_TAGE = 14;
function kiNum(v, d=2){ return v==null || isNaN(v) ? '–' : Number(v).toLocaleString('de-DE', {minimumFractionDigits:d, maximumFractionDigits:d}); }
function kiZoneTxt(z){ if(!Array.isArray(z) || z[1]==null) return '–'; return z[0]!=null ? kiNum(z[0])+'–'+kiNum(z[1]) : '≤ '+kiNum(z[1]); }
function kiSymbol(a){ return a.yahoo || a.ticker; }
function kiDepotStueck(ticker){
  return (data.positions||[]).filter(p=>String(p.displaySymbol||p.ticker||'').toUpperCase()===ticker).reduce((s,p)=>s+(Number(p.shares)||0), 0);
}
async function kiLaden(){
  if(_ki.laedt) return;
  _ki.laedt = true;
  try{
    const res = await fetch('ki-analysen-api.php?action=liste', {cache:'no-store'});
    const j = await res.json();
    if(j && j.analysen){
      Object.assign(_ki, {analysen:j.analysen, ausgeblendet:j.ausgeblendet||[], favoriten:j.favoriten||[], notizen:j.notizen||{},
        istAdmin:!!j.istAdmin, letzterAbruf:j.letzterAbruf, pdfsImOrdner:j.pdfsImOrdner, geladen:true});
    }
    const syms = [...new Set(_ki.analysen.map(kiSymbol))];
    for(let i=0; i<syms.length; i+=60) Object.assign(_ki.kurse, await fetchYahooQuotes(syms.slice(i, i+60)));
  } catch(e){ toast('KI-Analysen konnten nicht geladen werden.'); }
  _ki.laedt = false;
  renderKiAnalysenPage();
  if(document.getElementById('page-decisions')?.classList.contains('active')) renderDecisionsPage();
}
// Kennzahlen je Titel einmal ausrechnen - Filter, Sortierung und beide Ansichten nutzen dieselben Werte.
function kiZeilen(){
  return _ki.analysen.map(a=>{
    const q = _ki.kurse[kiSymbol(a)] || {};
    const kurs = q.regularMarketPrice ?? null;
    const kz1 = Array.isArray(a.kaufzone1) ? a.kaufzone1[1] : null;
    const kz2 = Array.isArray(a.kaufzone2) ? a.kaufzone2[1] : null;
    let zone = null;
    if(kurs!=null){ if(a.strongBuy!=null && kurs<=a.strongBuy) zone='sb'; else if(kz2!=null && kurs<=kz2) zone='kz2'; else if(kz1!=null && kurs<=kz1) zone='kz1'; }
    const abstandKz1 = (kurs!=null && kz1>0) ? (kurs/kz1 - 1)*100 : null;
    const karte = zone || (abstandKz1==null ? 'ohne' : (abstandKz1<=5 ? 'nah' : 'warten'));
    const hoch = q.fiftyTwoWeekHigh ?? null, tief = q.fiftyTwoWeekLow ?? null;
    const tage = Math.floor((Date.now() - new Date(a.datum+'T12:00:00').getTime())/86400000);
    return {a, kurs, zone, karte, abstandKz1, sektor:kiSektor(a), kat:(a.ddb && a.ddb.category) || null,
      mos: (kurs!=null && a.fvBase>0) ? (1 - kurs/a.fvBase)*100 : null,
      hoch, tief,
      unterHoch: (kurs!=null && hoch>0) ? (1 - kurs/hoch)*100 : null,
      ueberTief: (kurs!=null && tief>0) ? (kurs/tief - 1)*100 : null,
      seitAnalyse: (kurs!=null && a.kurs>0) ? (kurs/a.kurs - 1)*100 : null,
      abstandPoc: (kurs!=null && a.vp && a.vp.poc>0) ? (kurs/a.vp.poc - 1)*100 : null,
      abstandOb: (kurs!=null && a.vp && a.vp.ob && a.vp.ob.groesster) ? Math.max(0, (kurs/a.vp.ob.groesster.hoch - 1)*100) : null,
      tage, aus: _ki.ausgeblendet.includes(a.ticker), fav: _ki.favoriten.includes(a.ticker), stueck: kiDepotStueck(a.ticker)};
  });
}
function kiNumFeld(id){ const x = parseFloat(String(document.getElementById(id).value).replace(',','.')); return isNaN(x) ? null : x; }
// ohne: welche Filterart beim Zaehlen weggelassen wird (damit Karten/Chips zeigen, was ein Klick bringt)
function kiFilter(z, ohne){
  const suche = (document.getElementById('kiFSuche').value||'').trim().toLowerCase();
  if(suche && !(z.a.ticker.toLowerCase().includes(suche) || String(z.a.name||'').toLowerCase().includes(suche))) return false;
  if(z.aus && !document.getElementById('kiFAus').checked) return false;
  if(ohne!=='quick'){
    if(_kiQuick==='fav' && !z.fav) return false;
    if(_kiQuick==='neu' && z.tage>KI_NEU_TAGE) return false;
    if(_kiQuick==='depot' && !(z.stueck>0)) return false;
    if(_kiQuick==='zahlen' && !z.a.zahlenPruefen) return false;
  }
  if(ohne!=='zone' && _kiZone && z.karte!==_kiZone) return false;
  if(ohne!=='sektor' && _kiSektorWahl && z.sektor!==_kiSektorWahl) return false;
  if(ohne!=='kat' && _kiKatWahl && (z.kat||'ohne')!==_kiKatWahl) return false;
  if(ohne!=='bew' && _kiBewWahl && kiBewKlasse(z.mos)!==_kiBewWahl) return false;
  if(kiNumFeld('kiFDiv')!=null && !((z.a.divRendite??-1) >= kiNumFeld('kiFDiv'))) return false;
  if(kiNumFeld('kiFMos')!=null && !((z.mos??-999) >= kiNumFeld('kiFMos'))) return false;
  if(kiNumFeld('kiFQ')!=null && !((z.a.qualityScore??-1) >= kiNumFeld('kiFQ'))) return false;
  if(kiNumFeld('kiF52')!=null && !((z.unterHoch??-1) >= kiNumFeld('kiF52'))) return false;
  if(kiNumFeld('kiF52T')!=null && !(z.ueberTief!=null && z.ueberTief <= kiNumFeld('kiF52T'))) return false;
  if(document.getElementById('kiFAlt').checked && z.tage<=60) return false;
  return true;
}
const KI_ZONEN_RANG = {sb:0, kz2:1, kz1:2};
const KI_SPALTEN = {
  liste: [
    ['ticker','Titel'], ['datum','Datum'], ['kurs','Kurs jetzt'], ['fv','Fair Value'], ['mos','MoS jetzt'],
    ['kz1','Kaufzone 1'], ['kz2','Kaufzone 2'], ['sb','Strong Buy'], ['ob','Order Block (Monat)'], ['zone','Status / Abstand KZ1'],
    ['div','Div-Rendite'], ['kat','Kategorie'], ['mos','Bewertung'], ['q','Q / I'], ['rating','Urteil'], ['zahlen','Nächste Zahlen'], ['abgerufen','Abgerufen'], [null,'PDF'], [null,'']],
  w52: [
    ['ticker','Titel'], ['kurs','Kurs jetzt'], ['tief','52W-Tief'], ['hoch','52W-Hoch'], ['unterHoch','unter 52W-Hoch'],
    ['ueberTief','über 52W-Tief'], [null,'Spanne'], ['ob','Order Block (Monat)'], ['poc','Volumen-Schwerpunkt'], ['seit','seit Analyse'], ['zone','Status / Abstand KZ1'], ['mos','MoS jetzt'], ['kat','Kategorie']],
};
function kiSortWert(z, s){
  switch(s){
    case 'ticker': return z.a.ticker; case 'datum': return z.a.datum; case 'kurs': return z.kurs; case 'fv': return z.a.fvBase;
    case 'mos': return z.mos; case 'kz1': return z.a.kaufzone1?.[1]; case 'kz2': return z.a.kaufzone2?.[1]; case 'sb': return z.a.strongBuy;
    case 'zone': return [KI_ZONEN_RANG[z.zone] ?? 3, z.abstandKz1 ?? 9999];
    case 'div': return z.a.divRendite; case 'q': return z.a.qualityScore; case 'rating': return z.a.rating;
    case 'kat': return z.kat ? KI_KAT_RANG[z.kat] ?? 9 : null;
    case 'zahlen': return z.a.naechsteZahlen || null;
    case 'abgerufen': return z.a.geaendert || z.a.erstellt || null;
    case 'poc': return z.abstandPoc;
    case 'ob': return z.abstandOb;
    case 'tief': return z.tief; case 'hoch': return z.hoch; case 'unterHoch': return z.unterHoch; case 'ueberTief': return z.ueberTief; case 'seit': return z.seitAnalyse;
  }
  return null;
}
function kiSortieren(zeilen){
  const {spalte, ab} = _kiSort;
  return zeilen.sort((x,y)=>{
    let a = kiSortWert(x, spalte), b = kiSortWert(y, spalte);
    if(a==null && b==null) return 0; if(a==null) return 1; if(b==null) return -1;   // leere Werte immer ans Ende
    const r = Array.isArray(a) ? (a[0]-b[0] || a[1]-b[1]) : (typeof a==='string' ? a.localeCompare(b,'de') : a-b);
    return ab ? r : -r;
  });
}
function kiSortKlick(s){ if(!s) return; _kiSort = _kiSort.spalte===s ? {spalte:s, ab:!_kiSort.ab} : {spalte:s, ab:s!=='datum'}; renderKiAnalysenPage(); }
function kiStatusZelle(z){
  if(z.kurs==null) return '<span class="muted">–</span>';
  if(z.zone){ const t = {sb:'🟣 Strong Buy', kz2:'🟢 Kaufzone 2', kz1:'🟡 Kaufzone 1'}[z.zone]; return `<b>${t}</b>`; }
  return z.abstandKz1!=null ? `noch ${kiNum(z.abstandKz1,1)} %` : '<span class="muted">keine Zone</span>';
}
function kiProzent(v, gruenAb){ if(v==null) return '–'; const cls = gruenAb!=null ? (v>=gruenAb ? 'pos' : (v<0 ? 'neg' : '')) : ''; return `<span class="${cls}">${kiNum(v,1)} %</span>`; }
function kiUrteilWort(r){
  const u = String(r||'').split('/')[0].toUpperCase();   // nur die Hauptaussage vor dem ersten /
  if(!u) return '–';
  if(/VERKAUF|SELL/.test(u)) return 'Verkaufen';
  if(/AVOID|MEIDEN/.test(u)) return 'Meiden';
  if(/PULLBACK|WEAKNESS|RÜCKSETZER|RUECKSETZER/.test(u)) return 'Rücksetzer';
  if(/WATCH|BEOBACHT/.test(u)) return 'Beobachten';
  if(/HOLD|HALTEN/.test(u)) return 'Halten';
  if(/BUY|KAUF/.test(u)) return 'Kaufen';
  return String(r).split(/[\s\/]/)[0];
}
// Bewertung aus dem MoS (Nutzer 17.10.2026: "Hinweis unterbewertet / fair / ueberbewertet"): MoS = (FV - Kurs) / FV.
// Rahmen +-10 %, weil der Fair Value selbst eine Schaetzung ist - kleine Abstaende sind "fair".
const KI_BEWERTUNG_PCT = 10;
function kiBewKlasse(mos){ return mos==null ? 'ohne' : (mos>=KI_BEWERTUNG_PCT ? 'unter' : (mos<=-KI_BEWERTUNG_PCT ? 'ueber' : 'fair')); }
function kiBewertung(mos){
  if(mos==null) return '<span class="muted">–</span>';
  const [t, c] = mos>=KI_BEWERTUNG_PCT ? ['unterbewertet','#16a34a'] : (mos<=-KI_BEWERTUNG_PCT ? ['überbewertet','#dc2626'] : ['fair','#64748b']);
  return `<span title="MoS ${kiNum(mos,1)} %: Kurs ${mos>=0?'unter':'über'} dem fairen Wert (Rahmen ±${KI_BEWERTUNG_PCT} %)" style="display:inline-block;padding:2px 8px;border-radius:999px;font-size:10.5px;font-weight:700;border:1px solid ${c};color:${c};white-space:nowrap;">${t}</span>`;
}
function kiKatBadge(kat){
  if(!kat) return '<span class="muted">–</span>';
  const c = (typeof WL_CATEGORY_COLORS!=='undefined' && WL_CATEGORY_COLORS[kat]) || '#64748b';
  return `<span style="display:inline-block;padding:2px 8px;border-radius:999px;font-size:10.5px;font-weight:700;letter-spacing:.3px;text-transform:uppercase;border:1px solid ${c};color:${c};">${escapeHtml(kat)}</span>`;
}
function kiChip(text, n, aktiv, onclick){
  return `<button type="button" class="range-btn${aktiv?' active':''}" onclick="${onclick}" ${n===0&&!aktiv?'style="opacity:.45;"':''}>${text} <span style="opacity:.7;">${n}</span></button>`;
}
// Aufgeklappte Details eines Titels: Zonen mit Status, Bewertung, Dividende, Scores, Support/Widerstand,
// Notiz aus der Analyse, eigene Notiz, PDFs und alle Analysen zu diesem Titel.
function kiDetail(z, spaltenZahl){
  const a = z.a, d = a.ddb || {};
  const zonen = [['Strong Buy', a.strongBuy!=null ? [null, a.strongBuy] : null], ['Kaufzone 2', a.kaufzone2], ['Kaufzone 1', a.kaufzone1]]
    .filter(([,b])=>Array.isArray(b) && b[1]!=null).map(([n,b])=>{
      const st = z.kurs==null ? '–' : (z.kurs<=b[1] ? '<span class="pos">✓ erreicht</span>' : `noch ${kiNum((z.kurs/b[1]-1)*100,1)} %`);
      return `<tr><td>${n}</td><td>${kiZoneTxt(b)}</td><td>${st}</td></tr>`;
    }).join('');
  const liste = (v, e)=>{ const arr = Array.isArray(v) ? v : [a[e+'1'], a[e+'2'], a[e+'3']]; const x = arr.filter(n=>n!=null); return x.length ? x.map(n=>kiNum(n)).join(' · ') : '–'; };
  const scores = [['Quality',a.qualityScore],['Investment',a.investmentScore],['Moat',a.moatScore],['Krise',a.krisenstabilitaet],['Fundamental',a.fundamental],
    ['Qualität',a.qualitaet],['Trend',a.langfristTrend],['Bewertung',a.bewertung],['Div-Qualität',a.dividendenqualitaet]].filter(([,v])=>v!=null)
    .map(([n,v])=>`<span style="display:inline-block;margin:0 10px 4px 0;">${n} <b>${v}</b></span>`).join('') || '–';
  const pdfs = (a.pdfs||[]).map(p=>`<button class="btn ghost" style="padding:4px 9px;font-size:11.5px;margin:0 6px 6px 0;" onclick="openPdfViewer('ki-analysen-api.php?action=pdf&id=${encodeURIComponent(p.id)}', ${escapeHtml(JSON.stringify(p.name))}, null)">📄 ${escapeHtml(p.name)}${p.aelter?` <span class="muted">(Analyse vom ${escapeHtml(p.aelter)})</span>`:''}</button>`).join('');
  const h = _kiHistorie[a.ticker];
  const historie = a.anzahlAnalysen>1 ? (h ? `<div style="margin-top:6px;font-size:12px;">${h.map(x=>`${escapeHtml(x.datum)}: Kurs ${kiNum(x.kurs)} · FV ${kiNum(x.fvBase)} · KZ1 ${kiZoneTxt(x.kaufzone1)} · ${escapeHtml(x.rating||'')}`).join('<br>')}</div>`
    : `<button class="btn ghost" style="padding:4px 9px;font-size:11.5px;" onclick="kiHistorieLaden('${escapeHtml(a.ticker)}')">🕘 Alle Analysen (${a.anzahlAnalysen})</button>`) : '';
  const meine = escapeHtml(_ki.notizen[a.ticker] || '');
  const box = 'border:1px solid var(--border);border-radius:10px;padding:10px 12px;background:var(--card);';
  return `<tr class="ki-detail"><td colspan="${spaltenZahl}" style="position:static;padding:12px 16px;background:var(--surface-hover);">
    <div style="font-weight:700;margin-bottom:4px;">${escapeHtml(a.ticker)} · ${escapeHtml(a.name||'')} ${a.rating?`· <span class="muted">${escapeHtml(a.rating)}</span>`:''} ${kiKatBadge(z.kat)}</div>
    <div class="muted" style="font-size:12px;margin-bottom:10px;">${escapeHtml(z.sektor)}${d.industry?' · '+escapeHtml(d.industry):''} · Analyse ${escapeHtml(a.datum)} (vor ${z.tage} Tagen) · Kurs bei Analyse ${kiNum(a.kurs)} ${escapeHtml(a.waehrung||'')} · nächste Zahlen ${kiZahlenZelle(a)}${a.letzteZahlen?' · letzte Zahlen '+escapeHtml(a.letzteZahlen):''}${z.seitAnalyse!=null?` · seit Analyse ${kiProzent(z.seitAnalyse,0)}`:''}${z.stueck>0?` · <b>im Depot: ${kiNum(z.stueck,0)} Stk.</b>`:''}</div>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:10px;">
      <div style="${box}"><b>Zonen</b><table style="width:100%;font-size:12px;margin-top:4px;"><tr class="muted"><td>Zone</td><td>Bereich</td><td>Status</td></tr>${zonen || '<tr><td colspan="3">keine Zonen</td></tr>'}</table></div>
      <div style="${box}font-size:12px;"><b>Bewertung</b><br>Fair Value ${kiNum(a.fvBear)} / <b>${kiNum(a.fvBase)}</b> / ${kiNum(a.fvBull)} (Bear/Base/Bull)<br>MoS jetzt ${kiProzent(z.mos,20)}<br>Support ${liste(a.support,'support')}<br>Widerstand ${liste(a.resistance,'resistance')}<br>Order Block (Monat) ${kiObZelle(z)}${a.vp&&a.vp.ob&&a.vp.ob.naechster&&a.vp.ob.naechster.monat!==a.vp.ob.groesster.monat?` · nächster ${kiNum(a.vp.ob.naechster.tief)}–${kiNum(a.vp.ob.naechster.hoch)} (${escapeHtml(a.vp.ob.naechster.monat)})`:''}<br>Volumen-Schwerpunkt ${kiPocZelle(z)}${a.vp&&a.vp.poc!=null?` · Hauptvolumen ${kiNum(a.vp.vaTief)}–${kiNum(a.vp.vaHoch)}`:''}</div>
      <div style="${box}font-size:12px;"><b>Dividende</b><br>${a.divJeAktie!=null?kiNum(a.divJeAktie)+' je Aktie p. a. · ':''}Rendite ${a.divRendite!=null?kiNum(a.divRendite)+' %':'–'}<br>Wachstum ${a.divWachstum!=null?kiNum(a.divWachstum,1)+' % (10 J.)':(d.growthRate!=null?kiNum(d.growthRate,1)+' % (Datenbank)':'–')} · Chowder ${a.chowder!=null?kiNum(a.chowder,1):'–'}${a.dividendSafety?'<br>'+escapeHtml(a.dividendSafety):''}</div>
      <div style="${box}font-size:12px;"><b>Scores</b><br>${scores}</div>
    </div>
    ${a.notiz?`<div style="margin-top:10px;font-size:12.5px;"><b>Analyse:</b> ${escapeHtml(a.notiz)}</div>`:''}
    <div style="margin-top:10px;display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:10px;">
      <div><button class="btn ghost" style="padding:4px 9px;font-size:11.5px;margin:0 6px 6px 0;" data-ki-chart="${escapeHtml(a.ticker)}">📈 Zonen-Chart</button>${pdfs}${historie}</div>
      <div><b style="font-size:12px;">Meine Notiz</b> <span class="muted" style="font-size:11px;">(nur für dich, speichert beim Verlassen des Feldes)</span>
        <textarea rows="3" style="width:100%;margin-top:4px;padding:8px;border-radius:8px;border:1px solid var(--border);background:var(--card);color:var(--text);" onblur="kiNotizSpeichern('${escapeHtml(a.ticker)}', this.value)">${meine}</textarea></div>
    </div></td></tr>`;
}
