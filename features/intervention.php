<?php
// features/intervention.php — VETLINK
declare(strict_types=1);

/* Dépendances */
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_login();
csrf_check();

/* Contexte */
$patient_id      = isset($_GET['patient_id']) ? (int)$_GET['patient_id'] : 0;
$intervention_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

/* Patient */
if ($intervention_id && !$patient_id) {
  $tmp = db()->prepare("SELECT patient_id FROM interventions WHERE id=?");
  $tmp->execute([$intervention_id]);
  $pid = (int)($tmp->fetchColumn() ?: 0);
  if ($pid) $patient_id = $pid;
}
$st = db()->prepare("
  SELECT p.id, p.nom AS patient_nom, p.espece, c.nom AS client_nom
  FROM patients p
  LEFT JOIN clients c ON c.id = p.client_id
  WHERE p.id=?
");
$st->execute([$patient_id]);
$patient = $st->fetch();
if (!$patient) { redirect('index.php?page=patients'); exit; }

/* Types existants (suggestions datalist, sans doublons) */
$types_existants = [];
$ts = db()->prepare("SELECT DISTINCT TRIM(type_acte) AS t FROM interventions WHERE type_acte IS NOT NULL AND TRIM(type_acte) <> '' ORDER BY t ASC");
$ts->execute();
foreach ($ts->fetchAll(PDO::FETCH_COLUMN) as $t) {
  $types_existants[] = (string)$t;
}

/* Dossier upload */
if (function_exists('ensure_uploads_dir')) {
  $storeRel = ensure_uploads_dir(); // ex: uploads/interventions/{patient_id}
} else {
  $baseRel  = 'uploads/interventions/' . $patient_id;
  $storeRel = $intervention_id > 0 ? ($baseRel . '/int_' . $intervention_id) : $baseRel;
}
$root   = dirname(__DIR__, 1);
$absDir = rtrim($root, '/\\') . '/' . ltrim($storeRel, '/');
if (!is_dir($absDir)) { @mkdir($absDir, 0775, true); @chmod($absDir, 0775); }

/* Module capture (intact) */
require_once __DIR__ . '/../includes/capture.php';

/* Chargement intervention si édition */
$interv = null;
if ($intervention_id > 0) {
  $q = db()->prepare("SELECT * FROM interventions WHERE id=? AND patient_id=?");
  $q->execute([$intervention_id, $patient_id]);
  $interv = $q->fetch();
  if (!$interv) { redirect('index.php?page=intervention&patient_id='.(int)$patient_id); exit; }
}

/* POST */
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $act = $_POST['act'] ?? 'save';

  /* Supprimer */
  if ($act === 'delete' && $intervention_id > 0) {
    if (!empty($interv['fichiers'])) {
      $arr = json_decode((string)$interv['fichiers'], true);
      if (is_array($arr)) {
        foreach ($arr as $it) {
          $rel = $it['path'] ?? ''; if (!$rel) continue;
          $abs = realpath($root . '/' . ltrim($rel, '/'));
          $uploadsRoot = str_replace('\\','/',$root.'/uploads/');
          if ($abs && str_starts_with(str_replace('\\','/',$abs), $uploadsRoot) && is_file($abs)) { @unlink($abs); }
        }
      }
    }
    $del = db()->prepare("DELETE FROM interventions WHERE id=? AND patient_id=?");
    $del->execute([$intervention_id, $patient_id]);
    redirect('index.php?page=intervention&patient_id='.(int)$patient_id);
    exit;
  }

  /* Annuler = purge des fichiers de la session */
  if ($act === 'cancel') {
    $session_raw = $_POST['fichiers_json'] ?? '[]';
    $arr = json_decode($session_raw, true);
    if (is_array($arr)) {
      foreach ($arr as $it) {
        $rel = $it['path'] ?? ''; if (!$rel) continue;
        $abs = realpath($root . '/' . ltrim($rel, '/'));
        $uploadsRoot = str_replace('\\','/',$root.'/uploads/');
        if ($abs && str_starts_with(str_replace('\\','/',$abs), $uploadsRoot) && is_file($abs)) { @unlink($abs); }
      }
    }
    redirect('index.php?page=patient_view&id='.(int)$patient_id);
    exit;
  }

  /* Champs */
  $type_acte          = trim($_POST['type_acte'] ?? '');
  $date_intervention  = trim($_POST['date_intervention'] ?? '');
  $details            = trim($_POST['details'] ?? '');
  $prix               = trim($_POST['prix'] ?? '0');
  $prochaine_echeance = trim($_POST['prochaine_echeance'] ?? '');
  $deuxieme_echeance  = trim($_POST['deuxieme_echeance'] ?? '');
  $recurrence_months  = (int)($_POST['recurrence_months'] ?? 0);
  if ($recurrence_months < 0) $recurrence_months = 0;
  if ($recurrence_months > 24) $recurrence_months = 24;

  /* Fichiers (UI) */
  $fichiers_raw = $_POST['fichiers_json'] ?? '[]';
  $files = json_decode($fichiers_raw, true);
  if (!is_array($files)) $files = [];

  $clean_files = [];
  foreach ($files as $it) {
    if (!is_array($it)) continue;
    $t = isset($it['type']) ? (string)$it['type'] : 'Autre';
    $p = isset($it['path']) ? (string)$it['path'] : '';
    if (!$p) continue;
    if (!in_array($t, ['Ordonance','Imagerie','Analyse','Autre'], true)) $t = 'Autre';
    $abs = realpath($root . '/' . ltrim($p, '/'));
    $uploadsRoot = str_replace('\\','/',$root.'/uploads/');
    if (!$abs || !str_starts_with(str_replace('\\','/',$abs), $uploadsRoot)) continue;
    $clean_files[] = ['type'=>$t, 'path'=>$p];
  }
  $fichiers_json = json_encode($clean_files, JSON_UNESCAPED_SLASHES);

  /* Validations + formats */
  if ($type_acte === '') $errors[] = "Le type d'acte est requis.";
  $prix_val = (float)str_replace(',', '.', $prix);
  if (!is_finite($prix_val)) $prix_val = 0.0;

  $date_sql = null;
  if ($date_intervention !== '') {
    $ts = strtotime($date_intervention);
    if ($ts) $date_sql = date('Y-m-d H:i:s', $ts); else $errors[] = "Date d'intervention invalide.";
  }
  $echeance1_sql = null;
  if ($prochaine_echeance !== '') {
    $t1 = strtotime($prochaine_echeance.' 00:00:00');
    if ($t1) $echeance1_sql = date('Y-m-d', $t1); else $errors[] = "Prochaine échéance invalide.";
  }
  $echeance2_sql = null;
  if ($deuxieme_echeance !== '') {
    $t2 = strtotime($deuxieme_echeance.' 00:00:00');
    if ($t2) $echeance2_sql = date('Y-m-d', $t2); else $errors[] = "Deuxième échéance invalide.";
  }
  $recurrence_sql = $recurrence_months > 0 ? (string)$recurrence_months : '0';

  /* Persistance */
  if (!$errors) {
    if ($intervention_id > 0) {
      $u = db()->prepare("
        UPDATE interventions
           SET type_acte=?, date_intervention=?, details=?, prix=?,
               prochaine_echeance=?, deuxieme_echeance=?, recurrence=?, statut_rappel=?,
               fichiers=?
         WHERE id=? AND patient_id=?
      ");
      $u->execute([
        $type_acte, $date_sql, $details, $prix_val,
        $echeance1_sql, $echeance2_sql, $recurrence_sql, 'A_PROGRAMMER',
        $fichiers_json, $intervention_id, $patient_id
      ]);
    } else {
      $i = db()->prepare("
        INSERT INTO interventions
          (patient_id, type_acte, date_intervention, details, prix,
           prochaine_echeance, deuxieme_echeance, recurrence, statut_rappel, fichiers, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
      ");
      $i->execute([
        $patient_id, $type_acte, $date_sql, $details, $prix_val,
        $echeance1_sql, $echeance2_sql, $recurrence_sql, 'A_PROGRAMMER', $fichiers_json
      ]);
    }
    redirect('index.php?page=intervention&patient_id='.(int)$patient_id);
    exit;
  }
}

/* Valeurs form (date par défaut = maintenant) */
$nowLocal = date('Y-m-d\TH:i');
$val = [
  'type_acte'          => $interv['type_acte']          ?? '',
  'date_intervention'  => !empty($interv['date_intervention']) ? date('Y-m-d\TH:i', strtotime($interv['date_intervention'])) : $nowLocal,
  'details'            => $interv['details']            ?? '',
  'prix'               => isset($interv['prix']) ? (string)(float)$interv['prix'] : '0',
  'prochaine_echeance' => !empty($interv['prochaine_echeance']) ? date('Y-m-d', strtotime($interv['prochaine_echeance'])) : '',
  'deuxieme_echeance'  => !empty($interv['deuxieme_echeance']) ? date('Y-m-d', strtotime($interv['deuxieme_echeance'])) : '',
  'recurrence_months'  => isset($interv['recurrence']) && is_numeric($interv['recurrence']) ? min(24, max(0, (int)$interv['recurrence'])) : 0,
  'fichiers'           => []
];
if (!empty($interv['fichiers'])) {
  $arr = json_decode((string)$interv['fichiers'], true);
  if (is_array($arr)) {
    foreach ($arr as $it) {
      if (is_array($it) && isset($it['path'], $it['type']) && is_string($it['path'])) {
        $t = in_array($it['type'], ['Ordonance','Imagerie','Analyse','Autre'], true) ? $it['type'] : 'Autre';
        $val['fichiers'][] = ['type'=>$t, 'path'=>(string)$it['path']];
      }
    }
  }
}

/* Pagination + liste */
$PER_PAGE = 10;
$page = max(1, (int)($_GET['p'] ?? 1));
$offset = ($page - 1) * $PER_PAGE;

$cnt = db()->prepare("SELECT COUNT(*) FROM interventions WHERE patient_id=?");
$cnt->execute([$patient_id]);
$total_rows = (int)$cnt->fetchColumn();
$total_pages = max(1, (int)ceil($total_rows / $PER_PAGE));

$list = db()->prepare("
  SELECT *
  FROM interventions
  WHERE patient_id=?
  ORDER BY COALESCE(date_intervention, created_at) DESC, id DESC
  LIMIT $PER_PAGE OFFSET $offset
");
$list->execute([$patient_id]);
$rows = $list->fetchAll();

/* Injection capture (debug ON) */
capture_handle($storeRel, [
  'modal_id' => 'capIntervModal',
  'maxWidth' => 1600,
  'quality'  => 0.9,
  'debug'    => (APP_ENV === 'dev' ? 1 : 0),
]);
echo '<script>window.STORE_DIR = ' . json_encode($storeRel) . ';</script>';
?>
<div class="container py-3">

  <div class="d-flex align-items-center justify-content-between mb-3">
    <div>
      <h1 class="h4 mb-0">Interventions — <?= h($patient['patient_nom']) ?></h1>
      <div class="text-muted small">Client : <span class="fw-semibold"><?= h($patient['client_nom'] ?? '—') ?></span></div>
    </div>
    <a class="btn btn-sm btn-secondary" href="index.php?page=patient_view&id=<?= (int)$patient_id ?>">← Retour</a>
  </div>

  <?php if ($errors): ?>
    <div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul></div>
  <?php endif; ?>

  <!-- FORM -->
  <div class="card shadow-sm mb-4">
    <div class="card-body">
      <form method="post" id="intervForm">
        <?= csrf_input() ?>
        <input type="hidden" name="fichiers_json" id="fichiersJson">

        <div class="row g-3">
          <div class="col-lg-4">
            <label class="form-label fw-semibold">Type d'acte</label>
            <input name="type_acte" class="form-control" value="<?= h($val['type_acte']) ?>" list="typesActe" placeholder="Ex: Vaccin, Echographie…">
            <datalist id="typesActe">
              <?php foreach ($types_existants as $t): ?>
                <option value="<?= h($t) ?>"></option>
              <?php endforeach; ?>
            </datalist>
          </div>

          <div class="col-lg-4">
            <label class="form-label fw-semibold">Date intervention</label>
            <input id="dateInterv" type="datetime-local" name="date_intervention" class="form-control" value="<?= h($val['date_intervention']) ?>">
          </div>

          <div class="col-lg-4">
            <label class="form-label fw-semibold">Prix</label>
            <div class="input-group">
              <input type="number" step="0.01" min="0" name="prix" class="form-control" value="<?= h($val['prix']) ?>">
              <span class="input-group-text">MAD</span>
            </div>
          </div>

          <!-- Échéance 1 : reset + −1m/+1m -->
          <div class="col-md-4 col-12">
            <label class="form-label fw-semibold">Prochaine échéance</label>
            <div class="input-group">
              <span class="input-group-text p-0">
                <div class="btn-group btn-group-sm" role="group" aria-label="echeance1">
                  <button type="button" class="btn btn-outline-secondary" id="e1Reset" title="Réinitialiser">⟲</button>
                  <button type="button" class="btn btn-outline-secondary" id="e1Minus">−1m</button>
                  <button type="button" class="btn btn-outline-primary"   id="e1Plus">+1m</button>
                </div>
              </span>
              <input id="echeance1" type="date" name="prochaine_echeance" class="form-control" value="<?= h($val['prochaine_echeance']) ?>" placeholder="YYYY-MM-DD">
              <span class="input-group-text" id="e1Badge" style="min-width:58px;justify-content:center;">0m</span>
            </div>
          </div>

          <!-- Échéance 2 : reset + −1m/+1m -->
          <div class="col-md-4 col-12">
            <label class="form-label fw-semibold">Deuxième échéance</label>
            <div class="input-group">
              <span class="input-group-text p-0">
                <div class="btn-group btn-group-sm" role="group" aria-label="echeance2">
                  <button type="button" class="btn btn-outline-secondary" id="e2Reset" title="Réinitialiser">⟲</button>
                  <button type="button" class="btn btn-outline-secondary" id="e2Minus">−1m</button>
                  <button type="button" class="btn btn-outline-primary"   id="e2Plus">+1m</button>
                </div>
              </span>
              <input id="echeance2" type="date" name="deuxieme_echeance" class="form-control" value="<?= h($val['deuxieme_echeance']) ?>" placeholder="YYYY-MM-DD">
              <span class="input-group-text" id="e2Badge" style="min-width:58px;justify-content:center;">0m</span>
            </div>
          </div>

          <!-- Récurrence -->
          <div class="col-md-4 col-12">
            <label class="form-label fw-semibold">Récurrence (mois)</label>
            <div class="d-flex gap-2 align-items-center">
              <div class="btn-group btn-group-sm flex-shrink-0" role="group" aria-label="recurrence stepper">
                <button type="button" class="btn btn-outline-secondary" id="recMinus">−</button>
                <button type="button" class="btn btn-outline-primary"   id="recPlus">+</button>
              </div>
              <input id="recMonths" type="number" min="0" max="24" step="1" name="recurrence_months" class="form-control" value="<?= (int)$val['recurrence_months'] ?>" style="max-width:110px;">
              <span id="recPreview" class="badge text-bg-light d-none"></span>
            </div>
          </div>

          <div class="col-12">
            <label class="form-label fw-semibold">Détails</label>
            <textarea name="details" rows="3" class="form-control" placeholder="Notes, observations, consignes…"><?= h($val['details']) ?></textarea>
          </div>
        </div>

        <hr class="my-4">

        <!-- Pièces jointes -->
        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
          <h2 class="h6 mb-0">Pièces jointes</h2>
          <div class="d-flex align-items-center gap-2 ms-auto flex-wrap">
            <div class="next-type-wrap">
              <select id="nextType" class="form-select form-select-sm next-type">
                <option>Ordonance</option>
                <option>Imagerie</option>
                <option>Analyse</option>
                <option>Autre</option>
              </select>
            </div>
            <button type="button" id="btnAddFile" class="btn btn-sm btn-primary"
                    onclick="openCaptureModal({ onDone: (paths)=>onCaptureDone(paths) })">
              <i class="bi bi-plus-lg me-1"></i> Ajouter
            </button>
          </div>
        </div>

        <div id="filesZone" class="row g-3 mt-1"></div>

        <div class="mt-4 d-flex gap-2">
          <button class="btn btn-success" name="act" value="save"><i class="bi bi-check2-circle me-1"></i> Enregistrer</button>
          <button class="btn btn-outline-secondary" name="act" value="cancel"><i class="bi bi-x-circle me-1"></i> Annuler</button>
          <?php if ($interv): ?>
            <button class="btn btn-outline-danger ms-auto" name="act" value="delete" onclick="return confirm('Supprimer cette intervention ?');">
              <i class="bi bi-trash3 me-1"></i> Supprimer
            </button>
          <?php endif; ?>
        </div>
      </form>
    </div>
  </div>

  <!-- HISTORIQUE -->
  <div class="card border-0 shadow-sm">
    <div class="card-header bg-white d-flex justify-content-between align-items-center">
      <div class="d-flex align-items-center gap-2">
        <span class="fw-semibold">Historique des interventions</span>
        <span class="badge text-bg-light"><?= (int)$total_rows ?></span>
      </div>
      <span class="text-muted small">Page <?= (int)$page ?>/<?= (int)$total_pages ?></span>
    </div>

    <div class="list-group list-group-flush">
      <?php foreach ($rows as $r): ?>
        <?php
          $dateLbl = !empty($r['date_intervention'])
            ? date('d/m/Y H:i', strtotime($r['date_intervention']))
            : (!empty($r['created_at']) ? date('d/m/Y H:i', strtotime($r['created_at'])) : '—');

          $items = [];
          if (!empty($r['fichiers'])) {
            $decoded = json_decode((string)$r['fichiers'], true);
            if (is_array($decoded)) {
              foreach ($decoded as $it) {
                if (is_array($it) && isset($it['path'], $it['type']) && is_string($it['path'])) $items[] = $it;
              }
            }
          }
          $rid = (int)$r['id'];
        ?>
        <div class="list-group-item py-3">
          <div class="d-flex align-items-start gap-2">
            <div class="me-2">
              <span class="badge rounded-pill text-bg-secondary"><?= h($r['type_acte'] ?: '—') ?></span>
            </div>

            <div class="flex-grow-1">
              <div class="d-flex align-items-center gap-2 flex-wrap">
                <span class="text-muted small"><i class="bi bi-calendar2-week me-1"></i><?= h($dateLbl) ?></span>

                <?php if (!empty($r['prochaine_echeance'])): ?>
                  <span class="badge bg-warning-subtle text-dark border border-warning-subtle">
                    Éch.1 <?= h(date('d/m', strtotime($r['prochaine_echeance']))) ?>
                  </span>
                <?php endif; ?>
                <?php if (!empty($r['deuxieme_echeance'])): ?>
                  <span class="badge bg-warning-subtle text-dark border border-warning-subtle">
                    Éch.2 <?= h(date('d/m', strtotime($r['deuxieme_echeance']))) ?>
                  </span>
                <?php endif; ?>
                <?php if (!empty($r['recurrence']) && (int)$r['recurrence'] > 0): ?>
                  <span class="badge bg-info-subtle text-info border border-info-subtle">
                    Récurrence <?= (int)$r['recurrence'] ?>m
                  </span>
                <?php endif; ?>

                <div class="ms-auto d-flex align-items-center gap-2">
                  <?php if (!empty($r['prix']) && (float)$r['prix'] > 0): ?>
                    <span class="badge bg-success-subtle text-success border border-success-subtle">
                      <i class="bi bi-cash-coin me-1"></i><?= h(number_format((float)$r['prix'], 2, '.', ' ')) ?> MAD
                    </span>
                  <?php endif; ?>

                  <a class="btn btn-sm btn-outline-secondary" href="index.php?page=intervention&patient_id=<?= (int)$patient_id ?>&id=<?= (int)$r['id'] ?>">
                    <i class="bi bi-pencil-square"></i>
                  </a>
                  <form method="post" class="d-inline" onsubmit="return confirm('Supprimer cette intervention ?');">
                    <?= csrf_input() ?>
                    <input type="hidden" name="act" value="delete">
                    <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                    <button class="btn btn-sm btn-outline-danger" type="submit"><i class="bi bi-trash3"></i></button>
                  </form>
                </div>
              </div>

              <?php if (!empty($r['details'])): ?>
                <div class="mt-2">
                  <a class="small text-decoration-none" data-bs-toggle="collapse" href="#det<?= $rid ?>" role="button" aria-expanded="false" aria-controls="det<?= $rid ?>">
                    Voir +
                  </a>
                  <div class="collapse mt-2" id="det<?= $rid ?>">
                    <div class="text-muted small"><?= nl2br(h($r['details'])) ?></div>
                  </div>
                </div>
              <?php endif; ?>

              <?php if ($items): ?>
                <div class="d-flex flex-wrap gap-2 mt-3">
                  <?php foreach ($items as $it):
                    $p = (string)$it['path']; $t = (string)$it['type'];
                    $ext = strtolower(pathinfo($p, PATHINFO_EXTENSION));
                    $isImg = in_array($ext, ['jpg','jpeg','png','gif','webp']);
                    $isPdf = ($ext === 'pdf');
                  ?>
                    <button type="button" class="btn btn-light border d-flex align-items-center gap-2 py-1 px-2"
                            data-viewer-path="<?= h($p) ?>" data-viewer-type="<?= h($t) ?>" onclick="openSmartModal(this)">
                      <?php if ($isImg): ?>
                        <img src="<?= h($p) ?>" alt="" style="width:44px;height:44px;object-fit:cover;border-radius:8px;">
                      <?php elseif ($isPdf): ?>
                        <span class="bi bi-file-earmark-pdf" style="font-size:24px"></span>
                      <?php else: ?>
                        <span class="bi bi-file-earmark" style="font-size:24px"></span>
                      <?php endif; ?>
                      <span class="small"><?= h($t) ?></span>
                    </button>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>

    <?php if ($total_pages > 1): ?>
      <nav class="mt-3">
        <ul class="pagination pagination-sm mb-0">
          <?php $base = 'index.php?page=intervention&patient_id='.(int)$patient_id;
          for ($i=1;$i<=$total_pages;$i++): ?>
            <li class="page-item <?= $i===$page?'active':'' ?>">
              <a class="page-link" href="<?= $base.'&p='.$i ?>"><?= $i ?></a>
            </li>
          <?php endfor; ?>
        </ul>
      </nav>
    <?php endif; ?>
  </div>
</div>

<!-- Smart Viewer -->
<div class="modal fade" id="smartViewer" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header py-2">
        <h5 class="modal-title" id="smartViewerTitle">Aperçu</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button>
      </div>
      <div class="modal-body p-0">
        <div id="smartViewerBody" class="w-100" style="min-height:60vh;display:flex;align-items:center;justify-content:center;background:#111"></div>
      </div>
    </div>
  </div>
</div>

<style>
.file-card{border:1px solid #e5e7eb;border-radius:12px;padding:.6rem .75rem;display:flex;gap:.75rem;align-items:center;background:#fff}
.file-card .thumb{width:68px;height:68px;border-radius:10px;object-fit:cover;background:#f8f9fa;display:flex;align-items:center;justify-content:center}
.file-card .meta{display:flex;flex-direction:column;gap:.25rem}
.file-card .meta .tag{font-size:.8rem}
.file-card .btn-remove{margin-left:auto}
@media (max-width:576px){
  .file-card .thumb{width:56px;height:56px}
}

/* Select "Type" (joindre) : pill + compact, aligné à droite, mobile-friendly */
.next-type-wrap{position:relative}
.next-type{min-width:150px;border-radius:999px;padding-inline:0.75rem;background:#f8fafc;border:1px solid #e5e7eb}
.next-type:focus{border-color:var(--bs-primary);box-shadow:0 0 0 .2rem rgba(13,110,253,.15)}
</style>

<script>
/* ===== Helpers dates ===== */
function pad2(n){return String(n).padStart(2,'0');}
function ymd(dt){return dt.getFullYear()+'-'+pad2(dt.getMonth()+1)+'-'+pad2(dt.getDate());}
function addMonthsKeepDom(dateStr, delta){
  if(!dateStr) return '';
  const d = new Date(dateStr); if (isNaN(d.getTime())) return '';
  d.setMonth(d.getMonth() + delta);
  return ymd(d);
}
function baseForSecond(){ // priorité: Éch.1 > Date intervention
  const e1 = document.getElementById('echeance1').value;
  if (e1) return e1;
  const di = document.getElementById('dateInterv').value;
  return di ? di.split('T')[0] : ymd(new Date());
}
function baseForRec(){ // priorité: Éch.2 > Éch.1 > Date intervention
  const e2 = document.getElementById('echeance2').value;
  if (e2) return e2;
  const e1 = document.getElementById('echeance1').value;
  if (e1) return e1;
  const di = document.getElementById('dateInterv').value;
  return di ? di.split('T')[0] : ymd(new Date());
}

/* ===== Échéance 1/2 : −1m/+1m + reset + badge compteur ===== */
let e1Shift = 0, e2Shift = 0;
function refreshE1Badge(){ document.getElementById('e1Badge').textContent = (e1Shift>=0?'+':'') + e1Shift + 'm'; }
function refreshE2Badge(){ document.getElementById('e2Badge').textContent = (e2Shift>=0?'+':'') + e2Shift + 'm'; }

function applyE1(delta){
  e1Shift += delta;
  const input = document.getElementById('echeance1');
  const origin = input.value || (document.getElementById('dateInterv').value ? document.getElementById('dateInterv').value.split('T')[0] : ymd(new Date()));
  input.value = addMonthsKeepDom(origin, delta);
  refreshE1Badge();
}
function applyE2(delta){
  e2Shift += delta;
  const base = baseForSecond(); // E2 par rapport à E1 si dispo, sinon DI
  const input = document.getElementById('echeance2');
  const origin = input.value || base;
  input.value = addMonthsKeepDom(origin, delta);
  refreshE2Badge();
}
function resetE1(){
  e1Shift = 0;
  const di = document.getElementById('dateInterv').value;
  document.getElementById('echeance1').value = di ? di.split('T')[0] : '';
  refreshE1Badge();
}
function resetE2(){
  e2Shift = 0;
  document.getElementById('echeance2').value = baseForSecond() || '';
  refreshE2Badge();
}

/* Bind boutons échéances */
document.getElementById('e1Minus').addEventListener('click', ()=>applyE1(-1));
document.getElementById('e1Plus') .addEventListener('click', ()=>applyE1(+1));
document.getElementById('e2Minus').addEventListener('click', ()=>applyE2(-1));
document.getElementById('e2Plus') .addEventListener('click', ()=>applyE2(+1));
document.getElementById('e1Reset').addEventListener('click', resetE1);
document.getElementById('e2Reset').addEventListener('click', resetE2);

/* Reset compteurs si saisie manuelle */
document.getElementById('echeance1').addEventListener('input', ()=>{ e1Shift = 0; refreshE1Badge(); });
document.getElementById('echeance2').addEventListener('input', ()=>{ e2Shift = 0; refreshE2Badge(); });

/* Init badges */
refreshE1Badge(); refreshE2Badge();

/* ===== Récurrence stepper + aperçu ===== */
const recInput   = document.getElementById('recMonths');
const recPrev    = document.getElementById('recPreview');
document.getElementById('recMinus').addEventListener('click', ()=>{
  const n = Math.max(0, Math.min(24, (parseInt(recInput.value||'0',10)-1)));
  recInput.value = n; updateRecPreview();
});
document.getElementById('recPlus').addEventListener('click', ()=>{
  const n = Math.max(0, Math.min(24, (parseInt(recInput.value||'0',10)+1)));
  recInput.value = n; updateRecPreview();
});
recInput.addEventListener('input', updateRecPreview);
function updateRecPreview(){
  const months = parseInt(recInput.value||'0',10);
  if (!months){ recPrev.classList.add('d-none'); return; }
  const base = baseForRec(); // y-m-d
  const next = addMonthsKeepDom(base, months);
  if (next){
    const [y,m,d] = next.split('-');
    recPrev.textContent = '→ ' + [d,m,y].join('/');
    recPrev.classList.remove('d-none');
  } else {
    recPrev.classList.add('d-none');
  }
}
updateRecPreview();

/* ===== Fichiers UI (max 4), sans chemin ni select par fichier ===== */
const MAX_FILES = 4;
const filesZone = document.getElementById('filesZone');
const selectNextType = document.getElementById('nextType');
const hiddenJson = document.getElementById('fichiersJson');
let filesModel = []; // [{type, path}]

function extOf(p){const m=p.toLowerCase().match(/\.([a-z0-9]+)$/);return m?m[1]:'';}
function isImgExt(e){return ['jpg','jpeg','png','gif','webp'].includes(e);}
function isPdfExt(e){return e==='pdf';}

function renderFiles(){
  filesZone.innerHTML = '';
  filesModel.forEach((it, idx) => {
    const e = document.createElement('div');
    e.className = 'col-12';
    e.innerHTML = `
      <div class="file-card">
        <div class="thumb">
          ${ isImgExt(extOf(it.path)) ? `<img src="${it.path}" alt="" style="width:100%;height:100%;object-fit:cover;border-radius:10px;">`
            : isPdfExt(extOf(it.path)) ? `<i class="bi bi-file-earmark-pdf"></i>`
            : `<i class="bi bi-file-earmark"></i>` }
        </div>
        <div class="meta">
          <span class="badge text-bg-light tag">${it.type}</span>
        </div>
        <button type="button" class="btn btn-sm btn-outline-danger btn-remove ms-auto" title="Supprimer"><i class="bi bi-x-lg"></i></button>
      </div>`;
    filesZone.appendChild(e);
    e.querySelector('.btn-remove').addEventListener('click', () => {
      filesModel.splice(idx,1); renderFiles(); syncHidden(); updateAddState();
    });
  });
  syncHidden();
}
function syncHidden(){ hiddenJson.value = JSON.stringify(filesModel); }
function updateAddState(){
  const btn = document.getElementById('btnAddFile');
  if (btn) btn.disabled = (filesModel.length >= MAX_FILES);
}
function onCaptureDone(paths){
  const chosen = selectNextType?.value || 'Autre';
  (paths || []).forEach(p => {
    if (filesModel.length < MAX_FILES) filesModel.push({ type:String(chosen), path:String(p) });
  });
  renderFiles(); updateAddState();
}

/* Précharger en édition */
<?php if (!empty($val['fichiers'])): ?>
  filesModel = <?= json_encode($val['fichiers'], JSON_UNESCAPED_SLASHES) ?>;
  renderFiles(); updateAddState();
<?php endif; ?>

document.getElementById('intervForm').addEventListener('submit', ()=>{ syncHidden(); });

/* Smart viewer */
function openSmartModal(btn){
  const path = btn.getAttribute('data-viewer-path');
  const typ  = btn.getAttribute('data-viewer-type') || '';
  const body = document.getElementById('smartViewerBody');
  const title= document.getElementById('smartViewerTitle');
  if (!path) return;
  title.textContent = typ || 'Aperçu';
  body.innerHTML = '';
  const ext = (path.split('.').pop() || '').toLowerCase();
  if (['jpg','jpeg','png','gif','webp'].includes(ext)) {
    const img = new Image(); img.src = path; img.alt = ''; img.style.maxWidth='100%'; img.style.maxHeight='80vh'; img.style.objectFit='contain'; body.appendChild(img);
  } else if (ext === 'pdf') {
    const ifr = document.createElement('iframe'); ifr.src = path; ifr.style.width='100%'; ifr.style.height='80vh'; ifr.style.border='0'; body.appendChild(ifr);
  } else {
    const a = document.createElement('a'); a.href = path; a.target = '_blank'; a.className='btn btn-primary'; a.textContent='Télécharger le fichier'; body.appendChild(a);
  }
  const m = new bootstrap.Modal(document.getElementById('smartViewer'), {backdrop:true}); m.show();
}
</script>