<?php
// features/clients.php
// Gestion Clients mobile-first avec :
// - Recherche robuste (HY093 corrigé)
// - Carte Leaflet (Esri World Street Map) dans le formulaire (création / édition)
// - Initialisation paresseuse + invalidateSize pour desktop
// - Picker manuel, géoloc, marker draggable
// - Actions : appel, WhatsApp, email, navigation (modal Waze / Google Maps)
// - Bouton "Animaux (n)" vers patients filtrés par client

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';

require_login();
csrf_check();

/* ========================= Helpers coordonnées ========================= */
function client_latlng_candidates(): array {
  return [
    ['latitude', 'longitude'],
    ['gps_lat', 'gps_lng'],
    ['lat', 'lng'],
    ['lat', 'lon'],
    ['coord_lat', 'coord_lng'],
  ];
}
function detect_client_latlng_columns(PDO $pdo): array {
  static $cached = null;
  if ($cached !== null) return $cached;
  $cols = [];
  $q = $pdo->query("SHOW COLUMNS FROM clients");
  foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $c) {
    $cols[strtolower($c['Field'])] = $c['Field'];
  }
  foreach (client_latlng_candidates() as [$la,$lo]) {
    if (isset($cols[strtolower($la)]) && isset($cols[strtolower($lo)])) {
      $cached = [$cols[strtolower($la)], $cols[strtolower($lo)]];
      return $cached;
    }
  }
  $cached = [null, null];
  return $cached;
}
function latlng_set_clause(PDO $pdo, ?float $lat, ?float $lng, array &$params): string {
  [$latCol,$lngCol] = detect_client_latlng_columns($pdo);
  if ($latCol && $lngCol) {
    $params[":_lat"] = $lat;
    $params[":_lng"] = $lng;
    return ", `$latCol` = :_lat, `$lngCol` = :_lng ";
  }
  return "";
}
function client_row_latlng(array $row): array {
  foreach (client_latlng_candidates() as [$la,$lo]) {
    if (array_key_exists($la,$row) && array_key_exists($lo,$row)
        && $row[$la] !== null && $row[$lo] !== null && $row[$la] !== '' && $row[$lo] !== '') {
      return [floatval($row[$la]), floatval($row[$lo])];
    }
  }
  return [null, null];
}

/* ============== Normalisation téléphone Maroc (+212XXXXXXXXX) ============== */
function normalize_ma_phone(string $raw): string {
  // Supprime tout sauf chiffres et +
  $s = preg_replace('/[^0-9+]/', '', $raw ?? '');
  if ($s === null) $s = '';

  // 00XX -> +XX
  if (strpos($s, '00') === 0) {
    $s = '+'.substr($s, 2);
  }

  // Si commence par +212 : OK
  if (strpos($s, '+212') === 0) {
    return $s;
  }

  // Si commence par 212 (sans +) : préfixer +
  if (strpos($s, '212') === 0) {
    return '+'.$s;
  }

  // Si commence par 0 : enlever le 0 et préfixer +212
  if (strpos($s, '0') === 0) {
    $rest = substr($s, 1); // drop leading 0
    return '+212'.$rest;
  }

  // Si commence déjà par + mais autre pays, on garde tel quel
  if (strpos($s, '+') === 0) {
    return $s;
  }

  // Sinon : considérer que c'est un national sans 0 (cas rare) -> préfixer +212
  if ($s !== '') {
    return '+212'.$s;
  }

  return '';
}

/* ========================= Actions (POST) ========================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $act = $_POST['action'] ?? '';

  if ($act === 'create' || $act === 'update') {
    $id       = (int)($_POST['id'] ?? 0);
    $nom      = trim($_POST['nom'] ?? '');
    $prenom   = trim($_POST['prenom'] ?? '');
    $gsmRaw   = trim($_POST['gsm'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    $adresse  = trim($_POST['adresse'] ?? '');
    $lat      = isset($_POST['lat']) && $_POST['lat'] !== '' ? (float)$_POST['lat'] : null;
    $lng      = isset($_POST['lng']) && $_POST['lng'] !== '' ? (float)$_POST['lng'] : null;

    // Normalisation téléphone (obligatoire)
    $gsm = normalize_ma_phone($gsmRaw);

    if ($act === 'create') {
      $sql = "INSERT INTO clients (nom, prenom, gsm, email, adresse, created_at)
              VALUES (:nom, :prenom, :gsm, :email, :adresse, NOW())";
      $st = db()->prepare($sql);
      $st->bindValue(':nom', $nom);
      $st->bindValue(':prenom', $prenom);
      $st->bindValue(':gsm', $gsm);
      $st->bindValue(':email', $email);
      $st->bindValue(':adresse', $adresse);
      $st->execute();

      $newId = (int)db()->lastInsertId();

      $params2 = [':id'=>$newId];
      $setLL = latlng_set_clause(db(), $lat, $lng, $params2);
      if ($setLL !== "") {
        $sql2 = "UPDATE clients SET updated_at = NOW() $setLL WHERE id = :id";
        $u = db()->prepare($sql2);
        foreach ($params2 as $k=>$v) $u->bindValue($k,$v);
        $u->execute();
      }
      redirect('index.php?page=clients&ok=created');
    }

    if ($act === 'update' && $id > 0) {
      $sql = "UPDATE clients
              SET nom=:nom, prenom=:prenom, gsm=:gsm, email=:email, adresse=:adresse %LL%,
                  updated_at = NOW()
              WHERE id=:id";
      $params = [
        ':id'=>$id, ':nom'=>$nom, ':prenom'=>$prenom, ':gsm'=>$gsm,
        ':email'=>$email, ':adresse'=>$adresse
      ];
      $setLL = latlng_set_clause(db(), $lat, $lng, $params);
      $sql = str_replace('%LL%', $setLL, $sql);
      $st = db()->prepare($sql);
      foreach ($params as $k=>$v) $st->bindValue($k,$v);
      $st->execute();
      redirect('index.php?page=clients&ok=updated');
    }
  }

  if ($act === 'delete') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id > 0) {
      $del = db()->prepare("DELETE FROM clients WHERE id=?");
      $del->execute([$id]);
    }
    redirect('index.php?page=clients&ok=deleted');
  }
}

/* ========================= Recherche / Pagination ========================= */
$q = trim($_GET['q'] ?? '');
[$limit, $offset, $page] = paginate_params(20, 100);

$where = " WHERE 1=1 ";
$params = [];

if ($q !== '') {
  $where .= " AND (c.nom LIKE ? OR c.prenom LIKE ? OR c.email LIKE ? OR c.gsm LIKE ? OR c.adresse LIKE ?) ";
  $like = '%'.$q.'%';
  // 5 occurrences => 5 valeurs
  $params = [$like, $like, $like, $like, $like];
}

/* ===== Compter total ===== */
$countSql = "SELECT COUNT(*) cnt FROM clients c $where";
$cs = db()->prepare($countSql);
$cs->execute($params);              // tableau positionnel
$total = (int)$cs->fetchColumn();

/* ===== Récup liste paginée ===== */
$sql = "SELECT c.* FROM clients c $where
        ORDER BY c.updated_at DESC, c.id DESC
        LIMIT " . (int)$limit . " OFFSET " . (int)$offset;  // inline des entiers
$st = db()->prepare($sql);
$st->execute($params);              // même $params que pour le count
$clients = $st->fetchAll();

/* ===== Compter animaux par client ===== */
$ids = array_column($clients, 'id');
$counts = [];
if ($ids) {
  $in = implode(',', array_fill(0, count($ids), '?'));
  $pc = db()->prepare("SELECT client_id, COUNT(*) n FROM patients WHERE client_id IN ($in) GROUP BY client_id");
  $pc->execute($ids);               // OK : uniquement des ? positionnels
  foreach ($pc->fetchAll() as $row) {
    $counts[(int)$row['client_id']] = (int)$row['n'];
  }
}
/* ========================= Édition ? ========================= */
$editId = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;
$edit = null;
if ($editId > 0) {
  $es = db()->prepare("SELECT * FROM clients WHERE id=?");
  $es->execute([$editId]);
  $edit = $es->fetch();
  if (!$edit) $editId = 0;
}
[$latCol,$lngCol] = detect_client_latlng_columns(db());

?>
<div class="d-flex flex-wrap align-items-center gap-2 mb-3">
  <h1 class="h4 mb-0">Clients</h1>

  <form method="get" class="ms-auto d-flex align-items-center gap-2">
    <input type="hidden" name="page" value="clients">
    <div class="input-group input-group-sm">
      <input class="form-control" name="q" placeholder="Rechercher (nom, email, gsm, adresse)" value="<?= h($q) ?>">
      <button class="btn btn-outline-primary">OK</button>
      <?php if ($q!==''): ?>
        <a class="btn btn-outline-secondary" href="index.php?page=clients">Réinit.</a>
      <?php endif; ?>
    </div>
  </form>

  <a class="btn btn-success btn-sm" data-bs-toggle="collapse" href="#clientForm" role="button"
     aria-expanded="<?= $edit ? 'true':'false' ?>" aria-controls="clientForm">
    <?= $edit ? 'Modifier' : 'Nouveau client' ?>
  </a>
</div>

<?php if (isset($_GET['ok'])): ?>
  <div class="alert alert-success alert-dismissible fade show">
    Action réalisée avec succès.
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
  </div>
<?php endif; ?>

<!-- Formulaire Création / Édition -->
<div class="collapse <?= $edit ? 'show':'' ?>" id="clientForm">
  <div class="card shadow-sm">
    <div class="card-body">
      <form method="post" class="row g-3" id="clientFormEl">
        <?= csrf_input() ?>
        <input type="hidden" name="action" value="<?= $edit ? 'update' : 'create' ?>">
        <?php if ($edit): ?><input type="hidden" name="id" value="<?= h($edit['id']) ?>"><?php endif; ?>

        <div class="col-md-4">
          <label class="form-label">Nom *</label>
          <input name="nom" class="form-control" required value="<?= h($edit['nom'] ?? '') ?>">
        </div>
        <div class="col-md-4">
          <label class="form-label">Prénom</label>
          <input name="prenom" class="form-control" value="<?= h($edit['prenom'] ?? '') ?>">
        </div>
        <div class="col-md-4">
          <label class="form-label">GSM</label>
          <input name="gsm" class="form-control" 
                 
                 value="<?= h(isset($edit['gsm']) ? normalize_ma_phone((string)$edit['gsm']) : '') ?>">
          
        </div>

        <div class="col-md-6">
          <label class="form-label">Email</label>
          <input type="email" name="email" class="form-control" value="<?= h($edit['email'] ?? '') ?>">
        </div>
        <div class="col-md-6">
          <label class="form-label">Adresse</label>
          <input name="adresse" class="form-control" value="<?= h($edit['adresse'] ?? '') ?>">
        </div>

        <!-- Localisation (wrap dans un collapse) -->
        <div class="col-12">
          <div class="d-flex align-items-center justify-content-between mb-2">
            <div class="d-flex align-items-center gap-2">
              <span class="form-label m-0">Localisation</span>
              <span class="badge bg-light text-dark">Esri</span>
            </div>
            <?php
              $initLat = $edit && $latCol && isset($edit[$latCol]) ? (string)$edit[$latCol] : '';
              $initLng = $edit && $lngCol && isset($edit[$lngCol]) ? (string)$edit[$lngCol] : '';
              $locShown = ($initLat !== '' && $initLng !== '');
            ?>
            <a class="btn btn-sm btn-outline-primary"
               data-bs-toggle="collapse" href="#locCollapse" role="button"
               aria-expanded="<?= $locShown ? 'true':'false' ?>" aria-controls="locCollapse">
              + Localisation
            </a>
          </div>

          <div class="collapse <?= $locShown ? 'show':'' ?>" id="locCollapse">
            <div class="d-flex flex-wrap gap-2 align-items-center mb-2">
              <button class="btn btn-sm btn-outline-primary" type="button" id="btnLocate">
                Ma position
              </button>
              <span class="small text-muted">Cliquez sur la carte pour définir la position. Déplacez le marqueur pour affiner.</span>
            </div>

            <div id="clientMap" class="rounded border" style="width:100%;"></div>

            <input type="hidden" name="lat" id="lat" value="<?= h($initLat) ?>">
            <input type="hidden" name="lng" id="lng" value="<?= h($initLng) ?>">

            <div class="small text-muted mt-2" id="coordText">
              <?php if ($initLat !== '' && $initLng !== ''): ?>
                Position : <?= h($initLat) ?>, <?= h($initLng) ?>
              <?php else: ?>
                Position : — non définie —
              <?php endif; ?>
            </div>
          </div>
        </div>

        <div class="col-12 d-flex gap-2">
          <button class="btn btn-primary"><?= $edit ? 'Enregistrer' : 'Créer' ?></button>
          <?php if ($edit): ?>
            <a class="btn btn-outline-secondary" href="index.php?page=clients">Annuler</a>
          <?php endif; ?>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Liste / Cartes -->
<div class="card shadow-sm mt-3">
  <div class="card-body pb-0">
    <div class="text-muted"><?= $total ?> client(s) — Page <?= $page ?></div>
  </div>

  <div class="p-3">
    <div class="row g-3">
      <?php if (!$clients): ?>
        <div class="text-center text-muted py-4">Aucun client</div>
      <?php else: foreach ($clients as $c): ?>
        <?php [$clat,$clng] = client_row_latlng($c); $hasGPS = ($clat !== null && $clng !== null); ?>
        <div class="col-12 col-md-6 col-xl-4">
          <div class="card h-100 shadow-sm">
            <div class="card-body">
              <div class="d-flex align-items-start">
                <div class="flex-grow-1">
                  <h2 class="h6 mb-1"><?= h($c['nom'].' '.$c['prenom']) ?></h2>
                  <div class="small text-muted"><?= h($c['adresse'] ?: '— adresse non renseignée —') ?></div>
                </div>
                <div class="ms-2 d-flex flex-column gap-2">
                  <?php $n = $counts[(int)$c['id']] ?? 0; ?>
                  <a class="btn btn-outline-secondary btn-sm"
                     href="index.php?page=patients&q=<?= urlencode(trim(($c['nom'] ?? '').' '.($c['prenom'] ?? ''))) ?>">
                    Animaux (<?= (int)$n ?>)
                  </a>
                </div>
              </div>

              <div class="mt-3 d-flex flex-wrap gap-2">
                <?php if (!empty($c['gsm'])): ?>
                  <a class="btn btn-sm btn-primary" href="tel:<?= h(normalize_ma_phone((string)$c['gsm'])) ?>">Appeler</a>
                  <a class="btn btn-sm btn-success" target="_blank" href="https://wa.me/<?= urlencode(preg_replace('/\D+/','', normalize_ma_phone((string)$c['gsm']))) ?>">WhatsApp</a>
                <?php endif; ?>
                <?php if (!empty($c['email'])): ?>
                  <a class="btn btn-sm btn-outline-secondary" href="mailto:<?= h($c['email']) ?>">Email</a>
                <?php endif; ?>

                <?php if ($hasGPS): ?>
                  <button type="button" class="btn btn-sm btn-outline-primary ms-auto"
                          data-bs-toggle="modal" data-bs-target="#navModal"
                          data-lat="<?= h($clat) ?>" data-lng="<?= h($clng) ?>"
                          data-title="<?= h($c['nom'].' '.$c['prenom']) ?>">
                    Navigation
                  </button>
                <?php endif; ?>
              </div>

              <div class="mt-3 d-flex flex-wrap gap-2">
                <a class="btn btn-outline-secondary btn-sm" href="index.php?page=clients&edit=<?= h($c['id']) ?>#clientForm">
                  Modifier
                </a>
                <form method="post" onsubmit="return confirm('Supprimer ce client ?');">
                  <?= csrf_input() ?>
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="id" value="<?= h($c['id']) ?>">
                  <button class="btn btn-outline-danger btn-sm">Supprimer</button>
                </form>
              </div>
            </div>
          </div>
        </div>
      <?php endforeach; endif; ?>
    </div>
  </div>

  <?php $pages = max(1, (int)ceil($total / $limit)); ?>
  <div class="card-footer d-flex justify-content-between align-items-center">
    <div class="text-muted">Page <?= $page ?> / <?= $pages ?></div>
    <nav>
      <ul class="pagination mb-0">
        <?php
          $base = 'index.php?page=clients' . ($q!=='' ? '&q='.urlencode($q) : '');
          $prev = max(1, $page-1);
          $next = min($pages, $page+1);
        ?>
        <li class="page-item <?= $page<=1?'disabled':'' ?>"><a class="page-link" href="<?= $base.'&page='.$prev ?>">Précédent</a></li>
        <li class="page-item <?= $page>=$pages?'disabled':'' ?>"><a class="page-link" href="<?= $base.'&page='.$next ?>">Suivant</a></li>
      </ul>
    </nav>
  </div>
</div>

<!-- Modal Navigation (Waze / Google Maps) -->
<div class="modal fade" id="navModal" tabindex="-1" aria-labelledby="navModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="navModalLabel">Navigation</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button>
      </div>
      <div class="modal-body">
        <div class="small text-muted mb-3" id="navClientName"></div>
        <div class="d-grid gap-2">
          <a id="navGmaps" target="_blank" class="btn btn-primary">Google Maps</a>
          <a id="navWaze" target="_blank" class="btn btn-outline-primary">Waze</a>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Leaflet CSS/JS -->
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
      integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="anonymous">
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
        integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin="anonymous"></script>

<style>
  /* Taille fiable pour la carte (Leaflet nécessite une hauteur explicite) */
  #clientMap { height: 420px; }
  @media (max-width: 576px) { #clientMap { height: 320px; } }

  @media (max-width: 576px) {
    .table { font-size:.92rem; }
    .table td, .table th { padding:.45rem .5rem; }
  }
</style>

<script>
/* ======== Normalisation live légère pour le champ GSM (cosmétique) ======== */
(function(){
  const gsm = document.querySelector('input[name="gsm"]');
  if (!gsm) return;
  gsm.addEventListener('blur', () => {
    const v = gsm.value || '';
    // Miroir de la logique serveur, en simplifié (cosmétique)
    let s = v.replace(/[^0-9+]/g, '');
    if (s.startsWith('00')) s = '+' + s.slice(2);
    if (s.startsWith('+212')) { gsm.value = s; return; }
    if (s.startsWith('212'))  { gsm.value = '+' + s; return; }
    if (s.startsWith('0'))    { gsm.value = '+212' + s.slice(1); return; }
    if (s.startsWith('+'))    { gsm.value = s; return; }
    if (s) gsm.value = '+212' + s;
  });
})();

/* ======== Navigation modal (Waze / Google Maps) ======== */
(function(){
  const modal = document.getElementById('navModal');
  if (!modal) return;
  const nameEl = document.getElementById('navClientName');
  const gmaps = document.getElementById('navGmaps');
  const waze  = document.getElementById('navWaze');

  modal.addEventListener('show.bs.modal', function(e){
    const btn = e.relatedTarget;
    const lat = btn?.getAttribute('data-lat');
    const lng = btn?.getAttribute('data-lng');
    const title = btn?.getAttribute('data-title') || 'Client';

    nameEl.textContent = title;
    if (lat && lng) {
      const la = encodeURIComponent(lat);
      const lo = encodeURIComponent(lng);
      gmaps.href = `https://www.google.com/maps/dir/?api=1&destination=${la},${lo}`;
      waze.href  = `https://waze.com/ul?ll=${la},${lo}&navigate=yes`;
    } else {
      gmaps.removeAttribute('href');
      waze.removeAttribute('href');
    }
  });
})();

/* ======== Leaflet Map (Esri) — init paresseuse dans collapse ======== */
(function(){
  const mapCollapseEl = document.getElementById('locCollapse');
  const mapEl = document.getElementById('clientMap');
  const latInput = document.getElementById('lat');
  const lngInput = document.getElementById('lng');
  const btnLocate = document.getElementById('btnLocate');
  const coordText = document.getElementById('coordText');

  if (!mapCollapseEl || !mapEl) return;

  let map = null;
  let marker = null;

  function updateCoordText(lat, lng) {
    coordText.textContent = `Position : ${lat.toFixed(6)}, ${lng.toFixed(6)}`;
  }

  function setMarker(lat, lng, pan=true) {
    if (!map) return;
    if (marker) { marker.setLatLng([lat, lng]); }
    else {
      marker = L.marker([lat, lng], {draggable:true}).addTo(map);
      marker.on('dragend', e => {
        const p = e.target.getLatLng();
        latInput.value = p.lat.toFixed(6);
        lngInput.value = p.lng.toFixed(6);
        updateCoordText(p.lat, p.lng);
      });
    }
    latInput.value = lat.toFixed(6);
    lngInput.value = lng.toFixed(6);
    updateCoordText(lat, lng);
    if (pan) map.setView([lat, lng], Math.max(map.getZoom(), 15));
  }

  function initMap() {
    if (map) {
      setTimeout(() => map.invalidateSize(), 0);
      return;
    }
    // Vue par défaut : Maroc
    const DEFAULT = [31.7917, -7.0926];
    const initLat = parseFloat(latInput.value || '0');
    const initLng = parseFloat(lngInput.value || '0');
    const hasInit = isFinite(initLat) && isFinite(initLng) && (initLat !== 0 || initLng !== 0);
    const startCenter = hasInit ? [initLat, initLng] : DEFAULT;
    const startZoom   = hasInit ? 14 : 6;

    map = L.map(mapEl, { worldCopyJump: true }).setView(startCenter, startZoom);

    // Esri World Street Map
    L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Street_Map/MapServer/tile/{z}/{y}/{x}', {
      maxZoom: 19,
      attribution: '&copy; Esri &mdash; Sources: Esri, HERE, Garmin, FAO, NOAA, USGS, © OpenStreetMap contributors'
    }).addTo(map);

    if (hasInit) setMarker(initLat, initLng, false);

    map.on('click', function(e){
      setMarker(e.latlng.lat, e.latlng.lng, true);
    });

    // Corrige la taille après animation du collapse
    setTimeout(() => map.invalidateSize(), 80);
  }

  // Init si le collapse localisation est déjà ouvert (coords présentes)
  if (mapCollapseEl.classList.contains('show')) {
    initMap();
  }

  // Init à l'ouverture du collapse localisation
  mapCollapseEl.addEventListener('shown.bs.collapse', function(){
    initMap();
  });

  // Bouton Ma position
  if (btnLocate) {
    btnLocate.addEventListener('click', function(){
      if (!navigator.geolocation) { alert('Géolocalisation non supportée.'); return; }
      navigator.geolocation.getCurrentPosition(
        pos => {
          initMap(); // au cas où
          setMarker(pos.coords.latitude, pos.coords.longitude, true);
          setTimeout(() => map.invalidateSize(), 80);
        },
        err => alert('Position indisponible : ' + err.message),
        { enableHighAccuracy:true, timeout:8000, maximumAge:0 }
      );
    });
  }

  // Si on redimensionne la fenêtre (souvent le pb de crop desktop)
  window.addEventListener('resize', () => { if (map) map.invalidateSize(); });
})();
</script>