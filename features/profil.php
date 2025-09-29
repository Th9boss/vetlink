<?php
// features/profil.php
// Profil utilisateur connecté : infos + cachet (texte->PNG) + signature (canvas->PNG)

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';

require_login();
csrf_check();

$meId = (int)(current_user()['id'] ?? 0);

// ----- Charger infos utilisateur -----
$st = db()->prepare("SELECT id, role, prenom, nom, email, gsm, cachet_img, signature_img FROM users WHERE id=? LIMIT 1");
$st->execute([$meId]);
$me = $st->fetch(PDO::FETCH_ASSOC) ?: ['id'=>$meId];

// Sécurité douce si colonnes pas encore ajoutées
foreach (['cachet_img','signature_img'] as $col) {
  if (!array_key_exists($col, $me)) $me[$col] = null;
}

// ----- Helpers fichiers -----
function ensure_dir(string $dir): void { if (!is_dir($dir)) @mkdir($dir, 0775, true); }

/**
 * Génère un nom versionné : basename-YYYYmmddHHMMSS-XXXX.ext
 */
function versioned_path(string $destDir, string $basename, string $ext): string {
  $ts = date('YmdHis');
  $rand = substr(bin2hex(random_bytes(4)), 0, 4);
  return rtrim($destDir,'/')."/{$basename}-{$ts}-{$rand}.{$ext}";
}

/**
 * Sauvegarde un upload image en supprimant l'ancienne si fournie,
 * et en écrivant un fichier à nom unique pour casser le cache navigateur.
 * @param array $file $_FILES['...']
 * @param string $destDir
 * @param string $basename ex: 'cachet' | 'signature'
 * @param ?string $oldPath Chemin stocké en BDD à supprimer si existe
 * @return ?string Chemin relatif sauvegardé
 */
function save_image_upload(array $file, string $destDir, string $basename, ?string $oldPath=null): ?string {
  if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return null;
  if (($file['error'] ?? 0) !== UPLOAD_ERR_OK) return null;
  $tmp = $file['tmp_name'] ?? '';
  if (!$tmp || !is_uploaded_file($tmp)) return null;
  $info = @getimagesize($tmp);
  if (!$info) return null;
  $mime = $info['mime'] ?? '';
  $ext = ($mime === 'image/png') ? 'png' : 'jpg';

  ensure_dir($destDir);

  // Supprimer l'ancien fichier si connu
  if ($oldPath && is_file($oldPath)) @unlink($oldPath);

  $path = versioned_path($destDir, $basename, $ext);
  if (!move_uploaded_file($tmp, $path)) return null;
  return $path;
}

/**
 * Sauvegarde un dataURL PNG en supprimant l'ancienne si fournie,
 * et en écrivant un fichier à nom unique.
 */
function save_dataurl_png(?string $dataUrl, string $destDir, string $basename, ?string $oldPath=null): ?string {
  if (!$dataUrl || !str_starts_with($dataUrl, 'data:image/png;base64,')) return null;
  $b64 = substr($dataUrl, strlen('data:image/png;base64,'));
  $bin = base64_decode($b64, true);
  if ($bin === false) return null;

  ensure_dir($destDir);

  // Supprimer l'ancien fichier si connu
  if ($oldPath && is_file($oldPath)) @unlink($oldPath);

  $path = versioned_path($destDir, $basename, 'png');
  if (file_put_contents($path, $bin) === false) return null;
  return $path;
}

// ----- POST -----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $prenom = trim($_POST['prenom'] ?? '');
  $nom    = trim($_POST['nom'] ?? '');
  $email  = trim($_POST['email'] ?? '');
  $gsm    = trim($_POST['gsm'] ?? '');

  $cachet_text_png     = $_POST['cachet_text_png']     ?? null; // dataURL
  $signature_drawn_png = $_POST['signature_drawn_png'] ?? null; // dataURL

  $baseDir = "uploads/users/{$meId}";
  $updates = [];
  $params  = [':id'=>$meId];

  // Infos de base
  if ($prenom !== '' && $nom !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $updates[] = "prenom=:prenom"; $params[':prenom'] = $prenom;
    $updates[] = "nom=:nom";       $params[':nom']    = $nom;
    $updates[] = "email=:email";   $params[':email']  = $email;
    $updates[] = "gsm=:gsm";       $params[':gsm']    = ($gsm !== '' ? $gsm : null);
  }

  // Cachet : priorité au PNG généré texte -> supprime ancien + nouveau nom versionné
  $cachetPath = save_dataurl_png($cachet_text_png, $baseDir, 'cachet', $me['cachet_img'] ?? null);
  if (!$cachetPath) {
    $cachetPath = save_image_upload($_FILES['cachet_img'] ?? [], $baseDir, 'cachet', $me['cachet_img'] ?? null);
  }
  if ($cachetPath) {
    $updates[] = "cachet_img=:cachet_img"; $params[':cachet_img'] = $cachetPath;
  }

  // Signature : priorité au PNG dessiné -> supprime ancien + nouveau nom versionné
  $sigPath = save_dataurl_png($signature_drawn_png, $baseDir, 'signature', $me['signature_img'] ?? null);
  if (!$sigPath) {
    $sigPath = save_image_upload($_FILES['signature_img'] ?? [], $baseDir, 'signature', $me['signature_img'] ?? null);
  }
  if ($sigPath) {
    $updates[] = "signature_img=:signature_img"; $params[':signature_img'] = $sigPath;
  }

  if ($updates) {
    $sql = "UPDATE users SET ".implode(', ', $updates)." WHERE id=:id";
    $q = db()->prepare($sql);
    $q->execute($params);
  }

  redirect('index.php?page=profil&saved=1');
  exit;
}

require_once __DIR__ . '/../includes/header.php';
?>
<div class="container py-3">
  <h1 class="h4 mb-3">Mon profil</h1>

  <?php if (!empty($_GET['saved'])): ?>
    <div class="alert alert-success py-2">Profil mis à jour.</div>
  <?php endif; ?>

  <!-- Carte de profil -->
  <div class="card shadow-sm border-0 mb-3">
    <div class="card-body d-flex flex-wrap gap-3 align-items-center">
      <div class="rounded-circle bg-light d-flex align-items-center justify-content-center"
           style="width:72px;height:72px;">
        <i class="bi bi-person-circle fs-2 text-muted"></i>
      </div>
      <div class="flex-grow-1">
        <div class="fw-semibold">
          <?= h(($me['prenom'] ?? '').' '.($me['nom'] ?? '')) ?>
          <span class="badge bg-secondary ms-2"><?= h($me['role'] ?? '') ?></span>
        </div>
        <div class="small text-muted mt-1">
          <i class="bi bi-envelope me-1"></i><?= h($me['email'] ?? '') ?>
          <?php if (!empty($me['gsm'])): ?>
            &nbsp;·&nbsp;<i class="bi bi-telephone me-1"></i><?= h($me['gsm']) ?>
          <?php endif; ?>
        </div>
      </div>
      <div class="d-flex gap-2 ms-auto">
        <?php if (!empty($me['cachet_img'])): ?>
          <img src="<?= h($me['cachet_img']) ?>" alt="Cachet" style="height:56px" class="border rounded p-1">
        <?php endif; ?>
        <?php if (!empty($me['signature_img'])): ?>
          <img src="<?= h($me['signature_img']) ?>" alt="Signature" style="height:56px" class="border rounded p-1">
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Formulaire -->
  <div class="card shadow-sm">
    <div class="card-header bg-light"><strong>Éditer mes informations</strong></div>
    <div class="card-body">
      <form method="post" enctype="multipart/form-data" class="row g-3" id="profileForm">
        <?= csrf_input() ?>

        <!-- Infos de base -->
        <div class="col-12">
          <div class="row g-3">
            <div class="col-sm-6">
              <label class="form-label">Prénom *</label>
              <input name="prenom" class="form-control" required value="<?= h($me['prenom'] ?? '') ?>">
            </div>
            <div class="col-sm-6">
              <label class="form-label">Nom *</label>
              <input name="nom" class="form-control" required value="<?= h($me['nom'] ?? '') ?>">
            </div>
            <div class="col-sm-6">
              <label class="form-label">Email *</label>
              <input type="email" name="email" class="form-control" required value="<?= h($me['email'] ?? '') ?>">
            </div>
            <div class="col-sm-6">
              <label class="form-label">GSM</label>
              <input name="gsm" class="form-control" value="<?= h($me['gsm'] ?? '') ?>">
            </div>
          </div>
        </div>

        <hr class="my-2">

        <!-- Cachet : génération par texte OU upload -->
        <div class="col-12">
          <div class="d-flex align-items-center justify-content-between">
            <label class="form-label mb-0">Cachet</label>
            <div class="small text-muted">Deux lignes → PNG (ligne 1 gras, ligne 2 plus petite, même largeur)</div>
          </div>

          <!-- Hints -->
          <div class="small text-muted mb-2">
            Astuce : ex. <em>Dr AMINA FARID</em> (ligne 1) et <em>Vétérinaire sanitaire mandaté</em> (ligne 2).
          </div>

          <div class="row g-2 align-items-end">
            <div class="col-md-4">
              <label class="form-label">Ligne 1 (gras)</label>
              <input id="cachetL1" class="form-control" placeholder="Dr AMINA FARID">
            </div>
            <div class="col-md-4">
              <label class="form-label">Ligne 2 (petit)</label>
              <input id="cachetL2" class="form-control" placeholder="Vétérinaire sanitaire mandaté">
            </div>
            <div class="col-md-4">
              <div class="d-flex align-items-center gap-2">
                <div class="stamp-color swatch" data-color="#111" title="Noir"></div>
                <div class="stamp-color swatch" data-color="#d32f2f" title="Rouge"></div>
                <div class="stamp-color swatch" data-color="#1976d2" title="Bleu"></div>
                <div class="stamp-color swatch" data-color="#2e7d32" title="Vert"></div>
                <button type="button" class="btn btn-outline-primary ms-auto" id="btnGenCachet">Générer</button>
                <button type="button" class="btn btn-outline-secondary" id="btnResetCachet" title="Réinitialiser l’aperçu">
                  <i class="bi bi-arrow-counterclockwise"></i>
                </button>
              </div>
            </div>

            <input type="hidden" name="cachet_text_png" id="cachetTextPng">
            <input type="hidden" id="cachetColor" value="#111">

            <div class="col-12 d-flex align-items-center gap-3 mt-2">
              <div class="border rounded p-2" style="min-height:78px;min-width:260px;">
                <img id="cachetPreview" alt="Aperçu cachet" style="max-height:72px;max-width:100%; display:none;">
              </div>
              <div class="text-muted small">ou choisir un fichier PNG/JPG :</div>
              <div class="flex-grow-1">
                <input type="file" name="cachet_img" accept="image/png,image/jpeg" class="form-control">
              </div>
            </div>
          </div>
        </div>

        <hr class="my-2">

        <!-- Signature : capture dans une modale OU upload -->
        <div class="col-12">
          <div class="d-flex align-items-center justify-content-between">
            <label class="form-label mb-0">Signature</label>
            <div class="small text-muted">Signez au doigt/souris, le PNG remplace l’ancienne signature.</div>
          </div>

          <div class="d-flex flex-wrap gap-2 align-items-center">
            <button type="button" class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#sigModal">
              Capturer la signature
            </button>
            <div class="text-muted small">ou choisir un fichier PNG/JPG :</div>
            <div style="min-width:220px;flex:1 1 auto;">
              <input type="file" name="signature_img" accept="image/png,image/jpeg" class="form-control">
            </div>
          </div>

          <input type="hidden" name="signature_drawn_png" id="signatureDrawnPng">

          <div class="mt-2 border rounded p-2" style="min-height:72px;">
            <img id="signaturePreview" alt="Aperçu signature" style="max-height:72px;max-width:100%; <?= empty($me['signature_img'])?'display:none;':'' ?>"
                 src="<?= h($me['signature_img'] ?? '') ?>">
          </div>
        </div>

        <div class="col-12 d-flex justify-content-end">
          <button class="btn btn-primary">Enregistrer</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Modale Signature -->
<div class="modal fade" id="sigModal" tabindex="-1" aria-labelledby="sigModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-sm">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="sigModalLabel">Signer</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button>
      </div>
      <div class="modal-body">
        <div class="border rounded position-relative">
          <canvas id="sigCanvas" style="touch-action:none;width:100%;height:160px;display:block;"></canvas>
        </div>
        <div class="d-flex gap-2 mt-2">
          <button type="button" class="btn btn-outline-secondary btn-sm" id="sigClear">Effacer</button>
          <button type="button" class="btn btn-primary btn-sm ms-auto" id="sigSave">Utiliser cette signature</button>
        </div>
      </div>
    </div>
  </div>
</div>

<style>
  @media (max-width: 576px){
    .card .form-label { font-size:.95rem; }
    .card input, .card button, .card select { font-size: .95rem; }
  }
  .stamp-color.swatch {
    width:26px; height:26px; border-radius:6px; border:1px solid rgba(0,0,0,.1);
    cursor:pointer; box-shadow: inset 0 0 0 2px #fff;
  }
  .stamp-color.swatch[data-color="#111"]   { background:#111; }
  .stamp-color.swatch[data-color="#d32f2f"]{ background:#d32f2f; }
  .stamp-color.swatch[data-color="#1976d2"]{ background:#1976d2; }
  .stamp-color.swatch[data-color="#2e7d32"]{ background:#2e7d32; }
  .swatch.active { outline: 2px solid #0d6efd; }
</style>

<script>
/* ========== Palette couleurs cachet + rendu lignes même largeur ========== */
(function(){
  const l1 = document.getElementById('cachetL1');
  const l2 = document.getElementById('cachetL2');
  const btnGen = document.getElementById('btnGenCachet');
  const btnReset = document.getElementById('btnResetCachet');
  const out = document.getElementById('cachetTextPng');
  const img = document.getElementById('cachetPreview');
  const colorInput = document.getElementById('cachetColor');
  const swatches = document.querySelectorAll('.stamp-color.swatch');

  function selectSwatch(el){
    swatches.forEach(s => s.classList.remove('active'));
    el.classList.add('active');
    colorInput.value = el.dataset.color || '#111';
  }
  swatches.forEach(s => s.addEventListener('click', () => selectSwatch(s)));
  const first = Array.from(swatches).find(s => s.dataset.color === '#111') || swatches[0];
  if (first) selectSwatch(first);

  function renderStamp(){
    const line1 = (l1.value || 'Dr AMINA FARID').trim();
    const line2 = (l2.value || 'Vétérinaire sanitaire mandaté').trim();
    const color = colorInput.value || '#111';

    const c = document.createElement('canvas');
    const ctx = c.getContext('2d');
    const px = Math.max(2, Math.round(window.devicePixelRatio || 1)); // scale DPI
    const padX = 16*px, padY = 12*px;
    const font1 = 18*px; // bold
    const font2 = 13*px; // smaller

    ctx.font = `bold ${font1}px system-ui, -apple-system, "Segoe UI", Roboto`;
    const w1 = ctx.measureText(line1).width;
    ctx.font = `${font2}px system-ui, -apple-system, "Segoe UI", Roboto`;
    const w2 = ctx.measureText(line2).width;

    const textW = Math.max(w1, w2);
    const width = Math.ceil(textW + padX*2);
    const height = Math.ceil(font1 + (line2? (font2 + 8*px) : 0) + padY*2);

    c.width = width; c.height = height;
    ctx.clearRect(0,0,width,height);
    ctx.fillStyle = color;

    ctx.font = `bold ${font1}px system-ui, -apple-system, "Segoe UI", Roboto`;
    let m1 = ctx.measureText(line1).width;
    let x1 = Math.round((width - m1)/2);
    let y = padY + font1;
    ctx.fillText(line1, x1, y);

    if (line2) {
      ctx.font = `${font2}px system-ui, -apple-system, "Segoe UI", Roboto`;
      let m2 = ctx.measureText(line2).width;
      let x2 = Math.round((width - m2)/2);
      y += font2 + 8*px;
      ctx.fillText(line2, x2, y);
    }

    const dataUrl = c.toDataURL('image/png');
    out.value = dataUrl;
    img.src = dataUrl;
    img.style.display = 'inline-block';
  }

  btnGen?.addEventListener('click', renderStamp);
  btnReset?.addEventListener('click', function(){
    out.value=''; img.src=''; img.style.display='none';
  });
})();

/* ========== Signature : FIX du “1er coup” (sizing + pointer events) ========== */
(function(){
  const modalEl = document.getElementById('sigModal');
  const canvas = document.getElementById('sigCanvas');
  const clearBtn = document.getElementById('sigClear');
  const saveBtn  = document.getElementById('sigSave');
  const out = document.getElementById('signatureDrawnPng');
  const preview = document.getElementById('signaturePreview');

  let ctx, dpr=window.devicePixelRatio||1, hasDrawn=false, isDown=false;

  function sizeCanvas(){
    const styleWidth = canvas.clientWidth || 320;
    const styleHeight= 160;
    canvas.width  = Math.max(320, Math.floor(styleWidth*dpr));
    canvas.height = Math.floor(styleHeight*dpr);
    canvas.style.height = styleHeight+'px';
    ctx = canvas.getContext('2d');
    ctx.fillStyle = '#00000000'; ctx.clearRect(0,0,canvas.width,canvas.height);
    ctx.lineCap='round'; ctx.lineJoin='round'; ctx.lineWidth=2*dpr; ctx.strokeStyle='#111';
    hasDrawn = false;
  }

  function getPos(ev){
    const r = canvas.getBoundingClientRect();
    const x = (ev.clientX - r.left) * dpr;
    const y = (ev.clientY - r.top) * dpr;
    return {x,y};
  }

  function pointerDown(ev){
    ev.preventDefault();
    if (!ctx) sizeCanvas();
    isDown = true; hasDrawn = true;
    const p = getPos(ev);
    ctx.beginPath();
    ctx.moveTo(p.x, p.y);
    canvas.setPointerCapture?.(ev.pointerId);
  }
  function pointerMove(ev){
    if (!isDown) return;
    const p = getPos(ev);
    ctx.lineTo(p.x, p.y);
    ctx.stroke();
  }
  function pointerUp(ev){
    isDown = false;
  }

  modalEl?.addEventListener('show.bs.modal', sizeCanvas);
  modalEl?.addEventListener('shown.bs.modal', () => setTimeout(sizeCanvas, 0));
  window.addEventListener('resize', () => { if (modalEl?.classList.contains('show')) sizeCanvas(); });

  ['pointerdown','pointermove','pointerup','pointercancel','pointerleave'].forEach(type => {
    canvas.addEventListener(type, function(ev){
      if (type==='pointerdown') pointerDown(ev);
      else if (type==='pointermove') pointerMove(ev);
      else if (type==='pointerup' || type==='pointercancel' || type==='pointerleave') pointerUp(ev);
    }, {passive:false});
  });

  clearBtn?.addEventListener('click', function(){
    if (!ctx) sizeCanvas();
    ctx.clearRect(0,0,canvas.width,canvas.height);
    hasDrawn = false;
  });

  saveBtn?.addEventListener('click', function(){
    if (!ctx) sizeCanvas();
    const dataUrl = canvas.toDataURL('image/png');
    out.value = dataUrl;
    preview.src = dataUrl;
    preview.style.display = 'inline-block';
    const m = bootstrap.Modal.getInstance(modalEl);
    m?.hide();
  });
})();
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
