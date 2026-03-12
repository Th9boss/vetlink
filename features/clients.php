<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';

require_login();

$q = trim((string)($_GET['q'] ?? ''));
$page = max(1, (int)($_GET['p'] ?? 1));
?>

<style>
  .clients-shell {
    --ink: #16181d;
    --muted: #6f7684;
    --line: #dde2ea;
    --surface: #ffffff;
    --surface-soft: #f7f9fc;
    --accent: #1a73e8;
    --accent-2: #0a5fd6;
  }
  .clients-topbar {
    border: 1px solid var(--line);
    border-radius: 18px;
    padding: 12px;
    background: linear-gradient(180deg, #ffffff 0%, #f9fbff 100%);
    box-shadow: 0 10px 30px rgba(16, 24, 40, 0.06);
  }
  .clients-search {
    border-radius: 12px;
    border-color: var(--line);
    background: #fff;
  }
  .client-card {
    border: 1px solid var(--line);
    border-radius: 18px;
    background: var(--surface);
    box-shadow: 0 10px 30px rgba(16, 24, 40, 0.05);
    transition: transform .16s ease, box-shadow .16s ease;
  }
  .client-card:hover {
    transform: translateY(-1px);
    box-shadow: 0 16px 36px rgba(16, 24, 40, 0.09);
  }
  .chip-soft {
    display: inline-flex;
    align-items: center;
    gap: .35rem;
    border: 1px solid #d6dce8;
    border-radius: 999px;
    padding: .2rem .55rem;
    font-size: .78rem;
    color: #4f5a70;
    background: #f8fbff;
    white-space: nowrap;
    flex-shrink: 0;
  }
  .btn-macos {
    border-radius: 12px;
    border: 1px solid #d5dbe6;
    color: #182033;
    background: linear-gradient(180deg, #ffffff 0%, #f3f6fb 100%);
    box-shadow: 0 8px 18px rgba(16, 24, 40, .08);
  }
  .btn-macos-primary {
    border-radius: 12px;
    border: 1px solid var(--accent-2);
    color: #fff;
    background: linear-gradient(180deg, var(--accent) 0%, var(--accent-2) 100%);
    box-shadow: 0 8px 22px rgba(26, 115, 232, .35);
  }
  .btn-modal-blue {
    background: #0a66ff !important;
    border-color: #0a66ff !important;
    color: #ffffff !important;
  }
  .btn-modal-blue:hover,
  .btn-modal-blue:focus {
    background: #0057e0 !important;
    border-color: #0057e0 !important;
    color: #ffffff !important;
  }
  .clients-shell .btn.btn-macos-primary {
    color: #ffffff !important;
    background: linear-gradient(180deg, #2f83ff 0%, #0a5fd6 100%) !important;
    border-color: #0a5fd6 !important;
    text-shadow: 0 1px 0 rgba(0, 0, 0, .18);
  }
  .clients-shell .btn.btn-macos-primary:hover,
  .clients-shell .btn.btn-macos-primary:focus {
    color: #ffffff !important;
    background: linear-gradient(180deg, #4a96ff 0%, #126be0 100%) !important;
    border-color: #0a5fd6 !important;
  }
  .clients-modal .modal-content {
    border: 1px solid #d9dfeb;
    border-radius: 22px;
    box-shadow: 0 24px 80px rgba(16, 24, 40, .2);
    background: linear-gradient(180deg, #ffffff 0%, #f8fbff 100%);
  }
  .clients-modal .form-control {
    border-radius: 12px;
    border-color: #d8deea;
    min-height: 44px;
  }
  .clients-modal .form-control:focus {
    border-color: #8db8ff;
    box-shadow: 0 0 0 .25rem rgba(26,115,232,.14);
  }
  dialog.modal {
    border: 0;
    padding: 0;
    background: transparent;
    width: 100%;
    max-width: none;
    height: 100%;
  }
  dialog.modal:not([open]) { display: none; }
  dialog.modal[open] {
    display: flex;
    align-items: center;
    justify-content: center;
  }
  dialog.modal::backdrop {
    background: rgba(9, 20, 40, .36);
    backdrop-filter: blur(10px) saturate(135%);
  }
  .modal-box {
    width: min(92vw, 920px);
    max-height: 90vh;
    overflow: auto;
    border-radius: 22px;
  }
  .modal-box-sm { width: min(92vw, 520px); }
  .glass-box {
    background: linear-gradient(160deg, rgba(255,255,255,.82), rgba(245,250,255,.68));
    border: 1px solid rgba(255,255,255,.68);
    box-shadow: 0 28px 80px rgba(7, 19, 43, .28);
    backdrop-filter: blur(16px) saturate(160%);
  }
  @media (max-width: 576px) {
    .clients-topbar { padding: 10px; border-radius: 14px; }
    .client-card { border-radius: 14px; }
  }
</style>

<div class="clients-shell">
  <div class="clients-topbar mb-3">
    <div class="d-flex flex-column flex-md-row gap-2 align-items-stretch align-items-md-center">
      <div>
        <h1 class="h4 mb-0">Clients</h1>
        <div class="text-muted small" id="clientsMeta">Chargement…</div>
      </div>
      <div class="ms-md-auto d-flex gap-2">
        <input id="qInput" class="form-control clients-search" placeholder="Rechercher nom, email, gsm, adresse" value="<?= h($q) ?>">
        <button id="btnNewClient" class="btn btn-macos-primary">Nouveau</button>
      </div>
    </div>
  </div>

  <div id="clientsAlert" class="mb-2 d-none"></div>

  <div id="clientsGrid" class="row g-3"></div>

  <div class="d-flex justify-content-between align-items-center mt-3">
    <div class="text-muted small" id="pagerMeta">Page 1 / 1</div>
    <div class="d-flex gap-2">
      <button id="btnPrev" class="btn btn-sm btn-macos">Précédent</button>
      <button id="btnNext" class="btn btn-sm btn-macos">Suivant</button>
    </div>
  </div>
</div>

<dialog id="clientModal" class="modal">
  <div class="modal-box clients-modal glass-box p-0">
    <div class="p-4 p-md-5">
      <div class="d-flex align-items-start justify-content-between pb-1">
        <h5 class="modal-title mb-0" id="clientModalTitle">Nouveau client</h5>
        <button type="button" class="btn btn-sm btn-macos-primary" data-modal-close="clientModal" aria-label="Fermer">✕</button>
      </div>
      <div class="pt-3">
        <form id="clientForm" class="row g-3">
          <input type="hidden" id="clientId" value="">
          <input type="hidden" id="lat" value="">
          <input type="hidden" id="lng" value="">
          <input type="hidden" id="csrfToken" value="<?= h(csrf_token()) ?>">

          <div class="col-12 col-md-6">
            <label class="form-label" for="nom">Nom *</label>
            <input id="nom" class="form-control" required autocomplete="name">
          </div>
          <div class="col-12 col-md-6">
            <label class="form-label" for="prenom">Prénom</label>
            <input id="prenom" class="form-control" autocomplete="given-name">
          </div>
          <div class="col-12 col-md-6">
            <label class="form-label" for="gsm">GSM</label>
            <input id="gsm" class="form-control" placeholder="+212..." autocomplete="tel">
          </div>
          <div class="col-12 col-md-6">
            <label class="form-label" for="email">Email</label>
            <input id="email" type="email" class="form-control" autocomplete="email">
          </div>
          <div class="col-12">
            <label class="form-label" for="adresse">Adresse</label>
            <input id="adresse" class="form-control" autocomplete="street-address">
          </div>

          <div class="col-12">
            <div class="d-flex align-items-center justify-content-between mb-2">
              <button type="button" id="btnLocate" class="btn btn-sm btn-modal-blue">
                <span aria-hidden="true">📍</span>
                Ajouter position
              </button>
            </div>
            <div class="small mt-1" id="locateFeedback" style="min-height:1.1rem;"></div>
            <div class="small text-muted mt-1" id="coordText">Position : non définie</div>
          </div>
        </form>
      </div>
      <div class="d-flex justify-content-end gap-2 p-4 pt-0">
        <button type="button" class="btn btn-modal-blue" data-modal-close="clientModal">Fermer</button>
        <button type="button" id="btnSaveClient" class="btn btn-modal-blue">Enregistrer</button>
      </div>
    </div>
  </div>
</dialog>

<dialog id="navModal" class="modal">
  <div class="modal-box modal-box-sm glass-box">
      <div class="d-flex align-items-start justify-content-between mb-2">
        <h5 class="modal-title mb-0">Navigation</h5>
        <button type="button" class="btn btn-sm btn-macos-primary" data-modal-close="navModal" aria-label="Fermer">✕</button>
      </div>
      <div class="pt-2">
        <div id="navClientName" class="small text-muted mb-3"></div>
        <div class="d-grid gap-2">
          <a id="navGmaps" target="_blank" class="btn btn-macos-primary">Google Maps</a>
          <a id="navWaze" target="_blank" class="btn btn-macos-primary">Waze</a>
        </div>
      </div>
  </div>
</dialog>

<script>
(function(){
  const state = {
    q: <?= json_encode($q, JSON_UNESCAPED_UNICODE) ?> || '',
    page: <?= (int)$page ?> || 1,
    pages: 1,
    limit: 20,
    total: 0,
    items: [],
    poll: null,
  };

  const els = {
    qInput: document.getElementById('qInput'),
    grid: document.getElementById('clientsGrid'),
    meta: document.getElementById('clientsMeta'),
    pagerMeta: document.getElementById('pagerMeta'),
    btnPrev: document.getElementById('btnPrev'),
    btnNext: document.getElementById('btnNext'),
    alert: document.getElementById('clientsAlert'),
    btnNew: document.getElementById('btnNewClient'),
    modalTitle: document.getElementById('clientModalTitle'),
    btnSave: document.getElementById('btnSaveClient'),
    form: document.getElementById('clientForm'),
    clientId: document.getElementById('clientId'),
    nom: document.getElementById('nom'),
    prenom: document.getElementById('prenom'),
    gsm: document.getElementById('gsm'),
    email: document.getElementById('email'),
    adresse: document.getElementById('adresse'),
    lat: document.getElementById('lat'),
    lng: document.getElementById('lng'),
    csrf: document.getElementById('csrfToken'),
    coordText: document.getElementById('coordText'),
    locateFeedback: document.getElementById('locateFeedback'),
    btnLocate: document.getElementById('btnLocate'),
    navClientName: document.getElementById('navClientName'),
    navGmaps: document.getElementById('navGmaps'),
    navWaze: document.getElementById('navWaze'),
  };

  const clientModalEl = document.getElementById('clientModal');
  const navModalEl = document.getElementById('navModal');

  function makeModal(el){
    function show(){
      if (typeof el.showModal === 'function') el.showModal();
      else el.setAttribute('open', 'open');
    }
    function hide(){
      if (typeof el.close === 'function') el.close();
      else el.removeAttribute('open');
    }
    el.addEventListener('click', (ev) => {
      if (ev.target === el) hide();
    });
    return { show, hide };
  }

  const clientModal = makeModal(clientModalEl);
  const navModal = makeModal(navModalEl);
  document.querySelectorAll('[data-modal-close]').forEach((btn) => {
    btn.addEventListener('click', () => {
      const id = btn.getAttribute('data-modal-close');
      const dlg = id ? document.getElementById(id) : null;
      if (dlg && typeof dlg.close === 'function') dlg.close();
      else if (dlg) dlg.removeAttribute('open');
    });
  });

  function esc(s){
    return (s ?? '').toString().replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]));
  }

  function showAlert(message, type = 'success'){
    els.alert.className = 'mb-2 alert alert-' + type;
    els.alert.textContent = message;
    els.alert.classList.remove('d-none');
    setTimeout(() => els.alert.classList.add('d-none'), 2500);
  }

  async function apiGet(params = {}){
    const qs = new URLSearchParams(params).toString();
    const r = await fetch('api/clients.php?' + qs, { credentials:'same-origin' });
    return r.json();
  }

  async function apiPost(payload = {}){
    const fd = new FormData();
    Object.entries(payload).forEach(([k,v]) => fd.append(k, v ?? ''));
    fd.append('csrf_token', els.csrf.value);
    const r = await fetch('api/clients.php', { method:'POST', credentials:'same-origin', body: fd });
    return r.json();
  }

  function clientTitle(c){
    return [c.nom || '', c.prenom || ''].join(' ').trim();
  }

  function normalizePhone(v){
    let s = (v || '').replace(/[^0-9+]/g, '');
    if (s.startsWith('00')) s = '+' + s.slice(2);
    if (s.startsWith('+212')) return s;
    if (s.startsWith('212')) return '+' + s;
    if (s.startsWith('0')) return '+212' + s.slice(1);
    if (s.startsWith('+')) return s;
    return s ? ('+212' + s) : '';
  }

  function renderCards(items){
    if (!items.length){
      els.grid.innerHTML = '<div class="col-12"><div class="text-muted text-center py-4">Aucun client</div></div>';
      return;
    }

    els.grid.innerHTML = items.map(c => {
      const title = esc(clientTitle(c));
      const address = esc(c.adresse || '— adresse non renseignée —');
      const gsm = normalizePhone(c.gsm || '');
      const wa = gsm ? gsm.replace(/\D+/g, '') : '';
      const hasGPS = c.lat !== null && c.lng !== null;

      return `
        <div class="col-12 col-md-6 col-xl-4">
          <div class="client-card h-100">
            <div class="card-body p-3 p-md-4">
              <div class="d-flex align-items-start justify-content-between gap-2">
                <div>
                  <h2 class="h6 mb-1">${title}</h2>
                  <div class="text-muted small">${address}</div>
                </div>
                <a class="chip-soft text-decoration-none" href="index.php?page=patients&q=${encodeURIComponent(clientTitle(c))}" aria-label="Animaux ${Number(c.patient_count || 0)}">
                  Animaux ${Number(c.patient_count || 0)}
                </a>
              </div>

              <div class="mt-3 d-flex flex-wrap gap-2">
                ${gsm ? `<a class="btn btn-sm btn-macos" href="tel:${esc(gsm)}" aria-label="Appeler" title="Appeler"><i class="bi bi-telephone-fill" aria-hidden="true"></i></a>` : ''}
                ${wa ? `<a class="btn btn-sm btn-success" target="_blank" href="https://wa.me/${encodeURIComponent(wa)}" aria-label="WhatsApp" title="WhatsApp"><i class="bi bi-whatsapp" aria-hidden="true"></i></a>` : ''}
                ${c.email ? `<a class="btn btn-sm btn-macos" href="mailto:${esc(c.email)}" aria-label="Email" title="Email"><i class="bi bi-envelope-fill" aria-hidden="true"></i></a>` : ''}
              </div>

              <div class="mt-3 d-flex flex-wrap gap-2">
                <button class="btn btn-sm btn-macos" data-act="edit" data-id="${c.id}">Modifier</button>
                <button class="btn btn-sm btn-outline-danger" data-act="delete" data-id="${c.id}">Supprimer</button>
                ${hasGPS ? `<button class="btn btn-sm btn-outline-primary ms-auto" data-act="nav" data-id="${c.id}">Navigation</button>` : ''}
              </div>
            </div>
          </div>
        </div>
      `;
    }).join('');
  }

  function syncPager(){
    els.pagerMeta.textContent = `Page ${state.page} / ${state.pages}`;
    els.meta.textContent = `${state.total} client(s)`;
    els.btnPrev.disabled = state.page <= 1;
    els.btnNext.disabled = state.page >= state.pages;

    const u = new URL(window.location.href);
    u.searchParams.set('page', 'clients');
    if (state.q) u.searchParams.set('q', state.q); else u.searchParams.delete('q');
    u.searchParams.set('p', String(state.page));
    history.replaceState({}, '', u.toString());
  }

  async function loadClients(silent = false){
    const data = await apiGet({ action: 'list', q: state.q, p: state.page, limit: state.limit });
    if (!data.ok){
      if (!silent) showAlert(data.message || 'Erreur de chargement', 'danger');
      return;
    }
    state.total = Number(data.total || 0);
    state.pages = Number(data.pages || 1);
    state.items = Array.isArray(data.items) ? data.items : [];
    renderCards(state.items);
    syncPager();
  }

  function resetForm(){
    els.form.reset();
    els.clientId.value = '';
    els.lat.value = '';
    els.lng.value = '';
    els.locateFeedback.textContent = '';
    els.locateFeedback.className = 'small mt-1';
    els.coordText.textContent = 'Position : non définie';
    els.modalTitle.textContent = 'Nouveau client';
  }

  function fillForm(c){
    els.clientId.value = c.id || '';
    els.nom.value = c.nom || '';
    els.prenom.value = c.prenom || '';
    els.gsm.value = c.gsm || '';
    els.email.value = c.email || '';
    els.adresse.value = c.adresse || '';
    els.modalTitle.textContent = 'Modifier client';

    if (c.lat !== null && c.lng !== null){
      els.lat.value = Number(c.lat).toFixed(6);
      els.lng.value = Number(c.lng).toFixed(6);
      els.coordText.textContent = `Position : ${Number(c.lat).toFixed(6)}, ${Number(c.lng).toFixed(6)}`;
    }
  }

  async function onSave(){
    const payload = {
      action: 'save',
      id: els.clientId.value || '',
      nom: els.nom.value.trim(),
      prenom: els.prenom.value.trim(),
      gsm: els.gsm.value.trim(),
      email: els.email.value.trim(),
      adresse: els.adresse.value.trim(),
      lat: els.lat.value,
      lng: els.lng.value,
    };

    if (!payload.nom){
      showAlert('Le nom est obligatoire.', 'danger');
      els.nom.focus();
      return;
    }

    const r = await apiPost(payload);
    if (!r.ok){
      showAlert(r.message || 'Erreur enregistrement', 'danger');
      return;
    }

    clientModal.hide();
    showAlert('Client enregistré.');
    loadClients(true);
  }

  async function onDelete(id){
    if (!confirm('Supprimer ce client ?')) return;
    const r = await apiPost({ action: 'delete', id: String(id) });
    if (!r.ok){
      showAlert(r.message || 'Erreur suppression', 'danger');
      return;
    }
    showAlert('Client supprimé.');
    loadClients(true);
  }

  function openNav(c){
    if (c.lat === null || c.lng === null) return;
    const la = encodeURIComponent(String(c.lat));
    const lo = encodeURIComponent(String(c.lng));
    els.navClientName.textContent = clientTitle(c);
    els.navGmaps.href = `https://www.google.com/maps/dir/?api=1&destination=${la},${lo}`;
    els.navWaze.href = `https://waze.com/ul?ll=${la},${lo}&navigate=yes`;
    navModal.show();
  }

  function findItem(id){
    return state.items.find(x => Number(x.id) === Number(id)) || null;
  }

  function debounce(fn, ms){
    let t = null;
    return (...args) => {
      clearTimeout(t);
      t = setTimeout(() => fn(...args), ms);
    };
  }

  els.btnNew.addEventListener('click', () => {
    resetForm();
    clientModal.show();
  });

  els.btnSave.addEventListener('click', onSave);

  els.btnPrev.addEventListener('click', () => {
    if (state.page <= 1) return;
    state.page -= 1;
    loadClients();
  });
  els.btnNext.addEventListener('click', () => {
    if (state.page >= state.pages) return;
    state.page += 1;
    loadClients();
  });

  els.qInput.addEventListener('input', debounce(() => {
    state.q = els.qInput.value.trim();
    state.page = 1;
    loadClients();
  }, 260));

  els.gsm.addEventListener('blur', () => {
    els.gsm.value = normalizePhone(els.gsm.value);
  });

  els.btnLocate.addEventListener('click', () => {
    if (!navigator.geolocation) {
      els.locateFeedback.textContent = 'Géolocalisation non supportée.';
      els.locateFeedback.className = 'small mt-1 text-danger';
      return;
    }
    navigator.geolocation.getCurrentPosition(
      (pos) => {
        const lat = Number(pos.coords.latitude).toFixed(6);
        const lng = Number(pos.coords.longitude).toFixed(6);
        els.lat.value = lat;
        els.lng.value = lng;
        els.coordText.textContent = `Position : ${lat}, ${lng}`;
        els.locateFeedback.textContent = 'Position ajoutée.';
        els.locateFeedback.className = 'small mt-1 text-success';
      },
      (err) => {
        els.locateFeedback.textContent = 'Position indisponible : ' + err.message;
        els.locateFeedback.className = 'small mt-1 text-danger';
      },
      { enableHighAccuracy: true, timeout: 9000, maximumAge: 0 }
    );
  });

  els.grid.addEventListener('click', (e) => {
    const btn = e.target.closest('button[data-act]');
    if (!btn) return;
    const act = btn.getAttribute('data-act');
    const id = Number(btn.getAttribute('data-id') || 0);
    const c = findItem(id);
    if (!c) return;

    if (act === 'edit') {
      resetForm();
      fillForm(c);
      clientModal.show();
      return;
    }
    if (act === 'delete') {
      onDelete(id);
      return;
    }
    if (act === 'nav') {
      openNav(c);
    }
  });

  loadClients();
  state.poll = setInterval(() => loadClients(true), 15000);
})();
</script>
