<?php
// features/rappel.php
// Création / édition / suppression d'un rappel pour un patient
// + Aide de saisie "Type" basée sur la fréquence en BDD
// + Listes des rappels (actifs à venir / expirés) avec code couleur, actions & placeholder mailsend()

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';

require_login();

/* ===================== Inputs ===================== */
$patient_id = isset($_GET['patient_id']) ? (int)$_GET['patient_id'] : 0;
$rappel_id  = isset($_GET['id']) ? (int)$_GET['id'] : 0;

/* ===================== Charger patient ===================== */
$st = db()->prepare("
    SELECT p.*, c.nom AS c_nom, c.prenom AS c_prenom
    FROM patients p
    JOIN clients c ON c.id = p.client_id
    WHERE p.id = ?
");
$st->execute([$patient_id]);
$patient = $st->fetch();

if (!$patient) {
    echo '<div class="alert alert-danger my-4">Patient introuvable.</div>';
    echo '<a class="btn btn-primary" href="index.php?page=patients">Retour</a>';
    return;
}

/* ===================== Charger rappel (édition) ===================== */
$rappel = null;
if ($rappel_id > 0) {
    $q = db()->prepare("SELECT * FROM rappels WHERE id=? AND patient_id=?");
    $q->execute([$rappel_id, $patient_id]);
    $rappel = $q->fetch();
    if (!$rappel) {
        echo '<div class="alert alert-danger my-4">Rappel introuvable.</div>';
        echo '<a class="btn btn-primary" href="index.php?page=patient_view&id='.(int)$patient_id.'">Retour à la fiche</a>';
        return;
    }
}

/* ===================== Helpers ===================== */
function dt_for_input(?string $dt): string {
    if (!$dt) return '';
    $ts = strtotime($dt);
    return $ts ? date('Y-m-d\TH:i', $ts) : '';
}
function color_for_days(int $days): string {
    // >15 => vert, 4..15 => orange, <=3 => rouge
    if ($days <= 3) return 'danger';
    if ($days <= 15) return 'warning';
    return 'success';
}
function human_delta(?string $dt): string {
    if (!$dt) return '—';
    $now = time();
    $ts  = strtotime($dt);
    $diff = (int)floor(($ts - $now)/86400);
    if ($diff > 1)  return "dans $diff j";
    if ($diff === 1) return "demain";
    if ($diff === 0) return "aujourd’hui";
    if ($diff === -1) return "hier";
    return ($diff < -1) ? abs($diff)." j écoulés" : "$diff j";
}

/* ===================== POST: create / update / delete ===================== */
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['act'] ?? 'save';

    // suppression
    if ($act === 'delete' && $rappel) {
        $del = db()->prepare("DELETE FROM rappels WHERE id=? AND patient_id=?");
        $del->execute([$rappel_id, $patient_id]);
        echo '<script>location.href="index.php?page=rappel&patient_id='.(int)$patient_id.'&ok=rappel_deleted";</script>';
        return;
    }

    // normalisation / validation
    $type        = trim($_POST['type'] ?? '');
    $date_rappel = trim($_POST['date_rappel'] ?? ''); // datetime-local
    $canal       = trim($_POST['canal'] ?? 'EMAIL');
    $statut      = trim($_POST['statut'] ?? 'A_ENVOYER');
    $note        = trim($_POST['note'] ?? '');

    if ($type === '') {
        $errors[] = "Le type de rappel est requis.";
    }

    // datetime-local -> Y-m-d H:i:s
    $date_sql = null;
    if ($date_rappel !== '') {
        $ts = strtotime($date_rappel);
        if ($ts) $date_sql = date('Y-m-d H:i:s', $ts);
        else $errors[] = "Date/heure de rappel invalide.";
    } else {
        $errors[] = "La date/heure de rappel est requise.";
    }

    // enums
    $allowed_canal  = ['SMS','EMAIL','APP'];
    $allowed_statut = ['A_ENVOYER','ENVOYE','ANNULE'];
    if (!in_array($canal, $allowed_canal, true))   $canal = 'EMAIL';
    if (!in_array($statut, $allowed_statut, true)) $statut = 'A_ENVOYER';

    // meta = uniquement note (JSON)
    $meta_sql = $note !== '' ? json_encode(['note' => $note], JSON_UNESCAPED_UNICODE) : null;

    if (!$errors) {
        if ($rappel) {
            // UPDATE
            $u = db()->prepare("
                UPDATE rappels
                   SET type=?, date_rappel=?, canal=?, statut=?, meta=?
                 WHERE id=? AND patient_id=?
            ");
            $u->execute([
                $type,
                $date_sql ?: $rappel['date_rappel'],
                $canal,
                $statut,
                $meta_sql,
                $rappel_id,
                $patient_id
            ]);
            echo '<script>location.href="index.php?page=rappel&patient_id='.(int)$patient_id.'&id='.(int)$rappel_id.'&ok=rappel_updated";</script>';
            return;
        } else {
            // INSERT
            $ins = db()->prepare("
                INSERT INTO rappels (patient_id, type, date_rappel, canal, statut, meta)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $ins->execute([
                $patient_id,
                $type,
                $date_sql ?: date('Y-m-d H:i:s'),
                $canal,
                $statut,
                $meta_sql
            ]);
            echo '<script>location.href="index.php?page=patient_view&id='.(int)$patient_id.'&ok=rappel_created";</script>';
            return;
        }
    }
}

/* ===================== Pré-remplissage formulaire ===================== */
// extraire note depuis meta JSON si édition
$prefill_note = '';
if ($rappel && !empty($rappel['meta'])) {
    $meta = json_decode($rappel['meta'], true);
    if (is_array($meta) && isset($meta['note'])) $prefill_note = (string)$meta['note'];
}

$val = [
    'type'        => $rappel['type']        ?? '',
    'date_rappel' => dt_for_input($rappel['date_rappel'] ?? ''),
    'canal'       => $rappel['canal']       ?? 'EMAIL',
    'statut'      => $rappel['statut']      ?? 'A_ENVOYER',
    'note'        => $prefill_note,
];

// valeur par défaut de date si création
if (!$rappel && $val['date_rappel'] === '') {
    $ts = strtotime('+7 days 09:00');
    $val['date_rappel'] = date('Y-m-d\TH:i', $ts);
}

/* ===================== Type suggéré par fréquence ===================== */
$types = [];
$qt = db()->query("SELECT type, COUNT(*) AS n FROM rappels WHERE type IS NOT NULL AND type<>'' GROUP BY type ORDER BY n DESC, type ASC LIMIT 12");
$types = $qt ? $qt->fetchAll() : [];

/* ===================== Rappels existants (listes) ===================== */
$now = date('Y-m-d H:i:s');
// Actifs à venir (non ANNULE, date future)
$qa = db()->prepare("
    SELECT * FROM rappels
    WHERE patient_id=? AND statut <> 'ANNULE' AND date_rappel >= ?
    ORDER BY date_rappel ASC
");
$qa->execute([$patient_id, $now]);
$rappels_actifs = $qa->fetchAll();

// Expirés (non ANNULE, date passée)
$qx = db()->prepare("
    SELECT * FROM rappels
    WHERE patient_id=? AND statut <> 'ANNULE' AND date_rappel < ?
    ORDER BY date_rappel DESC
");
$qx->execute([$patient_id, $now]);
$rappels_expires = $qx->fetchAll();

?>
<?php if ($errors): ?>
  <div class="alert alert-danger">
    <b>Veuillez corriger :</b>
    <ul class="mb-0">
      <?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?>
    </ul>
  </div>
<?php endif; ?>

<div class="card shadow-sm">
  <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div class="d-flex align-items-center gap-2">
      <a class="btn btn-sm btn-outline-secondary" href="index.php?page=patient_view&id=<?= (int)$patient_id ?>" title="Retour à la fiche">
        &larr;
      </a>
      <strong><?= $rappel ? 'Modifier un rappel' : 'Nouveau rappel' ?></strong>
    </div>
    <div class="small text-muted">
      Patient : <b><?= h($patient['nom']) ?></b> (Propriétaire : <?= h(trim(($patient['c_nom'] ?? '').' '.($patient['c_prenom'] ?? ''))) ?>)
    </div>
  </div>

  <div class="card-body">
    <form method="post" class="row g-3">
      <?= csrf_input() ?>
      <input type="hidden" name="act" value="save">

      <!-- TYPE avec suggestions -->
      <div class="col-12">
        <label class="form-label">Type de rappel <span class="text-danger">*</span></label>
        <div class="d-flex flex-wrap gap-2 mb-2">
          <?php foreach ($types as $t):
                $label = trim($t['type'] ?? '');
                if ($label === '') continue;
          ?>
            <span class="badge bg-light text-dark border selectable-type" role="button" data-type="<?= h($label) ?>">
              <?= h($label) ?> <span class="text-muted">(<?= (int)$t['n'] ?>)</span>
            </span>
          <?php endforeach; ?>
        </div>
        <input list="types-datalist" type="text" name="type" id="typeInput" class="form-control" value="<?= h($val['type']) ?>" placeholder="Vaccin, Vermifuge, Contrôle..." required>
        <datalist id="types-datalist">
          <?php foreach ($types as $t):
                $label = trim($t['type'] ?? '');
                if ($label === '') continue;
          ?>
            <option value="<?= h($label) ?>"></option>
          <?php endforeach; ?>
        </datalist>
        <div class="form-text">Choisissez parmi les suggestions ou saisissez un nouveau type.</div>
      </div>

      <div class="col-md-6">
        <label class="form-label">Date & heure <span class="text-danger">*</span></label>
        <input type="datetime-local" name="date_rappel" class="form-control" value="<?= h($val['date_rappel']) ?>" required>
      </div>

      <div class="col-md-3 col-6">
        <label class="form-label">Canal</label>
        <select name="canal" class="form-select">
          <?php
            $canaux = ['EMAIL'=>'Email','SMS'=>'SMS','APP'=>'App'];
            foreach ($canaux as $k=>$lib):
          ?>
            <option value="<?= h($k) ?>" <?= $val['canal']===$k?'selected':'' ?>><?= h($lib) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="col-md-3 col-6">
        <label class="form-label">Statut</label>
        <select name="statut" class="form-select">
          <?php
            $stats = ['A_ENVOYER'=>'À envoyer','ENVOYE'=>'Envoyé','ANNULE'=>'Annulé'];
            foreach ($stats as $k=>$lib):
          ?>
            <option value="<?= h($k) ?>" <?= $val['statut']===$k?'selected':'' ?>><?= h($lib) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="col-12">
        <label class="form-label">Note</label>
        <textarea name="note" class="form-control" rows="3" placeholder="Ex: Rappel vaccin CHPPi, mentionner le lot ou préférences."><?= h($val['note']) ?></textarea>
        <div class="form-text">Enregistré dans <code>meta</code> sous forme JSON { "note": "..." }.</div>
      </div>

      <div class="col-12 d-flex gap-2 mt-2">
        <button class="btn btn-primary">Enregistrer</button>
        <a class="btn btn-outline-secondary" href="index.php?page=patient_view&id=<?= (int)$patient_id ?>">Annuler</a>

        <?php if ($rappel): ?>
          <button type="submit" name="act" value="delete" class="btn btn-outline-danger ms-auto"
                  onclick="return confirm('Supprimer définitivement ce rappel ?');">
            Supprimer
          </button>
        <?php endif; ?>
      </div>
    </form>
  </div>
</div>

<!-- LISTES -->
<div class="mt-4">
  <!-- Actifs à venir -->
  <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-2">
    <h6 class="mb-0">Rappels à venir</h6>
    <span class="badge bg-light text-dark"><?= count($rappels_actifs) ?> à venir</span>
  </div>
  <?php if (!$rappels_actifs): ?>
    <div class="alert alert-light border">Aucun rappel à venir.</div>
  <?php else: ?>
    <div class="row g-2">
    <?php foreach ($rappels_actifs as $rp):
          $meta = is_string($rp['meta']) ? json_decode($rp['meta'], true) : $rp['meta'];
          $note = is_array($meta) && isset($meta['note']) ? (string)$meta['note'] : '';
          $days = (int)floor((strtotime($rp['date_rappel']) - time())/86400);
          $color = color_for_days($days);
          $delta = human_delta($rp['date_rappel']);
    ?>
      <div class="col-12">
        <div class="card border-<?= $color ?> shadow-sm">
          <div class="card-body py-2">
            <div class="d-flex flex-wrap align-items-center gap-2">
              <span class="badge bg-<?= $color ?>"><?= h($rp['type'] ?: 'Rappel') ?></span>
              <span class="small text-muted"><?= h(date('d/m/Y H:i', strtotime($rp['date_rappel']))) ?> · <?= h($delta) ?></span>
              <?php if ($rp['statut'] === 'ENVOYE'): ?>
                <span class="badge bg-secondary">Déjà envoyé</span>
              <?php elseif ($rp['statut'] === 'A_ENVOYER'): ?>
                <span class="badge bg-info text-dark">À envoyer</span>
              <?php endif; ?>
              <span class="ms-auto"></span>
              <div class="btn-group btn-group-sm">
                <a class="btn btn-outline-primary" href="index.php?page=rappel&patient_id=<?= (int)$patient_id ?>&id=<?= (int)$rp['id'] ?>">Modifier</a>
                <form method="post" onsubmit="return confirm('Supprimer ce rappel ?');">
                  <?= csrf_input() ?>
                  <input type="hidden" name="act" value="delete">
                  <input type="hidden" name="id" value="<?= (int)$rp['id'] ?>">
                  <!-- conserver routing vers cette page -->
                  <input type="hidden" name="type" value="<?= h($rp['type']) ?>">
                  <input type="hidden" name="date_rappel" value="<?= h(dt_for_input($rp['date_rappel'])) ?>">
                  <input type="hidden" name="canal" value="<?= h($rp['canal']) ?>">
                  <input type="hidden" name="statut" value="<?= h($rp['statut']) ?>">
                  <button class="btn btn-outline-danger">Supprimer</button>
                </form>
                <button class="btn btn-success" onclick="mailsend(<?= (int)$rp['id'] ?>)">Envoyer email</button>
              </div>
            </div>
            <?php if ($note !== ''): ?>
              <div class="small text-muted mt-1"><?= nl2br(h($note)) ?></div>
            <?php endif; ?>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<div class="mt-4">
  <!-- Expirés -->
  <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-2">
    <h6 class="mb-0">Rappels expirés</h6>
    <span class="badge bg-light text-dark"><?= count($rappels_expires) ?></span>
  </div>
  <?php if (!$rappels_expires): ?>
    <div class="alert alert-light border">Aucun rappel expiré.</div>
  <?php else: ?>
    <div class="row g-2">
    <?php foreach ($rappels_expires as $rp):
          $meta = is_string($rp['meta']) ? json_decode($rp['meta'], true) : $rp['meta'];
          $note = is_array($meta) && isset($meta['note']) ? (string)$meta['note'] : '';
          $days = (int)ceil((time() - strtotime($rp['date_rappel']))/86400);
    ?>
      <div class="col-12">
        <div class="card border-secondary shadow-sm">
          <div class="card-body py-2">
            <div class="d-flex flex-wrap align-items-center gap-2">
              <span class="badge bg-dark"><?= h($rp['type'] ?: 'Rappel') ?></span>
              <span class="small text-muted">Échéance: <?= h(date('d/m/Y H:i', strtotime($rp['date_rappel']))) ?> (<?= $days > 0 ? h("$days j") : 'aujourd’hui' ?>)</span>
              <?php if ($rp['statut'] === 'ENVOYE'): ?>
                <span class="badge bg-secondary">Déjà envoyé</span>
              <?php elseif ($rp['statut'] === 'A_ENVOYER'): ?>
                <span class="badge bg-info text-dark">À envoyer</span>
              <?php endif; ?>
              <span class="ms-auto"></span>
              <div class="btn-group btn-group-sm">
                <a class="btn btn-outline-primary" href="index.php?page=rappel&patient_id=<?= (int)$patient_id ?>&id=<?= (int)$rp['id'] ?>">Modifier</a>
                <form method="post" onsubmit="return confirm('Supprimer ce rappel ?');">
                  <?= csrf_input() ?>
                  <input type="hidden" name="act" value="delete">
                  <input type="hidden" name="id" value="<?= (int)$rp['id'] ?>">
                  <button class="btn btn-outline-danger">Supprimer</button>
                </form>
              </div>
            </div>
            <?php if ($note !== ''): ?>
              <div class="small text-muted mt-1"><?= nl2br(h($note)) ?></div>
            <?php endif; ?>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<!-- Styles "human friendly" & mobile -->
<style>
  .selectable-type { user-select: none; }
  .selectable-type:hover { filter: brightness(0.95); }
  @media (max-width: 576px){
    .card-header strong { font-size: 1rem; }
    .btn-group { width: 100%; }
    .btn-group .btn { flex: 1 1 auto; }
  }
</style>

<script>
// Raccourci: cliquer sur un badge remplit le champ Type
document.addEventListener('click', (e) => {
  const el = e.target.closest('.selectable-type');
  if (!el) return;
  const t = el.getAttribute('data-type');
  const input = document.getElementById('typeInput');
  if (input && t) { input.value = t; input.focus(); }
});

// Placeholder: envoi email (à intégrer plus tard)
function mailsend(rappelId){
  // TODO: brancher sur votre système d'emailing
  alert('Envoi email pour le rappel #'+rappelId+' (placeholder).');
}
</script>
