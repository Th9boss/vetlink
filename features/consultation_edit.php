<?php
// features/consultation_edit.php
// Consultations + Analyses + Imageries
// - Motif (rouge) & Poids (vert, gras, 3 chiffres) sur la même ligne
// - "Examen clinique" : presets injectables
// - Fichiers: plus d’aperçus inline ; bouton "Voir" ouvre un modal plein écran (pile verticale, Télécharger + Supprimer)
// - Imageries: Compte-rendu inline (édition -> OK AJAX via api/cr-api.php -> affiche texte ; clic pour rééditer)
// - Capture (caméra/uploader) via capture_handle()

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/capture.php';

require_login();

/* ===================== Helpers ===================== */

function uploads_base_dir(): string {
    $dir = realpath(__DIR__ . '/../uploads');
    if ($dir === false) { $dir = __DIR__ . '/../uploads'; @mkdir($dir, 0775, true); }
    return $dir;
}
function ensure_dir(string $dir): void { if (!is_dir($dir)) @mkdir($dir, 0775, true); }

function sanitize_filename(string $name): string {
    $name = preg_replace('/[^A-Za-z0-9_\-.]/', '_', $name);
    return substr($name, 0, 180);
}
function is_allowed_upload(array $file): bool {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) return false;
    if (($file['size'] ?? 0) <= 0) return false;
    if ($file['size'] > 12 * 1024 * 1024) return false;
    $ext = strtolower(pathinfo($file['name'] ?? '', PATHINFO_EXTENSION));
    return in_array($ext, ['jpg','jpeg','png','gif','pdf'], true);
}
function auto_orient_and_compress(string $absolutePath): void {
    if (!is_file($absolutePath)) return;
    $info = @getimagesize($absolutePath);
    if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG], true)) return;
    $src = ($info[2] === IMAGETYPE_JPEG) ? @imagecreatefromjpeg($absolutePath) : @imagecreatefrompng($absolutePath);
    if (!$src) return;
    $orientation = 1;
    if ($info[2] === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
        $exif = @exif_read_data($absolutePath);
        if (!empty($exif['Orientation'])) $orientation = (int)$exif['Orientation'];
    }
    $dst = $src;
    switch ($orientation) {
        case 3: $dst = imagerotate($src, 180, 0); break;
        case 6: $dst = imagerotate($src, -90, 0); break;
        case 8: $dst = imagerotate($src, 90, 0); break;
    }
    @imagejpeg($dst, $absolutePath, 86);
    if ($dst !== $src) @imagerestroy($dst);
    @imagerestroy($src);
}
function compress_image_if_needed(string $absolutePath): void { auto_orient_and_compress($absolutePath); }

function save_uploaded(array $file, string $targetDir, string $basenamePrefix = ''): ?string {
    if (!is_allowed_upload($file)) return null;
    ensure_dir($targetDir);
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $clean = sanitize_filename(pathinfo($file['name'], PATHINFO_FILENAME));
    $fname = ($basenamePrefix ? $basenamePrefix.'_' : '') . $clean . '_' . date('Ymd_His') . '.' . $ext;
    $abs = rtrim($targetDir,'/\\') . '/' . $fname;
    if (!@move_uploaded_file($file['tmp_name'], $abs)) return null;
    if (in_array($ext, ['jpg','jpeg','png'], true)) compress_image_if_needed($abs);
    $uploadsRoot = realpath(__DIR__ . '/../uploads') ?: (__DIR__ . '/../uploads');
    $rel = 'uploads/' . ltrim(str_replace($uploadsRoot, '', $abs), '/\\');
    return preg_replace('#/+#', '/', $rel);
}
function save_dataurl_image(string $dataUrl, string $targetDir, string $basenamePrefix): ?string {
    if (!preg_match('#^data:image/(png|jpeg|jpg);base64,#i', $dataUrl, $m)) return null;
    $ext = strtolower($m[1]) === 'png' ? 'png' : 'jpg';
    $b64 = substr($dataUrl, strpos($dataUrl, ',') + 1);
    $bin = base64_decode($b64);
    if ($bin === false || strlen($bin) === 0) return null;
    ensure_dir($targetDir);
    $fname = $basenamePrefix . '_' . date('Ymd_His') . '.' . $ext;
    $abs = rtrim($targetDir,'/\\') . '/' . $fname;
    @file_put_contents($abs, $bin);
    compress_image_if_needed($abs);
    $uploadsRoot = realpath(__DIR__ . '/../uploads') ?: (__DIR__ . '/../uploads');
    $rel = 'uploads/' . ltrim(str_replace($uploadsRoot, '', $abs), '/\\');
    return preg_replace('#/+#', '/', $rel);
}

function json_paths_all(?string $json): array {
    if (!$json) return [];
    $v = json_decode($json, true);
    if (is_array($v)) {
        $out = [];
        foreach ($v as $x) if (is_string($x) && $x !== '') $out[] = $x;
        return $out;
    }
    if (is_string($v) && $v !== '') return [$v];
    return [];
}
function json_paths_union(?string $json, array $newPaths): string {
    $existing = json_paths_all($json);
    foreach ($newPaths as $p) if (is_string($p) && $p !== '' && !in_array($p, $existing, true)) $existing[] = $p;
    return json_encode(array_values($existing), JSON_UNESCAPED_SLASHES);
}
function json_paths_remove_path(?string $json, string $toRemove): string {
    $arr = array_values(array_filter(json_paths_all($json), fn($x) => $x !== $toRemove));
    return json_encode($arr, JSON_UNESCAPED_SLASHES);
}
function delete_if_local(string $rel): void {
    $uploadsRoot = realpath(__DIR__ . '/../uploads') ?: (__DIR__ . '/../uploads');
    $abs = $uploadsRoot . '/' . ltrim(preg_replace('#^uploads/#', '', $rel), '/\\');
    if (is_file($abs)) @unlink($abs);
}

/* ===================== Etat initial ===================== */

$consult_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$patient_id = isset($_GET['patient_id']) ? (int)$_GET['patient_id'] : 0;

$consult = null; $patient = null; $client = null;

if ($consult_id > 0) {
    $st = db()->prepare("
        SELECT c.*,
               p.id AS pid, p.nom AS patient_nom, p.espece, p.race, p.sexe, p.date_naissance, p.photo,
               cl.id AS client_id, cl.prenom AS client_prenom, cl.nom AS client_nom, cl.gsm AS client_gsm, cl.email AS client_email, cl.adresse AS client_adresse
        FROM consultations c
        JOIN patients p ON p.id = c.patient_id
        JOIN clients  cl ON cl.id = p.client_id
        WHERE c.id = ?
    ");
    $st->execute([$consult_id]);
    $consult = $st->fetch(PDO::FETCH_ASSOC);
    if ($consult) {
        $patient_id = (int)$consult['patient_id'];
        $patient = [
            'id'=>$consult['pid'],'nom'=>$consult['patient_nom'],'espece'=>$consult['espece'],'race'=>$consult['race'],
            'sexe'=>$consult['sexe'],'date_naissance'=>$consult['date_naissance'],'photo'=>$consult['photo'],
        ];
        $client = [
            'id'=>$consult['client_id'],'prenom'=>$consult['client_prenom'],'nom'=>$consult['client_nom'],
            'gsm'=>$consult['client_gsm'],'email'=>$consult['client_email'],'adresse'=>$consult['client_adresse']??null,
        ];
    }
}
if (!$consult && $patient_id > 0) {
    $sp = db()->prepare("
        SELECT p.*,
               cl.id AS client_id, cl.prenom AS client_prenom, cl.nom AS client_nom, cl.gsm AS client_gsm, cl.email AS client_email, cl.adresse AS client_adresse
        FROM patients p
        JOIN clients cl ON cl.id = p.client_id
        WHERE p.id = ?
    ");
    $sp->execute([$patient_id]);
    $row = $sp->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $patient = $row;
        $client = [
            'id'=>$row['client_id'],'prenom'=>$row['client_prenom'],'nom'=>$row['client_nom'],
            'gsm'=>$row['client_gsm'],'email'=>$row['client_email'],'adresse'=>$row['client_adresse']??null,
        ];
    }
}
if (!$patient) { echo '<div class="alert alert-danger m-3">Patient introuvable.</div>'; return; }

$baseUploads = uploads_base_dir();
$dirAnalyses = $baseUploads . '/reports/' . (int)$patient_id . '/analyses';
$dirImageries= $baseUploads . '/reports/' . (int)$patient_id . '/imageries';

/* ===================== Actions ===================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $act = $_POST['act'] ?? '';

    if ($act === 'capture_analyse_paths' && $consult_id > 0) {
        $aid = (int)($_POST['analyse_id'] ?? 0);
        $paths = json_decode($_POST['paths_json'] ?? '[]', true);
        if ($aid > 0 && is_array($paths) && !empty($paths)) {
            $g = db()->prepare("SELECT fichier_resultat FROM analyses WHERE id=? AND consultation_id=?");
            $g->execute([$aid, $consult_id]);
            $newJson = json_paths_union($g->fetchColumn(), $paths);
            db()->prepare("UPDATE analyses SET fichier_resultat=? WHERE id=?")->execute([$newJson, $aid]);
        }
        redirect('index.php?page=consultation_edit&id='.$consult_id.'&ok=analyse_files_updated');
    }

    if ($act === 'capture_imagerie_paths' && $consult_id > 0) {
        $imid = (int)($_POST['imagerie_id'] ?? 0);
        $paths = json_decode($_POST['paths_json'] ?? '[]', true);
        if ($imid > 0 && is_array($paths) && !empty($paths)) {
            $g = db()->prepare("SELECT fichiers FROM imageries WHERE id=? AND consultation_id=?");
            $g->execute([$imid, $consult_id]);
            $newJson = json_paths_union($g->fetchColumn(), $paths);
            db()->prepare("UPDATE imageries SET fichiers=? WHERE id=?")->execute([$newJson, $imid]);
        }
        redirect('index.php?page=consultation_edit&id='.$consult_id.'&ok=imagerie_files_updated');
    }

    if ($act === 'save_consult') {
        $date_consult = $_POST['date_consult'] ?: date('Y-m-d\TH:i');
        $motif   = trim($_POST['motif'] ?? '');
        $poids_raw = trim($_POST['poids'] ?? '');
$poids = null;
if ($poids_raw !== '') {
    // Accepte "12,4" ou "12.4"
    $norm = str_replace(',', '.', $poids_raw);
    if (is_numeric($norm)) {
        $poids = round((float)$norm, 2);          // garde 2 décimales en BDD
        if ($poids < 0)   $poids = 0.00;
        if ($poids > 999) $poids = 999.00;
    }
}
        $anamnese= trim($_POST['anamnese'] ?? '');
        $examen  = trim($_POST['examen'] ?? '');
        $diagnostic = trim($_POST['diagnostic'] ?? '');
        $traitement = trim($_POST['traitement'] ?? '');
        $comment_fact = trim($_POST['commentaire_facturation'] ?? '');
        $montant_ligne = ($_POST['montant_ligne'] ?? '') === '' ? null : (float)$_POST['montant_ligne'];
        $date_db = str_replace('T', ' ', $date_consult).':00';

        if ($consult_id > 0) {
            db()->prepare("UPDATE consultations 
                SET date_consult=?, motif=?, poids=?, anamnese=?, examen=?, diagnostic=?, traitement=?, 
                    commentaire_facturation=?, montant_ligne=?, updated_at=NOW() 
                WHERE id=?")
              ->execute([$date_db,$motif,$poids,$anamnese,$examen,$diagnostic,$traitement,$comment_fact,$montant_ligne,$consult_id]);
        } else {
            $praticien_id = current_user()['id'] ?? null;
            db()->prepare("INSERT INTO consultations 
                (patient_id, praticien_id, date_consult, motif, poids, anamnese, examen, diagnostic, traitement, commentaire_facturation, montant_ligne, created_at, updated_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())")
              ->execute([$patient_id,$praticien_id,$date_db,$motif,$poids,$anamnese,$examen,$diagnostic,$traitement,$comment_fact,$montant_ligne]);
            $consult_id = (int)db()->lastInsertId();
            redirect('index.php?page=consultation_edit&id='.$consult_id);
        }
        redirect('index.php?page=consultation_edit&id='.$consult_id.'&ok=saved');
    }

    if ($act === 'add_analyse' && $consult_id > 0) {
        $type = trim($_POST['type_analyse'] ?? '');
        $prix = (float)($_POST['prix'] ?? 0);
        db()->prepare("INSERT INTO analyses (consultation_id, type_analyse, prix, created_at) VALUES (?, ?, ?, NOW())")
           ->execute([$consult_id, $type, $prix]);
        redirect('index.php?page=consultation_edit&id='.$consult_id.'&ok=analyse_added');
    }

    if ($act === 'add_imagerie' && $consult_id > 0) {
        $type = trim($_POST['type_imagerie'] ?? '');
        $prix = (float)($_POST['prix'] ?? 0);
        db()->prepare("INSERT INTO imageries (consultation_id, type_imagerie, prix, created_at) VALUES (?, ?, ?, NOW())")
           ->execute([$consult_id, $type, $prix]);
        redirect('index.php?page=consultation_edit&id='.$consult_id.'&ok=imagerie_added');
    }

    if ($act === 'upload_analyse_files' && $consult_id > 0) {
        $aid = (int)($_POST['analyse_id'] ?? 0);
        if ($aid > 0 && !empty($_FILES['analyse_files'])) {
            $g = db()->prepare("SELECT fichier_resultat FROM analyses WHERE id=? AND consultation_id=?");
            $g->execute([$aid, $consult_id]);
            $oldJson = $g->fetchColumn();

            $saved = [];
            if (is_array($_FILES['analyse_files']['name'] ?? null)) {
                foreach (array_keys($_FILES['analyse_files']['name']) as $k) {
                    $sub = [
                        'name' => $_FILES['analyse_files']['name'][$k] ?? '',
                        'type' => $_FILES['analyse_files']['type'][$k] ?? '',
                        'tmp_name' => $_FILES['analyse_files']['tmp_name'][$k] ?? '',
                        'error' => $_FILES['analyse_files']['error'][$k] ?? UPLOAD_ERR_NO_FILE,
                        'size' => $_FILES['analyse_files']['size'][$k] ?? 0,
                    ];
                    if (is_allowed_upload($sub)) {
                        $rel = save_uploaded($sub, $dirAnalyses, 'an_'.$aid);
                        if ($rel) $saved[] = $rel;
                    }
                }
            }
            if ($saved) {
                $newJson = json_paths_union($oldJson, $saved);
                db()->prepare("UPDATE analyses SET fichier_resultat=? WHERE id=?")->execute([$newJson, $aid]);
            }
        }
        redirect('index.php?page=consultation_edit&id='.$consult_id.'&ok=analyse_files_updated');
    }

    if ($act === 'upload_analyse_camera' && $consult_id > 0) {
        $aid = (int)($_POST['analyse_id'] ?? 0);
        $dataUrl = $_POST['camera_image'] ?? '';
        if ($aid > 0 && $dataUrl) {
            $g = db()->prepare("SELECT fichier_resultat FROM analyses WHERE id=? AND consultation_id=?");
            $g->execute([$aid, $consult_id]);
            $rel = save_dataurl_image($dataUrl, $dirAnalyses, 'an_'.$aid);
            if ($rel) {
                $newJson = json_paths_union($g->fetchColumn(), [$rel]);
                db()->prepare("UPDATE analyses SET fichier_resultat=? WHERE id=?")->execute([$newJson, $aid]);
            }
        }
        redirect('index.php?page=consultation_edit&id='.$consult_id.'&ok=analyse_cam_added');
    }

    if ($act === 'upload_imagerie_files' && $consult_id > 0) {
        $imid = (int)($_POST['imagerie_id'] ?? 0);
        if ($imid > 0 && !empty($_FILES['imagerie_files'])) {
            $g = db()->prepare("SELECT fichiers FROM imageries WHERE id=? AND consultation_id=?");
            $g->execute([$imid, $consult_id]);
            $oldJson = $g->fetchColumn();

            $saved = [];
            if (is_array($_FILES['imagerie_files']['name'] ?? null)) {
                foreach (array_keys($_FILES['imagerie_files']['name']) as $k) {
                    $sub = [
                        'name' => $_FILES['imagerie_files']['name'][$k] ?? '',
                        'type' => $_FILES['imagerie_files']['type'][$k] ?? '',
                        'tmp_name' => $_FILES['imagerie_files']['tmp_name'][$k] ?? '',
                        'error' => $_FILES['imagerie_files']['error'][$k] ?? UPLOAD_ERR_NO_FILE,
                        'size' => $_FILES['imagerie_files']['size'][$k] ?? 0,
                    ];
                    if (is_allowed_upload($sub)) {
                        $rel = save_uploaded($sub, $dirImageries, 'im_'.$imid);
                        if ($rel) $saved[] = $rel;
                    }
                }
            }
            if ($saved) {
                $newJson = json_paths_union($oldJson, $saved);
                db()->prepare("UPDATE imageries SET fichiers=? WHERE id=?")->execute([$newJson, $imid]);
            }
        }
        redirect('index.php?page=consultation_edit&id='.$consult_id.'&ok=imagerie_files_updated');
    }

    if ($act === 'upload_imagerie_camera' && $consult_id > 0) {
        $imid = (int)$_POST['imagerie_id'];
        $dataUrl = $_POST['camera_image'] ?? '';
        if ($imid > 0 && $dataUrl) {
            $g = db()->prepare("SELECT fichiers FROM imageries WHERE id=? AND consultation_id=?");
            $g->execute([$imid, $consult_id]);
            $rel = save_dataurl_image($dataUrl, $dirImageries, 'im_'.$imid);
            if ($rel) {
                $newJson = json_paths_union($g->fetchColumn(), [$rel]);
                db()->prepare("UPDATE imageries SET fichiers=? WHERE id=?")->execute([$newJson, $imid]);
            }
        }
        redirect('index.php?page=consultation_edit&id='.$consult_id.'&ok=imagerie_cam_added');
    }

    if ($act === 'delete_analyse_one_file' && $consult_id > 0) {
        $aid = (int)($_POST['analyse_id'] ?? 0);
        $file = trim($_POST['file_path'] ?? '');
        if ($aid > 0 && $file) {
            $g = db()->prepare("SELECT fichier_resultat FROM analyses WHERE id=? AND consultation_id=?");
            $g->execute([$aid, $consult_id]);
            delete_if_local($file);
            db()->prepare("UPDATE analyses SET fichier_resultat=? WHERE id=?")
               ->execute([ json_paths_remove_path($g->fetchColumn(), $file), $aid ]);
        }
        redirect('index.php?page=consultation_edit&id='.$consult_id.'&ok=analyse_file_deleted');
    }

    if ($act === 'delete_imagerie_one_file' && $consult_id > 0) {
        $imid = (int)($_POST['imagerie_id'] ?? 0);
        $file = trim($_POST['file_path'] ?? '');
        if ($imid > 0 && $file) {
            $g = db()->prepare("SELECT fichiers FROM imageries WHERE id=? AND consultation_id=?");
            $g->execute([$imid, $consult_id]);
            delete_if_local($file);
            db()->prepare("UPDATE imageries SET fichiers=? WHERE id=?")
               ->execute([ json_paths_remove_path($g->fetchColumn(), $file), $imid ]);
        }
        redirect('index.php?page=consultation_edit&id='.$consult_id.'&ok=imagerie_file_deleted');
    }
}

/* ===================== Récupération listes ===================== */
$analyses = $imageries = [];
if ($consult_id > 0) {
    $sa = db()->prepare("SELECT * FROM analyses WHERE consultation_id=? ORDER BY id ASC");
    $sa->execute([$consult_id]);
    $analyses = $sa->fetchAll(PDO::FETCH_ASSOC);

    $si = db()->prepare("SELECT * FROM imageries WHERE consultation_id=? ORDER BY id ASC");
    $si->execute([$consult_id]);
    $imageries = $si->fetchAll(PDO::FETCH_ASSOC);
}

/* ====== Presets examen / liste analyses ====== */
$EXAM_PRESETS = [
  'Examen général' => "T: °C | FC: bpm | FR: /min | Muqueuses: roses humides | Gg: NOR | Hydratation: NOR | Douleur: non | Etat général: BON",
  'Cardiaque'      => "Auscultation: RAS | Souffle: non | Pouls: symétrique | FC: bpm | RC: régulier",
  'Respiratoire'   => "Auscultation: claire | Efforts: non | FR: /min | Toux: non | Dyspnée: non",
  'Digestif'       => "Appétit: NOR | Vomissements: non | Diarrhée: non | Douleurs abdominales: non",
  'Dermato'        => "Lésions: aucune | Prurit: non | Ectoparasites: non | Peau/poil: NOR",
];
$ANALYSES_OPTIONS = ['Biochimie','NFS','Hématologie','Ionogramme','CRP','Bilan hépatique','Bilan rénal','Glycémie','Fructosamine','T4','Cortisol','Parvovirose','Leishmaniose','FIV/FeLV','Coprologie','PCR','Urinanalyse','SNAP'];
?>
<style>
/* ——— Styles ——— */
.vl-patient-photo{width:64px;height:64px;object-fit:cover;border-radius:10px;cursor:pointer}
@media (min-width: 576px){ .vl-patient-aside{min-width:280px} }
.motif-red input{color:#c1121f;font-weight:700}
.poids-green input{background:#ecfff1;border-color:#d6f5dd;font-weight:700;max-width:7ch;text-align:center}
.vl-chip{display:inline-block;padding:.2rem .5rem;border-radius:999px;background:#f6f7f9;font-size:.85rem}
.vl-filecard{border:1px solid #eee;border-radius:10px;padding:.75rem;background:#fff;margin-bottom:.75rem}
.vl-filelist{max-height:85vh;overflow:auto}
.vl-price-strong input{font-weight:700}
.badge-soft{background:#eef2ff;color:#3949ab;border-radius:999px;padding:.2rem .6rem;font-size:.75rem}

/* ── Assistant Vocal ── */
.vl-voice-box{background:linear-gradient(135deg,#f0f7ff 0%,#e8f4fd 100%);border:1px solid #b3d9f5;border-radius:.75rem;transition:box-shadow .2s}
.vl-voice-box.is-recording{border-color:#dc3545;background:linear-gradient(135deg,#fff5f5 0%,#ffe9e9 100%);box-shadow:0 0 0 3px rgba(220,53,69,.12)}
.vl-mic-btn{width:52px;height:52px;padding:0;border-radius:50%;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-size:1.25rem;transition:transform .15s}
.vl-mic-btn:active{transform:scale(.92)}
@keyframes vl-bar-bounce{0%,100%{transform:scaleY(.35)}50%{transform:scaleY(1)}}
.vl-pulse-bar{display:inline-block;width:4px;height:22px;border-radius:2px;background:#dc3545;transform-origin:bottom;animation:vl-bar-bounce .7s ease-in-out infinite}
.vl-help-btn{width:22px;height:22px;padding:0;border-radius:50%;font-size:.7rem;flex-shrink:0;display:inline-flex;align-items:center;justify-content:center;opacity:.65;transition:opacity .15s}
.vl-help-btn:hover{opacity:1}
/* Mobile-first : formulaire */
@media (max-width:575px){
  .vl-pulse-bars{display:none!important}
  .vl-voice-box{flex-wrap:wrap;gap:.75rem!important}
  .vl-voice-timer{font-size:.95rem!important}
  .vl-exam-label{flex-direction:column!important;align-items:flex-start!important}
  .vl-exam-label .vl-preset-wrap{width:100%}
  .vl-exam-label .vl-preset-wrap select{max-width:100%!important;width:100%}
  .poids-green input{max-width:100%!important}
  .vl-submit-row{flex-direction:column!important}
  .vl-submit-row .btn{width:100%}
}
</style>

<div class="container my-3">
  <?php if (!empty($_GET['ok'])): ?><div class="alert alert-success"><?= h($_GET['ok']) ?></div><?php endif; ?>

  <!-- En-tête patient -->
  <div class="card mb-4">
    <div class="card-body">
      <div class="d-flex flex-column flex-sm-row align-items-start align-items-sm-center justify-content-between gap-3">
        <div class="d-flex align-items-center gap-3">
          <?php if (!empty($patient['photo'])): ?>
            <img src="<?= h($patient['photo']) ?>" alt="Photo" class="vl-patient-photo" data-bs-toggle="modal" data-bs-target="#modalPatientPhoto">
          <?php else: ?>
            <div class="vl-patient-photo d-flex align-items-center justify-content-center bg-light">🐾</div>
          <?php endif; ?>
          <div>
            <div class="h5 mb-1">
              <?= h($patient['nom']) ?>
              <small class="text-muted">[<?= h($patient['espece'] ?: '—') ?><?= $patient['race']?' · '.h($patient['race']):'' ?>]</small>
            </div>
            <div class="text-muted small">Sexe : <?= h($patient['sexe'] ?? '—') ?><?= $patient['date_naissance'] ? ' · Né(e) le '.h($patient['date_naissance']) : '' ?></div>
          </div>
        </div>
        <?php if ($client): ?>
        <div class="vl-patient-aside">
          <div class="p-3 rounded border">
            <div class="fw-semibold mb-1">Propriétaire</div>
            <div class="mb-1"><?= h(($client['prenom'] ?? '').' '.($client['nom'] ?? '')) ?></div>
            <?php if (!empty($client['gsm'])): ?><div class="mb-1"><span class="vl-chip">📞 <?= h($client['gsm']) ?></span></div><?php endif; ?>
            <?php if (!empty($client['email'])): ?><div class="mb-1"><span class="vl-chip">✉️ <?= h($client['email']) ?></span></div><?php endif; ?>
            <?php if (!empty($client['adresse'])): ?><div class="text-muted small mb-2"><?= nl2br(h($client['adresse'])) ?></div><?php endif; ?>
            <a class="btn btn-sm btn-primary w-100" href="index.php?page=patient_view&id=<?= (int)$patient['id'] ?>">Historique complet</a>
          </div>
        </div>
        <?php endif; ?>
      </div>

      <?php
$stW = db()->prepare("SELECT date_consult, poids 
                      FROM consultations 
                      WHERE patient_id=? AND poids IS NOT NULL 
                      ORDER BY date_consult ASC");
$stW->execute([$patient['id']]);
$weights = $stW->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="mt-3">
  <button id="toggleWeightBtn" class="btn btn-outline-dark btn-sm">➕ Courbe de poids</button>
  <div id="weightChartWrap" class="collapse mt-3">
    <div style="height:260px"><canvas id="weightChart"></canvas></div>
  </div>
</div>

<?php if ($weights): ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels@2" crossorigin="anonymous"></script>
<script>
document.addEventListener("DOMContentLoaded", function(){
  // Enregistrer le plugin AVANT de créer le chart
  Chart.register(ChartDataLabels);

  const labels = <?= json_encode(array_map(fn($w)=>date('d/m/Y', strtotime($w['date_consult'])), $weights)) ?>;
  const values = <?= json_encode(array_map(fn($w)=>(float)$w['poids'], $weights), JSON_NUMERIC_CHECK) ?>;

  const ctx = document.getElementById("weightChart").getContext("2d");
  new Chart(ctx, {
    type: "line",
    data: {
      labels,
      datasets: [{
        label: "Poids (kg)",
        data: values,
        tension: 0.3,
        fill: true,
        borderWidth: 2,
        pointRadius: 4,
        pointHoverRadius: 6
      }]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: { display: false },
        datalabels: {
          align: 'top',
          anchor: 'end',
          color: '#444',
          font: { size: 9 },
          offset: 4,
          clip: false,
          formatter: (v) => (typeof v === 'number' ? v.toFixed(1) : v) + 'kg'
        }
      },
      interaction: { mode: 'nearest', intersect: false },
      scales: {
        x: { ticks: { maxRotation: 0, autoSkip: true } },
        y: {
          title: { display: true, text: 'Poids (kg)' },
          beginAtZero: true
        }
      }
    }
  });

  // Bouton +/-
  const btn = document.getElementById("toggleWeightBtn");
  const wrap = document.getElementById("weightChartWrap");
  btn.addEventListener("click", function(){
    const open = wrap.classList.toggle("show");
    btn.textContent = open ? "➖ Courbe de poids" : "➕ Courbe de poids";
  });
});
</script>
<?php endif; ?>
    </div>
  </div>

  <!-- Modale Photo Patient -->
  <div class="modal fade" id="modalPatientPhoto" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-xl">
      <div class="modal-content">
        <div class="modal-body p-0" style="max-height:85vh;overflow:auto">
          <?php if (!empty($patient['photo'])): ?>
            <img src="<?= h($patient['photo']) ?>" alt="Patient" style="width:100%;height:auto;display:block">
          <?php else: ?>
            <div class="p-4 text-center text-muted">Aucune photo</div>
          <?php endif; ?>
        </div>
        <div class="modal-footer">
          <button class="btn btn-secondary" type="button" data-bs-dismiss="modal">Fermer</button>
        </div>
      </div>
    </div>
  </div>

  <!-- Formulaire consultation -->
  <div class="card mb-4">
    <div class="card-body">
      <form method="post">
        <?= csrf_input() ?>
        <input type="hidden" name="act" value="save_consult">
        <div class="row g-3 align-items-end">
          <div class="col-md-3">
            <label class="form-label">Date/heure</label>
            <input type="datetime-local" name="date_consult" class="form-control"
                   value="<?= h(isset($consult['date_consult']) ? str_replace(' ', 'T', substr($consult['date_consult'],0,16)) : date('Y-m-d\TH:i')) ?>">
          </div>
          <div class="col-md-7 motif-red">
            <label class="form-label">Motif</label>
            <input type="text" name="motif" class="form-control" value="<?= h($consult['motif'] ?? '') ?>">
          </div>
          <div class="col-md-2 poids-green">
  <label class="form-label">Poids (kg)</label>
  <input
    type="text"
    name="poids"
    class="form-control"
    value="<?= isset($consult['poids']) && $consult['poids'] !== null ? h(str_replace('.', ',', (string)$consult['poids'])) : '' ?>"
    inputmode="decimal"
    placeholder="ex: 12,4"
    maxlength="7"
    oninput="
      // autoriser chiffres, virgule et point, remplacer multiples séparateurs
      this.value = this.value.replace(/[^0-9,.\s]/g,'').replace(/\s+/g,'');
      // empêcher plusieurs virgules/points
      const parts = this.value.replace('.', ',').split(',');
      if (parts.length > 2) { this.value = parts[0] + ',' + parts.slice(1).join('').replace(/,/g,''); }
    "
  >
</div>

          <div class="col-md-9">
            <label class="form-label">Commentaire facturation</label>
            <input type="text" name="commentaire_facturation" class="form-control" value="<?= h($consult['commentaire_facturation'] ?? '') ?>">
          </div>
          <div class="col-md-3 vl-price-strong">
            <label class="form-label">Prix (DH)</label>
            <input type="number" name="montant_ligne" class="form-control" step="0.01" min="0" value="<?= h($consult['montant_ligne'] ?? '') ?>">
          </div>

          <div class="col-md-9">
            <label class="form-label d-flex justify-content-between align-items-center gap-2 vl-exam-label">
              <span>Examen clinique</span>
              <span class="vl-preset-wrap d-inline-flex gap-2">
                <select class="form-select form-select-sm" id="examPreset" style="max-width:260px">
                  <option value="">🧾 Insérer un preset…</option>
                  <?php foreach ($EXAM_PRESETS as $k=>$v): ?>
                    <option value="<?= h($v) ?>"><?= h($k) ?></option>
                  <?php endforeach; ?>
                </select>
              </span>
            </label>
            <textarea id="examArea" name="examen" class="form-control" rows="3"><?= h($consult['examen'] ?? '') ?></textarea>
          </div>
          <script>
          document.addEventListener('change', function(e){
            if(e.target && e.target.id==='examPreset' && e.target.value){
              var ta=document.getElementById('examArea');
              if(ta){
                if(!ta.value.trim()) ta.value=e.target.value;
                else ta.value = ta.value.replace(/\s*$/,'') + "\n" + e.target.value;
                ta.focus();
              }
              e.target.selectedIndex=0;
            }
          });
          </script>

          <!-- ANAMNESE -->
<div class="col-md-3">
  <div class="d-flex align-items-center justify-content-between">
    <label class="form-label mb-0" for="anamnese">Anamnèse</label>
    <button class="btn btn-sm btn-outline-secondary" type="button"
            data-bs-toggle="collapse" data-bs-target="#anamneseWrap" aria-expanded="false">+</button>
  </div>
  <div id="anamneseWrap" class="collapse mt-2">
    <textarea id="anamnese" name="anamnese" class="form-control" rows="3"><?= h($consult['anamnese'] ?? '') ?></textarea>
  </div>
</div>

<!-- DIAGNOSTIC -->
<div class="col-md-6">
  <div class="d-flex align-items-center justify-content-between">
    <label class="form-label mb-0" for="diagnostic">Diagnostic</label>
    <button class="btn btn-sm btn-outline-secondary" type="button"
            data-bs-toggle="collapse" data-bs-target="#diagnosticWrap" aria-expanded="false">+</button>
  </div>
  <div id="diagnosticWrap" class="collapse mt-2">
    <textarea id="diagnostic" name="diagnostic" class="form-control" rows="2"><?= h($consult['diagnostic'] ?? '') ?></textarea>
  </div>
</div>

<!-- TRAITEMENT -->
<div class="col-md-6">
  <div class="d-flex align-items-center justify-content-between">
    <label class="form-label mb-0" for="traitement">Traitement</label>
    <button class="btn btn-sm btn-outline-secondary" type="button"
            data-bs-toggle="collapse" data-bs-target="#traitementWrap" aria-expanded="false">+</button>
  </div>
  <div id="traitementWrap" class="collapse mt-2">
    <textarea id="traitement" name="traitement" class="form-control" rows="2"><?= h($consult['traitement'] ?? '') ?></textarea>
  </div>
</div>
        </div>

        <!-- ── Assistant Vocal IA ───────────────────────────────────────── -->
        <div class="mt-3" x-data="voiceConsult()" x-init="init()">
          <div class="vl-voice-box p-3 d-flex align-items-center gap-3"
               :class="{'is-recording': state==='recording'}">

            <!-- Bouton micro / stop / spinner -->
            <button type="button" class="vl-mic-btn btn shadow-sm"
                    :class="{
                      'btn-danger':  state==='recording',
                      'btn-primary': state!=='recording' && state!=='processing',
                      'btn-secondary': state==='processing'
                    }"
                    @click="toggle()"
                    :disabled="state==='processing'">
              <template x-if="state==='recording'">
                <i class="bi bi-stop-fill"></i>
              </template>
              <template x-if="state==='processing'">
                <span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>
              </template>
              <template x-if="state!=='recording' && state!=='processing'">
                <i class="bi bi-mic-fill"></i>
              </template>
            </button>

            <!-- Zone texte centrale -->
            <div class="flex-grow-1" style="min-width:0">

              <!-- Idle -->
              <template x-if="state==='idle'">
                <div>
                  <div class="d-flex align-items-center gap-2">
                    <span class="fw-semibold" style="color:#0e6ba8;font-size:.9rem">Assistant vocal</span>
                    <button type="button" class="vl-help-btn btn btn-outline-secondary border-0"
                            data-bs-toggle="modal" data-bs-target="#modalVoiceHelp"
                            title="Comment utiliser l'assistant vocal">
                      <i class="bi bi-question-circle-fill" style="color:#0e6ba8"></i>
                    </button>
                  </div>
                  <div class="text-muted" style="font-size:.8rem">Dictez la consultation — l'IA remplira les champs automatiquement.</div>
                </div>
              </template>

              <!-- Recording -->
              <template x-if="state==='recording'">
                <div class="d-flex align-items-center gap-3">
                  <div>
                    <div class="fw-semibold text-danger" style="font-size:.9rem">Enregistrement en cours…</div>
                    <div class="text-muted" style="font-size:.8rem">Parlez clairement. Arrêt auto à 120 s.</div>
                  </div>
                  <div class="ms-auto fw-bold text-danger vl-voice-timer" style="font-size:1.1rem;font-variant-numeric:tabular-nums" x-text="timerDisplay"></div>
                </div>
              </template>

              <!-- Processing -->
              <template x-if="state==='processing'">
                <div>
                  <div class="fw-semibold" style="color:#0e6ba8;font-size:.9rem">Analyse en cours…</div>
                  <div class="text-muted" style="font-size:.8rem">Transcription + extraction des champs</div>
                </div>
              </template>

              <!-- Done -->
              <template x-if="state==='done'">
                <div>
                  <div class="d-flex flex-wrap align-items-center gap-1 mb-1">
                    <span class="fw-semibold text-success" style="font-size:.85rem">Champs remplis :</span>
                    <template x-for="f in filledFields" :key="f.key">
                      <span class="badge badge-soft" x-text="f.label"></span>
                    </template>
                    <template x-if="filledFields.length===0">
                      <span class="text-muted" style="font-size:.8rem">Aucun champ reconnu.</span>
                    </template>
                  </div>
                  <div class="text-muted" style="font-size:.75rem;line-height:1.4"
                       x-text="transcript.length > 160 ? transcript.substring(0,160)+'…' : transcript"></div>
                </div>
              </template>

              <!-- Error -->
              <template x-if="state==='error'">
                <div>
                  <div class="fw-semibold text-danger" style="font-size:.9rem">Erreur</div>
                  <div class="text-muted" style="font-size:.8rem" x-text="errorMsg"></div>
                </div>
              </template>

            </div>

            <!-- Barres animées pendant l'enregistrement -->
            <template x-if="state==='recording'">
              <div class="vl-pulse-bars d-flex gap-1 align-items-center flex-shrink-0" style="height:28px">
                <span class="vl-pulse-bar"></span>
                <span class="vl-pulse-bar" style="animation-delay:.15s"></span>
                <span class="vl-pulse-bar" style="animation-delay:.30s"></span>
                <span class="vl-pulse-bar" style="animation-delay:.45s"></span>
                <span class="vl-pulse-bar" style="animation-delay:.60s"></span>
              </div>
            </template>

          </div>
        </div>

        <div class="mt-3 d-flex gap-2 vl-submit-row">
          <button id="btnSaveConsult" class="btn btn-primary" type="submit">Enregistrer</button>
          <a class="btn btn-outline-secondary" href="index.php?page=patient_view&id=<?= (int)$patient_id ?>">Retour fiche patient</a>
        </div>
      </form>
<script>
(function(){
  const motif = document.querySelector('[name="motif"]');
  const btn   = document.getElementById('btnSaveConsult');
  if (!motif || !btn) return;
  const sync = () => {
    const empty = motif.value.trim() === '';
    btn.disabled = empty;
    btn.classList.toggle('btn-secondary', empty);
    btn.classList.toggle('btn-primary',   !empty);
  };
  motif.addEventListener('input', sync);
  sync(); // état initial
})();
</script>

<script>
function voiceConsult() {
  return {
    state:        'idle', // idle | recording | processing | done | error
    mediaRecorder: null,
    chunks:        [],
    timerInterval: null,
    seconds:       0,
    transcript:    '',
    filledFields:  [],
    errorMsg:      '',
    MAX_SECONDS:   120,

    get timerDisplay() {
      const s = this.seconds;
      return String(Math.floor(s / 60)).padStart(2, '0') + ':' + String(s % 60).padStart(2, '0');
    },

    init() {},

    async toggle() {
      if (this.state === 'recording') {
        this.stopRecording();
      } else if (this.state !== 'processing') {
        this.state        = 'idle';
        this.filledFields = [];
        this.transcript   = '';
        this.errorMsg     = '';
        await this.startRecording();
      }
    },

    async startRecording() {
      this.chunks = [];
      let stream;
      try {
        stream = await navigator.mediaDevices.getUserMedia({ audio: true });
      } catch (_) {
        this.state    = 'error';
        this.errorMsg = 'Accès au microphone refusé. Vérifiez les permissions du navigateur.';
        return;
      }

      const mimeType = ['audio/webm;codecs=opus', 'audio/webm', 'audio/ogg;codecs=opus']
        .find(t => MediaRecorder.isTypeSupported(t)) || '';

      this.mediaRecorder = new MediaRecorder(stream, mimeType ? { mimeType } : {});

      this.mediaRecorder.ondataavailable = e => {
        if (e.data && e.data.size > 0) this.chunks.push(e.data);
      };

      this.mediaRecorder.onstop = () => {
        stream.getTracks().forEach(t => t.stop());
        const blob = new Blob(this.chunks, { type: mimeType || 'audio/webm' });
        this.sendAudio(blob);
      };

      this.mediaRecorder.start(250);
      this.state   = 'recording';
      this.seconds = 0;

      this.timerInterval = setInterval(() => {
        this.seconds++;
        if (this.seconds >= this.MAX_SECONDS) this.stopRecording();
      }, 1000);
    },

    stopRecording() {
      clearInterval(this.timerInterval);
      this.timerInterval = null;
      if (this.mediaRecorder && this.mediaRecorder.state !== 'inactive') {
        this.mediaRecorder.stop();
      }
      this.state = 'processing';
    },

    async sendAudio(blob) {
      const csrfInput = document.querySelector('input[name="csrf_token"]');
      const fd = new FormData();
      fd.append('audio', blob, 'enregistrement.webm');
      if (csrfInput) fd.append('csrf_token', csrfInput.value);

      try {
        const resp = await fetch('<?= base_url('api/voice_consult.php') ?>', {
          method: 'POST',
          body:   fd,
        });
        if (!resp.ok && resp.status !== 200) {
          const data = await resp.json().catch(() => ({}));
          this.state    = 'error';
          this.errorMsg = data.msg || 'Erreur serveur (' + resp.status + ').';
          return;
        }
        const data = await resp.json();
        if (!data.ok) {
          this.state    = 'error';
          this.errorMsg = data.msg || 'Erreur inconnue.';
          return;
        }
        this.transcript = data.transcript || '';
        this.fillForm(data.fields || {});
      } catch (_) {
        this.state    = 'error';
        this.errorMsg = 'Impossible de contacter le serveur. Vérifiez votre connexion.';
      }
    },

    fillForm(fields) {
      const append = fields.mode === 'append';

      // Helper : remplace ou ajoute selon le mode
      const set = (el, val) => {
        if (!el) return;
        if (append && el.value.trim() !== '') {
          el.value = el.value.trimEnd() + '\n' + val;
        } else {
          el.value = val;
        }
      };

      const MAP = [
        { key: 'motif',                  label: 'Motif',                 get: () => document.querySelector('[name="motif"]'), onSet: el => el.dispatchEvent(new Event('input')) },
        { key: 'poids',                  label: 'Poids',                 get: () => document.querySelector('[name="poids"]'),           noAppend: true },
        { key: 'commentaire_facturation',label: 'Commentaire fact.',     get: () => document.querySelector('[name="commentaire_facturation"]') },
        { key: 'montant_ligne',          label: 'Prix',                  get: () => document.querySelector('[name="montant_ligne"]'),   noAppend: true },
        { key: 'examen',                 label: 'Examen clinique',       get: () => document.getElementById('examArea') },
        { key: 'anamnese',               label: 'Anamnèse',              get: () => document.getElementById('anamnese'),                wrap: 'anamneseWrap' },
        { key: 'diagnostic',             label: 'Diagnostic',            get: () => document.getElementById('diagnostic'),              wrap: 'diagnosticWrap' },
        { key: 'traitement',             label: 'Traitement',            get: () => document.getElementById('traitement'),              wrap: 'traitementWrap' },
      ];

      this.filledFields = [];
      MAP.forEach(({ key, label, get, wrap, noAppend, onSet }) => {
        const val = fields[key];
        if (!val || typeof val !== 'string' || val.trim() === '') return;
        const el = get();
        if (!el) return;
        // Poids et prix : toujours remplacer (pas de sens d'ajouter)
        if (noAppend) {
          el.value = val;
        } else {
          set(el, val);
        }
        if (onSet) onSet(el);
        if (wrap) this._expand(wrap);
        this.filledFields.push({ key, label });
      });

      this.state = 'done';
    },

    _expand(wrapId) {
      const wrap = document.getElementById(wrapId);
      if (wrap && !wrap.classList.contains('show')) {
        if (typeof bootstrap !== 'undefined') {
          bootstrap.Collapse.getOrCreateInstance(wrap).show();
        } else {
          wrap.classList.add('show');
        }
      }
    },
  };
}
</script>

<!-- ── Modal : aide assistant vocal ─────────────────────────────── -->
<div class="modal fade" id="modalVoiceHelp" tabindex="-1" aria-labelledby="modalVoiceHelpLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header border-0 pb-0">
        <h5 class="modal-title fw-bold" id="modalVoiceHelpLabel" style="color:#0e6ba8">
          <i class="bi bi-mic-fill me-2"></i>Assistant vocal — Guide d'utilisation
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button>
      </div>
      <div class="modal-body pt-2">

        <!-- Démarrage -->
        <div class="mb-3 p-3 rounded-3" style="background:#f0f7ff">
          <div class="fw-semibold mb-1" style="color:#0e6ba8"><i class="bi bi-play-circle me-1"></i>Démarrer un enregistrement</div>
          <p class="mb-0 small text-muted">Appuyez sur le bouton <span class="badge bg-primary"><i class="bi bi-mic-fill"></i></span> pour commencer à dicter. L'enregistrement s'arrête automatiquement après <strong>120 secondes</strong> ou dès que vous appuyez sur <span class="badge bg-danger"><i class="bi bi-stop-fill"></i></span>.</p>
        </div>

        <!-- Champs reconnus -->
        <div class="mb-3">
          <div class="fw-semibold mb-2"><i class="bi bi-list-check me-1"></i>Champs reconnus automatiquement</div>
          <div class="row g-2">
            <div class="col-6"><span class="badge rounded-pill text-bg-light border w-100 py-2">Motif</span></div>
            <div class="col-6"><span class="badge rounded-pill text-bg-light border w-100 py-2">Poids (kg)</span></div>
            <div class="col-6"><span class="badge rounded-pill text-bg-light border w-100 py-2">Examen clinique</span></div>
            <div class="col-6"><span class="badge rounded-pill text-bg-light border w-100 py-2">Anamnèse</span></div>
            <div class="col-6"><span class="badge rounded-pill text-bg-light border w-100 py-2">Diagnostic</span></div>
            <div class="col-6"><span class="badge rounded-pill text-bg-light border w-100 py-2">Traitement</span></div>
            <div class="col-6"><span class="badge rounded-pill text-bg-light border w-100 py-2">Commentaire fact.</span></div>
            <div class="col-6"><span class="badge rounded-pill text-bg-light border w-100 py-2">Prix (DH)</span></div>
          </div>
        </div>

        <!-- Exemples -->
        <div class="mb-3">
          <div class="fw-semibold mb-2"><i class="bi bi-chat-quote me-1"></i>Exemples de dictée</div>
          <div class="vstack gap-2">
            <div class="p-2 rounded border-start border-3 border-primary bg-light small">
              <em>"Le motif de consultation est une boiterie du membre antérieur droit. Le poids est 28 virgule 5. À l'examen clinique, douleur à la palpation de l'articulation du coude. Diagnostic : arthrite. Traitement : anti-inflammatoire 3 jours."</em>
            </div>
            <div class="p-2 rounded border-start border-3 border-success bg-light small">
              <em>"Le prix de la consultation est 200 dirhams."</em><br>
              <span class="text-muted">→ Remplit uniquement le champ Prix.</span>
            </div>
          </div>
        </div>

        <!-- Mode ajoute -->
        <div class="mb-3 p-3 rounded-3" style="background:#f0fff4;border:1px solid #c3e6cb">
          <div class="fw-semibold mb-1 text-success"><i class="bi bi-plus-circle me-1"></i>Ajouter sans écraser</div>
          <p class="mb-0 small text-muted">Dites <strong>"ajoute"</strong>, <strong>"rajoute"</strong> ou <strong>"en plus"</strong> pour que le texte soit <em>ajouté</em> à la suite du contenu existant plutôt que de le remplacer.</p>
          <div class="mt-2 p-2 rounded border-start border-3 border-success bg-white small">
            <em>"Ajoute au traitement : contrôle dans 7 jours."</em>
          </div>
        </div>

        <!-- Conseils -->
        <div class="p-3 rounded-3" style="background:#fff8e1;border:1px solid #ffe082">
          <div class="fw-semibold mb-1" style="color:#b45309"><i class="bi bi-lightbulb me-1"></i>Conseils pour de meilleurs résultats</div>
          <ul class="mb-0 small text-muted ps-3">
            <li>Parlez clairement et à vitesse normale.</li>
            <li>Nommez explicitement les champs : <em>"le diagnostic est…"</em>, <em>"le traitement est…"</em></li>
            <li>Seuls les champs mentionnés sont remplis — les autres restent intacts.</li>
            <li>Poids et prix sont toujours remplacés, même avec "ajoute".</li>
          </ul>
        </div>

      </div>
      <div class="modal-footer border-0 pt-0">
        <button type="button" class="btn btn-primary w-100" data-bs-dismiss="modal">Compris !</button>
      </div>
    </div>
  </div>
</div>

    </div>
  </div>

  <?php if ($consult_id > 0): ?>
  <!-- ANALYSES -->
  <div class="card mb-4">
    <div class="card-header d-flex flex-column flex-md-row gap-2 justify-content-between align-items-md-center">
      <div class="h6 mb-0">Analyses</div>
      <form method="post" class="d-flex flex-column flex-sm-row gap-2 mb-0">
        <?= csrf_input() ?><input type="hidden" name="act" value="add_analyse">
        <select class="form-select form-select-sm" name="type_analyse" required>
          <option value="" disabled selected>Choisir une analyse…</option>
          <?php foreach ($ANALYSES_OPTIONS as $opt): ?><option value="<?= h($opt) ?>"><?= h($opt) ?></option><?php endforeach; ?>
        </select>
        <input type="number" step="0.01" class="form-control form-control-sm" name="prix" placeholder="Prix">
        <button class="btn btn-sm btn-success" type="submit">Ajouter</button>
      </form>
    </div>
    <div class="card-body p-0">
      <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
          <thead class="table-light">
            <tr>
              <th style="width:60px">#</th><th>Type</th><th>Résultat</th><th style="width:160px">Fichiers</th>
              <th style="width:100px" class="text-end">Prix</th><th style="width:220px">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!$analyses): ?>
              <tr><td colspan="6" class="text-center text-muted py-3">Aucune analyse</td></tr>
            <?php else: foreach ($analyses as $an): $files = json_paths_all($an['fichier_resultat'] ?? null); $aid=(int)$an['id']; ?>
              <tr>
                <td><?= $aid ?></td>
                <td><?= h($an['type_analyse'] ?? '—') ?></td>
                <td class="text-muted small"><?= h($an['resultat'] ?? '') ?></td>
                <td>
                  <?php $cnt=count($files); ?>
                  <?= $cnt ? '<span class="badge-soft">'.$cnt.' fichier(s)</span>' : '<span class="text-muted">—</span>' ?>
                </td>
                <td class="text-end"><?= h(number_format((float)($an['prix'] ?? 0), 2, '.', ' ')) ?></td>
                <td>
                  <div class="d-flex flex-wrap gap-2">
                    <?php if ($files): ?>
                      <button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="modal" data-bs-target="#modalVoirAnalyse<?= $aid ?>">Voir</button>
                    <?php endif; ?>
                    <button class="btn btn-sm btn-outline-primary" type="button"
                      onclick="openCaptureModal({ modalId:'capAnalyseModal',
                        onDone:(paths)=>vlSubmitPaths('capture_analyse_paths','analyse_id',<?= $aid ?>,paths) })">
                      + Fichiers
                    </button>
                  </div>
                </td>
              </tr>

              <!-- Modal Voir (Analyse) : plein écran, vertical, actions -->
              <div class="modal fade vl-nobackdrop-modal vl-move-to-body" id="modalVoirAnalyse<?= $aid ?>" tabindex="-1" data-bs-backdrop="false" data-bs-keyboard="true" aria-hidden="true">
                <div class="modal-dialog modal-xl modal-dialog-scrollable">
                  <div class="modal-content">
                    <div class="modal-header">
                      <h5 class="modal-title">Pièces jointes — Analyse #<?= $aid ?></h5>
                      <button class="btn-close" type="button" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body vl-filelist">
                      <?php if ($files): foreach ($files as $p): $ext = strtolower(pathinfo($p, PATHINFO_EXTENSION)); ?>
                        <div class="vl-filecard">
                          <?php if (in_array($ext, ['jpg','jpeg','png','gif'])): ?>
                            <img src="<?= h($p) ?>" style="width:100%;height:auto;max-height:75vh;object-fit:contain;border-radius:8px">
                          <?php else: ?>
                            <iframe src="<?= h($p) ?>#zoom=page-fit" style="width:100%;height:75vh;border:0;border-radius:8px;background:#fafafa"></iframe>
                          <?php endif; ?>
                          <div class="mt-2 d-flex gap-2 justify-content-end">
                            <a class="btn btn-sm btn-outline-secondary" href="<?= h($p) ?>" download>Télécharger</a>
                            <form method="post" onsubmit="return confirm('Supprimer ce fichier ?')">
                              <?= csrf_input() ?>
                              <input type="hidden" name="act" value="delete_analyse_one_file">
                              <input type="hidden" name="analyse_id" value="<?= $aid ?>">
                              <input type="hidden" name="file_path" value="<?= h($p) ?>">
                              <button class="btn btn-sm btn-outline-danger">Supprimer</button>
                            </form>
                          </div>
                        </div>
                      <?php endforeach; else: ?>
                        <div class="text-muted">Aucun fichier.</div>
                      <?php endif; ?>
                    </div>
                    <div class="modal-footer">
                      <button class="btn btn-secondary" type="button" data-bs-dismiss="modal">Fermer</button>
                    </div>
                  </div>
                </div>
              </div>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- IMAGERIES -->
  <div class="card mb-5">
    <div class="card-header d-flex flex-column flex-md-row gap-2 justify-content-between align-items-md-center">
      <div class="h6 mb-0">Imageries</div>
      <form method="post" class="d-flex flex-column flex-sm-row gap-2 mb-0">
        <?= csrf_input() ?><input type="hidden" name="act" value="add_imagerie">
        <input type="text" class="form-control form-control-sm" name="type_imagerie" placeholder="Type (ex: Radio Thorax)" required>
        <input type="number" step="0.01" class="form-control form-control-sm" name="prix" placeholder="Prix">
        <button class="btn btn-sm btn-success" type="submit">Ajouter</button>
      </form>
    </div>
    <div class="card-body p-0">
      <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
          <thead class="table-light">
            <tr>
              <th style="width:60px">#</th><th>Type</th><th>Compte-rendu</th><th style="width:160px">Fichiers</th>
              <th style="width:100px" class="text-end">Prix</th><th style="width:260px">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!$imageries): ?>
              <tr><td colspan="6" class="text-center text-muted py-3">Aucune imagerie</td></tr>
            <?php else: foreach ($imageries as $im): $files = json_paths_all($im['fichiers'] ?? null); $imid=(int)$im['id']; ?>
              <tr>
                <td><?= $imid ?></td>
                <td><?= h($im['type_imagerie'] ?? '—') ?></td>
                <td>
                  <div id="cr-view-<?= $imid ?>" class="<?= ($im['compte_rendu']??'')===''?'d-none':'' ?>">
                    <div class="p-2 border rounded small" onclick="vlCRtoggle(<?= $imid ?>, true)" style="cursor:text;white-space:pre-wrap"><?= h($im['compte_rendu'] ?? '') ?></div>
                  </div>
                  <form id="cr-edit-<?= $imid ?>" class="<?= ($im['compte_rendu']??'')!==''?'d-none':'' ?>" onsubmit="return vlCRsave(event, <?= $imid ?>)">
                    <?= csrf_input() ?>
                    <input type="hidden" name="imagerie_id" value="<?= $imid ?>">
                    <textarea name="compte_rendu" class="form-control form-control-sm" rows="3" placeholder="Saisir le compte-rendu…"><?= h($im['compte_rendu'] ?? '') ?></textarea>
                    <div class="mt-1 d-flex gap-2">
                      <button class="btn btn-sm btn-primary" type="submit">OK</button>
                      <button class="btn btn-sm btn-outline-secondary" type="button" onclick="vlCRtoggle(<?= $imid ?>, false)">Annuler</button>
                    </div>
                  </form>
                </td>
                <td>
                  <?php $cnt=count($files); ?>
                  <?= $cnt ? '<span class="badge-soft">'.$cnt.' fichier(s)</span>' : '<span class="text-muted">—</span>' ?>
                </td>
                <td class="text-end"><?= h(number_format((float)($im['prix'] ?? 0), 2, '.', ' ')) ?></td>
                <td>
                  <div class="d-flex flex-wrap gap-2">
                    <?php if ($files): ?>
                      <button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="modal" data-bs-target="#modalVoirImagerie<?= $imid ?>">Voir</button>
                    <?php endif; ?>
                    <button class="btn btn-sm btn-outline-primary" type="button"
                      onclick="openCaptureModal({ modalId:'capImagerieModal',
                        onDone:(paths)=>vlSubmitPaths('capture_imagerie_paths','imagerie_id',<?= $imid ?>,paths) })">
                      + Fichiers
                    </button>
                    <button class="btn btn-sm btn-outline-dark" type="button" onclick="vlCRtoggle(<?= $imid ?>, true)">Éditer CR</button>
                  </div>
                </td>
              </tr>

              <!-- Modal Voir (Imagerie) : plein écran, vertical, actions -->
              <div class="modal fade vl-nobackdrop-modal" id="modalVoirImagerie<?= $imid ?>" tabindex="-1" data-bs-backdrop="false" data-bs-keyboard="true" aria-hidden="true">
                <div class="modal-dialog modal-xl modal-dialog-scrollable">
                  <div class="modal-content">
                    <div class="modal-header">
                      <h5 class="modal-title">Pièces jointes — Imagerie #<?= $imid ?></h5>
                      <button class="btn-close" type="button" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body vl-filelist">
                      <?php if ($files): foreach ($files as $p): $ext = strtolower(pathinfo($p, PATHINFO_EXTENSION)); ?>
                        <div class="vl-filecard">
                          <?php if (in_array($ext, ['jpg','jpeg','png','gif'])): ?>
                            <img src="<?= h($p) ?>" style="width:100%;height:auto;max-height:75vh;object-fit:contain;border-radius:8px">
                          <?php else: ?>
                            <iframe src="<?= h($p) ?>#zoom=page-fit" style="width:100%;height:75vh;border:0;border-radius:8px;background:#fafafa"></iframe>
                          <?php endif; ?>
                          <div class="mt-2 d-flex gap-2 justify-content-end">
                            <a class="btn btn-sm btn-outline-secondary" href="<?= h($p) ?>" download>Télécharger</a>
                            <form method="post" onsubmit="return confirm('Supprimer ce fichier ?')">
                              <?= csrf_input() ?>
                              <input type="hidden" name="act" value="delete_imagerie_one_file">
                              <input type="hidden" name="imagerie_id" value="<?= $imid ?>">
                              <input type="hidden" name="file_path" value="<?= h($p) ?>">
                              <button class="btn btn-sm btn-outline-danger">Supprimer</button>
                            </form>
                          </div>
                        </div>
                      <?php endforeach; else: ?>
                        <div class="text-muted">Aucun fichier.</div>
                      <?php endif; ?>
                    </div>
                    <div class="modal-footer">
                      <button class="btn btn-secondary" type="button" data-bs-dismiss="modal">Fermer</button>
                    </div>
                  </div>
                </div>
              </div>

            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
  <?php endif; ?>
</div>

<?php
// Injection capture.php (une seule fois par page, par type)
$capAnalyseDir = 'uploads/reports/' . (int)$patient_id . '/analyses';
$capImagerieDir = 'uploads/reports/' . (int)$patient_id . '/imageries';
capture_handle($capAnalyseDir, ['modal_id' => 'capAnalyseModal', 'maxWidth' => 1600, 'quality' => 0.9, 'debug' => 1]);
capture_handle($capImagerieDir, ['modal_id' => 'capImagerieModal', 'maxWidth' => 1600, 'quality' => 0.9, 'debug' => 1]);
?>

<script>
// Bridge capture -> POST serveur
function vlGetCSRF(){
  var el = document.querySelector('input[type="hidden"][name^="__csrf"]') || document.querySelector('input[type="hidden"][name*="csrf"]');
  return el ? [el.getAttribute('name'), el.value] : null;
}
function vlSubmitPaths(action, idField, idValue, paths){
  if (!paths || !paths.length) return;
  var form = document.createElement('form'); form.method='post'; form.style.display='none';
  var csrf = vlGetCSRF(); if (csrf){ var iC=document.createElement('input'); iC.type='hidden'; iC.name=csrf[0]; iC.value=csrf[1]; form.appendChild(iC); }
  var iAct=document.createElement('input'); iAct.type='hidden'; iAct.name='act'; iAct.value=action; form.appendChild(iAct);
  var iId=document.createElement('input'); iId.type='hidden'; iId.name=idField; iId.value=idValue; form.appendChild(iId);
  var iPaths=document.createElement('input'); iPaths.type='hidden'; iPaths.name='paths_json'; iPaths.value=JSON.stringify(paths); form.appendChild(iPaths);
  document.body.appendChild(form); form.submit();
}

// Compte-rendu inline (imageries) — via API
function vlCRtoggle(id, edit){
  var v=document.getElementById('cr-view-'+id);
  var f=document.getElementById('cr-edit-'+id);
  if(!v||!f) return;
  if(edit){
    v.classList.add('d-none'); f.classList.remove('d-none');
    var ta=f.querySelector('textarea'); if(ta){ta.focus();}
  }else{
    f.classList.add('d-none'); v.classList.remove('d-none');
  }
}
async function vlCRsave(ev, id){
  ev.preventDefault();
  var form=ev.target;
  var fd=new FormData(form);
  try{
    let r=await fetch('api/cr-api.php', {method:'POST', body:fd});
    let j=await r.json();
    if(j && j.ok){
      var v=document.getElementById('cr-view-'+id);
      var f=document.getElementById('cr-edit-'+id);
      v.querySelector('div').textContent = (j.text||'');
      f.classList.add('d-none'); v.classList.remove('d-none');
    }else{
      alert(j && j.msg ? j.msg : 'Erreur de sauvegarde');
    }
  }catch(e){ alert('Erreur réseau'); }
  return false;
}

document.addEventListener('click', function(e){
  const btn = e.target.closest('[data-bs-toggle="collapse"][data-bs-target]');
  if(!btn) return;
  const sel = btn.getAttribute('data-bs-target');
  const el  = document.querySelector(sel);
  if(!el) return;
  // La bascule visuelle se fait après l'anim → attendre un tick
  setTimeout(() => {
    const open = el.classList.contains('show');
    btn.textContent = open ? '−' : '+';
  }, 200);
});
document.addEventListener('DOMContentLoaded', function(){
  document.querySelectorAll('.vl-move-to-body').forEach(function(m){
    if (m.parentElement !== document.body) document.body.appendChild(m);
  });
});
</script>
<style>
/* Modales sans backdrop (pas de voile gris), avec glow doux */
.vl-nobackdrop-modal { --vl-glow: 0 0 0 3px rgba(13,110,253,.2), 0 8px 28px rgba(0,0,0,.35); }
.vl-nobackdrop-modal .modal-backdrop { display:none !important; }
.vl-nobackdrop-modal .modal-content {
  box-shadow: var(--vl-glow);
  border-radius: 14px;
  border: 1px solid rgba(0,0,0,.05);
}

/* Dialog responsive: marges légères sur mobile, grandes sur desktop */
.vl-nobackdrop-modal .modal-dialog { margin: .75rem; }
@media (min-width: 576px){
  .vl-nobackdrop-modal .modal-dialog { margin: 1.25rem auto; }
}

/* Corps scrollable déjà géré par .modal-dialog-scrollable ; renfort mobiles */
.vl-filelist{max-height:85vh; overflow:auto}

/* Z-index un peu plus haut pour passer au-dessus d’éventuels headers mobiles */
.vl-nobackdrop-modal { z-index: 1085; }

/* Désactive toute tentative de blur global: on n'utilise QUE le glow ci-dessus */
body.modal-open { padding-right: 0 !important; }
/* Sur mobile, garder une marge en haut pour laisser visible le header */
.vl-nobackdrop-modal .modal-dialog {
  margin-top: 3.5rem; /* ~56px (hauteur navbar bootstrap) */
}

/* Plus de confort sur desktop : marge un peu plus large */
@media (min-width: 768px){
  .vl-nobackdrop-modal .modal-dialog {
    margin-top: 4.5rem;
  }
}
/* Toujours au-dessus de toute la structure */
.vl-nobackdrop-modal {
  z-index: 2000 !important; /* plus haut que n'importe quel header/sidebar */
}

/* Dialog plus compact, centré */
.vl-nobackdrop-modal .modal-dialog {
  margin: 3.5rem auto;          /* marge top pour header */
  max-width: 700px;             /* largeur réduite */
  max-height: calc(100vh - 5rem); /* pas collé au bas */
  display: flex;
  flex-direction: column;
}

/* Contenu avec glow et taille adaptée */
.vl-nobackdrop-modal .modal-content {
  box-shadow: 0 0 0 3px rgba(13,110,253,.2),
              0 6px 24px rgba(0,0,0,.35);
  border-radius: 14px;
  flex: 1 1 auto;
  display: flex;
  flex-direction: column;
  max-height: 100%;
}

/* Header/footer fixes, corps scrollable */
.vl-nobackdrop-modal .modal-header,
.vl-nobackdrop-modal .modal-footer {
  flex-shrink: 0;
}
.vl-nobackdrop-modal .modal-body {
  flex: 1 1 auto;
  overflow-y: auto;
  -webkit-overflow-scrolling: touch;
  padding: 1rem;
}

/* Sur très petits écrans : pleine largeur mais toujours centrée verticalement */
@media (max-width: 576px) {
  .vl-nobackdrop-modal .modal-dialog {
    max-width: 95%;
    margin: 1rem auto;
  }
}
/* === Modales "Voir" (Analyses / Imageries) === */
.vl-nobackdrop-modal {
  z-index: 3000 !important; /* Toujours au-dessus de tout */
}

.vl-nobackdrop-modal .modal-dialog {
  margin: 4rem auto 2rem auto;   /* marge top pour header/menu */
  max-width: 680px;              /* largeur réduite = fenêtre compacte */
  max-height: calc(100vh - 6rem);/* pas collé en bas de l'écran */
  display: flex;
  flex-direction: column;
}

.vl-nobackdrop-modal .modal-content {
  flex: 1 1 auto;
  display: flex;
  flex-direction: column;
  max-height: 100%;
  border-radius: 14px;
  box-shadow: 0 0 0 3px rgba(13,110,253,.25),
              0 10px 30px rgba(0,0,0,.4);
  border: 1px solid rgba(0,0,0,.08);
  background: #fff;
}

.vl-nobackdrop-modal .modal-header,
.vl-nobackdrop-modal .modal-footer {
  flex-shrink: 0;
}

.vl-nobackdrop-modal .modal-body {
  flex: 1 1 auto;
  overflow-y: auto;              /* scroll interne si contenu trop long */
  -webkit-overflow-scrolling: touch;
  padding: 1rem;
}

/* Sur mobile : largeur quasi-pleine mais marges */
@media (max-width: 576px) {
  .vl-nobackdrop-modal .modal-dialog {
    max-width: 95%;
    margin: 3.5rem auto 1rem auto;
  }
}
/* Modal "Voir" toujours au-dessus */
.vl-nobackdrop-modal { z-index: 5000 !important; } /* > tout le reste */

/* Fenêtre compacte et adaptée */
.vl-nobackdrop-modal .modal-dialog {
  margin: 4rem auto 2rem;          /* laisse la barre header */
  max-width: 680px;                /* plus petit */
  max-height: calc(100vh - 6rem);  /* pas croppé en bas */
  display: flex; flex-direction: column;
}
.vl-nobackdrop-modal .modal-content{
  flex:1 1 auto; display:flex; flex-direction:column; max-height:100%;
  border-radius:14px;
  box-shadow: 0 0 0 3px rgba(13,110,253,.25), 0 10px 30px rgba(0,0,0,.4);
  background:#fff; border:1px solid rgba(0,0,0,.08);
}
.vl-nobackdrop-modal .modal-body{
  flex:1 1 auto; overflow-y:auto; -webkit-overflow-scrolling:touch; padding:1rem;
}
@media (max-width:576px){
  .vl-nobackdrop-modal .modal-dialog{ max-width:95%; margin:3.5rem auto 1rem; }
}
/* Quand un modal est ouvert, la carte (card mb-5) passe derrière et n'intercepte rien */
body.modal-open .card.mb-5{
  position: relative !important;
  z-index: 0 !important;
  pointer-events: none;           /* évite tout clic à travers */
}
</style>