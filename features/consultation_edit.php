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
function render_media_preview(string $path): string {
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    ob_start();
    if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif'], true)) {
        ?>
        <img src="<?= h($path) ?>" style="width:100%;height:auto;max-height:75vh;object-fit:contain;border-radius:8px">
        <?php
    } elseif (in_array($ext, ['webm', 'mp4', 'mov', 'm4v', 'ogg'], true)) {
        ?>
        <div class="vl-video-wrap">
          <video class="vl-attachment-video" preload="metadata" playsinline>
            <source src="<?= h($path) ?>">
          </video>
          <button class="vl-video-toggle" type="button" onclick="vlToggleVideoPlayer(this)" aria-label="Lecture / pause">
            <i class="bi bi-play-fill"></i>
          </button>
        </div>
        <?php
    } else {
        ?>
        <iframe src="<?= h($path) ?>#zoom=page-fit" style="width:100%;height:75vh;border:0;border-radius:8px;background:#fafafa"></iframe>
        <?php
    }
    return (string)ob_get_clean();
}
function is_ajax_request(): bool {
    if (isset($_POST['ajax']) && $_POST['ajax'] === '1') {
        return true;
    }
    $hdr = strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''));
    return $hdr === 'xmlhttprequest';
}
function render_analyse_card(array $an): string {
    $files = json_paths_all($an['fichier_resultat'] ?? null);
    $aid = (int)($an['id'] ?? 0);
    $hasFiles = !empty($files);
    $result = trim((string)($an['resultat'] ?? ''));
    $resultPreview = $result !== '' ? mb_strimwidth(preg_replace('/\s+/', ' ', $result), 0, 180, '…', 'UTF-8') : '';
    ob_start();
    ?>
      <article class="vl-an-card" data-analyse-id="<?= $aid ?>">
        <div class="vl-an-head">
          <div class="vl-an-ident">
            <span class="vl-an-kicker">Analyse #<?= $aid ?></span>
            <h3 class="vl-an-title"><?= h($an['type_analyse'] ?? '—') ?></h3>
          </div>
          <div class="vl-an-price"><?= h(number_format((float)($an['prix'] ?? 0), 2, '.', ' ')) ?> DH</div>
        </div>
        <div class="vl-an-meta">
          <div class="vl-an-chip-row">
            <?php $cnt = count($files); ?>
            <span class="vl-an-chip is-type"><?= h($an['type_analyse'] ?? '—') ?></span>
            <span class="vl-an-chip <?= $cnt ? 'is-active' : '' ?>"><?= $cnt ? $cnt . ' fichier(s)' : 'Aucun fichier' ?></span>
            <span class="vl-an-chip"><?= $result !== '' ? 'Résultat saisi' : 'Résultat vide' ?></span>
          </div>
          <div class="vl-an-result">
            <?php if ($result !== ''): ?>
              <p class="mb-0"><?= h($resultPreview) ?></p>
            <?php else: ?>
              <p class="mb-0 text-muted">Aucun résultat saisi pour cette analyse.</p>
            <?php endif; ?>
          </div>
        </div>
        <div class="vl-an-actions">
          <button class="btn btn-sm btn-outline-primary" type="button"
            onclick="openCaptureModal({ modalId:'capAnalyseModal',
              onDone:(paths)=>vlSubmitPaths('analyse',<?= $aid ?>,paths) })">
            Ajouter fichiers
          </button>
          <?php if ($hasFiles): ?>
            <button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="modal" data-bs-target="#modalVoirAnalyse<?= $aid ?>">Voir pièces jointes</button>
          <?php endif; ?>
          <?php if (!$hasFiles): ?>
            <button class="btn btn-sm btn-outline-danger" type="button" onclick="vlDeleteAnalyse(<?= $aid ?>)">Supprimer</button>
          <?php endif; ?>
        </div>
      </article>

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
                  <?= render_media_preview($p) ?>
                  <div class="mt-2 d-flex gap-2 justify-content-end">
                    <a class="btn btn-sm btn-outline-secondary" href="<?= h($p) ?>" download>Télécharger</a>
                    <form method="post" data-confirm-delete="Supprimer ce fichier ?">
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
    <?php
    return (string)ob_get_clean();
}
function render_imagerie_card(array $im): string {
    $files = json_paths_all($im['fichiers'] ?? null);
    $imid = (int)($im['id'] ?? 0);
    $report = trim((string)($im['compte_rendu'] ?? ''));
    $reportPreview = $report !== '' ? mb_strimwidth(preg_replace('/\s+/', ' ', $report), 0, 220, '…', 'UTF-8') : '';
    ob_start();
    ?>
      <article class="vl-im-card" data-imagerie-id="<?= $imid ?>" data-imagerie-type="<?= h($im['type_imagerie'] ?? '—') ?>" data-imagerie-report="<?= h($report) ?>">
        <div class="vl-im-head">
          <div class="vl-im-ident">
            <span class="vl-im-kicker">Imagerie #<?= $imid ?></span>
            <h3 class="vl-im-title"><?= h($im['type_imagerie'] ?? '—') ?></h3>
          </div>
          <button class="vl-im-price" type="button" onclick="vlOpenPriceModal(<?= $imid ?>)">
            <?= h(number_format((float)($im['prix'] ?? 0), 2, '.', ' ')) ?> DH
          </button>
        </div>

        <div class="vl-im-meta">
          <div class="vl-im-chip-row">
            <?php $cnt = count($files); ?>
            <span class="vl-im-chip <?= $cnt ? 'is-active' : '' ?>"><?= $cnt ? $cnt . ' fichier(s)' : 'Aucun fichier' ?></span>
            <span class="vl-im-chip"><?= $report !== '' ? 'Compte-rendu saisi' : 'Compte-rendu vide' ?></span>
          </div>
          <div class="vl-im-report" id="cr-preview-<?= $imid ?>">
            <?php if ($report !== ''): ?>
              <p class="mb-0"><?= h($reportPreview) ?></p>
            <?php else: ?>
              <p class="mb-0 text-muted">Ajoutez un compte-rendu structuré pour documenter l’examen.</p>
            <?php endif; ?>
          </div>
        </div>

        <div class="vl-im-actions">
          <button class="btn btn-sm btn-outline-dark" type="button" onclick="vlOpenCRModal(<?= $imid ?>)">
            <?= $report !== '' ? 'Modifier CR' : 'Ajouter CR' ?>
          </button>
          <button class="btn btn-sm btn-outline-primary" type="button"
              onclick="openCaptureModal({ modalId:'capImagerieModal',
                onDone:(paths)=>vlSubmitPaths('imagerie',<?= $imid ?>,paths) })">
            Ajouter fichiers
          </button>
          <?php if ($files): ?>
            <button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="modal" data-bs-target="#modalVoirImagerie<?= $imid ?>">Voir pièces jointes</button>
          <?php endif; ?>
        </div>
      </article>

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
                  <?= render_media_preview($p) ?>
                  <div class="mt-2 d-flex gap-2 justify-content-end">
                    <a class="btn btn-sm btn-outline-secondary" href="<?= h($p) ?>" download>Télécharger</a>
                    <form method="post" data-confirm-delete="Supprimer ce fichier ?">
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
    <?php
    return (string)ob_get_clean();
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
            db()->prepare("UPDATE analyses SET fichier_resultat=?, updated_at=NOW() WHERE id=?")->execute([$newJson, $aid]);
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
            db()->prepare("UPDATE imageries SET fichiers=?, updated_at=NOW() WHERE id=?")->execute([$newJson, $imid]);
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
        db()->prepare("INSERT INTO analyses (consultation_id, type_analyse, prix, created_at, updated_at) VALUES (?, ?, ?, NOW(), NOW())")
           ->execute([$consult_id, $type, $prix]);
        $newId = (int)db()->lastInsertId();
        if (is_ajax_request()) {
            $st = db()->prepare("SELECT * FROM analyses WHERE id=? AND consultation_id=? LIMIT 1");
            $st->execute([$newId, $consult_id]);
            $row = $st->fetch(PDO::FETCH_ASSOC) ?: [
                'id' => $newId,
                'type_analyse' => $type,
                'prix' => $prix,
                'resultat' => '',
                'fichier_resultat' => json_encode([], JSON_UNESCAPED_SLASHES),
            ];
            $ct = db()->prepare("SELECT COUNT(*) FROM analyses WHERE consultation_id=?");
            $ct->execute([$consult_id]);
            json_response([
                'ok' => 1,
                'item' => $row,
                'html' => render_analyse_card($row),
                'count' => (int)$ct->fetchColumn(),
                'message' => 'Analyse ajoutée.',
            ]);
        }
        redirect('index.php?page=consultation_edit&id='.$consult_id.'&ok=analyse_added');
    }

    if ($act === 'add_imagerie' && $consult_id > 0) {
        $type = trim($_POST['type_imagerie'] ?? '');
        $prix = (float)($_POST['prix'] ?? 0);
        db()->prepare("INSERT INTO imageries (consultation_id, type_imagerie, prix, created_at, updated_at) VALUES (?, ?, ?, NOW(), NOW())")
           ->execute([$consult_id, $type, $prix]);
        $newId = (int)db()->lastInsertId();
        if (is_ajax_request()) {
            $st = db()->prepare("SELECT * FROM imageries WHERE id=? AND consultation_id=? LIMIT 1");
            $st->execute([$newId, $consult_id]);
            $row = $st->fetch(PDO::FETCH_ASSOC) ?: [
                'id' => $newId,
                'type_imagerie' => $type,
                'prix' => $prix,
                'compte_rendu' => '',
                'fichiers' => json_encode([], JSON_UNESCAPED_SLASHES),
            ];
            $ct = db()->prepare("SELECT COUNT(*) FROM imageries WHERE consultation_id=?");
            $ct->execute([$consult_id]);
            json_response([
                'ok' => 1,
                'item' => $row,
                'html' => render_imagerie_card($row),
                'count' => (int)$ct->fetchColumn(),
                'message' => 'Imagerie ajoutée.',
            ]);
        }
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
                db()->prepare("UPDATE analyses SET fichier_resultat=?, updated_at=NOW() WHERE id=?")->execute([$newJson, $aid]);
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
                db()->prepare("UPDATE analyses SET fichier_resultat=?, updated_at=NOW() WHERE id=?")->execute([$newJson, $aid]);
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
                db()->prepare("UPDATE imageries SET fichiers=?, updated_at=NOW() WHERE id=?")->execute([$newJson, $imid]);
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
                db()->prepare("UPDATE imageries SET fichiers=?, updated_at=NOW() WHERE id=?")->execute([$newJson, $imid]);
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
            db()->prepare("UPDATE analyses SET fichier_resultat=?, updated_at=NOW() WHERE id=?")
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
            db()->prepare("UPDATE imageries SET fichiers=?, updated_at=NOW() WHERE id=?")
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
.vl-inline-status{display:none}
.vl-inline-status.is-visible{display:block}
.vl-exam-panel{
  border:1px solid #e8edf4;
  border-radius:18px;
  background:linear-gradient(180deg,#ffffff 0%,#fbfcff 100%);
  padding:1rem;
}
.vl-exam-block{
  border:1px solid #e5eaf2;
  border-radius:16px;
  background:#fff;
  padding:.9rem;
  box-shadow:0 6px 18px rgba(15,23,42,.04);
}
.vl-exam-block .form-label{
  margin-bottom:.55rem;
  font-size:.78rem;
  font-weight:700;
  letter-spacing:.04em;
  text-transform:uppercase;
  color:#5b6473;
}
.vl-row-actions .btn{border-radius:12px}
.vl-icon-btn{min-width:42px;min-height:38px;display:inline-flex;align-items:center;justify-content:center}
.vl-im-shell{padding:1rem}
.vl-an-shell{padding:1rem}
.vl-an-add-form{display:grid;gap:.75rem}
.vl-an-list{display:grid;gap:1rem}
.vl-an-card{
  border:1px solid #e6ecf5;
  border-radius:22px;
  background:linear-gradient(180deg,#fff 0%,#fbfcff 100%);
  box-shadow:0 10px 30px rgba(15,23,42,.05);
  padding:1rem;
}
.vl-an-list > .vl-an-card:nth-child(4n+1){background:linear-gradient(180deg,#ffffff 0%,#f8fbff 100%);border-color:#dbeafe}
.vl-an-list > .vl-an-card:nth-child(4n+2){background:linear-gradient(180deg,#ffffff 0%,#f8fafc 100%);border-color:#e2e8f0}
.vl-an-list > .vl-an-card:nth-child(4n+3){background:linear-gradient(180deg,#ffffff 0%,#fdfaf5 100%);border-color:#f5e7c8}
.vl-an-list > .vl-an-card:nth-child(4n+4){background:linear-gradient(180deg,#ffffff 0%,#f7fbf7 100%);border-color:#d9eadb}
.vl-an-head{display:flex;align-items:flex-start;justify-content:space-between;gap:1rem;margin-bottom:.8rem}
.vl-an-kicker{display:inline-block;font-size:.72rem;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:#64748b;margin-bottom:.2rem}
.vl-an-title{margin:0;font-size:1.02rem;font-weight:800;color:#0f172a}
.vl-an-price{white-space:nowrap;font-size:1rem;font-weight:700;color:#111827}
.vl-an-meta{display:grid;gap:.75rem}
.vl-an-chip-row{display:flex;flex-wrap:wrap;gap:.5rem}
.vl-an-chip{display:inline-flex;align-items:center;border-radius:999px;background:#f1f5f9;color:#475569;padding:.38rem .72rem;font-size:.78rem;font-weight:600}
.vl-an-chip.is-active{background:#e0f2fe;color:#075985}
.vl-an-chip.is-type{background:#0f172a;color:#fff;font-weight:700}
.vl-an-result{border:1px solid #e2e8f0;border-radius:16px;background:#fff;padding:.85rem .95rem;color:#334155;font-size:.92rem;line-height:1.55;min-height:76px}
.vl-an-actions{display:grid;gap:.6rem;margin-top:.95rem}
.vl-an-actions .btn{width:100%;border-radius:14px;padding:.7rem .9rem;font-weight:600}
.vl-an-empty{padding:1.25rem;text-align:center;color:#64748b}
.vl-im-add-form{display:grid;gap:.75rem}
.vl-im-list{display:grid;gap:1rem}
.vl-im-card{
  border:1px solid #e7ebf2;
  border-radius:22px;
  background:linear-gradient(180deg,#fff 0%,#fbfcfe 100%);
  box-shadow:0 10px 30px rgba(15,23,42,.05);
  padding:1rem;
}
.vl-im-list > .vl-im-card:nth-child(4n+1){
  background:linear-gradient(180deg,#ffffff 0%,#f8fbff 100%);
  border-color:#dbeafe;
}
.vl-im-list > .vl-im-card:nth-child(4n+2){
  background:linear-gradient(180deg,#ffffff 0%,#f8fafc 100%);
  border-color:#e2e8f0;
}
.vl-im-list > .vl-im-card:nth-child(4n+3){
  background:linear-gradient(180deg,#ffffff 0%,#fdfaf5 100%);
  border-color:#f5e7c8;
}
.vl-im-list > .vl-im-card:nth-child(4n+4){
  background:linear-gradient(180deg,#ffffff 0%,#f7fbf7 100%);
  border-color:#d9eadb;
}
.vl-im-head{display:flex;align-items:flex-start;justify-content:space-between;gap:1rem;margin-bottom:.8rem}
.vl-im-kicker{display:inline-block;font-size:.72rem;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:#64748b;margin-bottom:.2rem}
.vl-im-title{margin:0;font-size:1rem;font-weight:700;color:#0f172a}
.vl-im-price{
  white-space:nowrap;
  font-size:1rem;
  font-weight:700;
  color:#111827;
  border:0;
  background:transparent;
  padding:0;
  cursor:pointer;
}
.vl-im-price:hover{color:#0f172a;text-decoration:underline}
.vl-im-meta{display:grid;gap:.75rem}
.vl-im-chip-row{display:flex;flex-wrap:wrap;gap:.5rem}
.vl-im-chip{display:inline-flex;align-items:center;border-radius:999px;background:#f1f5f9;color:#475569;padding:.38rem .72rem;font-size:.78rem;font-weight:600}
.vl-im-chip.is-active{background:#e0f2fe;color:#075985}
.vl-im-report{border:1px solid #e2e8f0;border-radius:16px;background:#fff;padding:.85rem .95rem;color:#334155;font-size:.92rem;line-height:1.55;min-height:76px}
.vl-im-actions{display:grid;gap:.6rem;margin-top:.95rem}
.vl-im-actions .btn{width:100%;border-radius:14px;padding:.7rem .9rem;font-weight:600}
.vl-im-empty{padding:1.25rem;text-align:center;color:#64748b}
.vl-video-wrap{
  position:relative;
  border-radius:12px;
  overflow:hidden;
  background:#020617;
}
.vl-attachment-video{
  display:block;
  width:100%;
  max-height:75vh;
  background:#020617;
}
.vl-video-toggle{
  position:absolute;
  left:50%;
  bottom:.8rem;
  transform:translateX(-50%);
  display:inline-flex;
  align-items:center;
  justify-content:center;
  min-width:52px;
  height:38px;
  border:0;
  border-radius:999px;
  padding:0 .95rem;
  background:rgba(15,23,42,.74);
  color:#fff;
  box-shadow:0 8px 24px rgba(2,6,23,.35);
  backdrop-filter:blur(8px);
}
.vl-video-toggle i{font-size:1.05rem;line-height:1}
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
  .vl-exam-panel{padding:.85rem}
  .vl-exam-block{padding:.8rem}
}
@media (min-width:768px){
  .vl-an-shell{padding:1.25rem}
  .vl-an-add-form{grid-template-columns:minmax(240px,1.6fr) minmax(120px,.6fr) auto;align-items:center}
  .vl-an-list{grid-template-columns:repeat(2,minmax(0,1fr))}
  .vl-im-shell{padding:1.25rem}
  .vl-im-add-form{grid-template-columns:minmax(240px,1.6fr) minmax(120px,.6fr) auto;align-items:center}
  .vl-im-list{grid-template-columns:repeat(2,minmax(0,1fr))}
}
@media (min-width:1200px){
  .vl-an-list{grid-template-columns:repeat(3,minmax(0,1fr))}
  .vl-im-list{grid-template-columns:repeat(3,minmax(0,1fr))}
}
@media (max-width:767.98px){
  .vl-mobile-table thead{display:none}
  .vl-mobile-table,
  .vl-mobile-table tbody,
  .vl-mobile-table tr,
  .vl-mobile-table td{display:block;width:100%}
  .vl-mobile-table tbody tr{
    padding:.9rem;
    border-bottom:1px solid #e9edf3;
    background:#fff;
  }
  .vl-mobile-table tbody tr:last-child{border-bottom:0}
  .vl-mobile-table td{
    border:0 !important;
    padding:.35rem 0 !important;
    text-align:left !important;
  }
  .vl-mobile-table td::before{
    content:attr(data-label);
    display:block;
    margin-bottom:.2rem;
    font-size:.72rem;
    font-weight:700;
    letter-spacing:.03em;
    text-transform:uppercase;
    color:#6b7280;
  }
  .vl-mobile-table td[data-label="Actions"]::before{margin-bottom:.45rem}
  .vl-row-actions{display:grid !important;grid-template-columns:1fr 1fr;gap:.5rem !important}
  .vl-row-actions .btn{width:100%}
  .vl-row-actions .vl-icon-btn{grid-column:span 2}
  .table-responsive{border:0}
}
</style>

<div class="container my-3">
  <?php if (!empty($_GET['ok'])): ?><div class="alert alert-success"><?= h($_GET['ok']) ?></div><?php endif; ?>
  <div id="vlAjaxStatus" class="alert alert-success vl-inline-status mb-3" role="status"></div>

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
  <div id="weightChartWrap" class="d-none mt-3">
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
  let weightChartInstance = null;
  function ensureWeightChart(){
    if (weightChartInstance) {
      weightChartInstance.resize();
      weightChartInstance.update('none');
      return;
    }
    const canvas = document.getElementById("weightChart");
    if (!canvas) return;
    const ctx = canvas.getContext("2d");
    weightChartInstance = new Chart(ctx, {
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
  }

  // Bouton +/-
  const btn = document.getElementById("toggleWeightBtn");
  const wrap = document.getElementById("weightChartWrap");
  btn.addEventListener("click", function(){
    const open = wrap.classList.toggle("d-none") === false;
    btn.textContent = open ? "➖ Courbe de poids" : "➕ Courbe de poids";
    if (open) {
      setTimeout(ensureWeightChart, 30);
    }
  });
  if (!wrap.classList.contains('d-none')) {
    setTimeout(ensureWeightChart, 30);
  }
  window.addEventListener('resize', function(){
    if (weightChartInstance && !wrap.classList.contains('d-none')) {
      weightChartInstance.resize();
    }
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

  <!-- ══════════════════════════════════════════
       TABS : Examen · Analyses · Imageries
  ══════════════════════════════════════════ -->
  <div x-data="vlConsultTabs()" x-init="init()">

    <!-- Barre d'onglets -->
    <div class="flex p-1 bg-slate-100 rounded-2xl gap-1 mb-5 shadow-inner">

      <!-- Examen -->
      <button type="button" @click="setTab('examen')"
              :class="tab==='examen'
                ? 'bg-white shadow-md text-blue-700 font-semibold'
                : 'text-slate-500 hover:text-slate-700 hover:bg-white/60'"
              class="flex-1 flex items-center justify-center gap-1.5 py-3 px-2 rounded-xl text-sm font-medium transition-all duration-200 border-0 cursor-pointer">
        <svg class="w-4 h-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
        </svg>
        <span>Examen</span>
      </button>

      <?php if ($consult_id > 0): ?>
      <!-- Analyses -->
      <button type="button" @click="setTab('analyses')"
              :class="tab==='analyses'
                ? 'bg-white shadow-md text-indigo-700 font-semibold'
                : 'text-slate-500 hover:text-slate-700 hover:bg-white/60'"
              class="flex-1 flex items-center justify-center gap-1.5 py-3 px-2 rounded-xl text-sm font-medium transition-all duration-200 border-0 cursor-pointer">
        <svg class="w-4 h-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M19.428 15.428a2 2 0 00-1.022-.547l-2.387-.477a6 6 0 00-3.86.517l-.318.158a6 6 0 01-3.86.517L6.05 15.21a2 2 0 00-1.806.547M8 4h8l-1 1v5.172a2 2 0 00.586 1.414l5 5c1.26 1.26.367 3.414-1.415 3.414H4.828c-1.782 0-2.674-2.154-1.414-3.414l5-5A2 2 0 009 10.172V5L8 4z"/>
        </svg>
        <span>Analyses</span>
        <?php $nb_an = count($analyses ?? []); if ($nb_an): ?>
          <span id="analysesCountBadge" class="text-xs rounded-full px-1.5 py-0.5 font-bold leading-none"
                :class="tab==='analyses' ? 'bg-indigo-100 text-indigo-700' : 'bg-slate-200 text-slate-600'"><?= $nb_an ?></span>
        <?php else: ?>
          <span id="analysesCountBadge" class="text-xs rounded-full px-1.5 py-0.5 font-bold leading-none d-none"
                :class="tab==='analyses' ? 'bg-indigo-100 text-indigo-700' : 'bg-slate-200 text-slate-600'">0</span>
        <?php endif; ?>
      </button>

      <!-- Imageries -->
      <button type="button" @click="setTab('imageries')"
              :class="tab==='imageries'
                ? 'bg-white shadow-md text-violet-700 font-semibold'
                : 'text-slate-500 hover:text-slate-700 hover:bg-white/60'"
              class="flex-1 flex items-center justify-center gap-1.5 py-3 px-2 rounded-xl text-sm font-medium transition-all duration-200 border-0 cursor-pointer">
        <svg class="w-4 h-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/>
        </svg>
        <span>Imageries</span>
        <?php $nb_im = count($imageries ?? []); if ($nb_im): ?>
          <span id="imageriesCountBadge" class="text-xs rounded-full px-1.5 py-0.5 font-bold leading-none"
                :class="tab==='imageries' ? 'bg-violet-100 text-violet-700' : 'bg-slate-200 text-slate-600'"><?= $nb_im ?></span>
        <?php else: ?>
          <span id="imageriesCountBadge" class="text-xs rounded-full px-1.5 py-0.5 font-bold leading-none d-none"
                :class="tab==='imageries' ? 'bg-violet-100 text-violet-700' : 'bg-slate-200 text-slate-600'">0</span>
        <?php endif; ?>
      </button>
      <?php endif; ?>

    </div><!-- /tab bar -->

    <!-- ── Panel Examen ──────────────────────────── -->
    <div x-show="tab==='examen'"
         x-transition:enter="transition ease-out duration-200"
         x-transition:enter-start="opacity-0 translate-y-1"
         x-transition:enter-end="opacity-100 translate-y-0">

  <!-- Formulaire consultation -->
  <div class="card mb-4">
    <div class="card-body">
      <form method="post">
        <?= csrf_input() ?>
        <input type="hidden" name="act" value="save_consult">
        <div class="vl-exam-panel">
        <div class="row g-3 align-items-start">
          <div class="col-md-3">
            <div class="vl-exam-block h-100">
            <label class="form-label">Date/heure</label>
            <input type="datetime-local" name="date_consult" class="form-control"
                   value="<?= h(isset($consult['date_consult']) ? str_replace(' ', 'T', substr($consult['date_consult'],0,16)) : date('Y-m-d\TH:i')) ?>">
          </div>
          </div>
          <div class="col-md-7 motif-red">
            <div class="vl-exam-block h-100">
            <label class="form-label">Motif</label>
            <input type="text" name="motif" class="form-control" value="<?= h($consult['motif'] ?? '') ?>">
          </div>
          </div>
          <div class="col-md-2 poids-green">
  <div class="vl-exam-block h-100">
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
</div>

          <div class="col-md-9">
            <div class="vl-exam-block h-100">
            <label class="form-label">Commentaire facturation</label>
            <input type="text" name="commentaire_facturation" class="form-control" value="<?= h($consult['commentaire_facturation'] ?? '') ?>">
          </div>
          </div>
          <div class="col-md-3 vl-price-strong">
            <div class="vl-exam-block h-100">
            <label class="form-label">Prix (DH)</label>
            <input type="number" name="montant_ligne" class="form-control" step="0.01" min="0" value="<?= h($consult['montant_ligne'] ?? '') ?>">
          </div>
          </div>

          <div class="col-md-9">
            <div class="vl-exam-block h-100">
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
  <div class="vl-exam-block h-100">
    <label class="form-label" for="anamnese">Anamnèse</label>
    <textarea id="anamnese" name="anamnese" class="form-control" rows="3"><?= h($consult['anamnese'] ?? '') ?></textarea>
  </div>
</div>

<!-- DIAGNOSTIC -->
<div class="col-md-6">
  <div class="vl-exam-block h-100">
    <label class="form-label" for="diagnostic">Diagnostic</label>
    <textarea id="diagnostic" name="diagnostic" class="form-control" rows="2"><?= h($consult['diagnostic'] ?? '') ?></textarea>
  </div>
</div>

<!-- TRAITEMENT -->
<div class="col-md-6">
  <div class="vl-exam-block h-100">
    <label class="form-label" for="traitement">Traitement</label>
    <textarea id="traitement" name="traitement" class="form-control" rows="2"><?= h($consult['traitement'] ?? '') ?></textarea>
  </div>
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
        { key: 'anamnese',               label: 'Anamnèse',              get: () => document.getElementById('anamnese') },
        { key: 'diagnostic',             label: 'Diagnostic',            get: () => document.getElementById('diagnostic') },
        { key: 'traitement',             label: 'Traitement',            get: () => document.getElementById('traitement') },
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

    </div><!-- /panel examen -->

    <?php if ($consult_id > 0): ?>
    <!-- ── Panel Analyses ────────────────────────── -->
    <div x-show="tab==='analyses'"
         x-transition:enter="transition ease-out duration-200"
         x-transition:enter-start="opacity-0 translate-y-1"
         x-transition:enter-end="opacity-100 translate-y-0">

  <!-- ANALYSES -->
  <div class="card mb-4">
    <div class="card-header d-flex flex-column gap-3">
      <div class="h6 mb-0">Analyses</div>
      <form method="post" class="vl-an-add-form mb-0" id="analyseAddForm">
        <?= csrf_input() ?><input type="hidden" name="act" value="add_analyse">
        <select class="form-select" name="type_analyse" required>
          <option value="" disabled selected>Choisir une analyse…</option>
          <?php foreach ($ANALYSES_OPTIONS as $opt): ?><option value="<?= h($opt) ?>"><?= h($opt) ?></option><?php endforeach; ?>
        </select>
        <input type="number" step="0.01" class="form-control" name="prix" placeholder="Prix">
        <button class="btn btn-success" type="submit">Ajouter</button>
      </form>
    </div>
    <div class="card-body vl-an-shell">
      <div id="analysesList" class="vl-an-list">
        <?php if (!$analyses): ?>
          <div id="analysesEmptyRow" class="vl-an-empty">Aucune analyse enregistrée.</div>
        <?php else: foreach ($analyses as $an): ?>
          <?= render_analyse_card($an) ?>
        <?php endforeach; endif; ?>
      </div>
    </div>
  </div>

    </div><!-- /panel analyses -->

    <!-- ── Panel Imageries ───────────────────────── -->
    <div x-show="tab==='imageries'"
         x-transition:enter="transition ease-out duration-200"
         x-transition:enter-start="opacity-0 translate-y-1"
         x-transition:enter-end="opacity-100 translate-y-0">

  <!-- IMAGERIES -->
  <div class="card mb-5">
    <div class="card-header d-flex flex-column gap-3">
      <div class="h6 mb-0">Imageries</div>
      <form method="post" class="vl-im-add-form mb-0" id="imagerieAddForm">
        <?= csrf_input() ?><input type="hidden" name="act" value="add_imagerie">
        <input type="text" class="form-control" name="type_imagerie" placeholder="Type d’imagerie, ex: Radio thorax" required>
        <input type="number" step="0.01" class="form-control" name="prix" placeholder="Prix">
        <button class="btn btn-success" type="submit">Ajouter</button>
      </form>
    </div>
    <div class="card-body vl-im-shell">
      <div id="imageriesList" class="vl-im-list">
        <?php if (!$imageries): ?>
          <div id="imageriesEmptyRow" class="vl-im-empty">Aucune imagerie enregistrée.</div>
        <?php else: foreach ($imageries as $im): ?>
          <?= render_imagerie_card($im) ?>
        <?php endforeach; endif; ?>
      </div>
    </div>
  </div>

    </div><!-- /panel imageries -->
    <?php endif; ?>

  </div><!-- /tabs wrapper -->
</div>

<div id="vlConfirmModal" class="fixed inset-0 z-[6000] hidden">
  <div class="absolute inset-0 bg-slate-950/45 backdrop-blur-sm"></div>
  <div class="relative flex min-h-full items-end justify-center p-3 sm:items-center sm:p-6">
    <div class="w-full max-w-sm overflow-hidden rounded-[28px] bg-white shadow-2xl ring-1 ring-slate-200">
      <div class="px-5 pt-5 pb-4 sm:px-6">
        <div class="mb-4 flex h-12 w-12 items-center justify-center rounded-2xl bg-red-50 text-red-600">
          <i class="bi bi-trash3-fill text-lg"></i>
        </div>
        <h3 class="text-base font-semibold text-slate-900">Confirmer la suppression</h3>
        <p id="vlConfirmMessage" class="mt-2 text-sm leading-6 text-slate-600">Cette action est irréversible.</p>
      </div>
      <div class="grid grid-cols-2 gap-3 bg-slate-50 px-5 py-4 sm:px-6">
        <button id="vlConfirmCancel" type="button" class="inline-flex items-center justify-center rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm font-medium text-slate-700 shadow-sm transition hover:bg-slate-100">Annuler</button>
        <button id="vlConfirmAccept" type="button" class="inline-flex items-center justify-center rounded-2xl bg-red-600 px-4 py-3 text-sm font-semibold text-white shadow-sm transition hover:bg-red-700">Supprimer</button>
      </div>
    </div>
  </div>
</div>

<div id="vlCRModal" class="fixed inset-0 z-[6100] hidden">
  <div class="absolute inset-0 bg-slate-950/45 backdrop-blur-sm"></div>
  <div class="relative flex min-h-full items-end justify-center p-3 sm:items-center sm:p-6">
    <div class="w-full max-w-2xl overflow-hidden rounded-[28px] bg-white shadow-2xl ring-1 ring-slate-200">
      <div class="border-b border-slate-200 px-5 py-4 sm:px-6">
        <div class="flex items-start justify-between gap-4">
          <div>
            <p class="mb-1 text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Imagerie</p>
            <h3 id="vlCRModalTitle" class="text-base font-semibold text-slate-900">Compte-rendu</h3>
          </div>
          <button id="vlCRModalClose" type="button" class="inline-flex h-10 w-10 items-center justify-center rounded-2xl border border-slate-200 bg-white text-slate-500 transition hover:bg-slate-100">
            <i class="bi bi-x-lg"></i>
          </button>
        </div>
      </div>
      <form id="vlCRModalForm" class="px-5 py-5 sm:px-6">
        <?= csrf_input() ?>
        <input type="hidden" name="imagerie_id" id="vlCRModalId" value="">
        <div id="vlImagingVoiceBox" class="vl-voice-box p-3 d-flex align-items-center gap-3 mb-4">
          <button id="vlImagingVoiceBtn" type="button" class="vl-mic-btn btn btn-primary shadow-sm">
            <i class="bi bi-mic-fill"></i>
          </button>
          <div class="flex-grow-1" style="min-width:0">
            <div id="vlImagingVoiceText" class="fw-semibold" style="color:#0e6ba8;font-size:.9rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">Assistant vocal IA</div>
          </div>
          <div id="vlImagingVoiceTimer" class="ms-auto fw-bold text-danger vl-voice-timer d-none" style="font-size:1.05rem;font-variant-numeric:tabular-nums">00:00</div>
        </div>
        <textarea name="compte_rendu" id="vlCRModalTextarea" class="form-control" rows="9" placeholder="Saisir le compte-rendu détaillé…"></textarea>
        <div class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2">
          <button id="vlCRModalCancel" type="button" class="inline-flex items-center justify-center rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm font-medium text-slate-700 transition hover:bg-slate-100">Annuler</button>
          <button id="vlCRModalSave" type="submit" class="inline-flex items-center justify-center rounded-2xl bg-slate-900 px-4 py-3 text-sm font-semibold text-white transition hover:bg-slate-800">Enregistrer le compte-rendu</button>
        </div>
      </form>
    </div>
  </div>
</div>

<div id="vlPriceModal" class="fixed inset-0 z-[6120] hidden">
  <div class="absolute inset-0 bg-slate-950/45 backdrop-blur-sm"></div>
  <div class="relative flex min-h-full items-end justify-center p-3 sm:items-center sm:p-6">
    <div class="w-full max-w-md overflow-hidden rounded-[28px] bg-white shadow-2xl ring-1 ring-slate-200">
      <div class="border-b border-slate-200 px-5 py-4 sm:px-6">
        <div class="flex items-start justify-between gap-4">
          <div>
            <p class="mb-1 text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Imagerie</p>
            <h3 id="vlPriceModalTitle" class="text-base font-semibold text-slate-900">Modifier le prix</h3>
          </div>
          <button id="vlPriceModalClose" type="button" class="inline-flex h-10 w-10 items-center justify-center rounded-2xl border border-slate-200 bg-white text-slate-500 transition hover:bg-slate-100">
            <i class="bi bi-x-lg"></i>
          </button>
        </div>
      </div>
      <form id="vlPriceModalForm" class="px-5 py-5 sm:px-6">
        <?= csrf_input() ?>
        <input type="hidden" name="imagerie_id" id="vlPriceModalId" value="">
        <label for="vlPriceModalInput" class="mb-2 block text-sm font-medium text-slate-700">Prix (DH)</label>
        <input id="vlPriceModalInput" name="prix" type="number" step="0.01" min="0" class="form-control" placeholder="0.00">
        <div class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2">
          <button id="vlPriceModalCancel" type="button" class="inline-flex items-center justify-center rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm font-medium text-slate-700 transition hover:bg-slate-100">Annuler</button>
          <button id="vlPriceModalSave" type="submit" class="inline-flex items-center justify-center rounded-2xl bg-slate-900 px-4 py-3 text-sm font-semibold text-white transition hover:bg-slate-800">Enregistrer</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php
// Injection capture.php (une seule fois par page, par type)
$capAnalyseDir = 'uploads/reports/' . (int)$patient_id . '/analyses';
$capImagerieDir = 'uploads/reports/' . (int)$patient_id . '/imageries';
capture_handle($capAnalyseDir,  ['modal_id' => 'capAnalyseModal',  'maxWidth' => 1600, 'quality' => 0.9]);
capture_handle($capImagerieDir, ['modal_id' => 'capImagerieModal', 'maxWidth' => 1600, 'quality' => 0.9]);
?>

<script>
function vlConsultTabStorageKey(){
  return 'vetlink:consultation-tab:<?= (int)$consult_id ?>';
}
function vlRememberConsultTab(tab){
  try {
    window.sessionStorage.setItem(vlConsultTabStorageKey(), tab || 'examen');
  } catch (e) {}
}
function vlGetRememberedConsultTab(){
  try {
    return window.sessionStorage.getItem(vlConsultTabStorageKey()) || 'examen';
  } catch (e) {
    return 'examen';
  }
}
function vlConsultTabs(){
  return {
    tab: 'examen',
    init(){
      this.tab = vlGetRememberedConsultTab();
      if (!['examen', 'analyses', 'imageries'].includes(this.tab)) {
        this.tab = 'examen';
      }
      vlRememberConsultTab(this.tab);
    },
    setTab(next){
      this.tab = next;
      vlRememberConsultTab(next);
    }
  };
}
function vlShowAjaxStatus(message, kind){
  var box = document.getElementById('vlAjaxStatus');
  if (!box) return;
  box.textContent = message || '';
  box.classList.remove('alert-success', 'alert-danger', 'is-visible');
  box.classList.add(kind === 'error' ? 'alert-danger' : 'alert-success');
  if (message) {
    box.classList.add('is-visible');
    window.clearTimeout(box._hideTimer);
    box._hideTimer = window.setTimeout(function(){
      box.classList.remove('is-visible');
    }, 2600);
  }
}
function vlUpdateCountBadge(id, count){
  var badge = document.getElementById(id);
  if (!badge) return;
  badge.textContent = String(count);
  badge.classList.toggle('d-none', !count);
}
var vlConfirmState = { onAccept: null };
function vlCloseConfirmModal(){
  var modal = document.getElementById('vlConfirmModal');
  if (!modal) return;
  modal.classList.add('hidden');
  document.body.classList.remove('overflow-hidden');
  vlConfirmState.onAccept = null;
}
function vlToggleVideoPlayer(btn){
  var wrap = btn ? btn.closest('.vl-video-wrap') : null;
  var video = wrap ? wrap.querySelector('video') : null;
  var icon = btn ? btn.querySelector('i') : null;
  if (!video || !icon) return;
  if (video.paused) {
    video.play().catch(function(){});
    icon.className = 'bi bi-pause-fill';
  } else {
    video.pause();
    icon.className = 'bi bi-play-fill';
  }
}
function vlStopModalVideos(scope){
  (scope || document).querySelectorAll('.vl-attachment-video').forEach(function(video){
    video.pause();
    var btn = video.closest('.vl-video-wrap')?.querySelector('.vl-video-toggle i');
    if (btn) btn.className = 'bi bi-play-fill';
  });
}
function vlOpenConfirmModal(message, onAccept){
  var modal = document.getElementById('vlConfirmModal');
  var msg = document.getElementById('vlConfirmMessage');
  if (!modal || !msg) {
    if (typeof onAccept === 'function') onAccept();
    return;
  }
  msg.textContent = message || 'Cette action est irréversible.';
  vlConfirmState.onAccept = onAccept;
  modal.classList.remove('hidden');
  document.body.classList.add('overflow-hidden');
}
function vlEscapeHtml(value){
  return String(value == null ? '' : value)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#039;');
}
function vlFormatPrice(value){
  var num = Number(value || 0);
  return num.toFixed(2);
}
function vlAppendAnalyseRow(item, tbodyId, emptyRowId){
  var tbody = document.getElementById(tbodyId);
  if (!tbody) return;
  var empty = document.getElementById(emptyRowId);
  if (empty) empty.remove();
  var id = Number(item.id || 0);
  var result = String(item.resultat || '').trim();
  var preview = result ? result.replace(/\s+/g, ' ').slice(0, 180) + (result.replace(/\s+/g, ' ').length > 180 ? '…' : '') : '';
  var card = document.createElement('article');
  card.className = 'vl-an-card';
  card.setAttribute('data-analyse-id', String(id));
  card.innerHTML = ''
    + '<div class="vl-an-head"><div class="vl-an-ident"><span class="vl-an-kicker">Analyse #' + id + '</span><h3 class="vl-an-title">' + vlEscapeHtml(item.type_analyse || '—') + '</h3></div><div class="vl-an-price">' + vlEscapeHtml(vlFormatPrice(item.prix)) + ' DH</div></div>'
    + '<div class="vl-an-meta"><div class="vl-an-chip-row"><span class="vl-an-chip is-type">' + vlEscapeHtml(item.type_analyse || '—') + '</span><span class="vl-an-chip">Aucun fichier</span><span class="vl-an-chip">' + (result ? 'Résultat saisi' : 'Résultat vide') + '</span></div>'
    + '<div class="vl-an-result"><p class="mb-0' + (result ? '' : ' text-muted') + '">' + vlEscapeHtml(result ? preview : 'Aucun résultat saisi pour cette analyse.') + '</p></div></div>'
    + '<div class="vl-an-actions">'
    + '<button class="btn btn-sm btn-outline-primary" type="button" onclick="openCaptureModal({ modalId:\'capAnalyseModal\', onDone:(paths)=>vlSubmitPaths(\'analyse\',' + id + ',paths) })">Ajouter fichiers</button>'
    + '<button class="btn btn-sm btn-outline-danger" type="button" onclick="vlDeleteAnalyse(' + id + ')">Supprimer</button>'
    + '</div>';
  tbody.appendChild(card);
}
function vlAppendImagerieRow(item, tbodyId, emptyRowId){
  var tbody = document.getElementById(tbodyId);
  if (!tbody) return;
  var empty = document.getElementById(emptyRowId);
  if (empty) empty.remove();
  var id = Number(item.id || 0);
  var report = String(item.compte_rendu || '').trim();
  var preview = report ? report.replace(/\s+/g, ' ').slice(0, 220) + (report.replace(/\s+/g, ' ').length > 220 ? '…' : '') : '';
  var card = document.createElement('article');
  card.className = 'vl-im-card';
  card.setAttribute('data-imagerie-id', String(id));
  card.setAttribute('data-imagerie-type', String(item.type_imagerie || '—'));
  card.setAttribute('data-imagerie-report', report);
  card.innerHTML = ''
    + '<div class="vl-im-head"><div class="vl-im-ident"><span class="vl-im-kicker">Imagerie #' + id + '</span><h3 class="vl-im-title">' + vlEscapeHtml(item.type_imagerie || '—') + '</h3></div><button class="vl-im-price" type="button" onclick="vlOpenPriceModal(' + id + ')">' + vlEscapeHtml(vlFormatPrice(item.prix)) + ' DH</button></div>'
    + '<div class="vl-im-meta"><div class="vl-im-chip-row"><span class="vl-im-chip">Aucun fichier</span><span class="vl-im-chip">' + (report ? 'Compte-rendu saisi' : 'Compte-rendu vide') + '</span></div>'
    + '<div class="vl-im-report" id="cr-preview-' + id + '"><p class="mb-0' + (report ? '' : ' text-muted') + '">' + vlEscapeHtml(report ? preview : 'Ajoutez un compte-rendu structuré pour documenter l’examen.') + '</p></div></div>'
    + '<div class="vl-im-actions">'
    + '<button class="btn btn-sm btn-outline-dark" type="button" onclick="vlOpenCRModal(' + id + ')">' + (report ? 'Modifier CR' : 'Ajouter CR') + '</button>'
    + '<button class="btn btn-sm btn-outline-primary" type="button" onclick="openCaptureModal({ modalId:\'capImagerieModal\', onDone:(paths)=>vlSubmitPaths(\'imagerie\',' + id + ',paths) })">Ajouter fichiers</button>'
    + '</div>';
  tbody.appendChild(card);
}
function vlReplaceItemHtml(kind, itemId, html){
  var selector = kind === 'analyse'
    ? '[data-analyse-id="' + String(itemId) + '"]'
    : '[data-imagerie-id="' + String(itemId) + '"]';
  var oldRow = document.querySelector(selector);
  if (!oldRow) return;

  var oldModalId = kind === 'analyse' ? 'modalVoirAnalyse' + itemId : 'modalVoirImagerie' + itemId;
  var oldModal = document.getElementById(oldModalId);
  if (oldModal) oldModal.remove();

  var tpl = document.createElement('template');
  tpl.innerHTML = html;
  var newRow = null;
  Array.from(tpl.content.childNodes).forEach(function(node){
    if (node.nodeType !== 1) return;
    if (!newRow && ((kind === 'analyse' && node.matches && node.matches('article')) || (kind === 'imagerie' && node.matches && node.matches('article')))) {
      newRow = node;
      return;
    }
    if (node.classList && node.classList.contains('modal')) {
      document.body.appendChild(node);
    }
  });
  if (newRow) oldRow.replaceWith(newRow);
}
function vlEnsureEmptyRow(tbodyId, emptyRowId, label, kind){
  var tbody = document.getElementById(tbodyId);
  if (!tbody) return;
  var hasRows = kind === 'imagerie'
    ? tbody.querySelector('[data-imagerie-id]')
    : tbody.querySelector('[data-analyse-id], [data-imagerie-id]');
  var empty = document.getElementById(emptyRowId);
  if (hasRows) {
    if (empty) empty.remove();
    return;
  }
  if (!empty) {
    empty = document.createElement('div');
    empty.id = emptyRowId;
    if (kind === 'imagerie') {
      empty.className = 'vl-im-empty';
      empty.textContent = label;
    } else {
      empty.className = 'vl-an-empty';
      empty.textContent = label;
    }
    tbody.appendChild(empty);
  }
}
async function vlAjaxAddItem(formId, tbodyId, emptyRowId, badgeId, kind){
  var form = document.getElementById(formId);
  if (!form) return;
  form.addEventListener('submit', async function(ev){
    ev.preventDefault();
    var fd = new FormData(form);
    fd.append('consultation_id', '<?= (int)$consult_id ?>');
    fd.append('type', kind);
    var btn = form.querySelector('button[type="submit"]');
    var oldLabel = btn ? btn.innerHTML : '';
    if (btn) {
      btn.disabled = true;
      btn.innerHTML = 'Envoi…';
    }
    try {
      var resp = await fetch('<?= base_url('api/consultation_items.php') ?>', {
        method: 'POST',
        body: fd,
        headers: {
          'X-Requested-With': 'XMLHttpRequest',
          'Accept': 'application/json'
        },
      });
      var raw = await resp.text();
      var data = null;
      try {
        data = JSON.parse(raw);
      } catch (e) {
        throw new Error(raw.slice(0, 160) || 'Réponse serveur invalide.');
      }
      if (!resp.ok || !data || !data.ok) {
        throw new Error((data && data.message) || 'Erreur lors de l\'ajout.');
      }
      if (kind === 'analyse') {
        vlAppendAnalyseRow(data.item || {}, tbodyId, emptyRowId);
      } else {
        vlAppendImagerieRow(data.item || {}, tbodyId, emptyRowId);
      }
      vlUpdateCountBadge(badgeId, Number(data.count || 0));
      form.reset();
      var select = form.querySelector('select');
      if (select) select.selectedIndex = 0;
      vlShowAjaxStatus(data.message || 'Ajout effectué.');
    } catch (err) {
      vlShowAjaxStatus(err.message || 'Erreur réseau.', 'error');
    } finally {
      if (btn) {
        btn.disabled = false;
        btn.innerHTML = oldLabel;
      }
    }
  });
}
// Bridge capture -> POST serveur
function vlGetCSRF(){
  var el = document.querySelector('input[type="hidden"][name^="__csrf"]') || document.querySelector('input[type="hidden"][name*="csrf"]');
  return el ? [el.getAttribute('name'), el.value] : null;
}
async function vlSubmitPaths(kind, itemId, paths){
  if (!paths || !paths.length) return;
  var fd = new FormData();
  var csrf = vlGetCSRF();
  if (csrf) fd.append(csrf[0], csrf[1]);
  fd.append('consultation_id', '<?= (int)$consult_id ?>');
  fd.append('type', kind === 'analyse' ? 'analyse_paths' : 'imagerie_paths');
  fd.append(kind === 'analyse' ? 'analyse_id' : 'imagerie_id', String(itemId));
  fd.append('paths_json', JSON.stringify(paths));
  try {
    var resp = await fetch('<?= base_url('api/consultation_items.php') ?>', {
      method: 'POST',
      body: fd,
      headers: {
        'X-Requested-With': 'XMLHttpRequest',
        'Accept': 'application/json'
      },
    });
    var raw = await resp.text();
    var data = null;
    try {
      data = JSON.parse(raw);
    } catch (e) {
      throw new Error(raw.slice(0, 160) || 'Réponse serveur invalide.');
    }
    if (!resp.ok || !data || !data.ok) {
      throw new Error((data && data.message) || 'Erreur lors de l\'enregistrement des fichiers.');
    }
    if (data.html) vlReplaceItemHtml(kind, itemId, data.html);
    vlShowAjaxStatus(data.message || 'Fichiers mis à jour.');
  } catch (err) {
    vlShowAjaxStatus(err.message || 'Erreur réseau.', 'error');
  }
}
async function vlDeleteAnalyse(itemId){
  vlOpenConfirmModal('Supprimer cette analyse vide ?', async function(){
  var fd = new FormData();
  var csrf = vlGetCSRF();
  if (csrf) fd.append(csrf[0], csrf[1]);
  fd.append('consultation_id', '<?= (int)$consult_id ?>');
  fd.append('type', 'analyse_delete');
  fd.append('analyse_id', String(itemId));
  try {
    var resp = await fetch('<?= base_url('api/consultation_items.php') ?>', {
      method: 'POST',
      body: fd,
      headers: {
        'X-Requested-With': 'XMLHttpRequest',
        'Accept': 'application/json'
      },
    });
    var raw = await resp.text();
    var data = null;
    try {
      data = JSON.parse(raw);
    } catch (e) {
      throw new Error(raw.slice(0, 160) || 'Réponse serveur invalide.');
    }
    if (!resp.ok || !data || !data.ok) {
      throw new Error((data && data.message) || 'Erreur lors de la suppression.');
    }
    document.querySelector('tr[data-analyse-id="' + String(itemId) + '"]')?.remove();
    document.getElementById('modalVoirAnalyse' + String(itemId))?.remove();
    vlUpdateCountBadge('analysesCountBadge', Number(data.count || 0));
  vlEnsureEmptyRow('analysesList', 'analysesEmptyRow', 'Aucune analyse enregistrée.', 'analyse');
    vlShowAjaxStatus(data.message || 'Analyse supprimée.');
  } catch (err) {
    vlShowAjaxStatus(err.message || 'Erreur réseau.', 'error');
  }
  });
}

function vlCloseCRModal(){
  var modal = document.getElementById('vlCRModal');
  if (!modal) return;
  modal.classList.add('hidden');
  document.body.classList.remove('overflow-hidden');
  if (window.vlImagingVoice) window.vlImagingVoice.reset();
}
function vlClosePriceModal(){
  var modal = document.getElementById('vlPriceModal');
  if (!modal) return;
  modal.classList.add('hidden');
  document.body.classList.remove('overflow-hidden');
}
function vlOpenCRModal(id){
  var card = document.querySelector('[data-imagerie-id="' + String(id) + '"]');
  var modal = document.getElementById('vlCRModal');
  if (!card || !modal) return;
  document.getElementById('vlCRModalId').value = String(id);
  document.getElementById('vlCRModalTitle').textContent = 'Compte-rendu — ' + (card.getAttribute('data-imagerie-type') || ('Imagerie #' + id));
  document.getElementById('vlCRModalTextarea').value = card.getAttribute('data-imagerie-report') || '';
  modal.classList.remove('hidden');
  document.body.classList.add('overflow-hidden');
  window.setTimeout(function(){
    document.getElementById('vlCRModalTextarea')?.focus();
  }, 30);
}
function vlOpenPriceModal(id){
  var card = document.querySelector('[data-imagerie-id="' + String(id) + '"]');
  var modal = document.getElementById('vlPriceModal');
  if (!card || !modal) return;
  document.getElementById('vlPriceModalId').value = String(id);
  document.getElementById('vlPriceModalTitle').textContent = 'Modifier le prix — ' + (card.getAttribute('data-imagerie-type') || ('Imagerie #' + id));
  var btn = card.querySelector('.vl-im-price');
  var current = btn ? btn.textContent.replace(/[^\d.]/g, '') : '0.00';
  document.getElementById('vlPriceModalInput').value = current || '0.00';
  modal.classList.remove('hidden');
  document.body.classList.add('overflow-hidden');
  window.setTimeout(function(){
    document.getElementById('vlPriceModalInput')?.focus();
    document.getElementById('vlPriceModalInput')?.select();
  }, 30);
}
function vlUpdateImagerieReport(id, text){
  var card = document.querySelector('[data-imagerie-id="' + String(id) + '"]');
  if (!card) return;
  var report = String(text || '').trim();
  card.setAttribute('data-imagerie-report', report);
  var preview = document.getElementById('cr-preview-' + String(id));
  if (preview) {
    var shortText = report ? report.replace(/\s+/g, ' ').slice(0, 220) + (report.replace(/\s+/g, ' ').length > 220 ? '…' : '') : '';
    preview.innerHTML = '<p class="mb-0' + (report ? '' : ' text-muted') + '">' + vlEscapeHtml(report ? shortText : 'Ajoutez un compte-rendu structuré pour documenter l’examen.') + '</p>';
  }
  var statusChip = card.querySelector('.vl-im-chip-row .vl-im-chip:last-child');
  if (statusChip) statusChip.textContent = report ? 'Compte-rendu saisi' : 'Compte-rendu vide';
  var actionBtn = Array.from(card.querySelectorAll('.vl-im-actions .btn')).find(function(btn){
    return btn.classList.contains('btn-outline-dark');
  });
  if (actionBtn) actionBtn.textContent = report ? 'Modifier CR' : 'Ajouter CR';
}
async function vlCRsave(ev){
  ev.preventDefault();
  var form = ev.target;
  var id = Number(document.getElementById('vlCRModalId').value || 0);
  if (!id) return false;
  var fd = new FormData(form);
  try {
    let r = await fetch('api/cr-api.php', { method:'POST', body:fd });
    let j = await r.json();
    if (j && j.ok) {
      vlUpdateImagerieReport(id, j.text || '');
      vlCloseCRModal();
      vlShowAjaxStatus('Compte-rendu enregistré.');
    } else {
      vlShowAjaxStatus(j && j.msg ? j.msg : 'Erreur de sauvegarde', 'error');
    }
  } catch (e) {
    vlShowAjaxStatus('Erreur réseau', 'error');
  }
  return false;
}
async function vlPriceSave(ev){
  ev.preventDefault();
  var form = ev.target;
  var id = Number(document.getElementById('vlPriceModalId').value || 0);
  if (!id) return false;
  var fd = new FormData(form);
  fd.append('consultation_id', '<?= (int)$consult_id ?>');
  fd.append('type', 'imagerie_price');
  try {
    let r = await fetch('<?= base_url('api/consultation_items.php') ?>', {
      method:'POST',
      body: fd,
      headers: {
        'X-Requested-With': 'XMLHttpRequest',
        'Accept': 'application/json'
      }
    });
    let raw = await r.text();
    let j = null;
    try {
      j = JSON.parse(raw);
    } catch (e) {
      throw new Error(raw.slice(0, 160) || 'Réponse serveur invalide.');
    }
    if (!r.ok || !j || !j.ok) {
      throw new Error((j && j.message) || 'Erreur de sauvegarde');
    }
    if (j.html) vlReplaceItemHtml('imagerie', id, j.html);
    vlClosePriceModal();
    vlShowAjaxStatus(j.message || 'Prix mis à jour.');
  } catch (e) {
    vlShowAjaxStatus(e.message || 'Erreur réseau', 'error');
  }
  return false;
}
window.vlImagingVoice = {
  state: 'idle',
  mediaRecorder: null,
  chunks: [],
  timerInterval: null,
  seconds: 0,
  MAX_SECONDS: 120,
  getEls(){
    return {
      box: document.getElementById('vlImagingVoiceBox'),
      btn: document.getElementById('vlImagingVoiceBtn'),
      text: document.getElementById('vlImagingVoiceText'),
      timer: document.getElementById('vlImagingVoiceTimer'),
      textarea: document.getElementById('vlCRModalTextarea'),
    };
  },
  render(){
    var els = this.getEls();
    if (!els.btn || !els.text || !els.box || !els.timer) return;
    els.box.classList.toggle('is-recording', this.state === 'recording');
    els.timer.classList.toggle('d-none', this.state !== 'recording');
    els.timer.textContent = String(Math.floor(this.seconds / 60)).padStart(2, '0') + ':' + String(this.seconds % 60).padStart(2, '0');
    if (this.state === 'recording') {
      els.btn.className = 'vl-mic-btn btn btn-danger shadow-sm';
      els.btn.innerHTML = '<i class="bi bi-stop-fill"></i>';
      els.text.textContent = 'Assistant vocal IA';
    } else if (this.state === 'processing') {
      els.btn.className = 'vl-mic-btn btn btn-secondary shadow-sm';
      els.btn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>';
      els.text.textContent = 'Assistant vocal IA';
    } else if (this.state === 'done') {
      els.btn.className = 'vl-mic-btn btn btn-primary shadow-sm';
      els.btn.innerHTML = '<i class="bi bi-mic-fill"></i>';
      els.text.textContent = 'Assistant vocal IA';
    } else if (this.state === 'error') {
      els.btn.className = 'vl-mic-btn btn btn-primary shadow-sm';
      els.btn.innerHTML = '<i class="bi bi-mic-fill"></i>';
      els.text.textContent = 'Assistant vocal IA';
    } else {
      els.btn.className = 'vl-mic-btn btn btn-primary shadow-sm';
      els.btn.innerHTML = '<i class="bi bi-mic-fill"></i>';
      els.text.textContent = 'Assistant vocal IA';
    }
    els.btn.disabled = this.state === 'processing';
  },
  reset(){
    if (this.timerInterval) clearInterval(this.timerInterval);
    this.timerInterval = null;
    this.seconds = 0;
    if (this.mediaRecorder && this.mediaRecorder.state !== 'inactive') {
      try { this.mediaRecorder.stop(); } catch (e) {}
    }
    this.mediaRecorder = null;
    this.chunks = [];
    this.state = 'idle';
    this.render();
  },
  async toggle(){
    if (this.state === 'recording') {
      this.stop();
      return;
    }
    if (this.state === 'processing') return;
    await this.start();
  },
  async start(){
    this.chunks = [];
    let stream;
    try {
      stream = await navigator.mediaDevices.getUserMedia({ audio: true });
    } catch (_) {
      this.state = 'error';
      this.render();
      vlShowAjaxStatus('Accès au microphone refusé.', 'error');
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
      this.send(blob);
    };
    this.mediaRecorder.start(250);
    this.state = 'recording';
    this.seconds = 0;
    this.render();
    this.timerInterval = setInterval(() => {
      this.seconds++;
      this.render();
      if (this.seconds >= this.MAX_SECONDS) this.stop();
    }, 1000);
  },
  stop(){
    if (this.timerInterval) clearInterval(this.timerInterval);
    this.timerInterval = null;
    if (this.mediaRecorder && this.mediaRecorder.state !== 'inactive') {
      this.mediaRecorder.stop();
    }
    this.state = 'processing';
    this.render();
  },
  async send(blob){
    const csrfInput = document.querySelector('#vlCRModalForm input[name="csrf_token"]');
    const fd = new FormData();
    fd.append('audio', blob, 'imagerie.webm');
    if (csrfInput) fd.append('csrf_token', csrfInput.value);
    try {
      const resp = await fetch('<?= base_url('api/voice_imagerie.php') ?>', {
        method: 'POST',
        body: fd,
      });
      const data = await resp.json().catch(() => ({}));
      if (!resp.ok || !data || !data.ok) {
        throw new Error(data.msg || 'Erreur de traitement audio.');
      }
      const els = this.getEls();
      if (els.textarea) {
        const current = els.textarea.value.trim();
        els.textarea.value = current ? (current + '\n\n' + (data.report || '').trim()) : (data.report || '').trim();
      }
      this.state = 'done';
      this.render();
      vlShowAjaxStatus('Compte-rendu vocal généré.');
    } catch (e) {
      this.state = 'error';
      this.render();
      vlShowAjaxStatus(e.message || 'Erreur réseau', 'error');
    }
  }
};

document.addEventListener('click', function(e){
  var accept = e.target.closest('#vlConfirmAccept');
  if (accept) {
    var cb = vlConfirmState.onAccept;
    vlCloseConfirmModal();
    if (typeof cb === 'function') cb();
    return;
  }
  if (e.target.closest('#vlConfirmCancel') || e.target.id === 'vlConfirmModal') {
    vlCloseConfirmModal();
    return;
  }
  if (e.target.closest('#vlCRModalClose') || e.target.closest('#vlCRModalCancel') || e.target.id === 'vlCRModal') {
    vlCloseCRModal();
    return;
  }
  if (e.target.closest('#vlPriceModalClose') || e.target.closest('#vlPriceModalCancel') || e.target.id === 'vlPriceModal') {
    vlClosePriceModal();
    return;
  }
});
document.addEventListener('submit', function(e){
  var tabHost = e.target.closest('[x-data]');
  if (tabHost && tabHost.__x && tabHost.__x.$data && typeof tabHost.__x.$data.tab === 'string') {
    vlRememberConsultTab(tabHost.__x.$data.tab);
  }
  var form = e.target.closest('form[data-confirm-delete]');
  if (!form || form.dataset.confirmApproved === '1') return;
  e.preventDefault();
  vlOpenConfirmModal(form.getAttribute('data-confirm-delete') || 'Confirmer la suppression ?', function(){
    form.dataset.confirmApproved = '1';
    form.requestSubmit ? form.requestSubmit() : form.submit();
    window.setTimeout(function(){ delete form.dataset.confirmApproved; }, 0);
  });
});
document.addEventListener('keydown', function(e){
  if (e.key === 'Escape') vlCloseConfirmModal();
  if (e.key === 'Escape') vlCloseCRModal();
  if (e.key === 'Escape') vlClosePriceModal();
});
document.addEventListener('DOMContentLoaded', function(){
  vlAjaxAddItem('analyseAddForm', 'analysesList', 'analysesEmptyRow', 'analysesCountBadge', 'analyse');
  vlAjaxAddItem('imagerieAddForm', 'imageriesList', 'imageriesEmptyRow', 'imageriesCountBadge', 'imagerie');
  document.getElementById('vlCRModalForm')?.addEventListener('submit', vlCRsave);
  document.getElementById('vlPriceModalForm')?.addEventListener('submit', vlPriceSave);
  document.getElementById('vlImagingVoiceBtn')?.addEventListener('click', function(){
    window.vlImagingVoice.toggle();
  });
  window.vlImagingVoice.render();
  document.querySelectorAll('.vl-move-to-body').forEach(function(m){
    if (m.parentElement !== document.body) document.body.appendChild(m);
  });
  document.querySelectorAll('.modal').forEach(function(modal){
    modal.addEventListener('hidden.bs.modal', function(){
      vlStopModalVideos(modal);
    });
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
