<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';

require_login();

$client_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

$client = null;
if ($client_id > 0) {
    $st = db()->prepare("SELECT * FROM clients WHERE id = ? LIMIT 1");
    $st->execute([$client_id]);
    $client = $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

if (!$client): ?>
  <div class="alert alert-danger my-4">Client introuvable.</div>
  <a href="index.php?page=clients" class="btn btn-primary">Retour clients</a>
  <?php return; ?>
<?php endif;

$patients = [];
$ps = db()->prepare("SELECT * FROM patients WHERE client_id = ? ORDER BY nom ASC");
$ps->execute([$client_id]);
$patients = $ps->fetchAll(PDO::FETCH_ASSOC) ?: [];

function cv_row_latlng(array $row): array {
    $candidates = [
        ['latitude', 'longitude'],
        ['gps_lat', 'gps_lng'],
        ['lat', 'lng'],
        ['lat', 'lon'],
        ['coord_lat', 'coord_lng'],
    ];
    foreach ($candidates as [$la, $lo]) {
        if (!empty($row[$la]) && !empty($row[$lo])) {
            return [(float)$row[$la], (float)$row[$lo]];
        }
    }
    return [null, null];
}

function cv_age(?string $dob): string {
    if (!$dob || $dob === '0000-00-00') return '';
    $ts = strtotime($dob);
    if (!$ts) return '';
    $diff = (new DateTime())->diff(new DateTime(date('Y-m-d', $ts)));
    if ($diff->y > 0) return $diff->y . ' an' . ($diff->y > 1 ? 's' : '');
    if ($diff->m > 0) return $diff->m . ' mois';
    return $diff->d . ' j';
}

$clientName = trim(($client['nom'] ?? '') . ' ' . ($client['prenom'] ?? ''));
$gsm = $client['gsm'] ?? '';
$wa = preg_replace('/\D+/', '', $gsm);
[$lat, $lng] = cv_row_latlng($client);

$speciesIcons = [
    'chien' => '🐕', 'chat' => '🐈', 'lapin' => '🐇', 'oiseau' => '🐦',
    'reptile' => '🦎', 'hamster' => '🐹', 'cochon' => '🐖', 'cheval' => '🐴',
];
?>

<style>
  .cv-shell {
    --ink: #16181d;
    --muted: #6f7684;
    --line: #dde2ea;
    --surface: #ffffff;
    --accent: #1a73e8;
    --accent-2: #0a5fd6;
  }
  .cv-card {
    border: 1px solid var(--line);
    border-radius: 18px;
    background: var(--surface);
    box-shadow: 0 10px 30px rgba(16,24,40,.05);
  }
  .cv-info-row {
    display: flex;
    align-items: flex-start;
    gap: .65rem;
    padding: .55rem 0;
    border-bottom: 1px solid var(--line);
  }
  .cv-info-row:last-child { border-bottom: 0; }
  .cv-info-icon { color: var(--muted); flex-shrink: 0; width: 1.1rem; text-align: center; }
  .cv-info-label { color: var(--muted); font-size: .78rem; min-width: 70px; padding-top: .1rem; }
  .cv-info-val { font-size: .9rem; color: var(--ink); }
  .pet-card {
    border: 1px solid var(--line);
    border-radius: 14px;
    background: #fff;
    transition: transform .14s ease, box-shadow .14s ease;
    text-decoration: none;
    color: inherit;
    display: flex;
    flex-direction: column;
    overflow: hidden;
  }
  .pet-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 12px 30px rgba(16,24,40,.10);
    color: inherit;
  }
  .pet-photo-wrap {
    width: 100%;
    aspect-ratio: 1;
    background: #f0f4fa;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 2.8rem;
    overflow: hidden;
  }
  .pet-photo-wrap img { width: 100%; height: 100%; object-fit: cover; }
  .pet-badge {
    display: inline-block;
    font-size: .7rem;
    font-weight: 600;
    padding: .18rem .5rem;
    border-radius: 999px;
    text-transform: uppercase;
    letter-spacing: .04em;
  }
  .btn-macos {
    border-radius: 12px;
    border: 1px solid #d5dbe6;
    color: #182033;
    background: linear-gradient(180deg,#fff 0%,#f3f6fb 100%);
    box-shadow: 0 8px 18px rgba(16,24,40,.08);
  }
  .btn-macos-primary {
    border-radius: 12px;
    border: 1px solid var(--accent-2);
    color: #fff !important;
    background: linear-gradient(180deg,var(--accent) 0%,var(--accent-2) 100%);
    box-shadow: 0 8px 22px rgba(26,115,232,.30);
  }
  .sex-m  { background: #e3f0ff; color: #1a73e8; }
  .sex-f  { background: #fce4ec; color: #c2185b; }
  .sex-uk { background: #f0f0f0; color: #888; }
  @media (max-width:576px) {
    .cv-card { border-radius: 14px; }
    .pet-card { border-radius: 10px; }
  }
</style>

<div class="cv-shell">

  <nav aria-label="breadcrumb" class="mb-3">
    <ol class="breadcrumb mb-0" style="font-size:.85rem">
      <li class="breadcrumb-item"><a href="index.php?page=clients">Clients</a></li>
      <li class="breadcrumb-item active" aria-current="page"><?= h($clientName) ?></li>
    </ol>
  </nav>

  <!-- Info client -->
  <div class="cv-card p-4 mb-4">
    <div class="d-flex align-items-start justify-content-between gap-3 flex-wrap">
      <div>
        <h1 class="h4 mb-1"><?= h($clientName) ?></h1>
        <span class="text-muted small"><?= count($patients) ?> animal<?= count($patients) > 1 ? 'x' : '' ?></span>
      </div>
      <div class="d-flex flex-wrap gap-2">
        <?php if ($gsm): ?>
          <a class="btn btn-sm btn-macos" href="tel:<?= h($gsm) ?>"><i class="bi bi-telephone-fill me-1"></i><?= h($gsm) ?></a>
          <?php if ($wa): ?>
            <a class="btn btn-sm btn-success" target="_blank" href="https://wa.me/<?= urlencode($wa) ?>"><i class="bi bi-whatsapp"></i></a>
          <?php endif; ?>
        <?php endif; ?>
        <?php if (!empty($client['email'])): ?>
          <a class="btn btn-sm btn-macos" href="mailto:<?= h($client['email']) ?>"><i class="bi bi-envelope-fill me-1"></i><?= h($client['email']) ?></a>
        <?php endif; ?>
        <?php if ($lat !== null && $lng !== null): ?>
          <a class="btn btn-sm btn-outline-primary" target="_blank"
             href="https://www.google.com/maps/dir/?api=1&destination=<?= urlencode((string)$lat) ?>,<?= urlencode((string)$lng) ?>">
            <i class="bi bi-geo-alt-fill me-1"></i>Itinéraire
          </a>
        <?php endif; ?>
        <a class="btn btn-sm btn-macos" href="index.php?page=clients"><i class="bi bi-arrow-left me-1"></i>Retour</a>
      </div>
    </div>

    <?php if (!empty($client['adresse']) || $lat !== null): ?>
      <hr class="my-3">
      <?php if (!empty($client['adresse'])): ?>
        <div class="cv-info-row">
          <span class="cv-info-icon"><i class="bi bi-house-fill"></i></span>
          <span class="cv-info-label">Adresse</span>
          <span class="cv-info-val"><?= h($client['adresse']) ?></span>
        </div>
      <?php endif; ?>
      <?php if ($lat !== null && $lng !== null): ?>
        <div class="cv-info-row">
          <span class="cv-info-icon"><i class="bi bi-geo-alt-fill"></i></span>
          <span class="cv-info-label">GPS</span>
          <span class="cv-info-val text-muted small"><?= number_format($lat, 6) ?>, <?= number_format($lng, 6) ?></span>
        </div>
      <?php endif; ?>
    <?php endif; ?>
  </div>

  <!-- Animaux -->
  <div class="d-flex align-items-center justify-content-between mb-3">
    <h2 class="h5 mb-0">Animaux</h2>
    <a href="index.php?page=patients&new_for=<?= (int)$client_id ?>&new_name=<?= urlencode($clientName) ?>"
       class="btn btn-sm btn-macos-primary">
      <i class="bi bi-plus-lg me-1"></i>Ajouter
    </a>
  </div>

  <?php if (empty($patients)): ?>
    <div class="cv-card p-4 text-center text-muted">
      <div style="font-size:2.5rem" class="mb-2">🐾</div>
      Aucun animal enregistré pour ce client.
    </div>
  <?php else: ?>
    <div class="row g-3">
      <?php foreach ($patients as $p):
        $petName = h($p['nom'] ?? '—');
        $espece  = strtolower(trim($p['espece'] ?? ''));
        $icon    = $speciesIcons[$espece] ?? '🐾';
        $age     = cv_age($p['date_naissance'] ?? null);
        $sexe    = strtoupper($p['sexe'] ?? '');
        $sexClass = $sexe === 'M' ? 'sex-m' : ($sexe === 'F' ? 'sex-f' : 'sex-uk');
        $sexLabel = $sexe === 'M' ? 'Mâle' : ($sexe === 'F' ? 'Femelle' : '—');
      ?>
        <div class="col-6 col-md-4 col-lg-3">
          <a href="index.php?page=patient_view&id=<?= (int)$p['id'] ?>" class="pet-card h-100">
            <div class="pet-photo-wrap">
              <?php if (!empty($p['photo'])): ?>
                <img src="<?= h($p['photo']) ?>" alt="<?= $petName ?>">
              <?php else: ?>
                <span><?= $icon ?></span>
              <?php endif; ?>
            </div>
            <div class="p-2 p-md-3">
              <div class="fw-semibold mb-1" style="font-size:.9rem"><?= $petName ?></div>
              <div class="d-flex flex-wrap gap-1 mb-1">
                <?php if ($espece): ?>
                  <span class="pet-badge" style="background:#f0f4fa;color:#4f5a70"><?= h(ucfirst($espece)) ?></span>
                <?php endif; ?>
                <span class="pet-badge <?= $sexClass ?>"><?= $sexLabel ?></span>
              </div>
              <?php if (!empty($p['race'])): ?>
                <div class="text-muted" style="font-size:.75rem"><?= h($p['race']) ?></div>
              <?php endif; ?>
              <?php if ($age): ?>
                <div class="text-muted" style="font-size:.75rem"><?= $age ?></div>
              <?php endif; ?>
              <?php if (!empty($p['sterilise']) && (int)$p['sterilise']): ?>
                <div class="text-muted" style="font-size:.72rem; margin-top:.15rem">✂ Stérilisé·e</div>
              <?php endif; ?>
            </div>
          </a>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

</div>
