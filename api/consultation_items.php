<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';

require_login();

function json_paths_all(?string $json): array {
    if (!$json) return [];
    $v = json_decode($json, true);
    if (is_array($v)) {
        $out = [];
        foreach ($v as $x) {
            if (is_string($x) && $x !== '') $out[] = $x;
        }
        return $out;
    }
    if (is_string($v) && $v !== '') return [$v];
    return [];
}
function json_paths_union(?string $json, array $newPaths): string {
    $existing = json_paths_all($json);
    foreach ($newPaths as $p) {
        if (is_string($p) && $p !== '' && !in_array($p, $existing, true)) $existing[] = $p;
    }
    return json_encode(array_values($existing), JSON_UNESCAPED_SLASHES);
}
function render_media_preview_api(string $path): string {
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
function render_analyse_card_api(array $an): string {
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
                  <?= render_media_preview_api($p) ?>
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
function render_imagerie_card_api(array $im): string {
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
                  <?= render_media_preview_api($p) ?>
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

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    json_response(['ok' => 0, 'message' => 'Méthode non autorisée.'], 405);
}

csrf_check();

$consultId = (int)($_POST['consultation_id'] ?? 0);
$type = (string)($_POST['type'] ?? '');

if ($consultId <= 0) {
    json_response(['ok' => 0, 'message' => 'Consultation invalide.'], 400);
}

$st = db()->prepare('SELECT id FROM consultations WHERE id = ? LIMIT 1');
$st->execute([$consultId]);
if (!$st->fetchColumn()) {
    json_response(['ok' => 0, 'message' => 'Consultation introuvable.'], 404);
}

if ($type === 'analyse') {
    $label = trim((string)($_POST['type_analyse'] ?? ''));
    $prix = (float)($_POST['prix'] ?? 0);
    if ($label === '') {
        json_response(['ok' => 0, 'message' => 'Type d\'analyse requis.'], 422);
    }

    db()->prepare('INSERT INTO analyses (consultation_id, type_analyse, prix, created_at, updated_at) VALUES (?, ?, ?, NOW(), NOW())')
        ->execute([$consultId, $label, $prix]);

    $id = (int)db()->lastInsertId();
    $row = db()->prepare('SELECT * FROM analyses WHERE id = ? AND consultation_id = ? LIMIT 1');
    $row->execute([$id, $consultId]);
    $item = $row->fetch(PDO::FETCH_ASSOC) ?: [
        'id' => $id,
        'type_analyse' => $label,
        'prix' => $prix,
        'resultat' => '',
        'fichier_resultat' => '[]',
    ];

    $ct = db()->prepare('SELECT COUNT(*) FROM analyses WHERE consultation_id = ?');
    $ct->execute([$consultId]);

    json_response([
        'ok' => 1,
        'kind' => 'analyse',
        'item' => $item,
        'count' => (int)$ct->fetchColumn(),
        'message' => 'Analyse ajoutée.',
    ]);
}

if ($type === 'imagerie') {
    $label = trim((string)($_POST['type_imagerie'] ?? ''));
    $prix = (float)($_POST['prix'] ?? 0);
    if ($label === '') {
        json_response(['ok' => 0, 'message' => 'Type d\'imagerie requis.'], 422);
    }

    db()->prepare('INSERT INTO imageries (consultation_id, type_imagerie, prix, created_at, updated_at) VALUES (?, ?, ?, NOW(), NOW())')
        ->execute([$consultId, $label, $prix]);

    $id = (int)db()->lastInsertId();
    $row = db()->prepare('SELECT * FROM imageries WHERE id = ? AND consultation_id = ? LIMIT 1');
    $row->execute([$id, $consultId]);
    $item = $row->fetch(PDO::FETCH_ASSOC) ?: [
        'id' => $id,
        'type_imagerie' => $label,
        'prix' => $prix,
        'compte_rendu' => '',
        'fichiers' => '[]',
    ];

    $ct = db()->prepare('SELECT COUNT(*) FROM imageries WHERE consultation_id = ?');
    $ct->execute([$consultId]);

    json_response([
        'ok' => 1,
        'kind' => 'imagerie',
        'item' => $item,
        'count' => (int)$ct->fetchColumn(),
        'message' => 'Imagerie ajoutée.',
    ]);
}

if ($type === 'imagerie_price') {
    $id = (int)($_POST['imagerie_id'] ?? 0);
    $prix = (float)($_POST['prix'] ?? 0);
    if ($id <= 0) {
        json_response(['ok' => 0, 'message' => 'Imagerie invalide.'], 422);
    }
    $st = db()->prepare('SELECT * FROM imageries WHERE id = ? AND consultation_id = ? LIMIT 1');
    $st->execute([$id, $consultId]);
    $item = $st->fetch(PDO::FETCH_ASSOC);
    if (!$item) {
        json_response(['ok' => 0, 'message' => 'Imagerie introuvable.'], 404);
    }
    db()->prepare('UPDATE imageries SET prix = ?, updated_at = NOW() WHERE id = ?')->execute([$prix, $id]);
    $st->execute([$id, $consultId]);
    $item = $st->fetch(PDO::FETCH_ASSOC) ?: $item;
    json_response([
        'ok' => 1,
        'kind' => 'imagerie',
        'item_id' => $id,
        'prix' => (float)($item['prix'] ?? 0),
        'html' => render_imagerie_card_api($item),
        'message' => 'Prix mis à jour.',
    ]);
}

if ($type === 'analyse_paths') {
    $id = (int)($_POST['analyse_id'] ?? 0);
    $paths = json_decode((string)($_POST['paths_json'] ?? '[]'), true);
    if ($id <= 0 || !is_array($paths) || !$paths) {
        json_response(['ok' => 0, 'message' => 'Pièces jointes invalides.'], 422);
    }
    $st = db()->prepare('SELECT * FROM analyses WHERE id = ? AND consultation_id = ? LIMIT 1');
    $st->execute([$id, $consultId]);
    $item = $st->fetch(PDO::FETCH_ASSOC);
    if (!$item) {
        json_response(['ok' => 0, 'message' => 'Analyse introuvable.'], 404);
    }
    $newJson = json_paths_union((string)($item['fichier_resultat'] ?? ''), $paths);
    db()->prepare('UPDATE analyses SET fichier_resultat = ?, updated_at = NOW() WHERE id = ?')->execute([$newJson, $id]);
    $st->execute([$id, $consultId]);
    $item = $st->fetch(PDO::FETCH_ASSOC) ?: $item;
    json_response([
        'ok' => 1,
        'kind' => 'analyse',
        'item_id' => $id,
        'html' => render_analyse_card_api($item),
        'files_count' => count(json_paths_all((string)($item['fichier_resultat'] ?? ''))),
        'message' => 'Fichiers analyse mis à jour.',
    ]);
}

if ($type === 'analyse_delete') {
    $id = (int)($_POST['analyse_id'] ?? 0);
    if ($id <= 0) {
        json_response(['ok' => 0, 'message' => 'Analyse invalide.'], 422);
    }
    $st = db()->prepare('SELECT fichier_resultat FROM analyses WHERE id = ? AND consultation_id = ? LIMIT 1');
    $st->execute([$id, $consultId]);
    $filesJson = $st->fetchColumn();
    if ($filesJson === false) {
        json_response(['ok' => 0, 'message' => 'Analyse introuvable.'], 404);
    }
    if (count(json_paths_all(is_string($filesJson) ? $filesJson : null)) > 0) {
        json_response(['ok' => 0, 'message' => 'Suppression autorisée seulement si aucun fichier n\'est attaché.'], 409);
    }
    db()->prepare('DELETE FROM analyses WHERE id = ? AND consultation_id = ?')->execute([$id, $consultId]);
    $ct = db()->prepare('SELECT COUNT(*) FROM analyses WHERE consultation_id = ?');
    $ct->execute([$consultId]);
    json_response([
        'ok' => 1,
        'kind' => 'analyse',
        'item_id' => $id,
        'count' => (int)$ct->fetchColumn(),
        'message' => 'Analyse supprimée.',
    ]);
}

if ($type === 'imagerie_paths') {
    $id = (int)($_POST['imagerie_id'] ?? 0);
    $paths = json_decode((string)($_POST['paths_json'] ?? '[]'), true);
    if ($id <= 0 || !is_array($paths) || !$paths) {
        json_response(['ok' => 0, 'message' => 'Pièces jointes invalides.'], 422);
    }
    $st = db()->prepare('SELECT * FROM imageries WHERE id = ? AND consultation_id = ? LIMIT 1');
    $st->execute([$id, $consultId]);
    $item = $st->fetch(PDO::FETCH_ASSOC);
    if (!$item) {
        json_response(['ok' => 0, 'message' => 'Imagerie introuvable.'], 404);
    }
    $newJson = json_paths_union((string)($item['fichiers'] ?? ''), $paths);
    db()->prepare('UPDATE imageries SET fichiers = ?, updated_at = NOW() WHERE id = ?')->execute([$newJson, $id]);
    $st->execute([$id, $consultId]);
    $item = $st->fetch(PDO::FETCH_ASSOC) ?: $item;
    json_response([
        'ok' => 1,
        'kind' => 'imagerie',
        'item_id' => $id,
        'html' => render_imagerie_card_api($item),
        'files_count' => count(json_paths_all((string)($item['fichiers'] ?? ''))),
        'message' => 'Fichiers imagerie mis à jour.',
    ]);
}

json_response(['ok' => 0, 'message' => 'Type non supporté.'], 400);
