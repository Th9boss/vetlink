<?php
// includes/n_patients.php
// Formulaire "Nouveau / Modifier patient" avec :
// - recherche client typeahead (sélection => client_id)
// - espèce: suggestions (valeurs uniques) + saisie libre
    // - naissance: "DD/MM/YYYY" OU âge en mois ("18", "18m", "18 mois") => converti en date
// - compression de la photo côté client (JPEG)

if (!isset($clients)) {
  $clients = db()->query("SELECT id, prenom, nom FROM clients ORDER BY nom, prenom")->fetchAll();
}
if (!isset($species)) {
  $speciesStmt = db()->query("SELECT DISTINCT espece FROM patients WHERE COALESCE(espece,'')<>'' ORDER BY espece");
  $species = array_column($speciesStmt->fetchAll(), 'espece');
}
?>
<div class="card card-body mb-3">
  <form method="post" class="row g-3" enctype="multipart/form-data" id="npatient_form">
    <?= csrf_input() ?>
    <input type="hidden" name="action" value="create">
    <input type="hidden" name="id" value="">
    <input type="hidden" name="photo_b64" id="npatient_photo_b64">
    <!-- La vraie valeur envoyée sera dans ce champ caché, après parsing/conversion -->
    <input type="hidden" name="date_naissance" id="npatient_date_naissance_hidden">

    <!-- Client (typeahead) -->
    <div class="col-12">
      <label class="form-label">Client *</label>
      <input type="hidden" name="client_id" id="npatient_client_id">
      <div class="position-relative">
        <input id="npatient_client_input" class="form-control" autocomplete="off" placeholder="Tapez pour chercher (nom, prénom)" required>
        <div id="npatient_client_list" class="list-group position-absolute w-100 shadow-sm d-none" style="z-index:1055; max-height:220px; overflow:auto;"></div>
      </div>
      <div id="npatient_client_help" class="form-text">Sélectionnez un client dans la liste (obligatoire).</div>
    </div>

    <div class="col-md-6">
      <label class="form-label">Nom de l’animal *</label>
      <input name="nom" class="form-control" required>
    </div>

    <!-- Espèce (suggestions + libre) -->
    <div class="col-md-6">
      <label class="form-label">Espèce</label>
      <input name="espece" id="npatient_espece" class="form-control" list="species_list" placeholder="Ex: Chien, Chat, ...">
      <datalist id="species_list">
        <?php foreach ($species as $sp): ?>
          <option value="<?= h($sp) ?>"></option>
        <?php endforeach; ?>
      </datalist>
      <div class="form-text">Choisissez une valeur existante ou saisissez-en une nouvelle.</div>
    </div>

    <div class="col-md-6">
      <label class="form-label">Race</label>
      <input name="race" class="form-control">
    </div>

    <div class="col-md-3">
      <label class="form-label">Sexe</label>
      <select name="sexe" class="form-select">
        <option value="M">Mâle</option>
        <option value="F">Femelle</option>
        <option value="INCONNU" selected>Inconnu</option>
      </select>
    </div>

    <!-- Naissance: date OU âge en mois -->
    <div class="col-md-3">
      <label class="form-label">Naissance (date ou âge en mois)</label>
      <input id="npatient_naissance_free" class="form-control" placeholder="dd/mm/yyyy ou 18m" inputmode="numeric">
      <div class="form-text" id="npatient_naissance_hint">Exemples : 01/05/2024 • 6 • 6m • 6 mois</div>
    </div>

    <div class="col-md-6">
      <label class="form-label">N° identification (puce, tatouage)</label>
      <input name="identification" class="form-control">
    </div>

    <div class="col-md-3 form-check mt-4 ms-2">
      <input class="form-check-input" type="checkbox" name="sterilise" id="npatient_sterilise">
      <label class="form-check-label" for="npatient_sterilise">Stérilisé</label>
    </div>

    <div class="col-12">
      <label class="form-label">Allergies</label>
      <input name="allergies" class="form-control">
    </div>

    <div class="col-12">
      <label class="form-label">Notes</label>
      <textarea name="notes" class="form-control" rows="2"></textarea>
    </div>

    <!-- Photo -->
    <div class="col-md-6">
      <label class="form-label">Photo (compressée côté client)</label>
      <input type="file" name="photo_file" id="npatient_photo_file" class="form-control" accept=".jpg,.jpeg,.png,.gif,.webp">
      <div class="form-text">JPEG/PNG/GIF/WebP — max 8 Mo. La photo sera compressée automatiquement.</div>
    </div>
    <div class="col-md-6">
      <label class="form-label">Aperçu</label>
      <div>
        <img id="npatient_photo_preview" src="assets/img/avatar_placeholder.png" class="rounded border" style="max-height:120px;max-width:100%;object-fit:cover">
      </div>
    </div>

    <div class="col-12 d-flex gap-2">
      <button class="btn btn-primary">Enregistrer</button>
      <a class="btn btn-outline-secondary" data-bs-toggle="collapse" href="#patientForm" role="button" aria-controls="patientForm">Fermer</a>
    </div>
  </form>
</div>

<script>
// ======== Données pour le typeahead clients (générées côté PHP) ========
const NPATIENT_CLIENTS = <?php
  $labels = array_map(function($c){
    // label: "Nom Prénom"
    $lbl = trim(($c['nom'] ?? '').' '.($c['prenom'] ?? ''));
    return ['id'=>(int)$c['id'], 'label'=>$lbl];
  }, $clients);
  echo json_encode($labels, JSON_UNESCAPED_UNICODE);
?>;

// ======== Typeahead Clients ========
(function(){
  const input = document.getElementById('npatient_client_input');
  const list  = document.getElementById('npatient_client_list');
  const hidden = document.getElementById('npatient_client_id');
  const help = document.getElementById('npatient_client_help');

  if (!input || !list) return;

  function clearList(){ list.innerHTML=''; list.classList.add('d-none'); }
  function setClient(id, label){
    hidden.value = id || '';
    input.value = label || '';
    clearList();
    if (help) help.textContent = id ? 'Client sélectionné.' : 'Sélectionnez un client dans la liste (obligatoire).';
  }

  function render(matches){
    clearList();
    if (!matches.length) return;
    matches.slice(0, 12).forEach(c => {
      const a = document.createElement('button');
      a.type = 'button';
      a.className = 'list-group-item list-group-item-action';
      a.textContent = c.label + '  (#' + c.id + ')';
      a.addEventListener('click', () => setClient(c.id, c.label));
      list.appendChild(a);
    });
    list.classList.remove('d-none');
  }

  function filter(q){
    q = q.trim().toLowerCase();
    if (!q) { clearList(); hidden.value=''; return; }
    const toks = q.split(/\s+/);
    const out = NPATIENT_CLIENTS.filter(c => {
      const lab = (c.label || '').toLowerCase();
      return toks.every(t => lab.includes(t));
    });
    render(out);
  }

  input.addEventListener('input', () => {
    hidden.value = ''; // reset jusqu’à sélection
    filter(input.value);
  });
  input.addEventListener('focus', () => {
    if (input.value && !hidden.value) filter(input.value);
  });
  document.addEventListener('click', (e) => {
    if (!list.contains(e.target) && e.target !== input) clearList();
  });
})();

// ======== Naissance: parse "DD/MM/YYYY" OU "18m"/"18 mois"/"18" ========
(function(){
  const free = document.getElementById('npatient_naissance_free');
  const hidden = document.getElementById('npatient_date_naissance_hidden');
  const hint = document.getElementById('npatient_naissance_hint');
  const form = document.getElementById('npatient_form');

  if (!free || !hidden || !form) return;

  function toDateString(d){
    const y = d.getFullYear();
    const m = String(d.getMonth()+1).padStart(2,'0');
    const da = String(d.getDate()).padStart(2,'0');
    return `${y}-${m}-${da}`;
  }

  function toDisplayDate(iso){
    if (!iso || !/^\d{4}-\d{2}-\d{2}$/.test(iso)) return '';
    const [y, m, d] = iso.split('-');
    return `${d}/${m}/${y}`;
  }

  function parseValue(val){
    if (!val) return '';
    val = val.trim();

    // format date ISO directe
    if (/^\d{4}-\d{2}-\d{2}$/.test(val)) return val;

    // format date FR
    const fr = val.match(/^(\d{2})\/(\d{2})\/(\d{4})$/);
    if (fr) {
      const day = parseInt(fr[1], 10);
      const month = parseInt(fr[2], 10);
      const year = parseInt(fr[3], 10);
      const dt = new Date(year, month - 1, day);
      if (
        dt.getFullYear() === year &&
        dt.getMonth() === month - 1 &&
        dt.getDate() === day
      ) {
        return toDateString(dt);
      }
    }

    // âge en mois : "18", "18m", "18 mois"
    const m = val.toLowerCase().match(/^(\d+)\s*(m|mois)?$/);
    if (m) {
      const months = parseInt(m[1], 10);
      if (months >= 0 && months < 600) { // borne prudente
        const d = new Date();
        // retirer "months" mois
        const year = d.getFullYear();
        const mon  = d.getMonth();
        const day  = d.getDate();
        // calc approx: reculer mois en conservant jour
        const target = new Date(year, mon - months, day);
        return toDateString(target);
      }
    }
    return ''; // invalide
  }

  function updateHint(){
    const v = free.value;
    const parsed = parseValue(v);
    if (parsed) {
      hidden.value = parsed;
      if (hint) hint.textContent = `→ Interprété comme date : ${toDisplayDate(parsed)}`;
    } else {
      hidden.value = '';
      if (hint) hint.textContent = 'Exemples : 01/05/2024 • 6 • 6m • 6 mois';
    }
  }

  free.addEventListener('input', updateHint);

  form.addEventListener('submit', (e) => {
    updateHint();
    // Vérif client_id obligatoire
    const cid = document.getElementById('npatient_client_id')?.value || '';
    if (!cid) {
      e.preventDefault();
      alert('Veuillez sélectionner un client dans la liste.');
      return;
    }
    // Si champ naissance rempli mais non interprétable → bloquer
    if (free.value.trim() !== '' && hidden.value === '') {
      e.preventDefault();
      alert('Champ "Naissance" invalide. Saisissez une date (dd/mm/yyyy) ou un âge en mois (ex: 6, 6m, 6 mois).');
      free.focus();
      return;
    }
  });
})();

// ======== Compression image côté client (form "n_patient") ========
(function(){
  const input = document.getElementById('npatient_photo_file');
  const preview = document.getElementById('npatient_photo_preview');
  const hidden = document.getElementById('npatient_photo_b64');

  if (!input) return;

  function compressImage(file, maxW=900, quality=0.82){
    return new Promise((resolve, reject) => {
      const reader = new FileReader();
      reader.onload = e => {
        const img = new Image();
        img.onload = () => {
          const scale = Math.min(1, maxW / img.width);
          const w = Math.round(img.width * scale);
          const h = Math.round(img.height * scale);
          const canvas = document.createElement('canvas');
          canvas.width = w; canvas.height = h;
          const ctx = canvas.getContext('2d');
          ctx.drawImage(img, 0, 0, w, h);
          const b64 = canvas.toDataURL('image/jpeg', quality);
          resolve(b64);
        };
        img.onerror = reject;
        img.src = e.target.result;
      };
      reader.onerror = reject;
      reader.readAsDataURL(file);
    });
  }

  input.addEventListener('change', async () => {
    const file = input.files && input.files[0];
    if (!file) { hidden.value=''; preview.src='assets/img/avatar_placeholder.png'; return; }
    try {
      const b64 = await compressImage(file);
      hidden.value = b64;
      preview.src = b64;
    } catch(e) {
      console.error(e);
      hidden.value = '';
    }
  });
})();
</script>
