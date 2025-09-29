<?php
// pages/dashboard.php
?>
<style>
  :root{
    --rappel-bg:#fff9f2; --rappel-accent:#f59e0b; --rappel-chip:#fde7c3;
    --interv-bg:#f2fbff; --interv-accent:#0ea5e9; --interv-chip:#cfeeff;
    --muted:#7c8696; --card-shadow:0 8px 24px rgba(16,24,40,.08);
    --ok:#16a34a; --warn:#f59e0b; --bad:#ef4444;
  }
  .dash-header{display:flex;align-items:center;justify-content:space-between;gap:1rem;margin-bottom:1rem}
  .dash-title{font-weight:700;letter-spacing:.2px}
  .muted{color:var(--muted)}
  .card-ghost{border:0;box-shadow:var(--card-shadow);border-radius:1rem;overflow:hidden;background:#fff}
  .card-ghost .card-header{background:#fff;border-bottom:1px solid #f0f1f3}
  .btn-icon{display:inline-flex;align-items:center;justify-content:center;width:36px;height:36px;border-radius:10px;border:1px solid #e6e8ee;background:#fff}
  .btn-icon:active{transform:translateY(1px)}

  /* header brand */
  .brand{
  display:flex;
  flex-direction:column;
  align-items:center;
  justify-content:center;
  text-align:center;
  gap:.5rem;
}
.brand img{
  height:64px;  /* plus grand */
  width:auto;
  object-fit:contain;
}
.brand .name{
  font-weight:800;
  font-size:1.1rem;
}
.brand .user-line{
  color:var(--muted);
  font-size:.95rem;
}

  /* Rappels theming */
  .wrap-rappels{background:var(--rappel-bg)}
  .wrap-rappels .accent{color:var(--rappel-accent)}
  .wrap-rappels .chip{background:var(--rappel-chip)}
  .wrap-rappels .list-group-item{border-left:4px solid var(--rappel-accent)}

  /* Interventions theming */
  .wrap-interv{background:var(--interv-bg)}
  .wrap-interv .accent{color:var(--interv-accent)}
  .wrap-interv .chip{background:var(--interv-chip)}
  .wrap-interv .list-group-item{border-left:4px solid var(--interv-accent)}

  .list-slim .list-group-item{display:flex;align-items:flex-start;gap:.75rem;padding:.65rem .9rem;border:0;border-bottom:1px dashed #eef0f4;background:transparent}
  .list-slim .list-group-item:last-child{border-bottom:0}
  .title-strong{font-weight:800; letter-spacing:.2px}
  .tag{font-size:.78rem;padding:.15rem .5rem;border-radius:6px;background:#eef4ff}
  .skeleton{height:54px;border-radius:10px;background:linear-gradient(90deg,#f2f4f7 25%,#e9ecf2 37%,#f2f4f7 63%);background-size:400% 100%;animation:shimmer 1.3s infinite;margin-bottom:.5rem}
  @keyframes shimmer{0%{background-position:100% 0}100%{background-position:0 0}}
  .empty-state{padding:1rem;text-align:center;color:#8a94a6;font-size:.95rem}

  .progress-slim{height:6px;border-radius:999px;background:#eef1f6;overflow:hidden}
  .progress-slim > div{height:100%; width:0%; transition:width .35s ease}

  .meta-btn,.note-btn{border:0;background:transparent;color:inherit;opacity:.85;font-weight:800;line-height:1}
  .meta-box{margin-top:.35rem;padding:.5rem .75rem;border-radius:.6rem;background:rgba(0,0,0,.04);font-size:.9rem}
  .links a{ text-decoration:none }
  .links a:hover{ text-decoration:underline }

  /* Dernières consultations */
  .mini-list .item{display:flex;align-items:center;justify-content:space-between;gap:.5rem;padding:.5rem .75rem;border-bottom:1px dashed #eef0f4}
  .mini-list .item:last-child{border-bottom:0}
  .pill{display:inline-block;padding:.1rem .45rem;border-radius:999px;background:#eef1f6;font-size:.78rem}

  /* (remplacement) KPI progress bars */
  .kpi-bar{margin-top:.5rem;height:14px;border-radius:999px;background:#eef1f6;overflow:hidden;position:relative}
  .kpi-bar span{display:block;height:100%;width:0%;transition:width .4s ease;background:linear-gradient(90deg,hsl(120,70%,45%),hsl(60,80%,50%),hsl(0,70%,50%))}
  .kpi-label{font-weight:700;margin-top:.35rem;text-align:center}
  .kpi-sub{font-size:.9rem;color:#7c8696;text-align:center}

  /* Charts */
  .card canvas{max-height:320px}

  /* Map */
  #dash-map{width:100%;height:380px;border-radius:1rem;overflow:hidden;touch-action:manipulation}

  /* Responsive */
  @media (max-width: 767px){
    .dash-header{flex-direction:column;align-items:flex-start}
  }
</style>

<div class="container-fluid">
  <!-- Entête: nom clinique + logo à droite + utilisateur -->
  <div class="dash-header">
    <div class="brand">
  <img id="clinic-logo" src="" alt="logo" style="display:none">
  <span class="name" id="clinic-name">Clinique</span>
  <div class="user-line" id="user-line"></div>
</div>
    <div class="d-flex align-items-center gap-2">
      <span class="tag"><i class="bi bi-clock-history me-1"></i> <span id="dash-refresh-time"><?= htmlspecialchars(date('H:i')) ?></span></span>
      <button id="dash-refresh" class="btn btn-sm btn-outline-primary"><i class="bi bi-arrow-repeat me-1"></i>Actualiser</button>
    </div>
  </div>

  <div class="row g-4">
    <!-- Rappels -->
    <div class="col-12 col-lg-6">
      <div class="card card-ghost wrap-rappels">
        <div class="card-header d-flex align-items-center justify-content-between">
          <div class="d-flex align-items-center gap-2">
            <span class="badge bg-warning-subtle text-warning-emphasis rounded-pill"><i class="bi bi-info-circle"></i> Rappels</span>
          </div>
          <div class="btn-group btn-group-sm" role="group" id="r-window-group">
            <button class="btn btn-outline-secondary active" data-rwindow="today">Aujourd’hui</button>
            <button class="btn btn-outline-secondary" data-rwindow="tomorrow">Demain</button>
            <button class="btn btn-outline-secondary" data-rwindow="15">15j</button>
            <button class="btn btn-outline-secondary" data-rwindow="30">30j</button>
          </div>
        </div>
        <div class="px-3 pt-2">
          <div class="progress-slim"><div id="r-loadbar"></div></div>
        </div>
        <div class="card-body">
          <div id="rappels-list" class="list-group list-slim"></div>
          <div id="rappels-empty" class="empty-state d-none">Aucun rappel dans cette fenêtre.</div>
          <div class="d-grid mt-2"><button id="r-more" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-down-circle me-1"></i>Charger plus</button></div>
        </div>
      </div>
    </div>

    <!-- Interventions -->
    <div class="col-12 col-lg-6">
      <div class="card card-ghost wrap-interv">
        <div class="card-header d-flex align-items-center justify-content-between">
          <div class="d-flex align-items-center gap-2">
            <span class="badge bg-info-subtle text-info-emphasis rounded-pill"><i class="bi bi-activity"></i> Interventions</span>
          </div>
          <div class="btn-group btn-group-sm" role="group" id="i-window-group">
            <button class="btn btn-outline-secondary active" data-window="today">Aujourd’hui</button>
            <button class="btn btn-outline-secondary" data-window="tomorrow">Demain</button>
            <button class="btn btn-outline-secondary" data-window="15">15j</button>
            <button class="btn btn-outline-secondary" data-window="30">30j</button>
          </div>
        </div>
        <div class="px-3 pt-2">
          <div class="progress-slim"><div id="i-loadbar"></div></div>
        </div>
        <div class="card-body">
          <div id="interv-list" class="list-group list-slim"></div>
          <div id="interv-empty" class="empty-state d-none">Aucune intervention dans cet intervalle.</div>
          <div class="d-grid mt-2"><button id="i-more" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-down-circle me-1"></i>Charger plus</button></div>
        </div>
      </div>
    </div>
  </div>

  <!-- Dernières consultations -->
  <div class="row g-4 mt-1">
    <div class="col-12">
      <div class="card card-ghost">
        <div class="card-header d-flex align-items-center justify-content-between">
          <div class="d-flex align-items-center gap-2">
            <span class="badge bg-secondary-subtle text-secondary-emphasis rounded-pill"><i class="bi bi-journal-medical"></i> Dernières consultations</span>
          </div>
        </div>
        <div class="card-body mini-list" id="last-consultations">
          <!-- items ajax -->
        </div>
      </div>
    </div>
  </div>

  <!-- KPIs (remplacement des gauges par progress bars) -->
  <div class="row g-4 mt-1">
    <div class="col-6 col-lg-6">
      <div class="card card-ghost">
        <div class="card-header d-flex align-items-center justify-content-between">
          <span class="badge bg-primary-subtle text-primary-emphasis rounded-pill"><i class="bi bi-speedometer2"></i> Actes non facturés</span>
        </div>
        <div class="card-body text-center">
          <div class="kpi-bar"><span id="acts-bar"></span></div>
          <div class="kpi-label" id="acts-label">0%</div>
          <div class="kpi-sub" id="acts-sub"></div>
        </div>
      </div>
    </div>
    <div class="col-6 col-lg-6">
      <div class="card card-ghost">
        <div class="card-header d-flex align-items-center justify-content-between">
          <span class="badge bg-success-subtle text-success-emphasis rounded-pill"><i class="bi bi-cash-coin"></i> Impayés vs Factures</span>
        </div>
        <div class="card-body text-center">
          <div class="kpi-bar"><span id="pay-bar"></span></div>
          <div class="kpi-label" id="pay-label">0%</div>
          <div class="kpi-sub" id="pay-sub"></div>
        </div>
      </div>
    </div>
  </div>

  <!-- Charts -->
  <div class="row g-4 mt-1">
    <div class="col-12 col-lg-6">
      <div class="card card-ghost">
        <div class="card-header d-flex align-items-center justify-content-between">
          <span class="badge bg-dark-subtle text-dark-emphasis rounded-pill"><i class="bi bi-graph-up"></i> Consultations / mois</span>
          <div>
            <select id="year-consults" class="form-select form-select-sm" style="width:auto;display:inline-block"></select>
          </div>
        </div>
        <div class="card-body">
          <canvas id="chart-consults"></canvas>
        </div>
      </div>
    </div>
    <div class="col-12 col-lg-6">
      <div class="card card-ghost">
        <div class="card-header d-flex align-items-center justify-content-between">
          <span class="badge bg-warning-subtle text-warning-emphasis rounded-pill"><i class="bi bi-currency-exchange"></i> Chiffre d’affaires / mois</span>
          <div>
            <select id="year-revenue" class="form-select form-select-sm" style="width:auto;display:inline-block"></select>
          </div>
        </div>
        <div class="card-body">
          <canvas id="chart-revenue"></canvas>
        </div>
      </div>
    </div>
  </div>

  <!-- Carte -->
  <div class="row g-4 mt-1">
    <div class="col-12">
      <div class="card card-ghost">
        <div class="card-header d-flex align-items-center justify-content-between">
          <span class="badge bg-info-subtle text-info-emphasis rounded-pill"><i class="bi bi-geo-alt"></i> Éleveurs</span>
        </div>
        <div class="card-body">
          <div id="dash-map"></div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Vendor libs (CDN) : Chart.js + Leaflet + GestureHandling -->
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" crossorigin="">
<link rel="stylesheet" href="https://unpkg.com/leaflet-gesture-handling/dist/leaflet-gesture-handling.min.css">
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" crossorigin=""></script>
<script src="https://unpkg.com/leaflet-gesture-handling"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>

<script>
(function(){
  // Base URLs
  const ORIGIN = window.location.origin || '';
  const BASE   = (window.location.pathname || '/index.php').replace(/\/[^\/]*$/, '');
  const URL_RAPPELS = ORIGIN + BASE + '/api/rappels_dash.php';
  const URL_INTERV  = ORIGIN + BASE + '/api/interventions_dash.php';
  const URL_META    = ORIGIN + BASE + '/api/dash_meta.php';
  const URL_KPIS    = ORIGIN + BASE + '/api/dash_kpis.php';
  const URL_CHARTS  = ORIGIN + BASE + '/api/dash_charts.php';
  const URL_MAP     = ORIGIN + BASE + '/api/dash_map.php';

  // Helpers
  const escapeHtml = s => (s ?? '').toString().replace(/[&<>"']/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[m]);
  function setLoadBarSmooth(el, n, scale=10){
    const t = Math.max(0, Math.min(1, n/scale)); // 0..1
    const pct = Math.round(t*100);
    const hue = 120 * (1 - t); // 120=vert -> 0=rouge
    el.style.width = pct + '%';
    el.style.background = `hsl(${hue.toFixed(0)}, 70%, 45%)`;
  }
  const fmtDate = iso => {
    try { return new Date(iso+'T00:00:00').toLocaleDateString('fr-FR',{weekday:'short',day:'2-digit',month:'short'}); }
    catch(e){ return iso; }
  };
  async function fetchJSON(url, params){
    const qs = '?' + new URLSearchParams(params||{}).toString();
    const r = await fetch(url+qs, {credentials:'same-origin'});
    if (!r.ok) return {__error:true,status:r.status,text:await r.text()};
    const ct = r.headers.get('content-type')||'';
    if (!ct.includes('application/json')) return {__error:true,status:200,text:await r.text()};
    return r.json();
  }
  function resolveUrlMaybeRelative(path){
    if (!path) return '';
    if (/^https?:\/\//i.test(path)) return path; // absolu http
    if (path.startsWith('/')) return path;       // absolu site
    // relatif depuis /pages -> remonter à la racine app
    const baseRoot = BASE.replace(/\/pages?$/,'');
    return ORIGIN + baseRoot + '/' + path.replace(/^\.?\//,'');
  }
function resolveUrlMaybeRelative(path){
  if (!path) return '';
  if (/^https?:\/\//i.test(path)) return path; // URL absolue
  if (path.startsWith('/')) return path;       // Chemin absolu site

  // On est dans /pages/dashboard.php → on remonte à la racine de l’app
  const ORIGIN = window.location.origin || '';
  const BASE   = (window.location.pathname || '/index.php').replace(/\/[^\/]*$/, '');
  const baseRoot = BASE.replace(/\/pages?$/,''); // retire /pages
  return ORIGIN + baseRoot + '/' + path.replace(/^\.?\//,'');
}
  // --------- META (brand + user + last consultations) ----------
  async function loadMeta(){
    const data = await fetchJSON(URL_META);
    if (data.__error) return;
  if (data.clinic){
  const logo = document.getElementById('clinic-logo');
  const name = document.getElementById('clinic-name');
  if (name && data.clinic.name) name.textContent = data.clinic.name;

  if (logo && data.clinic.logo_url){
    const src = resolveUrlMaybeRelative(data.clinic.logo_url);
    logo.onload  = () => { logo.style.display = 'block'; };
    logo.onerror = () => { logo.style.display = 'none';  };
    logo.src = src;
  }
}
    if (data.user){
      const line = document.getElementById('user-line');
      const isDoctor = (data.user.role||'').match(/admin|doctor/i);
      line.textContent = (isDoctor ? 'Dr ' : '') + (data.user.name||'');
    }
    // Dernières consultations
    const box = document.getElementById('last-consultations');
    box.innerHTML = '';
    (data.last_consultations||[]).forEach(i=>{
      const q = i.client_gsm ? encodeURIComponent(i.client_gsm) : encodeURIComponent(i.client||'');
      const div = document.createElement('div'); div.className='item';
      div.innerHTML = `
        <div>
          <a href="index.php?page=patient_view&id=${encodeURIComponent(i.patient_id)}" class="me-2"><strong>${escapeHtml(i.patient)}</strong></a>
          <span class="muted">chez</span>
          <a href="index.php?page=clients&q=${q}#clientForm" class="ms-2">${escapeHtml(i.client||'')}</a>
          ${i.motif ? `<span class="pill ms-2">${escapeHtml(i.motif)}</span>`:''}
        </div>
        <div class="muted">${escapeHtml(i.date_human||i.date||'')}</div>
      `;
      box.appendChild(div);
    });
  }

  // ---------- Rappels ----------
  let rWindow = 'today';
  let rPage = 1, rPerPage = 10, rTotal = 0;
  const rList=document.getElementById('rappels-list'), rEmpty=document.getElementById('rappels-empty');
  const rMore=document.getElementById('r-more'); const rLoadbar=document.getElementById('r-loadbar');

  async function loadRappels({reset=false}={}){
    if (reset){ rPage=1; rList.innerHTML=''; }
    rEmpty.classList.add('d-none');
    for (let i=0;i<3;i++){ const s=document.createElement('div'); s.className='skeleton'; rList.appendChild(s); }

    const data = await fetchJSON(URL_RAPPELS, {window:rWindow,page:rPage,per_page:rPerPage});
    rList.querySelectorAll('.skeleton').forEach(n=>n.remove());
    if (data.__error){ rEmpty.classList.remove('d-none'); rEmpty.textContent="Impossible de charger les rappels."; return; }

    rTotal = data.total ?? 0;
    const charge = data.window_count ?? data.total ?? 0;
    setLoadBarSmooth(rLoadbar, charge, 10);

    const items = Array.isArray(data.items) ? data.items : [];
    if (items.length===0 && rPage===1){ rEmpty.classList.remove('d-none'); rEmpty.textContent="Aucun rappel dans cette fenêtre."; }
    items.forEach(it=>{
      const clientQuery = it.client_gsm ? encodeURIComponent(it.client_gsm) : encodeURIComponent(it.client ?? '');
      const hasNote = !!(it.note && String(it.note).trim());
      const div=document.createElement('div'); div.className='list-group-item';
      div.innerHTML = `
        <div class="flex-grow-1">
          <div class="d-flex align-items-center justify-content-between">
            <div class="title-strong accent">
              ${hasNote ? `<button class="note-btn me-1" title="Afficher la note" data-toggle-note>+</button>` : ``}
              <span>${escapeHtml(it.type ?? 'Rappel')}</span>
            </div>
            <span class="chip tag"><i class="bi bi-calendar3 me-1"></i>${escapeHtml(fmtDate(it.date_human ?? it.date))}</span>
          </div>
          <div class="links muted mt-1">
            <a class="me-2" href="index.php?page=patient_view&id=${encodeURIComponent(it.patient_id)}"><i class="bi bi-heart-pulse me-1"></i>${escapeHtml(it.patient)}</a>
            •
            <a class="ms-2" href="index.php?page=clients&q=${clientQuery}#clientForm"><i class="bi bi-person me-1"></i>${escapeHtml(it.client ?? '')}</a>
          </div>
          ${hasNote ? `<div class="meta-box d-none" data-note-box>${escapeHtml(it.note)}</div>` : ``}
        </div>
      `;
      const btn = div.querySelector('[data-toggle-note]');
      const box = div.querySelector('[data-note-box]');
      if (btn && box){
        btn.addEventListener('click', ()=>{
          const hidden = box.classList.toggle('d-none');
          btn.textContent = hidden ? '+' : '−';
          btn.title = hidden ? 'Afficher la note' : 'Masquer la note';
        });
      }
      rList.appendChild(div);
    });

    const shown = rPage * rPerPage;
    rMore.style.display = (shown < rTotal) ? 'block' : 'none';
  }

  document.querySelectorAll('#r-window-group [data-rwindow]').forEach(btn=>{
    btn.addEventListener('click', ()=>{
      document.querySelectorAll('#r-window-group [data-rwindow]').forEach(b=>b.classList.remove('active'));
      btn.classList.add('active');
      rWindow = btn.getAttribute('data-rwindow');
      loadRappels({reset:true});
    });
  });
  document.getElementById('r-more').addEventListener('click', ()=>{ rPage++; loadRappels(); });

  // ---------- Interventions ----------
  let iWindow='today', iPage=1, iPerPage=10, iTotal=0;
  const iList=document.getElementById('interv-list'), iEmpty=document.getElementById('interv-empty');
  const iMore=document.getElementById('i-more'); const iLoadbar=document.getElementById('i-loadbar');

  function renderIntervention(it){
    const clientQuery = it.client_gsm ? encodeURIComponent(it.client_gsm) : encodeURIComponent(it.client ?? '');
    const hasNote = !!(it.note && String(it.note).trim());
    return `
      <div class="flex-grow-1">
        <div class="d-flex align-items-center justify-content-between">
          <div class="title-strong accent">
            ${hasNote ? `<button class="note-btn me-1" title="Afficher détail" data-toggle-note>+</button>` : ``}
            <span>${escapeHtml(it.acte ?? 'Intervention')}</span>
          </div>
          <span class="chip tag"><i class="bi bi-calendar2-event me-1"></i>${escapeHtml(fmtDate(it.prochaine_human ?? it.prochaine_date))}</span>
        </div>
        <div class="links muted mt-1">
          <a class="me-2" href="index.php?page=patient_view&id=${encodeURIComponent(it.patient_id)}"><i class="bi bi-heart-pulse me-1"></i>${escapeHtml(it.patient)}</a>
          •
          <a class="ms-2" href="index.php?page=clients&q=${clientQuery}#clientForm"><i class="bi bi-person me-1"></i>${escapeHtml(it.client ?? '')}</a>
        </div>
        ${hasNote ? `<div class="meta-box d-none" data-note-box>${escapeHtml(it.note)}</div>` : ``}
      </div>
    `;
  }

  async function loadInterv({reset=false}={}){
    if (reset){ iPage=1; iList.innerHTML=''; }
    iEmpty.classList.add('d-none');
    for (let i=0;i<3;i++){ const s=document.createElement('div'); s.className='skeleton'; iList.appendChild(s); }

    const data = await fetchJSON(URL_INTERV, {window:iWindow,page:iPage,per_page:iPerPage});
    iList.querySelectorAll('.skeleton').forEach(n=>n.remove());
    if (data.__error){ iEmpty.classList.remove('d-none'); iEmpty.textContent="Impossible de charger les interventions."; return; }

    iTotal = data.total ?? 0;
    const charge = data.window_count ?? data.total ?? 0;
    setLoadBarSmooth(iLoadbar, charge, 10);

    const items = Array.isArray(data.items) ? data.items : [];
    if (items.length===0 && iPage===1){ iEmpty.classList.remove('d-none'); iEmpty.textContent="Aucune intervention dans cet intervalle."; }
    items.forEach(it=>{
      const row=document.createElement('div'); row.className='list-group-item';
      row.innerHTML = renderIntervention(it);
      const btn = row.querySelector('[data-toggle-note]');
      const box = row.querySelector('[data-note-box]');
      if (btn && box){
        btn.addEventListener('click', ()=>{
          const hidden = box.classList.toggle('d-none');
          btn.textContent = hidden ? '+' : '−';
          btn.title = hidden ? 'Afficher détail' : 'Masquer détail';
        });
      }
      iList.appendChild(row);
    });

    const shown = iPage * iPerPage;
    iMore.style.display = (shown < iTotal) ? 'block' : 'none';
  }

  document.querySelectorAll('#i-window-group [data-window]').forEach(btn=>{
    btn.addEventListener('click', ()=>{
      document.querySelectorAll('#i-window-group [data-window]').forEach(b=>b.classList.remove('active'));
      btn.classList.add('active');
      iWindow = btn.getAttribute('data-window');
      loadInterv({reset:true});
    });
  });
  iMore.addEventListener('click', ()=>{ iPage++; loadInterv(); });

  // -------- KPI progress bars (remplace les gauges) ----------
  function setProgress(elBar, elLabel, pct, elSub, subText){
    const t = Math.max(0, Math.min(100, Number(pct)||0));
    elBar.style.width = t + '%';
    elLabel.textContent = (Math.round(t*10)/10) + '%';
    if (elSub) elSub.textContent = subText || '';
  }
  async function loadKpis(){
    const data = await fetchJSON(URL_KPIS);
    if (data.__error) return;

    // Actes non facturés
    setProgress(
      document.getElementById('acts-bar'),
      document.getElementById('acts-label'),
      data.acts?.non_invoiced_pct || 0,
      document.getElementById('acts-sub'),
      data.acts?.text || ''
    );
    // Impayés = unpaid_pct si dispo, sinon 100 - paid_pct
    const unpaidPct = (typeof data.payment?.unpaid_pct === 'number') ? data.payment.unpaid_pct : (100 - (data.payment?.paid_pct||0));
    setProgress(
      document.getElementById('pay-bar'),
      document.getElementById('pay-label'),
      unpaidPct,
      document.getElementById('pay-sub'),
      data.payment?.text || ''
    );
  }

  // -------- Charts ----------
  const yearNow = new Date().getFullYear();
  const years = [yearNow-2, yearNow-1, yearNow, yearNow+1];
  const selC = document.getElementById('year-consults');
  const selR = document.getElementById('year-revenue');
  years.forEach(y=>{
    const o1=document.createElement('option'); o1.value=o1.textContent=y; if (y===yearNow) o1.selected=true; selC.appendChild(o1);
    const o2=document.createElement('option'); o2.value=o2.textContent=y; if (y===yearNow) o2.selected=true; selR.appendChild(o2);
  });

  function makeLineChart(ctx, labels, data, title){
    if (!ctx) return;
    if (ctx.chart) { ctx.chart.destroy(); }
    ctx.chart = new Chart(ctx, {
      type:'line',
      data:{ labels, datasets:[{ label:title, data, tension:.25, fill:false }]},
      options:{ responsive:true, maintainAspectRatio:false, plugins:{legend:{display:false}}, scales:{y:{beginAtZero:true}} }
    });
  }

  async function loadCharts(){
    const yc = selC.value, yr = selR.value;
    const cons = await fetchJSON(URL_CHARTS, {year:yc});
    const rev  = await fetchJSON(URL_CHARTS, {year:yr, metric:'revenue'});

    const labels = ['Jan','Fév','Mar','Avr','Mai','Juin','Juil','Août','Sep','Oct','Nov','Déc'];
    makeLineChart(document.getElementById('chart-consults').getContext('2d'), labels, cons.series||[], 'Consultations');
    makeLineChart(document.getElementById('chart-revenue').getContext('2d'), labels, rev.series||[], 'CA');
  }
  selC.addEventListener('change', loadCharts);
  selR.addEventListener('change', loadCharts);

  // -------- Map (Leaflet + Esri, gestes contrôlés) ----------
  let map, markers;
  async function loadMap(){
    if (!map){
      map = L.map('dash-map', {
        zoomControl:true,
        gestureHandling: true,
        scrollWheelZoom: false,
        doubleClickZoom: false,
        touchZoom: true,
        dragging: true
      });
      L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/Canvas/World_Light_Gray_Base/MapServer/tile/{z}/{y}/{x}', {
        attribution: '&copy; Esri, HERE, Garmin, FAO, NOAA, USGS',
        maxZoom: 18
      }).addTo(map);
      map.setView([31.8,-6.0], 5);
      markers = L.layerGroup().addTo(map);

      // Bloquer le pan à 1 doigt, autoriser pan à 2 doigts (mobile)
      const el = map.getContainer();
      el.addEventListener('touchstart', (e)=>{
        if (e.touches && e.touches.length === 1) { map.dragging.disable(); }
        else { map.dragging.enable(); }
      }, {passive:true});
      el.addEventListener('touchend', ()=>{ map.dragging.disable(); }, {passive:true});
      // souris = pan autorisé
      el.addEventListener('pointerdown', (e)=>{ if (e.pointerType === 'mouse') map.dragging.enable(); });
    }

    const data = await fetchJSON(URL_MAP);
    markers.clearLayers();
    const pts = data.points||[];
    if (pts.length){
      const bounds = [];
      pts.forEach(p=>{
        const m = L.marker([p.lat, p.lng]).bindPopup(
          `<strong>${escapeHtml(p.client||'')}</strong><br>`+
          (p.gsm?`<span class="muted">${escapeHtml(p.gsm)}</span><br>`:'')+
          (p.address?`${escapeHtml(p.address)}`:'')
        );
        markers.addLayer(m);
        bounds.push([p.lat, p.lng]);
      });
      if (bounds.length) map.fitBounds(bounds, {padding:[20,20]});
    }
  }

  // ---------- Global actions ----------
  document.getElementById('dash-refresh').addEventListener('click', ()=>{
    document.getElementById('dash-refresh-time').textContent = new Date().toLocaleTimeString('fr-FR', {hour:'2-digit',minute:'2-digit'});
    loadRappels({reset:true}); loadInterv({reset:true}); loadMeta(); loadKpis(); loadCharts(); loadMap();
  });

  // First paint
  loadMeta();
  loadRappels({reset:true});
  loadInterv({reset:true});
  loadKpis();
  loadCharts();
  loadMap();
})();
</script>