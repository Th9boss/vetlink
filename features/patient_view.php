<?php
// features/patient_view.php
// Fiche patient : photo cliquable (modale), bannière "Prochains rappels",
// timeline mixte (consultations + interventions) avec items repliés/étendus,
// pièces jointes (images/pdf) affichées en modale.

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';

require_login();

/* ===================== Input ===================== */
$patient_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

/* ===================== Patient + client ===================== */
$patient = null;
if ($patient_id > 0) {
    $st = db()->prepare("
        SELECT p.*,
               c.nom    AS c_nom,
               c.prenom AS c_prenom,
               c.gsm    AS c_gsm,
               c.email  AS c_email,
               c.adresse AS c_adresse
        FROM patients p
        JOIN clients c ON c.id = p.client_id
        WHERE p.id = ?
    ");
    $st->execute([$patient_id]);
    $patient = $st->fetch();
}

if (!$patient): ?>
  <div class="alert alert-danger my-4">Patient introuvable.</div>
  <a href="index.php?page=patients" class="btn btn-primary">Retour</a>
  <?php return; ?>
<?php endif; ?>

<?php
/* ===================== Helpers ===================== */
function is_img(?string $p): bool {
    if (!$p) return false;
    $ext = strtolower(pathinfo($p, PATHINFO_EXTENSION));
    return in_array($ext, ['jpg','jpeg','png','gif','webp'], true);
}
function is_pdf(?string $p): bool {
    if (!$p) return false;
    return strtolower(pathinfo($p, PATHINFO_EXTENSION)) === 'pdf';
}
function delete_local_if_exists(?string $rel): void {
    if (!$rel) return;
    $abs = realpath(__DIR__ . '/../' . ltrim($rel,'/'));
    if ($abs && is_file($abs)) @unlink($abs);
}
function fmt_dt(?string $dt, string $fmt='d/m/Y H:i'): string {
    if (!$dt) return '—';
    $ts = strtotime($dt);
    return $ts ? date($fmt, $ts) : '—';
}
function fmt_d(?string $d, string $fmt='d/m/Y'): string {
    if (!$d) return '—';
    $ts = strtotime($d);
    return $ts ? date($fmt, $ts) : '—';
}
function money_fmt_local($v): string { return number_format((float)$v, 2, '.', ' ').' MAD'; }
function is_image_path(?string $path): bool {
    if (!$path) return false;
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    return in_array($ext, ['jpg','jpeg','png','gif','webp'], true);
}

/** Décode proprement un champ JSON (string | string[]) en tableau de chemins */
function json_paths_all(?string $json): array {
    if (!$json) return [];
    $v = json_decode($json, true);
    if (json_last_error() === JSON_ERROR_NONE) {
        if (is_string($v)) return [$v];
        if (is_array($v))  return array_values(array_filter($v, 'is_string'));
        return [];
    }
    // fallback si la BDD contient un simple chemin texte
    if (is_string($json) && preg_match('~^/?uploads/~i', $json)) return [ltrim($json, '/')];
    return [];
}
/* ===================== Actions POST ===================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['act'] ?? '';

    // Delete consultation (+ attachments)
    if ($act === 'delete_consult') {
        $cid = (int)($_POST['consult_id'] ?? 0);
        if ($cid > 0) {
            // analyses files
            $a = db()->prepare("SELECT fichier_resultat FROM analyses WHERE consultation_id=? AND COALESCE(fichier_resultat,'')<>''");
            $a->execute([$cid]);
            foreach ($a->fetchAll() as $row) delete_local_if_exists($row['fichier_resultat']);

            // imageries files (JSON, peut être string)
            $i = db()->prepare("SELECT fichiers FROM imageries WHERE consultation_id=? AND fichiers IS NOT NULL");
            $i->execute([$cid]);
            foreach ($i->fetchAll() as $row) {
                $fj = $row['fichiers'];
                $paths = [];
                if ($fj !== null && $fj !== '') {
                    $decoded = json_decode($fj, true);
                    if (is_array($decoded)) {
                        foreach ($decoded as $it) if (is_string($it) && $it!=='') $paths[] = $it;
                    } elseif (is_string($decoded)) {
                        $paths[] = $decoded;
                    } else {
                        if (is_string($fj)) $paths[] = trim($fj,'"');
                    }
                }
                foreach ($paths as $p) delete_local_if_exists($p);
            }

            $del = db()->prepare("DELETE FROM consultations WHERE id=? AND patient_id=?");
            $del->execute([$cid, $patient_id]);
        }
        echo '<script>location.href="index.php?page=patient_view&id='. (int)$patient_id .'&ok=consult_deleted";</script>';
        return;
    }

    // Delete analyse
    if ($act === 'delete_analyse') {
        $aid = (int)($_POST['analyse_id'] ?? 0);
        if ($aid > 0) {
            $g = db()->prepare("SELECT a.fichier_resultat 
                                FROM analyses a 
                                JOIN consultations c ON c.id=a.consultation_id 
                                WHERE a.id=? AND c.patient_id=?");
            $g->execute([$aid, $patient_id]);
            if ($row = $g->fetch()) {
                delete_local_if_exists($row['fichier_resultat'] ?? null);
                $del = db()->prepare("DELETE a FROM analyses a 
                                      JOIN consultations c ON c.id=a.consultation_id 
                                      WHERE a.id=? AND c.patient_id=?");
                $del->execute([$aid, $patient_id]);
            }
        }
        echo '<script>location.href="index.php?page=patient_view&id='. (int)$patient_id .'&ok=analyse_deleted";</script>';
        return;
    }

    // Delete imagerie
    if ($act === 'delete_imagerie') {
        $iid = (int)($_POST['imagerie_id'] ?? 0);
        if ($iid > 0) {
            $g = db()->prepare("SELECT i.fichiers 
                                FROM imageries i 
                                JOIN consultations c ON c.id=i.consultation_id 
                                WHERE i.id=? AND c.patient_id=?");
            $g->execute([$iid, $patient_id]);
            if ($row = $g->fetch()) {
                $fj = $row['fichiers'];
                $paths = [];
                if ($fj !== null && $fj !== '') {
                    $decoded = json_decode($fj, true);
                    if (is_array($decoded)) {
                        foreach ($decoded as $it) if (is_string($it) && $it!=='') $paths[] = $it;
                    } elseif (is_string($decoded)) {
                        $paths[] = $decoded;
                    } else {
                        if (is_string($fj)) $paths[] = trim($fj,'"');
                    }
                }
                foreach ($paths as $p) delete_local_if_exists($p);

                $del = db()->prepare("DELETE i FROM imageries i 
                                      JOIN consultations c ON c.id=i.consultation_id 
                                      WHERE i.id=? AND c.patient_id=?");
                $del->execute([$iid, $patient_id]);
            }
        }
        echo '<script>location.href="index.php?page=patient_view&id='. (int)$patient_id .'&ok=imagerie_deleted";</script>';
        return;
    }

    // Delete intervention
    if ($act === 'delete_interv') {
        $iv = (int)($_POST['interv_id'] ?? 0);
        if ($iv > 0) {
            $del = db()->prepare("DELETE FROM interventions WHERE id=? AND patient_id=?");
            $del->execute([$iv, $patient_id]);
        }
        echo '<script>location.href="index.php?page=patient_view&id='. (int)$patient_id .'&ok=interv_deleted";</script>';
        return;
    }

    // Delete patient
    if ($act === 'delete_patient') {
        if (!empty($patient['photo'])) delete_local_if_exists($patient['photo']);
        $del = db()->prepare("DELETE FROM patients WHERE id=?");
        $del->execute([$patient_id]);
        echo '<script>location.href="index.php?page=patients&ok=patient_deleted";</script>';
        return;
    }
}

/* ===================== Charger données ===================== */
// Consultations + praticien
$cq = db()->prepare("
    SELECT c.*, CONCAT_WS(' ', u.prenom, u.nom) AS praticien_nom
    FROM consultations c
    LEFT JOIN users u ON u.id = c.praticien_id
    WHERE c.patient_id=?
    ORDER BY c.date_consult DESC, c.id DESC
");
$cq->execute([$patient_id]);
$consultations = $cq->fetchAll();

// Analyses / Imageries par consultation
$analysesByConsult = [];
$imageriesByConsult = [];
if ($consultations) {
    $ids = array_column($consultations, 'id');
    $in  = implode(',', array_fill(0, count($ids), '?'));

    $a = db()->prepare("SELECT * FROM analyses WHERE consultation_id IN ($in) ORDER BY id DESC");
    $a->execute($ids);
    foreach ($a->fetchAll() as $row) {
        $analysesByConsult[(int)$row['consultation_id']][] = $row;
    }

    $im = db()->prepare("SELECT * FROM imageries WHERE consultation_id IN ($in) ORDER BY id DESC");
    $im->execute($ids);
    foreach ($im->fetchAll() as $row) {
        // normaliser JSON fichiers → array de chemins
        $files = [];
        $fj = $row['fichiers'];
        if ($fj !== null && $fj !== '') {
            $decoded = json_decode($fj, true);
            if (is_array($decoded)) {
                foreach ($decoded as $it) if (is_string($it) && $it!=='') $files[] = $it;
            } elseif (is_string($decoded)) {
                $files[] = $decoded;
            } else {
                if (is_string($fj)) $files[] = trim($fj,'"');
            }
        }
        $row['_files'] = $files;
        $imageriesByConsult[(int)$row['consultation_id']][] = $row;
    }
}

// Interventions
$it = db()->prepare("SELECT * FROM interventions WHERE patient_id=? ORDER BY date_intervention DESC, id DESC");
$it->execute([$patient_id]);
$interventions = $it->fetchAll();

// Rappels (prochains) + Echéances d'interventions
$now = date('Y-m-d H:i:s');
$rp = db()->prepare("SELECT * FROM rappels WHERE patient_id=? AND date_rappel >= ? AND statut <> 'ANNULE' ORDER BY date_rappel ASC LIMIT 20");
$rp->execute([$patient_id, $now]);
$rappels = $rp->fetchAll();

// Extraire prochaines échéances à partir des interventions
$echeances = [];
foreach ($interventions as $iv) {
    $dt = $iv['prochaine_echeance'] ?? null; // date (YYYY-MM-DD)
    if ($dt && strtotime($dt.' 23:59:59') >= time()) {
        $echeances[] = [
            'source' => 'intervention',
            'label'  => $iv['type_acte'] ?: 'Intervention',
            'date'   => $dt.' 08:00:00',
            'raw'    => $iv,
        ];
    }
}
// Fusionner + trier (rappels + échéances)
$prochains = [];
foreach ($rappels as $r) {
    $prochains[] = [
        'source' => 'rappel',
        'label'  => $r['type'] ?: 'Rappel',
        'date'   => $r['date_rappel'],
        'raw'    => $r,
    ];
}
$prochains = array_merge($prochains, $echeances);
usort($prochains, fn($x,$y) => strtotime($x['date']) <=> strtotime($y['date']));

/* ===================== Timeline mixte ===================== */
$items = [];
foreach ($consultations as $c) {
    $items[] = ['kind' => 'consult', 'date' => strtotime($c['date_consult']), 'data' => $c];
}
foreach ($interventions as $iv) {
    $items[] = ['kind' => 'interv', 'date' => strtotime($iv['date_intervention'] ?? $iv['created_at'] ?? 'now'), 'data' => $iv];
}
usort($items, fn($x,$y) => $y['date'] <=> $x['date']);

// autorisation suppression patient (aucun historique)
$canDeletePatient = (count($consultations) === 0 && count($interventions) === 0);

/* ===================== UI ===================== */
?>
<?php if (isset($_GET['ok'])): ?>
  <div class="alert alert-success alert-dismissible fade show">
    Action réalisée avec succès.
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
  </div>
<?php endif; ?>

<!-- En-tête patient -->
<style>
.pv-patient-header .pv-actions{display:flex;flex-wrap:wrap;gap:.6rem}
.pv-consult-cta{
  display:inline-flex;
  align-items:center;
  justify-content:center;
  gap:.55rem;
  border:0;
  border-radius:16px;
  padding:.9rem 1.15rem;
  background:linear-gradient(180deg,#1274ff 0%,#0a5fd6 100%);
  color:#fff !important;
  font-weight:700;
  box-shadow:0 14px 30px rgba(10,95,214,.24);
}
.pv-consult-cta:hover{color:#fff !important}
.pv-consult-cta .bi{font-size:1rem;line-height:1}
.pv-ai-btn{
  display:inline-flex;
  align-items:center;
  justify-content:center;
  gap:.55rem;
  padding:.78rem 1rem;
  border-radius:16px;
  border:1px solid rgba(194,65,12,.16);
  background:linear-gradient(180deg,#ffb347 0%,#f97316 100%);
  color:#fff !important;
  font-weight:700;
  box-shadow:0 14px 28px rgba(249,115,22,.22);
}
.pv-ai-btn-mobile{
  padding:.45rem .8rem;
  border-radius:999px;
  font-size:.88rem;
  box-shadow:none;
}
.pv-ai-inline{
  margin-top:.85rem;
}
@media (max-width:576px){
  .pv-patient-header .card-body{display:block !important}
  .pv-patient-header .pv-meta{margin-bottom:1rem}
  .pv-patient-header .pv-actions{display:grid;grid-template-columns:1fr;gap:.7rem}
  .pv-patient-header .pv-actions .btn{width:100%;justify-content:center}
  .pv-consult-cta{
    padding:1rem 1.1rem;
    font-size:1rem;
    border-radius:18px;
    box-shadow:0 18px 34px rgba(10,95,214,.28);
  }
  .pv-ai-btn{
    padding:1rem 1.1rem;
    border-radius:18px;
  }
  .pv-ai-btn-mobile{
    width:auto !important;
    padding:.5rem .85rem;
    border-radius:999px;
  }
}
</style>

<div class="card shadow-sm mb-3 pv-patient-header">
  <!-- Barre haute avec flèche retour & suppression conditionnelle -->
  <div class="card-header py-2 d-flex justify-content-between align-items-center">
    <a class="btn btn-sm btn-outline-secondary" href="index.php?page=patients" title="Retour">
      &larr;
    </a>
    <div class="d-flex align-items-center gap-2">
      <button type="button" class="btn pv-ai-btn pv-ai-btn-mobile d-sm-none" data-bs-toggle="modal" data-bs-target="#patientAiModal">
        <i class="bi bi-stars"></i>
        <span>Mon Assistant IA</span>
      </button>
      <?php if ($canDeletePatient): ?>
        <form method="post" class="m-0" onsubmit="return confirm('Supprimer définitivement ce patient (aucun historique) ?');">
          <?= csrf_input() ?>
          <input type="hidden" name="act" value="delete_patient">
          <button class="btn btn-sm btn-outline-danger" title="Supprimer le patient">&times;</button>
        </form>
      <?php else: ?>
        <span class="small text-muted d-none d-sm-inline">Historique présent — suppression désactivée</span>
      <?php endif; ?>
    </div>
  </div>

  <div class="card-body d-flex align-items-center">
    <div class="me-3">
      <?php $img = $patient['photo'] ?: 'assets/img/avatar_placeholder.webp'; ?>
      <a href="#" data-bs-toggle="modal" data-bs-target="#photoModal">
        <img src="<?= h($img) ?>" class="rounded-circle border" style="width:72px;height:72px;object-fit:cover" alt="">
      </a>
    </div>
    <div class="flex-grow-1 pv-meta">
      <h1 class="h5 mb-1"><?= h($patient['nom']) ?></h1>
      <div class="text-muted small">
        <?= h(trim(($patient['espece']?:'').' / '.($patient['race']?:''),' /')) ?> &middot;
        <?= h($patient['sexe'] ?: '—') ?> &middot;
        Naissance : <?= h($patient['date_naissance'] ?: '—') ?>
      </div>
      <div class="text-muted small">
        Client : <b><?= h($patient['c_nom'].' '.$patient['c_prenom']) ?></b>
        <?php if ($patient['c_gsm']): ?> · GSM: <a href="tel:<?= h($patient['c_gsm']) ?>"><?= h($patient['c_gsm']) ?></a><?php endif; ?>
        <?php if ($patient['c_email']): ?> · Email: <a href="mailto:<?= h($patient['c_email']) ?>"><?= h($patient['c_email']) ?></a><?php endif; ?>
      </div>
      <div class="pv-ai-inline d-none d-sm-block">
        <button type="button" class="btn pv-ai-btn" data-bs-toggle="modal" data-bs-target="#patientAiModal">
          <i class="bi bi-stars"></i>
          <span>Mon Assistant IA</span>
        </button>
      </div>
    </div>
    <div class="pv-actions">
      <a class="btn pv-consult-cta" href="index.php?page=consultation_edit&patient_id=<?= h($patient_id) ?>">
        <i class="bi bi-plus-circle-fill"></i>
        <span>Nouvelle consultation</span>
      </a>
      <a class="btn btn-outline-primary btn-sm" href="index.php?page=intervention&patient_id=<?= h($patient_id) ?>">
        + Intervention
      </a>
      <a class="btn btn-outline-warning btn-sm" href="index.php?page=rappel&patient_id=<?= h($patient_id) ?>">
        + Rappel
      </a>
    </div>
  </div>
  
</div>

<div class="modal fade" id="patientAiModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-fullscreen-sm-down">
    <div class="modal-content pv-ai-modal">
      <div class="modal-header border-0 pb-0">
        <div>
          <div class="pv-ai-kicker">Assistant clinique</div>
          <h5 class="modal-title mb-1">Mon Assistant IA</h5>
          <p class="text-muted small mb-0">Choisissez la portée du résumé.</p>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button>
      </div>
      <div class="modal-body pt-3">
        <div class="pv-ai-choice-grid" id="pvAiChoices">
          <button type="button" class="pv-ai-choice" data-mode="latest">
            <span class="pv-ai-choice-title">Dernière consultation</span>
            <span class="pv-ai-choice-text">Résumé clinique court sur la consultation la plus récente.</span>
          </button>
          <button type="button" class="pv-ai-choice" data-mode="history">
            <span class="pv-ai-choice-title">Tout l’historique</span>
            <span class="pv-ai-choice-text">Aperçu global du cas depuis le début.</span>
          </button>
        </div>
        <div class="pv-ai-state d-none" id="pvAiLoading">
          <div class="spinner-border text-primary" role="status"></div>
          <div>
            <div class="fw-semibold">Analyse en cours</div>
            <div class="text-muted small">Résumé clinique compact en préparation…</div>
          </div>
        </div>
        <div class="pv-ai-result d-none" id="pvAiResultWrap">
          <div class="d-flex align-items-center justify-content-between gap-2 mb-2">
            <div>
              <div class="pv-ai-kicker">Résultat</div>
              <div class="fw-semibold" id="pvAiResultTitle">Résumé</div>
            </div>
            <button type="button" class="btn btn-sm btn-outline-secondary" id="pvAiBackBtn">Retour</button>
          </div>
          <div class="pv-ai-result-card" id="pvAiResultBody"></div>
        </div>
        <div class="alert alert-danger d-none mt-3 mb-0" id="pvAiError"></div>
      </div>
    </div>
  </div>
</div>
<?php
/* ===== Courbe de poids (données) ===== */
$wq = db()->prepare("SELECT DATE(date_consult) d, poids 
                     FROM consultations 
                     WHERE patient_id=? AND poids IS NOT NULL AND poids>0
                     ORDER BY date_consult ASC");
$wq->execute([$patient_id]);
$weights = $wq->fetchAll(PDO::FETCH_ASSOC);
$wDates  = array_map(fn($r)=>$r['d'], $weights);
$wVals   = array_map(fn($r)=>(int)$r['poids'], $weights);
?>
<!-- ===== Bloc Courbe de poids (plié par défaut) ===== -->
<div class="card shadow-sm mb-3">
  <div class="card-header d-flex align-items-center justify-content-between">
    <div class="d-flex align-items-center gap-2">
      <span class="fw-semibold">Courbe de poids</span>
      <?php if (!empty($wVals)): ?>
        <span class="badge bg-light text-dark"><?= count($wVals) ?> mesure(s)</span>
      <?php else: ?>
        <span class="badge bg-secondary">aucune donnée</span>
      <?php endif; ?>
    </div>
    <button id="pvWeightToggle" class="btn btn-sm btn-outline-primary" 
            type="button" aria-expanded="false" aria-controls="pvWeightWrap">
      Courbe de poids +
    </button>
  </div>

  <div id="pvWeightWrap" class="d-none">
    <div class="card-body">
      <?php if (!$wVals): ?>
        <div class="text-muted small">Aucune mesure de poids enregistrée pour ce patient.</div>
      <?php else: ?>
        <div class="pv-weight-chartbox">
          <canvas id="pvWeightChart"></canvas>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<style>
/* Hauteur maîtrisée, responsive */
.pv-weight-chartbox{
  position:relative;
  height: 260px;
}
.pv-ai-modal{
  border:0;
  border-radius:24px;
  overflow:hidden;
  background:
    radial-gradient(circle at top right, rgba(18,116,255,.08), transparent 34%),
    linear-gradient(180deg, #ffffff 0%, #f8fafc 100%);
}
.pv-ai-kicker{
  font-size:.72rem;
  font-weight:700;
  letter-spacing:.08em;
  text-transform:uppercase;
  color:#64748b;
}
.pv-ai-choice-grid{
  display:grid;
  gap:.85rem;
}
.pv-ai-choice{
  width:100%;
  display:flex;
  flex-direction:column;
  align-items:flex-start;
  gap:.25rem;
  text-align:left;
  border:1px solid rgba(15,23,42,.1);
  background:#fff;
  border-radius:20px;
  padding:1rem 1rem 1.05rem;
  box-shadow:0 12px 26px rgba(15,23,42,.06);
}
.pv-ai-choice-title{
  font-weight:700;
  color:#0f172a;
}
.pv-ai-choice-text{
  color:#64748b;
  font-size:.92rem;
  line-height:1.4;
}
.pv-ai-state{
  display:flex;
  align-items:center;
  gap:.85rem;
  padding:1rem;
  border-radius:18px;
  background:#fff;
  border:1px solid rgba(15,23,42,.08);
}
.pv-ai-result-card{
  white-space:pre-line;
  padding:1rem;
  border-radius:20px;
  background:#fff;
  border:1px solid rgba(15,23,42,.08);
  color:#0f172a;
  line-height:1.6;
  box-shadow:0 14px 28px rgba(15,23,42,.06);
}
@media (max-width:576px){
  .pv-weight-chartbox{ height: 200px; }
  .pv-ai-modal{
    border-radius:24px 24px 0 0;
    min-height:72vh;
  }
  .pv-ai-choice{
    padding:1rem;
    border-radius:18px;
  }
}
</style>

<script>
(function(){
  // ——— lib loader (Chart.js depuis CDN, injectée une seule fois)
  function ensureChartJs(cb){
    if (window.Chart) { cb(); return; }
    const s = document.createElement('script');
    s.src = "https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js";
    s.onload = cb;
    document.head.appendChild(s);
  }

  // ——— Données PHP -> JS
  const W_LABELS = <?= json_encode($wDates, JSON_UNESCAPED_UNICODE) ?>;
  const W_DATA   = <?= json_encode($wVals,  JSON_UNESCAPED_UNICODE) ?>;

  let chartInstance = null;
  const wrap   = document.getElementById('pvWeightWrap');
  const toggle = document.getElementById('pvWeightToggle');

  // bouton + / -
  function updateToggleText(){
    const open = !wrap.classList.contains('d-none');
    toggle.textContent = open ? 'Courbe de poids -' : 'Courbe de poids +';
    toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
  }

  // init du graphique (une seule fois, à l’ouverture)
  function initChart(){
    if (chartInstance || !window.Chart) return;
    const ctx = document.getElementById('pvWeightChart');
    if (!ctx) return;

    // petit plugin pour afficher le poids en police discrète près de chaque point
    const valueLabelPlugin = {
      id: 'valueLabelPlugin',
      afterDatasetsDraw(chart, args, pluginOpts){
        const {ctx, chartArea:{top}} = chart;
        const ds = chart.data.datasets[0];
        if (!ds) return;
        ctx.save();
        ctx.font = '10px system-ui, -apple-system, Segoe UI, Roboto, Arial';
        ctx.fillStyle = '#555';
        ctx.textAlign = 'left';
        ctx.textBaseline = 'middle';
        const meta = chart.getDatasetMeta(0);
        meta.data.forEach((elem, i) => {
          const {x, y} = elem.getProps(['x','y'], true);
          const val = ds.data[i];
          // petit décalage pour ne pas gêner la lecture de la courbe
          ctx.fillText(String(val), x + 6, y - 8);
        });
        ctx.restore();
      }
    };

    chartInstance = new Chart(ctx, {
      type: 'line',
      data: {
        labels: W_LABELS,
        datasets: [{
          label: 'Poids (kg)',
          data: W_DATA,
          borderWidth: 2,
          tension: 0.3,
          pointRadius: 3,
          pointHoverRadius: 4,
          fill: false
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        interaction: { mode: 'nearest', intersect: false },
        scales: {
          x: {
            ticks: { maxRotation: 0, autoSkip: true, maxTicksLimit: 6 },
            grid: { display: false }
          },
          y: {
            beginAtZero: false,
            grace: '10%',
            grid: { color: 'rgba(0,0,0,0.06)' },
            ticks: { precision: 0 }
          }
        },
        plugins: {
          legend: { display: false },
          tooltip: {
            callbacks: {
              label(ctx){ return ` ${ctx.parsed.y} kg`; }
            }
          }
        }
      },
      plugins: [valueLabelPlugin]
    });
  }

  if (wrap && toggle){
    toggle.addEventListener('click', function(){
      const open = wrap.classList.toggle('d-none') === false;
      updateToggleText();
      if (open) {
        ensureChartJs(() => {
          setTimeout(() => {
            initChart();
            if (chartInstance) chartInstance.resize();
          }, 30);
        });
      }
    });
    updateToggleText();
    window.addEventListener('resize', function(){
      if (chartInstance && !wrap.classList.contains('d-none')) {
        chartInstance.resize();
      }
    });
  }
})();
</script>
<!-- Modale Photo -->
<div class="modal fade" id="photoModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-fullscreen-sm-down">
    <div class="modal-content bg-dark">
      <button type="button" class="btn-close btn-close-white ms-auto m-2" data-bs-dismiss="modal" aria-label="Fermer"></button>
      <div class="modal-body d-flex justify-content-center align-items-center p-2">
        <img src="<?= h($img) ?>" style="max-width:100%;max-height:80vh;object-fit:contain" alt="">
      </div>
    </div>
  </div>
</div>

<!-- Prochains rappels -->
<?php if ($prochains): ?>
  <div class="alert alert-info d-flex align-items-center justify-content-between flex-wrap shadow-sm">
    <div class="me-2">
      <strong>Prochains rappels</strong>
      <span class="text-muted small">· <?= count($prochains) ?> à venir</span>
    </div>
    <div class="d-flex flex-wrap gap-2">
      <?php foreach (array_slice($prochains,0,6) as $p): ?>
        <?php
          $lbl = ($p['source']==='rappel' ? 'Rappel' : 'Échéance');
          $txt = h(($p['label'] ?: $lbl).' · '.fmt_dt($p['date'], 'd/m H:i'));
        ?>
        <span class="badge bg-light text-dark"><?= $txt ?></span>
      <?php endforeach; ?>
    </div>
  </div>
<?php endif; ?>

<!-- Timeline mixte -->
<div class="row g-3">
  <?php if (!$items): ?>
    <div class="col-12">
      <div class="alert alert-light border">Aucun historique pour l’instant. Créez une première consultation.</div>
    </div>
  <?php else: $uid = 0; foreach ($items as $item): $uid++; ?>
    <?php if ($item['kind'] === 'consult'):
      $c = $item['data'];
      $consultId = (int)$c['id'];
      $anas = $analysesByConsult[$consultId] ?? [];
      $imgs = $imageriesByConsult[$consultId] ?? [];
      // count imagerie files
      $imgCount = 0;
      foreach ($imgs as $im) { $imgCount += isset($im['_files']) ? count($im['_files']) : 0; }
    ?>
    <div class="col-12">
      <div class="card shadow-sm timeline-item" data-item="<?= $uid ?>">
        <!-- Header compact -->
        <div class="card-header py-2 d-flex align-items-center flex-wrap gap-2">
          <span class="badge bg-primary">Consultation</span>
          <span class="fw-semibold"><?= h(fmt_dt($c['date_consult'])) ?></span>
          <?php if ($c['motif']): ?><span class="text-truncate" style="max-width:240px"><?= h($c['motif']) ?></span><?php endif; ?>
          <span class="text-muted small">· <?= h($c['praticien_nom'] ?: '—') ?></span>
          <span class="badge bg-secondary">Analyses: <?= count($anas) ?></span>
          <span class="badge bg-secondary">Imageries: <?= $imgCount ?></span>
          <?php if ((float)$c['montant_ligne']>0): ?>
            <span class="ms-auto badge bg-success"><?= h(money_fmt_local($c['montant_ligne'])) ?></span>
          <?php else: ?>
            <span class="ms-auto"></span>
          <?php endif; ?>
        <button class="btn btn-sm btn-outline-dark ms-2 btn-ordonnance"
        data-ordo-type="consultation"
        data-ordo-id="<?= h($consultId) ?>">
  Ordonnance
</button>


          <button class="btn btn-sm btn-outline-primary ms-2 btn-toggle" data-target="#body-<?= $uid ?>">Voir +</button>
        </div>

        <!-- Body détaillé (plié par défaut) -->
        <div id="body-<?= $uid ?>" class="card-body d-none">
          <div class="d-flex justify-content-end gap-2 mb-2">
            <a class="btn btn-sm btn-outline-primary" href="index.php?page=consultation_edit&id=<?= h($consultId) ?>">Éditer</a>
            <form method="post" onsubmit="return confirm('Supprimer cette consultation ?');">
              <?= csrf_input() ?>
              <input type="hidden" name="act" value="delete_consult">
              <input type="hidden" name="consult_id" value="<?= h($consultId) ?>">
              <button class="btn btn-sm btn-outline-danger">Supprimer</button>
            </form>
          </div>

          <?php if ($c['motif']): ?><div class="mb-2"><b>Motif</b><br><?= nl2br(h($c['motif'])) ?></div><?php endif; ?>
          <?php if ($c['anamnese']): ?><div class="mb-2"><b>Anamnèse</b><br><?= nl2br(h($c['anamnese'])) ?></div><?php endif; ?>
          <?php if ($c['examen']): ?><div class="mb-2"><b>Examen</b><br><?= nl2br(h($c['examen'])) ?></div><?php endif; ?>
          <?php if ($c['diagnostic']): ?><div class="mb-2"><b>Diagnostic</b><br><?= nl2br(h($c['diagnostic'])) ?></div><?php endif; ?>
          <?php if ($c['traitement']): ?><div class="mb-2"><b>Traitement</b><br><?= nl2br(h($c['traitement'])) ?></div><?php endif; ?>

          <?php if ($c['commentaire_facturation'] || (float)$c['montant_ligne']>0): ?>
            <div class="alert alert-light border mt-3 mb-0 py-2">
              <div class="d-flex justify-content-between">
                <div><b>Facturation</b> : <?= h($c['commentaire_facturation'] ?: '—') ?></div>
                <div><b><?= h(money_fmt_local((float)$c['montant_ligne'])) ?></b></div>
              </div>
            </div>
          <?php endif; ?>

          <!-- Analyses -->
          <!-- Analyses -->
<div class="mt-3">
  <div class="mb-2"><span class="badge bg-warning text-dark">Analyses</span></div>
  <?php if (!$anas): ?>
    <div class="text-muted small">Aucune analyse.</div>
  <?php else: ?>
    <div class="table-responsive">
      <table class="table table-sm align-middle">
        <thead><tr><th>Type</th><th>Résultat</th><th>Références</th><th>Fichiers</th><th>Actions</th></tr></thead>
        <tbody>
          <?php foreach ($anas as $a): ?>
            <?php $files = json_paths_all($a['fichier_resultat'] ?? null); ?>
            <tr>
              <td><?= h($a['type_analyse'] ?: '—') ?></td>
              <td><?= nl2br(h($a['resultat'] ?: '—')) ?> <?php if ($a['unite']): ?><span class="text-muted"><?= h($a['unite']) ?></span><?php endif; ?></td>
              <td class="text-muted"><?= h(($a['ref_min'] ?: '—').' - '.($a['ref_max'] ?: '—')) ?></td>
              <td class="small">
                <?php if ($files): ?>
                  <button type="button" class="btn btn-sm btn-outline-secondary"
                          data-bs-toggle="modal" data-bs-target="#pvModalViewAnalyse<?= $a['id'] ?>">
                    Voir
                  </button>
                <?php else: ?>
                  <span class="text-muted">—</span>
                <?php endif; ?>
              </td>
              <td>
                <form method="post" class="d-inline" onsubmit="return confirm('Supprimer cette analyse ?');">
                  <?= csrf_input() ?>
                  <input type="hidden" name="act" value="delete_analyse">
                  <input type="hidden" name="analyse_id" value="<?= h($a['id']) ?>">
                  <button class="btn btn-sm btn-outline-danger">Supprimer</button>
                </form>
              </td>
            </tr>

            <!-- Modale multi-view Analyses (sans suppression) -->
           <div class="modal fade vl-view-modal" id="pvModalViewAnalyse<?= $a['id'] ?>" tabindex="-1"
     aria-hidden="true" data-bs-backdrop="false" data-bs-keyboard="true">
  <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
                <div class="modal-content vl-modal-glow">
                  <div class="modal-header">
                    <h5 class="modal-title">Fichiers — <?= h($a['type_analyse'] ?: 'Analyse') ?></h5>
                    <button type="button" class="btn-close vl-modal-close" data-bs-dismiss="modal" aria-label="Fermer"></button>
                  </div>
                  <div class="modal-body">
                    <?php if ($files): ?>
                      <div class="row g-3">
                        <?php foreach ($files as $pf): ?>
                          <div class="col-12">
                            <?php if (is_image_path($pf)): ?>
                              <img src="<?= h($pf) ?>" class="img-fluid rounded border">
                            <?php else: ?>
                              <iframe src="<?= h($pf) ?>#toolbar=1&navpanes=0&view=fitH" style="width:100%;height:60vh;border:0;"></iframe>
                            <?php endif; ?>
                          </div>
                        <?php endforeach; ?>
                      </div>
                    <?php else: ?>
                      <div class="text-muted">Aucun fichier.</div>
                    <?php endif; ?>
                  </div>
                </div>
              </div>
            </div>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

          <!-- Imageries -->
<div class="mt-3">
  <div class="mb-2"><span class="badge bg-warning text-dark">Imageries</span></div>
  <?php if (!$imgs): ?>
    <div class="text-muted small">Aucune imagerie.</div>
  <?php else: ?>
    <div class="table-responsive">
      <table class="table table-sm align-middle">
        <thead><tr><th>Type</th><th>Compte-rendu</th><th>Fichiers</th><th>Actions</th></tr></thead>
        <tbody>
          <?php foreach ($imgs as $im): ?>
            <?php $files = $im['_files'] ?? []; ?>
            <tr>
              <td><?= h($im['type_imagerie'] ?: '—') ?></td>
              <td><?= nl2br(h($im['compte_rendu'] ?: '—')) ?></td>
              <td class="small">
                <?php if ($files): ?>
                  <button type="button" class="btn btn-sm btn-outline-secondary"
                          data-bs-toggle="modal" data-bs-target="#pvModalViewImagerie<?= $im['id'] ?>">
                    Voir
                  </button>
                <?php else: ?>
                  <span class="text-muted">—</span>
                <?php endif; ?>
              </td>
              <td>
                <form method="post" class="d-inline" onsubmit="return confirm('Supprimer cette imagerie ?');">
                  <?= csrf_input() ?>
                  <input type="hidden" name="act" value="delete_imagerie">
                  <input type="hidden" name="imagerie_id" value="<?= h($im['id']) ?>">
                  <button class="btn btn-sm btn-outline-danger">Supprimer</button>
                </form>
              </td>
            </tr>

            <!-- Modale multi-view Imageries (sans suppression) -->
            <div class="modal fade vl-view-modal" id="pvModalViewImagerie<?= $im['id'] ?>" tabindex="-1"
     aria-hidden="true" data-bs-backdrop="false" data-bs-keyboard="true">
  <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
                <div class="modal-content vl-modal-glow">
                  <div class="modal-header">
                    <h5 class="modal-title">Fichiers — <?= h($im['type_imagerie'] ?: 'Imagerie') ?></h5>
                    <button type="button" class="btn-close vl-modal-close" data-bs-dismiss="modal" aria-label="Fermer"></button>
                  </div>
                  <div class="modal-body">
                    <?php if ($files): ?>
                      <div class="row g-3">
                        <?php foreach ($files as $pf): ?>
                          <div class="col-12">
                            <?php if (is_image_path($pf)): ?>
                              <img src="<?= h($pf) ?>" class="img-fluid rounded border">
                            <?php else: ?>
                              <iframe src="<?= h($pf) ?>#toolbar=1&navpanes=0&view=fitH" style="width:100%;height:60vh;border:0;"></iframe>
                            <?php endif; ?>
                          </div>
                        <?php endforeach; ?>
                      </div>
                    <?php else: ?>
                      <div class="text-muted">Aucun fichier.</div>
                    <?php endif; ?>
                  </div>
                </div>
              </div>
            </div>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>
                 
        </div>
      </div>
    </div>

    <?php else:
      $iv = $item['data'];
      $ivId = (int)$iv['id'];
    ?>
    <div class="col-12">
      <div class="card shadow-sm timeline-item" data-item="<?= $uid ?>">
        <!-- Header compact -->
        <div class="card-header py-2 d-flex align-items-center flex-wrap gap-2">
          <span class="badge bg-success">Intervention</span>
          <span class="fw-semibold"><?= h(fmt_dt($iv['date_intervention'] ?: $iv['created_at'])) ?></span>
          <span class="text-truncate" style="max-width:300px"><?= h($iv['type_acte'] ?: '—') ?></span>
          <?php if (!empty($iv['prochaine_echeance'])): ?>
            <span class="badge bg-info text-dark">Prochaine : <?= h(fmt_d($iv['prochaine_echeance'])) ?></span>
          <?php endif; ?>
          <?php if ((float)$iv['prix']>0): ?>
            <span class="ms-auto badge bg-success"><?= h(money_fmt_local($iv['prix'])) ?></span>
          <?php else: ?>
            <span class="ms-auto"></span>
          <?php endif; ?>
         <button class="btn btn-sm btn-outline-dark ms-2 btn-ordonnance"
        data-ordo-type="intervention"
        data-ordo-id="<?= h($ivId) ?>">
  Ordonnance
</button>

          <button class="btn btn-sm btn-outline-primary ms-2 btn-toggle" data-target="#body-<?= $uid ?>">Voir +</button>
        </div>

        <div id="body-<?= $uid ?>" class="card-body d-none">
          <div class="d-flex justify-content-end gap-2 mb-2">
            <form method="post" class="d-inline" onsubmit="return confirm('Supprimer cette intervention ?');">
              <?= csrf_input() ?>
              <input type="hidden" name="act" value="delete_interv">
              <input type="hidden" name="interv_id" value="<?= h($ivId) ?>">
              <button class="btn btn-sm btn-outline-danger">Supprimer</button>
            </form>
          </div>
          <?php if ($iv['details']): ?>
            <div class="mb-2"><b>Détails</b><br><?= nl2br(h($iv['details'])) ?></div>
          <?php endif; ?>
          <div class="small text-muted">
            Statut rappel : <?= h($iv['statut_rappel'] ?: '—') ?>
            <?php if (!empty($iv['recurrence'])): ?> · Récurrence : <?= h($iv['recurrence']) ?><?php endif; ?>
          </div>
        </div>
      </div>
    </div>
    <?php endif; ?>
  <?php endforeach; ?>
  <?php endif; // <-- IMPORTANT : on ferme le if (!$items) ?>
</div>

<!-- Modale Vue Fichier (image/pdf) -->
<!-- Modale Ordonnance (sans backdrop, contrôlée en JS) -->
<div class="modal fade" id="ordoModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Ordonnance</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button>
      </div>
      <div class="modal-body" id="ordoModalBody">
        <div class="text-center text-muted py-5">
          <div class="spinner-border me-2" role="status"></div> Chargement…
        </div>
      </div>
      <div class="modal-footer">
        <a class="btn btn-primary d-none" id="ordoDownload" target="_blank" rel="noopener">Télécharger le PDF</a>
      </div>
    </div>
  </div>
</div>

<style>
  .timeline-item .card-header { background: #f9fbfd; }
  .timeline-item .btn-toggle { white-space: nowrap; }
  .timeline-item .text-truncate { overflow:hidden; text-overflow:ellipsis; }
  @media (max-width:576px){
    .timeline-item .card-header { font-size:.92rem; }
  }

  /* ===== Modal "Voir" (Analyses/Imageries) — compact, sans backdrop, toujours au-dessus ===== */
  .vl-view-modal{ 
    z-index: 2000 !important;              /* > bootstrap modal (1055) et tout le reste */
  }
  .vl-view-modal .modal-dialog{
    max-width: min(720px, 95vw);
    margin: calc( env(safe-area-inset-top, 0) + 3.75rem ) auto 1.25rem; /* marge top pour header */
  }
  .vl-view-modal .modal-content{
    border-radius: 14px;
    background:#fff;
    border:1px solid rgba(0,0,0,.08);
    box-shadow: 0 0 0 2px rgba(13,110,253,.20), 0 10px 30px rgba(0,0,0,.35);
    display:flex; flex-direction:column;
    max-height: calc(100dvh - 6rem);       /* anti-crop en bas */
  }
  .vl-view-modal .modal-header{ 
    position: sticky; top: 0; z-index: 1; 
    background: #fff; 
    border-bottom: 1px solid rgba(0,0,0,.06);
  }
  .vl-view-modal .modal-body{
    overflow:auto; -webkit-overflow-scrolling:touch;
    max-height: calc(100dvh - 10rem);
  }
  .vl-view-modal .modal-body iframe{
    width:100%; height:65vh; border:0; border-radius:8px;
    background:#fafafa;
  }
  .vl-view-modal .modal-body img{
    max-width:100%; height:auto; max-height:70vh; object-fit:contain;
    border-radius:8px; border:1px solid #eee;
  }

  /* Mobile : plus étroit et marge top + petite */
  @media (max-width:576px){
    .vl-view-modal .modal-dialog{ max-width:92vw; margin: calc( env(safe-area-inset-top,0) + 3rem ) auto 1rem; }
    .vl-view-modal .modal-body iframe{ height:60vh; }
    .vl-view-modal .modal-body img{ max-height:65vh; }
  }

  /* Pendant la modale (sans backdrop), neutraliser les clics sous-jacents */
  body.modal-open .timeline-item,
  body.modal-open .card,
  body.modal-open .table-responsive {
    z-index: 0 !important;
    position: relative !important;
    pointer-events: none;
  }
  /* Mais autoriser les clics DANS la modale */
  .vl-view-modal, .vl-view-modal *{ pointer-events: auto; }
</style>


<script>
// Un seul item développé à la fois (inchangé)
document.addEventListener('click', (e) => {
  const btn = e.target.closest('.btn-toggle');
  if (!btn) return;
  const targetSel = btn.getAttribute('data-target');
  const body = document.querySelector(targetSel);
  if (!body) return;
  document.querySelectorAll('.timeline-item .card-body').forEach(el => {
    if (el !== body) el.classList.add('d-none');
  });
  body.classList.toggle('d-none');
});

// ====== Modale de vue fichier (image/pdf) — VERSION SIMPLE MAIS ROBUSTE ======
(function(){
  const modal = document.getElementById('fileModal');
  const body = document.getElementById('fileModalBody');
  const download = document.getElementById('fileDownload');

  // Encode chaque segment pour garder les '/' intacts
  function encodePathPreserveSlashes(p) {
    return p.split('/').map(seg => encodeURIComponent(seg)).join('/');
  }

  // Essaie d'extraire le PREMIER chemin s'il s'agit d'un JSON ["uploads/a","uploads/b"] ou "uploads/a"
  function pickFirstPathFromRaw(raw) {
    if (!raw) return null;
    let s = String(raw).trim();

    // si la valeur est entourée de guillemets
    if ((s.startsWith('"') && s.endsWith('"')) || (s.startsWith("'") && s.endsWith("'"))) {
      s = s.slice(1, -1).trim();
    }

    // JSON array ou string ?
    if (s.startsWith('[')) {
      try {
        const v = JSON.parse(s);
        if (Array.isArray(v) && v.length && typeof v[0] === 'string') return v[0];
      } catch(_) {}
    }
    if (s.startsWith('{')) {
      // pas prévu, on ignore
      return null;
    }
    return s;
  }

  function normalizePath(raw) {
    const first = pickFirstPathFromRaw(raw);
    if (!first) return null;

    let p = first.replace(/\\/g, '/').trim();

    // couper à partir de 'uploads/' si absolu
    const idx = p.toLowerCase().indexOf('uploads/');
    if (idx >= 0) p = p.slice(idx);

    if (p.startsWith('./')) p = p.slice(2);
    p = p.replace(/^\/+/, '');

    // sécurité : on n'autorise que uploads/
    if (!/^uploads\//i.test(p)) return null;

    return encodePathPreserveSlashes(p);
  }

  function openFileViewer(rawPath){
    body.innerHTML = '';
    const path = normalizePath(rawPath);

    if (!path) {
      const p = document.createElement('p');
      p.className = 'm-3 text-danger';
      p.textContent = 'Fichier introuvable ou chemin invalide.';
      body.appendChild(p);
      new bootstrap.Modal(modal).show();
      return;
    }

    download.href = path;

    const ext = (path.split('.').pop() || '').toLowerCase();
    if (['jpg','jpeg','png','gif','webp'].includes(ext)) {
      const img = document.createElement('img');
      img.src = path;
      img.style.maxWidth = '100%';
      img.style.maxHeight = '80vh';
      img.style.display = 'block';
      img.style.margin = '0 auto';
      body.appendChild(img);
    } else if (ext === 'pdf') {
      const iframe = document.createElement('iframe');
      iframe.src = path + '#view=FitH';
      iframe.style.width = '100%';
      iframe.style.height = '80vh';
      iframe.style.border = '0';
      body.appendChild(iframe);
    } else {
      const p = document.createElement('p');
      p.className = 'm-3';
      p.textContent = 'Type de fichier non prévisualisable. Utilisez le bouton Télécharger.';
      body.appendChild(p);
    }

    new bootstrap.Modal(modal).show();
  }

  // Boutons "Voir" — data-file peut être: "uploads/xxx.pdf" OU '["uploads/a.jpg","uploads/b.pdf"]'
  
})();

/* ====== Ordonnance : ouverture non bloquante, sans backdrop ====== */
(() => {
  const ordoModal   = document.getElementById('ordoModal');
  const bodyEl      = document.getElementById('ordoModalBody');
  const downloadBtn = document.getElementById('ordoDownload');

  if (!ordoModal || !bodyEl || !downloadBtn) return;

  document.addEventListener('click', (e) => {
    const trigger = e.target.closest('.btn-ordonnance');
    if (!trigger) return;

    const type = trigger.getAttribute('data-ordo-type') || trigger.getAttribute('data-kind');
    const id   = trigger.getAttribute('data-ordo-id')   || trigger.getAttribute('data-id');

    // Reset UI
    bodyEl.innerHTML = '<div class="text-center text-muted py-5"><div class="spinner-border me-2" role="status"></div> Chargement…</div>';
    downloadBtn.classList.add('d-none');
    downloadBtn.removeAttribute('href');

    // Ouvre la modale SANS backdrop (une seule instance, contrôlée ici)
    const modal = bootstrap.Modal.getOrCreateInstance(ordoModal, { backdrop: false, keyboard: true });
    modal.show();

    if (!type || !id) {
      bodyEl.innerHTML = '<div class="alert alert-danger m-0">Paramètres manquants.</div>';
      return;
    }

    const url = `api/ordonnances.php?type=${encodeURIComponent(type)}&id=${encodeURIComponent(id)}`;

    fetch(url, { headers: { 'Accept': 'application/json' }})
      .then(async (r) => {
        const ct = r.headers.get('content-type') || '';
        if (ct.includes('application/json')) {
          const j = await r.json().catch(() => null);
          if (j && j.status === 'ok') {
            bodyEl.innerHTML = j.html || '<div class="alert alert-warning m-0">Aucun contenu.</div>';
            if (j.pdf_url) { downloadBtn.href = j.pdf_url; downloadBtn.classList.remove('d-none'); }
          } else {
            bodyEl.innerHTML = '<div class="alert alert-warning m-0">Aucun contenu.</div>';
          }
        } else {
          const html = await r.text().catch(() => '');
          bodyEl.innerHTML = html || '<div class="alert alert-warning m-0">Aucun contenu.</div>';
        }
      })
      .catch(() => {
        bodyEl.innerHTML = '<div class="alert alert-danger m-0">Impossible de charger l’ordonnance.</div>';
      });
  });

  // Nettoyage défensif
  ordoModal.addEventListener('hidden.bs.modal', () => {
    document.querySelectorAll('.modal-backdrop').forEach(el => el.remove());
    document.body.classList.remove('modal-open');
    document.body.style.removeProperty('padding-right');
  });
})();


/* ====== Assurer que les modales "Voir" (Analyses/Imageries) sont au top du DOM ====== */
document.addEventListener('DOMContentLoaded', function(){
  document.querySelectorAll('.vl-view-modal').forEach(function(m){
    if (m.parentElement !== document.body) document.body.appendChild(m);
  });
});

(() => {
  const modal = document.getElementById('patientAiModal');
  if (!modal) return;

  const patientId = <?= json_encode((int)$patient_id) ?>;
  const csrfToken = <?= json_encode(csrf_token(), JSON_UNESCAPED_UNICODE) ?>;
  const choices = document.getElementById('pvAiChoices');
  const loading = document.getElementById('pvAiLoading');
  const resultWrap = document.getElementById('pvAiResultWrap');
  const resultTitle = document.getElementById('pvAiResultTitle');
  const resultBody = document.getElementById('pvAiResultBody');
  const errorBox = document.getElementById('pvAiError');
  const backBtn = document.getElementById('pvAiBackBtn');

  function showState(state, payload = {}) {
    choices.classList.toggle('d-none', state !== 'choices');
    loading.classList.toggle('d-none', state !== 'loading');
    resultWrap.classList.toggle('d-none', state !== 'result');
    errorBox.classList.add('d-none');
    errorBox.textContent = '';

    if (state === 'result') {
      resultTitle.textContent = payload.title || 'Résumé';
      resultBody.textContent = payload.body || '';
    }
    if (state === 'error') {
      choices.classList.remove('d-none');
      errorBox.classList.remove('d-none');
      errorBox.textContent = payload.message || 'Impossible de générer le résumé.';
    }
  }

  async function requestSummary(mode) {
    showState('loading');

    const form = new FormData();
    form.append('csrf_token', csrfToken);
    form.append('patient_id', String(patientId));
    form.append('mode', mode);

    try {
      const res = await fetch('api/patient_ai_summary.php', {
        method: 'POST',
        body: form,
        headers: { 'Accept': 'application/json' },
      });
      const text = await res.text();
      let data = null;
      try {
        data = JSON.parse(text);
      } catch (_) {
        throw new Error('Réponse invalide du serveur');
      }

      if (!res.ok || !data || !data.ok) {
        throw new Error((data && data.msg) ? data.msg : 'Impossible de générer le résumé');
      }

      showState('result', {
        title: data.label || 'Résumé',
        body: data.summary || '',
      });
    } catch (err) {
      showState('error', {
        message: err && err.message ? err.message : 'Impossible de générer le résumé',
      });
    }
  }

  choices?.addEventListener('click', (e) => {
    const btn = e.target.closest('[data-mode]');
    if (!btn) return;
    requestSummary(btn.getAttribute('data-mode') || 'latest');
  });

  backBtn?.addEventListener('click', () => showState('choices'));
  modal.addEventListener('hidden.bs.modal', () => showState('choices'));
})();
</script>
