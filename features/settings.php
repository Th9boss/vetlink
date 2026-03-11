<?php
// features/settings.php
// Paramètres de la structure (ADMIN uniquement)
// Haut: aperçu stylé des infos | Bas: formulaire d’édition

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';

require_login();
require_role('ADMIN');
csrf_check();

/* ========= Charger (ou init) la config singleton id=1 ========= */
$cfg = db()->query("SELECT * FROM config WHERE id=1")->fetch(PDO::FETCH_ASSOC);
if (!$cfg) {
  db()->exec("INSERT INTO config (id, nom) VALUES (1,'Ma Clinique Vétérinaire')");
  $cfg = db()->query("SELECT * FROM config WHERE id=1")->fetch(PDO::FETCH_ASSOC);
}

/* ========= Upload helper ========= */
function save_logo_upload(array $f): ?string {
  if (($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return null;
  if (($f['error'] ?? 0) !== UPLOAD_ERR_OK) return null;

  $tmp = $f['tmp_name'] ?? '';
  if (!$tmp || !is_uploaded_file($tmp)) return null;

  $info = @getimagesize($tmp);
  if (!$info) return null;
  $ext = ($info['mime'] ?? '') === 'image/png' ? 'png' : 'jpg';

  $dir = "uploads/config";
  if (!is_dir($dir)) @mkdir($dir, 0775, true);

  // Nettoyer anciens formats
  foreach (['png','jpg','jpeg'] as $e) {
    $old = "$dir/logo.$e";
    if (is_file($old)) @unlink($old);
  }
  $path = "$dir/logo.$ext";
  if (!move_uploaded_file($tmp, $path)) return null;
  return $path; // chemin relatif
}

/* ========= Traitement POST ========= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $nom   = trim($_POST['nom'] ?? '');
  $ice   = trim($_POST['ice'] ?? '');
  $tel   = trim($_POST['tel'] ?? '');
  $email = trim($_POST['email'] ?? '');
  $adr   = trim($_POST['adresse'] ?? '');
  $ville = trim($_POST['ville'] ?? '');
  $site  = trim($_POST['site'] ?? '');
  $wa    = trim($_POST['whatsapp'] ?? '');

  $logo = save_logo_upload($_FILES['logo_img'] ?? []);
  $sql  = "UPDATE config SET nom=:nom, ice=:ice, tel=:tel, email=:email, adresse=:adr, ville=:ville, site=:site, whatsapp=:wa";
  $params = [':nom'=>$nom, ':ice'=>$ice, ':tel'=>$tel, ':email'=>$email, ':adr'=>$adr, ':ville'=>$ville, ':site'=>$site, ':wa'=>$wa];
  if ($logo) { $sql .= ", logo_img=:logo_img"; $params[':logo_img'] = $logo; }
  $sql .= " WHERE id=1";
  $st = db()->prepare($sql);
  $st->execute($params);

  redirect("index.php?page=settings&ok=1");
  exit;
}

/* ========= Recharger après éventuelle maj ========= */
$cfg = db()->query("SELECT * FROM config WHERE id=1")->fetch(PDO::FETCH_ASSOC);

/* ========= Vue ========= */
?>
<div class="container py-3">
  <div class="d-flex align-items-center gap-3 mb-3">
    <h1 class="h4 mb-0">Paramètres de la structure</h1>
    <?php if (!empty($_GET['ok'])): ?>
      <span class="badge bg-success">Enregistré</span>
    <?php endif; ?>
  </div>

  <!-- Aperçu stylé -->
  <div class="card shadow-sm border-0 mb-4">
    <div class="card-body">
      <div class="d-flex flex-wrap align-items-center gap-3">
        <div class="p-2 rounded border bg-light">
          <?php if (!empty($cfg['logo_img'])): ?>
            <img src="<?= h($cfg['logo_img']) ?>" alt="Logo" style="height:72px;width:auto;">
          <?php else: ?>
            <div class="d-flex align-items-center justify-content-center"
                 style="height:72px; width:72px; background:#f1f3f5; border-radius:12px;">
              <span class="fw-bold text-muted">Logo</span>
            </div>
          <?php endif; ?>
        </div>

        <div class="flex-grow-1">
          <div class="d-flex align-items-center gap-2">
            <h2 class="h5 mb-0"><?= h($cfg['nom'] ?? 'Ma Clinique Vétérinaire') ?></h2>
            <?php if (!empty($cfg['ville'])): ?>
              <span class="badge bg-primary-subtle text-primary border"><?= h($cfg['ville']) ?></span>
            <?php endif; ?>
          </div>
          <div class="text-muted small mt-1">
            <?php if (!empty($cfg['adresse'])): ?>
              <i class="bi bi-geo-alt me-1"></i><?= h($cfg['adresse']) ?><?= !empty($cfg['ville'])? ', '.h($cfg['ville']):'' ?>
              &nbsp;&middot;&nbsp;
            <?php endif; ?>
            <?php if (!empty($cfg['tel'])): ?>
              <i class="bi bi-telephone me-1"></i><a href="tel:<?= h($cfg['tel']) ?>"><?= h($cfg['tel']) ?></a>
              &nbsp;&middot;&nbsp;
            <?php endif; ?>
            <?php if (!empty($cfg['email'])): ?>
              <i class="bi bi-envelope me-1"></i><a href="mailto:<?= h($cfg['email']) ?>"><?= h($cfg['email']) ?></a>
              &nbsp;&middot;&nbsp;
            <?php endif; ?>
            <?php if (!empty($cfg['site'])): ?>
              <i class="bi bi-globe me-1"></i><a href="<?= h($cfg['site']) ?>" target="_blank" rel="noopener">Site</a>
              &nbsp;&middot;&nbsp;
            <?php endif; ?>
            <?php if (!empty($cfg['whatsapp'])): ?>
              <i class="bi bi-whatsapp me-1"></i><a target="_blank" href="https://wa.me/<?= h(preg_replace('/\D+/','',$cfg['whatsapp'])) ?>">WhatsApp</a>
            <?php endif; ?>
          </div>

          <div class="mt-2 small">
            <?php if (!empty($cfg['ice'])): ?>
              <span class="badge bg-dark-subtle text-dark border"><i class="bi bi-card-text me-1"></i>ICE: <?= h($cfg['ice']) ?></span>
            <?php endif; ?>
            <span class="badge bg-light text-muted border ms-1"><i class="bi bi-clock me-1"></i>Maj: <?= h($cfg['updated_at'] ?? '') ?></span>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- Formulaire d’édition -->
  <div class="card shadow-sm">
    <div class="card-header bg-light">
      <div class="d-flex align-items-center gap-2">
        <i class="bi bi-gear"></i><strong>Modifier les informations</strong>
      </div>
    </div>
    <div class="card-body">
      <form method="post" enctype="multipart/form-data" class="row g-3">
        <?= csrf_input() ?>

        <div class="col-md-6">
          <label class="form-label">Nom de la clinique *</label>
          <input name="nom" class="form-control" required value="<?= h($cfg['nom'] ?? '') ?>">
        </div>

        <div class="col-md-3">
          <label class="form-label">ICE</label>
          <input name="ice" class="form-control" value="<?= h($cfg['ice'] ?? '') ?>">
        </div>

        <div class="col-md-3">
          <label class="form-label">Logo (PNG/JPG)</label>
          <input type="file" name="logo_img" accept="image/png,image/jpeg" class="form-control">
          <?php if (!empty($cfg['logo_img'])): ?>
            <div class="mt-2">
              <img src="<?= h($cfg['logo_img']) ?>" alt="Logo" style="max-height:64px">
            </div>
          <?php endif; ?>
        </div>

        <div class="col-md-4">
          <label class="form-label">Téléphone</label>
          <input name="tel" class="form-control" value="<?= h($cfg['tel'] ?? '') ?>">
        </div>

        <div class="col-md-4">
          <label class="form-label">Email</label>
          <input type="email" name="email" class="form-control" value="<?= h($cfg['email'] ?? '') ?>">
        </div>

        <div class="col-md-4">
          <label class="form-label">WhatsApp</label>
          <input name="whatsapp" class="form-control" value="<?= h($cfg['whatsapp'] ?? '') ?>">
        </div>

        <div class="col-md-8">
          <label class="form-label">Adresse</label>
          <input name="adresse" class="form-control" value="<?= h($cfg['adresse'] ?? '') ?>">
        </div>

        <div class="col-md-4">
          <label class="form-label">Ville</label>
          <input name="ville" class="form-control" value="<?= h($cfg['ville'] ?? '') ?>">
        </div>

        <div class="col-md-8">
          <label class="form-label">Site web</label>
          <input name="site" class="form-control" value="<?= h($cfg['site'] ?? '') ?>">
        </div>

        <div class="col-12 d-flex justify-content-end gap-2">
          <a class="btn btn-outline-secondary" href="index.php?page=settings">Annuler</a>
          <button class="btn btn-primary">Enregistrer</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Un petit style pour soigner l’aperçu -->
<style>
  .bg-primary-subtle { background: #e7f1ff !important; }
  .bg-dark-subtle    { background: #eef0f2 !important; }
</style>
