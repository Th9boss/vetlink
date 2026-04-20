<?php
// features/patients.php
// Liste + création/édition patients (photo fix), suppression conditionnelle, détails en modale,
// carte mobile-first, filtres et recherche intelligente. Le formulaire "Nouveau patient" est
// externalisé dans includes/n_patients.php (mais le traitement POST reste ici).

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';

require_login();
csrf_check();

/* ========================= Helpers Upload Photo ========================= */
function uploads_patiens_dir(): string {
    $dir = __DIR__ . '/../uploads/patiens';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    return $dir;
}
function save_base64_image(string $dataUrl, int $patient_id): ?string {
    if (!preg_match('#^data:image/(png|jpe?g|gif|webp);base64,#i', $dataUrl, $m)) return null;
    $ext = strtolower($m[1]) === 'jpeg' ? 'jpg' : strtolower($m[1]);
    $base64 = substr($dataUrl, strpos($dataUrl, ',') + 1);
    $bin = base64_decode($base64);
    if ($bin === false || strlen($bin) < 10) return null;

    $dir = uploads_patiens_dir();
    $fname = 'p_'.$patient_id.'_'.date('YmdHis').'.'.$ext;
    $path = rtrim($dir, '/').'/'.$fname;
    if ($bin === false || strlen($bin) < 10) return null;

$src = imagecreatefromstring($bin);
if (!$src) return null;

// Compression JPEG vers max 500ko
$dir = uploads_patiens_dir();
$fname = 'p_'.$patient_id.'_'.date('YmdHis').'.jpg';
$path = rtrim($dir, '/').'/'.$fname;

$quality = 90;
do {
    ob_start();
    imagejpeg($src, null, $quality);
    $data = ob_get_clean();
    $size = strlen($data);
    $quality -= 5;
} while ($size > 500*1024 && $quality > 10);

file_put_contents($path, $data);
imagedestroy($src);

return 'uploads/patiens/'.$fname;
}
function save_uploaded_file(array $file, int $patient_id): ?string {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) return null;
    if (($file['size'] ?? 0) <= 0 || $file['size'] > 8 * 1024 * 1024) return null;
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg','jpeg','png','gif','webp'], true)) return null;

    $dir = uploads_patiens_dir();
    $fname = 'p_'.$patient_id.'_'.date('YmdHis').'.'.$ext;
    $dest = rtrim($dir,'/').'/'.$fname;
    if (!move_uploaded_file($file['tmp_name'], $dest)) return null;
    return 'uploads/patiens/'.$fname;
}
function delete_patient_photo(?string $rel): void {
    if (!$rel) return;
    $abs = realpath(__DIR__ . '/../' . ltrim($rel, '/'));
    if ($abs && is_file($abs)) @unlink($abs);
}

function format_birth_fr(?string $date): string {
    if (!$date || $date === '0000-00-00') return '—';
    $ts = strtotime($date);
    return $ts ? date('d/m/Y', $ts) : (string)$date;
}

/* ========================= ACTIONS (POST) ========================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ====== Create / Update patient ======
    if ($action === 'create' || $action === 'update') {
        $id             = (int)($_POST['id'] ?? 0);
        $client_id      = (int)($_POST['client_id'] ?? 0);
        $nom            = trim($_POST['nom'] ?? '');
        $espece         = trim($_POST['espece'] ?? '');
        $race           = trim($_POST['race'] ?? '');
        $sexe           = $_POST['sexe'] ?? 'INCONNU';
        $date_naissance = $_POST['date_naissance'] ?: null;
        $sterilise      = isset($_POST['sterilise']) ? 1 : 0;
        $identification = trim($_POST['identification'] ?? '');
        $allergies      = trim($_POST['allergies'] ?? '');
        $notes          = trim($_POST['notes'] ?? '');
        $photo_b64      = $_POST['photo_b64'] ?? null;
        $photo_file     = $_FILES['photo_file'] ?? null;

        if ($action === 'create') {
            // IMPORTANT: insérer photo='' pour éviter 1364 (NOT NULL sans valeur par défaut)
            $sql = "INSERT INTO patients
                    (client_id, nom, espece, race, sexe, date_naissance, sterilise, identification, allergies, notes, photo, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, '', NOW())";
            $stmt = db()->prepare($sql);
            $stmt->execute([
                $client_id, $nom, $espece, $race, $sexe,
                $date_naissance, $sterilise, $identification, $allergies, $notes
            ]);
            $id = (int)db()->lastInsertId();

            // Photo (update après insert)
            $photoPath = null;
            if ($photo_b64) $photoPath = save_base64_image($photo_b64, $id);
            elseif ($photo_file) $photoPath = save_uploaded_file($photo_file, $id);
            if ($photoPath !== null) {
                $u = db()->prepare("UPDATE patients SET photo=? WHERE id=?");
                $u->execute([$photoPath, $id]);
            }
            redirect('index.php?page=patients&ok=created');
        }

        if ($action === 'update') {
            $sql = "UPDATE patients
                    SET client_id=?, nom=?, espece=?, race=?, sexe=?,
                        date_naissance=?, sterilise=?, identification=?,
                        allergies=?, notes=?, updated_at=NOW()
                    WHERE id=?";
            $stmt = db()->prepare($sql);
            $stmt->execute([
                $client_id, $nom, $espece, $race, $sexe,
                $date_naissance, $sterilise, $identification,
                $allergies, $notes, $id
            ]);

            // handle photo
            $cur = db()->prepare("SELECT photo FROM patients WHERE id=?");
            $cur->execute([$id]);
            $oldPhoto = $cur->fetchColumn();

            $newPath = null;
            if ($photo_b64) $newPath = save_base64_image($photo_b64, $id);
            elseif ($photo_file) $newPath = save_uploaded_file($photo_file, $id);

            if ($newPath !== null) {
                $u = db()->prepare("UPDATE patients SET photo=? WHERE id=?");
                $u->execute([$newPath, $id]);
                if ($oldPhoto && $oldPhoto !== $newPath) delete_patient_photo($oldPhoto);
            }
            redirect('index.php?page=patients&ok=updated');
        }
    }

    // ====== Delete patient (bloqué s'il a de l'historique) ======
    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);

        // refuse if has consultations or interventions
        $h = db()->prepare("
          SELECT
            (SELECT COUNT(*) FROM consultations WHERE patient_id=?) +
            (SELECT COUNT(*) FROM interventions WHERE patient_id=?)
        ");
        $h->execute([$id,$id]);
        $hasHistory = (int)$h->fetchColumn() > 0;

        if (!$hasHistory) {
            $cur = db()->prepare("SELECT photo FROM patients WHERE id=?");
            $cur->execute([$id]);
            $old = $cur->fetchColumn();
            if ($old) delete_patient_photo($old);

            $stmt = db()->prepare("DELETE FROM patients WHERE id=?");
            $stmt->execute([$id]);
            redirect('index.php?page=patients&ok=deleted');
        } else {
            redirect('index.php?page=patients&err=cannot_delete_has_history');
        }
    }

    // ====== Delete photo only ======
    if ($action === 'delete_photo') {
        $id = (int)($_POST['id'] ?? 0);
        $cur = db()->prepare("SELECT photo FROM patients WHERE id=?");
        $cur->execute([$id]);
        $old = $cur->fetchColumn();
        if ($old) {
            delete_patient_photo($old);
            $u = db()->prepare("UPDATE patients SET photo='' WHERE id=?");
            $u->execute([$id]);
        }
        redirect('index.php?page=patients&ok=photo_deleted');
    }
}

/* ========================= Data pour formulaires ========================= */
$clients = db()->query("SELECT id, prenom, nom FROM clients ORDER BY nom, prenom")->fetchAll();

/* ========================= Filtres / Recherche / GPS ========================= */
$q        = trim($_GET['q'] ?? '');
$esp      = trim($_GET['esp'] ?? '');
$gps      = (int)($_GET['gps'] ?? 0);
$radius   = (int)($_GET['r'] ?? 0); // km 1/5/10/15
$lat      = isset($_GET['lat']) ? (float)$_GET['lat'] : null;
$lng      = isset($_GET['lng']) ? (float)$_GET['lng'] : null;

$radius = in_array($radius, [1,5,10,15], true) ? $radius : 5;
$useGps = $gps === 1 && $lat !== null && $lng !== null;

// Espèces uniques
$speciesStmt = db()->query("SELECT DISTINCT espece FROM patients WHERE COALESCE(espece,'')<>'' ORDER BY espece");
$species = array_column($speciesStmt->fetchAll(), 'espece');

// Pagination params
[$limit, $offset, $page] = paginate_params(20, 200);

/* ========================= Build Query (positionnels) ========================= */
$whereParts = ['1=1'];
$params = [];

if ($esp !== '') {
    $whereParts[] = 'p.espece = ?';
    $params[] = $esp;
}
if ($q !== '') {
    $tokens = preg_split('/\s+/', $q, -1, PREG_SPLIT_NO_EMPTY);
    foreach ($tokens as $tok) {
        $like = '%'.$tok.'%';
        $whereParts[] = '('
          .'p.nom LIKE ? OR p.identification LIKE ? OR p.notes LIKE ? OR c.nom LIKE ? OR c.prenom LIKE ? '
          .'OR EXISTS (SELECT 1 FROM consultations co WHERE co.patient_id = p.id AND (co.motif LIKE ? OR co.diagnostic LIKE ?))'
        .')';
        $params[] = $like; // p.nom
        $params[] = $like; // p.identification
        $params[] = $like; // p.notes
        $params[] = $like; // c.nom
        $params[] = $like; // c.prenom
        $params[] = $like; // co.motif
        $params[] = $like; // co.diagnostic
    }
}
$whereSql = implode(' AND ', $whereParts);

$sqlAll = "SELECT
            p.id              AS patient_id,
            p.nom             AS patient_nom,
            p.espece          AS patient_espece,
            p.race            AS patient_race,
            p.sexe            AS patient_sexe,
            p.date_naissance  AS patient_date_naissance,
            p.identification  AS patient_identification,
            p.allergies       AS patient_allergies,
            p.notes           AS patient_notes,
            p.photo           AS patient_photo,
            p.sterilise       AS patient_sterilise,
            p.updated_at      AS patient_updated_at,
            p.created_at      AS patient_created_at,
            c.id              AS client_id,
            c.nom             AS client_nom,
            c.prenom          AS client_prenom,
            c.gsm             AS client_gsm,
            c.email           AS client_email,
            c.adresse         AS client_adresse,
            c.*
          FROM patients p
          JOIN clients c ON c.id = p.client_id
          WHERE $whereSql
          ORDER BY p.updated_at DESC, p.id DESC";
$stmtAll = db()->prepare($sqlAll);
$stmtAll->execute($params);
$allRows = $stmtAll->fetchAll();

/* ========================= Filtre GPS en PHP ========================= */
function client_latlng_candidates(): array {
    return [
        ['latitude','longitude'],
        ['gps_lat','gps_lng'],
        ['lat','lng'],
        ['lat','lon'],
        ['coord_lat','coord_lng'],
    ];
}
function row_client_coords(array $row): array {
    foreach (client_latlng_candidates() as [$la,$lo]) {
        if (array_key_exists($la,$row) && array_key_exists($lo,$row)
            && $row[$la] !== null && $row[$lo] !== '' && $row[$la] !== '' && $row[$lo] !== null) {
            return [floatval($row[$la]), floatval($row[$lo])];
        }
    }
    return [null, null];
}
function haversine_km(float $lat1, float $lon1, float $lat2, float $lon2): float {
    $R = 6371.0;
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    $a = sin($dLat/2)**2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon/2)**2;
    $c = 2 * atan2(sqrt($a), sqrt(1-$a));
    return $R * $c;
}

$filtered = [];
if ($useGps) {
    foreach ($allRows as $r) {
        [$cla, $clo] = row_client_coords($r);
        if ($cla === null || $clo === null) continue;
        $dist = haversine_km($lat, $lng, $cla, $clo);
        if ($dist <= $radius) {
            $r['distance_km'] = $dist;
            $filtered[] = $r;
        }
    }
    usort($filtered, function($a,$b){
        $da = ($a['distance_km'] ?? 0) <=> ($b['distance_km'] ?? 0);
        if ($da !== 0) return $da;
        return (strtotime($b['patient_updated_at'] ?? '1970-01-01') <=> strtotime($a['patient_updated_at'] ?? '1970-01-01'));
    });
} else {
    $filtered = $allRows;
}

/* Pagination */
$total = count($filtered);
$pages = max(1, (int)ceil($total / $limit));
$rows  = array_slice($filtered, ($page-1)*$limit, $limit);

/* ====== Pré-calcul : nb consultations + interventions par patient ====== */
$ids = array_column($rows, 'patient_id');
$countsByPid = [];
if ($ids) {
    $in = implode(',', array_fill(0, count($ids), '?'));
    $q1 = db()->prepare("SELECT patient_id, COUNT(*) n FROM consultations WHERE patient_id IN ($in) GROUP BY patient_id");
    $q1->execute($ids);
    foreach ($q1->fetchAll() as $r) $countsByPid[(int)$r['patient_id']]['c'] = (int)$r['n'];

    $q2 = db()->prepare("SELECT patient_id, COUNT(*) n FROM interventions WHERE patient_id IN ($in) GROUP BY patient_id");
    $q2->execute($ids);
    foreach ($q2->fetchAll() as $r) $countsByPid[(int)$r['patient_id']]['i'] = (int)$r['n'];
}

/* ========================= Utils ========================= */
function human_age(?string $birth): string {
    if (!$birth) return '—';
    try {
        $d = new DateTime($birth);
        $now = new DateTime();
        $diff = $d->diff($now);
        if ($diff->y > 0) return $diff->y.' an'.($diff->y>1?'s':'');
        if ($diff->m > 0) return $diff->m.' mois';
        return max(0,$diff->d).' j';
    } catch (Exception $e) { return '—'; }
}
?>
<div class="mb-3">
  <!-- Ligne 1 : titre + bouton nouveau patient -->
  <div class="d-flex align-items-center gap-2 mb-2">
    <h1 class="h4 mb-0">Patients</h1>
    <button id="btnNewPatient" type="button"
            class="btn btn-success ms-auto d-flex align-items-center gap-1 fw-semibold"
            onclick="if(typeof openWizardPatient==='function')openWizardPatient()">
      <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="currentColor" viewBox="0 0 16 16">
        <path d="M8 4a.5.5 0 0 1 .5.5v3h3a.5.5 0 0 1 0 1h-3v3a.5.5 0 0 1-1 0v-3h-3a.5.5 0 0 1 0-1h3v-3A.5.5 0 0 1 8 4z"/>
      </svg>
      <span class="d-none d-sm-inline">Nouveau patient</span>
      <span class="d-inline d-sm-none">Nouveau</span>
    </button>
  </div>

  <!-- Ligne 2 : recherche (pleine largeur sur mobile) -->
  <?php $useGpsFlag = $useGps; ?>
  <form method="get">
    <input type="hidden" name="page" value="patients">
    <div class="input-group input-group-lg shadow-sm">
      <span class="input-group-text bg-white border-end-0">
        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="#6c757d" viewBox="0 0 16 16">
          <path d="M11.742 10.344a6.5 6.5 0 1 0-1.397 1.398l3.85 3.85a1 1 0 0 0 1.415-1.415l-3.868-3.833zm-5.242 1.156a5.5 5.5 0 1 1 0-11 5.5 5.5 0 0 1 0 11z"/>
        </svg>
      </span>
      <input class="form-control border-start-0 ps-0 fs-6" name="q"
             placeholder="Rechercher un patient, puce, client…"
             value="<?= h($q) ?>" autofocus>
      <?php if ($q !== '' || $esp !== '' || $useGpsFlag): ?>
        <a class="btn btn-outline-secondary" href="index.php?page=patients" title="Réinitialiser">✕</a>
      <?php endif; ?>
    </div>

    <!-- Filtres secondaires : espèce + GPS (compacts, sous la barre) -->
    <div class="d-flex flex-wrap align-items-center gap-2 mt-2">
      <select name="esp" class="form-select form-select-sm" style="width:auto" onchange="this.form.submit()">
        <option value="">Toutes espèces</option>
        <?php foreach ($species as $sp): ?>
          <option value="<?= h($sp) ?>" <?= $esp===$sp?'selected':'' ?>><?= h($sp) ?></option>
        <?php endforeach; ?>
      </select>
      <div class="form-check form-switch mb-0">
        <input class="form-check-input" type="checkbox" role="switch" id="gpsSwitch" <?= $useGpsFlag?'checked':'' ?>>
        <label class="form-check-label" for="gpsSwitch">GPS</label>
      </div>
      <select id="radiusSelect" class="form-select form-select-sm" style="width:auto" <?= $useGpsFlag?'':'disabled' ?>>
        <?php foreach ([1,5,10,15] as $r): ?>
          <option value="<?= $r ?>" <?= $radius===$r?'selected':'' ?>><?= $r ?> km</option>
        <?php endforeach; ?>
      </select>
    </div>
  </form>
</div>

<?php if (isset($_GET['ok'])): ?>
  <div class="alert alert-success alert-dismissible fade show">
    Action réalisée avec succès.
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
  </div>
<?php elseif (isset($_GET['err']) && $_GET['err']==='cannot_delete_has_history'): ?>
  <div class="alert alert-warning alert-dismissible fade show">
    Impossible de supprimer : ce patient possède des consultations ou des interventions.
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
  </div>
<?php endif; ?>

<!-- Formulaire Création (include) -->
<div class="collapse <?= isset($_GET['showform']) ? 'show':'' ?>" id="patientForm">
  <?php include __DIR__ . '/../includes/n_patients.php'; ?>
</div>

<!-- Grille de cartes -->
<div class="card shadow-sm">
  <div class="card-body pb-0">
    <div class="d-flex justify-content-between align-items-center mb-2">
      <div class="text-muted"><?= $total ?> patient(s) — Page <?= $page ?></div>
      <?php if ($useGpsFlag): ?>
        <div class="badge bg-info text-dark">Filtré par GPS ≤ <?= (int)$radius ?> km</div>
      <?php endif; ?>
    </div>
  </div>

  <div class="p-3">
    <div id="cardsGrid" class="row g-3">
      <?php if (!$rows): ?>
        <div class="text-center text-muted py-4">Aucun patient</div>
      <?php else: foreach ($rows as $r): ?>
        <?php
          $pid = (int)$r['patient_id'];
          $nbC = (int)($countsByPid[$pid]['c'] ?? 0);
          $nbI = (int)($countsByPid[$pid]['i'] ?? 0);
          $hasHist = ($nbC + $nbI) > 0;
        ?>
        <div class="col-12 col-md-6 col-xl-4 patient-card-wrap" data-id="<?= h($pid) ?>">
          <div class="card patient-card h-100 shadow-sm">
            <div class="card-body">
              <div class="d-flex">
                <div class="me-3">
                  <?php $img = $r['patient_photo'] ?: 'assets/img/avatar_placeholder.webp'; ?>
                  <img src="<?= h($img) ?>" class="rounded-circle border" style="width:64px;height:64px;object-fit:cover" alt="">
                </div>
                <div class="flex-grow-1">
                  <div class="d-flex align-items-center justify-content-between">
                    <h2 class="h6 mb-0"><?= h($r['patient_nom']) ?></h2>
                    <!-- Edit (stylo) -->
                    <button type="button" class="btn btn-sm btn-outline-secondary" title="Éditer"
                       onclick='openWizardPatientEdit(<?= json_encode([
                         "id"             => $pid,
                         "client_id"      => $r["client_id"],
                         "client_label"   => trim(($r["client_nom"]??'').' '.($r["client_prenom"]??'')),
                         "nom"            => $r["patient_nom"],
                         "espece"         => $r["patient_espece"],
                         "race"           => $r["patient_race"],
                         "sexe"           => $r["patient_sexe"],
                         "date_naissance" => $r["patient_date_naissance"],
                         "identification" => $r["patient_identification"],
                         "sterilise"      => (int)$r["patient_sterilise"],
                         "allergies"      => $r["patient_allergies"],
                         "notes"          => $r["patient_notes"],
                         "photo"          => $r["patient_photo"],
                       ], JSON_UNESCAPED_UNICODE|JSON_HEX_QUOT|JSON_HEX_APOS) ?>)'>
                      ✏️
                    </button>
                  </div>
                  <div class="text-muted small mt-1">
                    <?= h(trim(($r['patient_espece']?:'').' / '.($r['patient_race']?:''),' /')) ?> &middot; <?= h($r['patient_sexe']) ?>
                  </div>
                  <div class="text-muted small">Client : <?= h($r['client_nom'].' '.$r['client_prenom']) ?></div>
                  <div class="text-muted small">Naissance : <?= h(format_birth_fr($r['patient_date_naissance'])) ?> (<?= h(human_age($r['patient_date_naissance'])) ?>)</div>

                  <div class="mt-3 d-grid gap-2">
                    <!-- Fiche pleine largeur -->
                    <a class="btn btn-primary"
                       href="index.php?page=patient_view&id=<?= h($pid) ?>">Fiche</a>

                    <div class="d-flex gap-2">
                      <!-- Détails (modale) -->
                      <button class="btn btn-outline-dark flex-grow-1 btn-details"
                              data-payload='<?= h(json_encode([
                                'patient_nom'=>$r['patient_nom'],
                                'photo'=>$r['patient_photo'],
                                'client_nom'=>$r['client_nom'],
                                'client_prenom'=>$r['client_prenom'],
                                'client_gsm'=>$r['client_gsm'],
                                'client_email'=>$r['client_email'],
                                'client_adresse'=>$r['client_adresse'],
                                'identification'=>$r['patient_identification'],
                                'allergies'=>$r['patient_allergies'],
                                'notes'=>$r['patient_notes'],
                              ], JSON_UNESCAPED_UNICODE)) ?>'>
                        Détails
                      </button>

                      <!-- Supprimer (grisé si historique) -->
                      <form method="post" onsubmit="return <?= $hasHist ? 'alert(\'Impossible : historique présent.\'), false' : 'confirm(\'Supprimer ce patient ?\')' ?>;">
                        <?= csrf_input() ?>
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?= h($pid) ?>">
                        <button class="btn <?= $hasHist ? 'btn-secondary disabled' : 'btn-outline-danger' ?>"
                                <?= $hasHist ? 'disabled aria-disabled="true" title="Historique présent ('.$nbC.' consult., '.$nbI.' interv.)"' : '' ?>>
                          Supprimer
                        </button>
                      </form>
                    </div>

                    <?php if ($hasHist): ?>
                      <div class="small text-muted mt-1">Historique : <?= $nbC ?> consultation(s), <?= $nbI ?> intervention(s)</div>
                    <?php endif; ?>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      <?php endforeach; endif; ?>
    </div>
  </div>

  <!-- Pagination -->
  <div class="card-footer d-flex justify-content-between align-items-center">
    <div class="text-muted">
      Page <?= $page ?> / <?= $pages ?>
    </div>
    <nav>
      <ul class="pagination mb-0">
        <?php
          $base = 'index.php?page=patients'
                .($q!=='' ? '&q='.urlencode($q) : '')
                .($esp!=='' ? '&esp='.urlencode($esp) : '')
                .($useGpsFlag ? '&gps=1&r='.$radius.'&lat='.urlencode((string)$lat).'&lng='.urlencode((string)$lng) : '');
          $prev = max(1, $page-1);
          $next = min($pages, $page+1);
        ?>
        <li class="page-item <?= $page<=1?'disabled':'' ?>"><a class="page-link" href="<?= $base.'&p='.$prev ?>">Précédent</a></li>
        <li class="page-item <?= $page>=$pages?'disabled':'' ?>"><a class="page-link" href="<?= $base.'&p='.$next ?>">Suivant</a></li>
      </ul>
    </nav>
  </div>
</div>

<!-- Modale Détails -->
<div class="modal fade" id="detailsModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="detailsTitle">Détails patient</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button>
      </div>
      <div class="modal-body">
        <div class="d-flex align-items-center mb-3">
          <img id="detailsPhoto" src="assets/img/avatar_placeholder.webp"
               class="rounded-circle border me-3" style="width:64px;height:64px;object-fit:cover" alt="">
          <div>
            <div id="detailsClient" class="small text-muted"></div>
            <div id="detailsAdresse" class="small text-muted"></div>
          </div>
        </div>
        <div class="small">
          <div><b>Identifiant :</b> <span id="detailsIdent">—</span></div>
          <div class="mt-1"><b>Allergies :</b> <span id="detailsAllergies">—</span></div>
          <div class="mt-1"><b>Notes :</b> <div id="detailsNotes" class="border rounded p-2 mt-1 bg-light">—</div></div>
        </div>
      </div>
      <div class="modal-footer">
        <a id="detailsCall" class="btn btn-primary" target="_blank">Appeler</a>
        <a id="detailsWa" class="btn btn-success" target="_blank">WhatsApp</a>
        <a id="detailsMail" class="btn btn-outline-secondary" target="_blank">Email</a>
        <button class="btn btn-light" data-bs-dismiss="modal">Fermer</button>
      </div>
    </div>
  </div>
</div>

<style>
.patient-card-wrap.expanded { order: -1; }
.patient-card-wrap.expanded .patient-card { border:2px solid #0e6ba8; }
@media (max-width: 576px) {
  .table { font-size: .9rem; }
  .table td, .table th { padding: .4rem .5rem; }
}
</style>

<script>
// ===== GPS switch / rayon (URL params) =====
(function(){
  const sw = document.getElementById('gpsSwitch');
  const sel = document.getElementById('radiusSelect');

  function updateUrlWithGps(lat, lng, r){
    const u = new URL(window.location.href);
    u.searchParams.set('page','patients');
    u.searchParams.set('gps','1');
    u.searchParams.set('lat', lat.toFixed(6));
    u.searchParams.set('lng', lng.toFixed(6));
    u.searchParams.set('r', r);
    window.location.href = u.toString();
  }
  function removeGps(){
    const u = new URL(window.location.href);
    u.searchParams.delete('gps');
    u.searchParams.delete('lat');
    u.searchParams.delete('lng');
    u.searchParams.delete('r');
    u.searchParams.set('page','patients');
    window.location.href = u.toString();
  }

  if (sw) {
    sw.addEventListener('change', () => {
      if (sw.checked) {
        if (!navigator.geolocation) { alert('La géolocalisation n’est pas supportée.'); sw.checked = false; return; }
        const r = parseInt(sel.value || '5', 10);
        navigator.geolocation.getCurrentPosition(
          pos => { sel.disabled = false; updateUrlWithGps(pos.coords.latitude, pos.coords.longitude, r); },
          err => { alert('Position indisponible : ' + err.message); sw.checked = false; },
          { enableHighAccuracy: true, timeout: 8000, maximumAge: 0 }
        );
      } else {
        sel.disabled = true;
        removeGps();
      }
    });
  }
  if (sel) {
    sel.addEventListener('change', () => {
      if (!sw.checked) return;
      const url = new URL(window.location.href);
      const lat = parseFloat(url.searchParams.get('lat'));
      const lng = parseFloat(url.searchParams.get('lng'));
      const r = parseInt(sel.value, 10);
      if (isFinite(lat) && isFinite(lng)) {
        updateUrlWithGps(lat, lng, r);
      } else if (navigator.geolocation) {
        navigator.geolocation.getCurrentPosition(
          pos => updateUrlWithGps(pos.coords.latitude, pos.coords.longitude, r),
          () => alert('Position indisponible.')
        );
      }
    });
  }
})();

// ===== Détails (modale) =====
(function(){
  const modal = document.getElementById('detailsModal');
  const title = document.getElementById('detailsTitle');
  const photo = document.getElementById('detailsPhoto');
  const client = document.getElementById('detailsClient');
  const addr   = document.getElementById('detailsAdresse');
  const ident  = document.getElementById('detailsIdent');
  const allerg = document.getElementById('detailsAllergies');
  const notes  = document.getElementById('detailsNotes');
  const call   = document.getElementById('detailsCall');
  const wa     = document.getElementById('detailsWa');
  const mail   = document.getElementById('detailsMail');

  document.addEventListener('click', (e) => {
    const btn = e.target.closest('.btn-details');
    if (!btn) return;
    const data = JSON.parse(btn.getAttribute('data-payload') || '{}');
    title.textContent = data.patient_nom || 'Détails patient';
    photo.src = data.photo || 'assets/img/avatar_placeholder.webp';
    client.textContent = (data.client_nom && data.client_prenom) ? `${data.client_nom} ${data.client_prenom}` : 'Client : —';
    addr.textContent   = data.client_adresse ? data.client_adresse : '';
    ident.textContent  = data.identification || '—';
    allerg.textContent = data.allergies || '—';
    notes.innerHTML    = data.notes ? (data.notes.replace(/\n/g,'<br>')) : '—';

    const phone = (data.client_gsm || '').replace(/\D+/g,'');
    if (phone) {
      call.href = `tel:${phone}`;
      wa.href   = `https://wa.me/${phone}`;
      call.classList.remove('disabled'); wa.classList.remove('disabled');
    } else {
      call.removeAttribute('href'); wa.removeAttribute('href');
      call.classList.add('disabled'); wa.classList.add('disabled');
    }
    if (data.client_email) {
      mail.href = `mailto:${data.client_email}`;
      mail.classList.remove('disabled');
    } else {
      mail.removeAttribute('href'); mail.classList.add('disabled');
    }

    new bootstrap.Modal(modal).show();
  });

  // Remplissage rapide du formulaire d’édition via le bouton stylo
/* --- Remplissage rapide du formulaire d’édition (bouton stylo) --- */
(function(){
  function isoToFr(dateIso){
    if (!dateIso || !/^\d{4}-\d{2}-\d{2}$/.test(dateIso)) return '';
    const [y, m, d] = dateIso.split('-');
    return `${d}/${m}/${y}`;
  }

  // helper pour retrouver le libellé du client à partir de son id
  function findClientLabelById(id){
    try {
      id = parseInt(id, 10);
      if (!Array.isArray(NPATIENT_CLIENTS)) return '';
      const m = NPATIENT_CLIENTS.find(c => parseInt(c.id,10) === id);
      return m ? (m.label || '') : '';
    } catch(e){ return ''; }
  }

  document.addEventListener('click', (e) => {
    const edit = e.target.closest('[data-edit-payload]');
    if (!edit) return;

    const p = JSON.parse(edit.getAttribute('data-edit-payload') || '{}');

    // Ouvre/affiche le formulaire
    const collapse = document.getElementById('patientForm');
    if (collapse && !collapse.classList.contains('show')) {
      // déclenche l’ouverture du collapse si besoin
      const trigger = document.querySelector('[href="#patientForm"][data-bs-toggle="collapse"]');
      if (trigger) trigger.click();
    }

    // Laisse le temps au DOM du collapse de s’afficher
    setTimeout(() => {
      const form = document.getElementById('npatient_form');
      if (!form) return;

      // Action + id
      form.querySelector('[name=action]').value = 'update';
      form.querySelector('[name=id]').value     = p.id || '';

      // -------- CLIENT (typeahead) ----------
      const cidHidden = document.getElementById('npatient_client_id');
      const cinp      = document.getElementById('npatient_client_input');
      const clabel    = findClientLabelById(p.client_id);
      if (cidHidden) cidHidden.value = p.client_id || '';
      if (cinp)      cinp.value      = clabel || '';

      // -------- CHAMPS PATIENT -------------
      form.querySelector('[name=nom]').value            = p.nom || '';
      form.querySelector('[name=espece]').value         = p.espece || '';
      form.querySelector('[name=race]').value           = p.race || '';
      form.querySelector('[name=sexe]').value           = p.sexe || 'INCONNU';
      form.querySelector('[name=identification]').value = p.identification || '';
      form.querySelector('[name=sterilise]').checked    = (p.sterilise == 1);
      form.querySelector('[name=allergies]').value      = p.allergies || '';
      form.querySelector('[name=notes]').value          = p.notes || '';

      // -------- NAISSANCE (date ou âge) -----
      // On pré-remplit en date ISO (ex: 2024-05-01) dans le champ libre
      const birthFree   = document.getElementById('npatient_naissance_free');
      const birthHidden = document.getElementById('npatient_date_naissance_hidden');
      const birthHint   = document.getElementById('npatient_naissance_hint');
      const dateIso     = (p.date_naissance || '').trim();
      if (birthFree)   birthFree.value   = isoToFr(dateIso);
      if (birthHidden) birthHidden.value = dateIso;
      if (birthHint && dateIso) birthHint.textContent = '→ Interprété comme date : ' + isoToFr(dateIso);

      // -------- PHOTO (aperçu) --------------
      const prev = document.getElementById('npatient_photo_preview');
      if (prev) prev.src = p.photo || 'assets/img/avatar_placeholder.webp';
      const b64 = document.getElementById('npatient_photo_b64');
      if (b64) b64.value = ''; // on ne touche pas au fichier, on laisse vide tant que l’utilisateur ne change pas

      // Scroll vers le formulaire
      document.getElementById('patientForm')?.scrollIntoView({behavior:'smooth', block:'start'});
    }, 120);
  });
})();
})();
</script>
<script>
/* --- Ouvrir le formulaire et le pré-remplir de façon fiable --- */
(function(){
  function isoToFr(dateIso){
    if (!dateIso || !/^\d{4}-\d{2}-\d{2}$/.test(dateIso)) return '';
    const [y, m, d] = dateIso.split('-');
    return `${d}/${m}/${y}`;
  }

  function findClientLabelById(id){
    try{
      id = parseInt(id,10);
      if (!Array.isArray(NPATIENT_CLIENTS)) return '';
      const m = NPATIENT_CLIENTS.find(c => parseInt(c.id,10) === id);
      return m ? (m.label || '') : '';
    }catch(e){ return ''; }
  }

  function fillForm(p){
    const form = document.getElementById('npatient_form');
    if (!form) return;

    // mode update
    form.querySelector('[name=action]').value = 'update';
    form.querySelector('[name=id]').value     = p.id || '';

    // client (typeahead)
    const cidHidden = document.getElementById('npatient_client_id');
    const cinp      = document.getElementById('npatient_client_input');
    const clabel    = findClientLabelById(p.client_id);
    if (cidHidden) cidHidden.value = p.client_id || '';
    if (cinp)      cinp.value      = clabel || '';

    // champs
    form.querySelector('[name=nom]').value            = p.nom || '';
    form.querySelector('[name=espece]').value         = p.espece || '';
    form.querySelector('[name=race]').value           = p.race || '';
    form.querySelector('[name=sexe]').value           = p.sexe || 'INCONNU';
    form.querySelector('[name=identification]').value = p.identification || '';
    form.querySelector('[name=sterilise]').checked    = (p.sterilise == 1);
    form.querySelector('[name=allergies]').value      = p.allergies || '';
    form.querySelector('[name=notes]').value          = p.notes || '';

    // naissance (champ libre + hidden)
    const birthFree   = document.getElementById('npatient_naissance_free');
    const birthHidden = document.getElementById('npatient_date_naissance_hidden');
    const birthHint   = document.getElementById('npatient_naissance_hint');
    const dateIso     = (p.date_naissance || '').trim();
    if (birthFree)   birthFree.value   = isoToFr(dateIso);
    if (birthHidden) birthHidden.value = dateIso;
    if (birthHint)   birthHint.textContent = dateIso ? '→ Interprété comme date : ' + isoToFr(dateIso) : 'Exemples : 01/05/2024 • 6 • 6m • 6 mois';

    // photo preview
    const prev = document.getElementById('npatient_photo_preview');
    if (prev) prev.src = p.photo || 'assets/img/avatar_placeholder.webp';
    const b64 = document.getElementById('npatient_photo_b64');
    if (b64) b64.value = '';
  }

  document.addEventListener('click', (e) => {
    const btn = e.target.closest('[data-edit-payload]');
    if (!btn) return;

    // 1) empêcher la navigation (sinon reload et form vide)
    e.preventDefault();
    e.stopPropagation();

    const p = JSON.parse(btn.getAttribute('data-edit-payload') || '{}');
    const wrap = document.getElementById('patientForm');
    if (!wrap) return;

    // 2) ouvrir le collapse via l’API
    const collapse = bootstrap.Collapse.getOrCreateInstance(wrap, {toggle:false});
    const isOpen = wrap.classList.contains('show');

    if (isOpen) {
      // déjà ouvert → on remplit directement
      fillForm(p);
      wrap.scrollIntoView({behavior:'smooth', block:'start'});
    } else {
      // pas encore ouvert → on attend l’événement "shown" UNE seule fois
      const once = () => {
        wrap.removeEventListener('shown.bs.collapse', once);
        fillForm(p);
        wrap.scrollIntoView({behavior:'smooth', block:'start'});
      };
      wrap.addEventListener('shown.bs.collapse', once, {once:true});
      collapse.show();
    }
  });
})();
</script>
<script>
// Remet le formulaire en mode "create" et vide tous les champs
(function(){
  function resetNewPatientForm(){
    const form = document.getElementById('npatient_form');
    if (!form) return;

    // mode création
    form.querySelector('[name=action]').value = 'create';
    const idEl = form.querySelector('[name=id]'); if (idEl) idEl.value = '';

    // client (typeahead)
    const cidHidden = document.getElementById('npatient_client_id'); if (cidHidden) cidHidden.value = '';
    const cinp      = document.getElementById('npatient_client_input'); if (cinp) cinp.value = '';
    const clist     = document.getElementById('npatient_client_list'); if (clist) { clist.innerHTML=''; clist.classList.add('d-none'); }
    const chelp     = document.getElementById('npatient_client_help'); if (chelp) chelp.textContent = 'Sélectionnez un client dans la liste (obligatoire).';

    // champs patient
    const set = (sel,v='') => { const el=form.querySelector(sel); if (el) el.value=v; };
    set('[name=nom]'); set('[name=espece]'); set('[name=race]'); set('[name=identification]');
    set('[name=allergies]'); set('[name=notes]');
    set('[name=sexe]','INCONNU');
    const ster = form.querySelector('[name=sterilise]'); if (ster) ster.checked = false;

    // naissance (champ libre + hidden + hint)
    const birthFree   = document.getElementById('npatient_naissance_free'); if (birthFree) birthFree.value = '';
    const birthHidden = document.getElementById('npatient_date_naissance_hidden'); if (birthHidden) birthHidden.value = '';
    const birthHint   = document.getElementById('npatient_naissance_hint');
    if (birthHint) birthHint.textContent = 'Exemples : 01/05/2024 • 6 • 6m • 6 mois';

    // photo
    const prev = document.getElementById('npatient_photo_preview');
    if (prev) prev.src = 'assets/img/avatar_placeholder.webp';
    const b64 = document.getElementById('npatient_photo_b64'); if (b64) b64.value = '';
    const file = document.getElementById('npatient_photo_file'); if (file) file.value = '';
  }

  // btnNewPatient now opens wizard; reset is handled by the wizard itself
})();
</script>
<?php include __DIR__ . '/../includes/wizard_patient.php'; ?>
