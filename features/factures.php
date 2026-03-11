<?php
// features/factures.php — mobile-friendly, recherche intégrée au select, auto-chargement
// Sélection client (filtre texte+GPS) -> actes non facturés -> création facture
// Liste des factures du client sélectionné uniquement, avec paiement inline + total impayé

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';

require_login();
csrf_check();

/* ================= Helpers ================= */

function facture_recalc_totaux(int $facture_id): void {
    $stmt = db()->prepare("SELECT qty, prix_unitaire, tva FROM facture_lignes WHERE facture_id=?");
    $stmt->execute([$facture_id]);
    $rows = $stmt->fetchAll();

    $total_ht=0; $total_tva=0; $total_ttc=0;
    foreach ($rows as $r) {
        $ht  = (float)$r['qty'] * (float)$r['prix_unitaire'];
        $tva = $ht * ((float)$r['tva'] / 100.0);
        $ttc = $ht + $tva;
        $total_ht  += $ht;
        $total_tva += $tva;
        $total_ttc += $ttc;
    }
    db()->prepare("UPDATE factures SET total_ht=?, tva=?, total_ttc=? WHERE id=?")
       ->execute([round($total_ht,2), round($total_tva,2), round($total_ttc,2), $facture_id]);

    // Ajuster statut selon paiement si la colonne existe
    try {
        $r = db()->prepare("SELECT statut, total_ttc, COALESCE(payement,0) AS payement FROM factures WHERE id=?");
        $r->execute([$facture_id]);
        if ($f = $r->fetch()) {
            $pay = (float)$f['payement']; $ttc = (float)$f['total_ttc']; $new = $f['statut'];
            if ($ttc > 0) {
                if ($pay <= 0) $new = 'IMPAYEE';
                elseif ($pay < $ttc) $new = 'PAYEE_PARTIELLE';
                else $new = 'PAYEE';
            }
            db()->prepare("UPDATE factures SET statut=? WHERE id=?")->execute([$new, $facture_id]);
        }
    } catch (\Throwable $e) { /* colonne payement absente : ignorer */ }
}

function mark_origin_invoiced(string $type, int $id, int $value): void {
    switch ($type) {
        case 'CONSULTATION': $sql="UPDATE consultations SET invoiced=? WHERE id=?"; break;
        case 'ANALYSE':      $sql="UPDATE analyses SET invoiced=? WHERE id=?"; break;
        case 'IMAGERIE':     $sql="UPDATE imageries SET invoiced=? WHERE id=?"; break;
        case 'INTERVENTION': $sql="UPDATE interventions SET invoiced=? WHERE id=?"; break;
        default: return;
    }
    db()->prepare($sql)->execute([$value, $id]);
}

function libelle_for_origin(array $o): string {
    switch ($o['type']) {
        case 'CONSULTATION':
            return "Cas clinique: " . trim($o['motif'] ?? '') . " / " . ($o['animal'] ?? '') . " / " . date('Y-m-d H:i', strtotime($o['date']));
        case 'ANALYSE':
            return ($o['type_analyse'] ?? 'Analyse') . " / " . date('Y-m-d H:i', strtotime($o['date']));
        case 'IMAGERIE':
            return ($o['type_imagerie'] ?? 'Imagerie') . " / " . date('Y-m-d H:i', strtotime($o['date']));
        case 'INTERVENTION':
            return ($o['type_acte'] ?? 'Intervention') . (empty($o['details']) ? '' : " — " . $o['details']);
        default:
            return 'Ligne';
    }
}

/* ================= Actions (POST) ================= */

if ($_SERVER['REQUEST_METHOD']==='POST') {
    $act = $_POST['act'] ?? '';

    // Création facture à partir d'actes cochés
    if ($act === 'create_from_acts') {
        $client_id = (int)($_POST['client_id'] ?? 0);
        $items = $_POST['items'] ?? []; // ex: ["CONSULTATION:12","ANALYSE:7",...]

        db()->prepare("INSERT INTO factures (client_id, statut, date_facture, total_ht, tva, total_ttc, created_at)
                       VALUES (?, 'EMISE', NOW(), 0, 0, 0, NOW())")->execute([$client_id]);
        $fid = (int)db()->lastInsertId();

        foreach ($items as $it) {
            if (!preg_match('~^(CONSULTATION|ANALYSE|IMAGERIE|INTERVENTION):(\d+)$~', $it, $m)) continue;
            $type = $m[1]; $id = (int)$m[2];

            if ($type==='CONSULTATION') {
                $q = db()->prepare("SELECT c.id, c.motif, c.montant_ligne, c.date_consult AS d, p.nom AS animal
                                    FROM consultations c JOIN patients p ON p.id=c.patient_id WHERE c.id=?");
                $q->execute([$id]); if ($r=$q->fetch()) {
                    $lib = libelle_for_origin(['type'=>'CONSULTATION','motif'=>$r['motif'],'animal'=>$r['animal'],'date'=>$r['d']]);
                    $pu  = (float)$r['montant_ligne'];
                    db()->prepare("INSERT INTO facture_lignes (facture_id, origine_type, origine_id, libelle, qty, prix_unitaire, tva, total_ligne)
                                   VALUES (?,?,?,?,?,?,?,0)")
                      ->execute([$fid,'CONSULTATION',$id,$lib,1,$pu,0]);
                    mark_origin_invoiced('CONSULTATION',$id,1);
                }
            } elseif ($type==='ANALYSE') {
                $q = db()->prepare("SELECT a.id, a.type_analyse, a.prix, co.date_consult AS d
                                    FROM analyses a JOIN consultations co ON co.id=a.consultation_id WHERE a.id=?");
                $q->execute([$id]); if ($r=$q->fetch()) {
                    $lib = libelle_for_origin(['type'=>'ANALYSE','type_analyse'=>$r['type_analyse'],'date'=>$r['d']]);
                    $pu  = (float)$r['prix'];
                    db()->prepare("INSERT INTO facture_lignes (facture_id, origine_type, origine_id, libelle, qty, prix_unitaire, tva, total_ligne)
                                   VALUES (?,?,?,?,?,?,?,0)")
                      ->execute([$fid,'PRODUIT',$id,$lib,1,$pu,0]);
                    mark_origin_invoiced('ANALYSE',$id,1);
                }
            } elseif ($type==='IMAGERIE') {
                $q = db()->prepare("SELECT i.id, i.type_imagerie, i.prix, co.date_consult AS d
                                    FROM imageries i JOIN consultations co ON co.id=i.consultation_id WHERE i.id=?");
                $q->execute([$id]); if ($r=$q->fetch()) {
                    $lib = libelle_for_origin(['type'=>'IMAGERIE','type_imagerie'=>$r['type_imagerie'],'date'=>$r['d']]);
                    $pu  = (float)$r['prix'];
                    db()->prepare("INSERT INTO facture_lignes (facture_id, origine_type, origine_id, libelle, qty, prix_unitaire, tva, total_ligne)
                                   VALUES (?,?,?,?,?,?,?,0)")
                      ->execute([$fid,'PRODUIT',$id,$lib,1,$pu,0]);
                    mark_origin_invoiced('IMAGERIE',$id,1);
                }
            } elseif ($type==='INTERVENTION') {
                $q = db()->prepare("SELECT it.id, it.type_acte, it.details, it.prix, it.date_intervention AS d
                                    FROM interventions it WHERE it.id=?");
                $q->execute([$id]); if ($r=$q->fetch()) {
                    $lib = libelle_for_origin(['type'=>'INTERVENTION','type_acte'=>$r['type_acte'],'details'=>$r['details'],'date'=>$r['d']]);
                    $pu  = (float)$r['prix'];
                    db()->prepare("INSERT INTO facture_lignes (facture_id, origine_type, origine_id, libelle, qty, prix_unitaire, tva, total_ligne)
                                   VALUES (?,?,?,?,?,?,?,0)")
                      ->execute([$fid,'INTERVENTION',$id,$lib,1,$pu,0]);
                    mark_origin_invoiced('INTERVENTION',$id,1);
                }
            }
        }

        facture_recalc_totaux($fid);
        redirect('index.php?page=factures&edit='.$fid.'&ok=facture_created');
    }

    // Ajout ligne libre (TTC stocké en HT pour simplicité, TVA=0)
    if ($act === 'add_ligne_libre') {
        $fid = (int)($_POST['facture_id'] ?? 0);
        $lib = trim($_POST['libelle'] ?? 'Ligne libre');
        $ttc = (float)($_POST['prix_ttc'] ?? 0);
        $qty = max(1.0, (float)($_POST['qty'] ?? 1));
        db()->prepare("INSERT INTO facture_lignes (facture_id, origine_type, origine_id, libelle, qty, prix_unitaire, tva, total_ligne)
                       VALUES (?,?,?,?,?,?,?,0)")
          ->execute([$fid,'PRODUIT',null,$lib,$qty,$ttc,0]);
        facture_recalc_totaux($fid);
        redirect('index.php?page=factures&edit='.$fid.'&ok=ligne_libre_added');
    }

    // Supprimer ligne (et remettre invoiced=0 si elle venait d’un acte)
    if ($act === 'delete_ligne') {
        $fid = (int)($_POST['facture_id'] ?? 0);
        $lid = (int)($_POST['ligne_id'] ?? 0);
        $s = db()->prepare("SELECT origine_type, origine_id FROM facture_lignes WHERE id=? AND facture_id=?");
        $s->execute([$lid,$fid]);
        if ($ln=$s->fetch()) {
            db()->prepare("DELETE FROM facture_lignes WHERE id=? AND facture_id=?")->execute([$lid,$fid]);
            if ($ln['origine_id']) {
                if (in_array($ln['origine_type'],['CONSULTATION','INTERVENTION'])) {
                    mark_origin_invoiced($ln['origine_type'], (int)$ln['origine_id'], 0);
                } else { // PRODUIT => ANALYSE/IMAGERIE
                    mark_origin_invoiced('ANALYSE', (int)$ln['origine_id'], 0);
                    mark_origin_invoiced('IMAGERIE', (int)$ln['origine_id'], 0);
                }
            }
        }
        facture_recalc_totaux($fid);
        redirect('index.php?page=factures&edit='.$fid.'&ok=ligne_deleted');
    }

    // Changer statut facture
    if ($act === 'change_statut') {
        $fid = (int)($_POST['facture_id'] ?? 0);
        $statut = $_POST['statut'] ?? 'EMISE';
        db()->prepare("UPDATE factures SET statut=? WHERE id=?")->execute([$statut,$fid]);
        redirect('index.php?page=factures&edit='.$fid.'&ok=statut_changed');
    }

    // Saisie paiement (depuis édition ou liste)
    if ($act === 'save_payment' || $act === 'save_payment_quick') {
        $fid = (int)($_POST['facture_id'] ?? 0);
        $amount = (float)($_POST['payement'] ?? 0);
        try {
            db()->prepare("UPDATE factures SET payement=? WHERE id=?")->execute([round($amount,2), $fid]);
        } catch (\Throwable $e) {
            $back = ($act==='save_payment_quick') ? 'index.php?page=factures' : 'index.php?page=factures&edit='.$fid;
            redirect($back.'&err=missing_payement_column');
        }
        facture_recalc_totaux($fid);
        $back = ($act==='save_payment_quick') ? 'index.php?page=factures&ok=payment_saved' : 'index.php?page=factures&edit='.$fid.'&ok=payment_saved';
        redirect($back);
    }

    // Supprimer facture (remettre invoiced=0 avant)
    if ($act === 'delete_facture') {
        $fid = (int)($_POST['facture_id'] ?? 0);
        $lns = db()->prepare("SELECT origine_type, origine_id FROM facture_lignes WHERE facture_id=?");
        $lns->execute([$fid]);
        foreach ($lns->fetchAll() as $ln) {
            if ($ln['origine_id']) {
                if (in_array($ln['origine_type'],['CONSULTATION','INTERVENTION'])) {
                    mark_origin_invoiced($ln['origine_type'], (int)$ln['origine_id'], 0);
                } else {
                    mark_origin_invoiced('ANALYSE', (int)$ln['origine_id'], 0);
                    mark_origin_invoiced('IMAGERIE', (int)$ln['origine_id'], 0);
                }
            }
        }
        db()->prepare("DELETE FROM factures WHERE id=?")->execute([$fid]);
        redirect('index.php?page=factures&ok=facture_deleted');
    }
}

/* ================= Données communes ================= */

$client_id = isset($_GET['client_id']) ? (int)$_GET['client_id'] : 0;
$q = trim($_GET['q'] ?? '');

// Clients pour le picker (inclut gps)
$clients = db()->query("SELECT id, prenom, nom, gsm, adresse, gps_lat, gps_lng FROM clients ORDER BY nom, prenom")->fetchAll();

/* ================= Édition d’une facture ================= */

$editId = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;
if ($editId > 0) {
    $stmt = db()->prepare("SELECT f.*, c.prenom, c.nom FROM factures f JOIN clients c ON c.id=f.client_id WHERE f.id=?");
    $stmt->execute([$editId]);
    $facture = $stmt->fetch();

    if (!$facture) { echo '<div class="alert alert-danger">Facture introuvable.</div>'; return; }

    $l = db()->prepare("SELECT * FROM facture_lignes WHERE facture_id=? ORDER BY id ASC");
    $l->execute([$editId]);
    $lignes = $l->fetchAll();
    ?>
    <div class="d-flex align-items-center mb-3">
      <a href="index.php?page=factures<?= $client_id?('&client_id='.intval($client_id)) : '' ?>" class="btn btn-sm btn-outline-secondary me-2">&larr; Retour</a>
      <h1 class="h5 mb-0">Facture #<?= h($facture['id']) ?> — <?= h($facture['nom'].' '.$facture['prenom']) ?></h1>
      <div class="ms-auto d-flex gap-2">
        <button class="btn btn-sm btn-outline-dark" data-bs-toggle="modal" data-bs-target="#paymentModal">Saisir paiement</button>
        <form method="post" class="d-inline" onsubmit="return confirm('Supprimer cette facture ?');">
          <?= csrf_input() ?>
          <input type="hidden" name="act" value="delete_facture">
          <input type="hidden" name="facture_id" value="<?= h($facture['id']) ?>">
          <button class="btn btn-outline-danger btn-sm">Supprimer</button>
        </form>
      </div>
    </div>

    <?php if (isset($_GET['ok'])): ?>
      <div class="alert alert-success alert-dismissible fade show">Action réalisée avec succès.<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
    <?php endif; if (isset($_GET['err']) && $_GET['err']==='missing_payement_column'): ?>
      <div class="alert alert-warning alert-dismissible fade show">
        La colonne <code>payement</code> manque dans <code>factures</code>. Ajoute-la puis réessaie.
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
      </div>
    <?php endif; ?>

    <div class="row g-3">
      <div class="col-lg-8">
        <div class="card shadow-sm">
          <div class="card-body">
            <h2 class="h6 mb-3">Lignes</h2>

            <!-- Ajout ligne libre -->
            <form method="post" class="row g-2 mb-3">
              <?= csrf_input() ?>
              <input type="hidden" name="act" value="add_ligne_libre">
              <input type="hidden" name="facture_id" value="<?= h($facture['id']) ?>">
              <div class="col-md-6">
                <label class="form-label">Libellé (ligne libre)</label>
                <input name="libelle" class="form-control" placeholder="Libellé">
              </div>
              <div class="col-md-3">
                <label class="form-label">Prix TTC</label>
                <input name="prix_ttc" type="number" step="0.01" value="0" class="form-control">
              </div>
              <div class="col-md-3">
                <label class="form-label">Qté</label>
                <input name="qty" type="number" step="0.01" value="1" class="form-control">
              </div>
              <div class="col-12">
                <button class="btn btn-primary">Ajouter la ligne libre</button>
              </div>
            </form>

            <div class="table-responsive">
              <table class="table align-middle">
                <thead class="table-light">
                  <tr>
                    <th>#</th><th>Libellé</th><th>Qté</th><th>PU (TTC)</th><th>TVA %</th><th>Total (TTC)</th><th style="width:110px">Actions</th>
                  </tr>
                </thead>
                <tbody>
                <?php if (!$lignes): ?>
                  <tr><td colspan="7" class="text-center text-muted py-4">Aucune ligne</td></tr>
                <?php else: foreach ($lignes as $i=>$ln):
                    $ht = (float)$ln['qty'] * (float)$ln['prix_unitaire'];
                ?>
                  <tr>
                    <td><?= $i+1 ?></td>
                    <td>
                      <div><strong><?= h($ln['libelle']) ?></strong></div>
                      <div class="text-muted small"><?= h($ln['origine_type']) ?> <?= $ln['origine_id']?('#'.h($ln['origine_id'])):'' ?></div>
                    </td>
                    <td><?= h($ln['qty']) ?></td>
                    <td><?= number_format((float)$ln['prix_unitaire'], 2, '.', ' ') ?></td>
                    <td><?= number_format((float)$ln['tva'], 2, '.', ' ') ?></td>
                    <td><?= number_format($ht, 2, '.', ' ') ?></td>
                    <td>
                      <form method="post" onsubmit="return confirm('Supprimer cette ligne ?');">
                        <?= csrf_input() ?>
                        <input type="hidden" name="act" value="delete_ligne">
                        <input type="hidden" name="facture_id" value="<?= h($facture['id']) ?>">
                        <input type="hidden" name="ligne_id" value="<?= h($ln['id']) ?>">
                        <button class="btn btn-sm btn-outline-danger">Supprimer</button>
                      </form>
                    </td>
                  </tr>
                <?php endforeach; endif; ?>
                </tbody>
              </table>
            </div>
          </div>
          <div class="card-footer d-flex justify-content-between align-items-center">
            <div class="text-muted">Créée le <?= h(date('d/m/Y H:i', strtotime($facture['date_facture']))) ?></div>
            <form method="post" class="d-flex align-items-center gap-2">
              <?= csrf_input() ?>
              <input type="hidden" name="act" value="change_statut">
              <input type="hidden" name="facture_id" value="<?= h($facture['id']) ?>">
              <select name="statut" class="form-select form-select-sm" onchange="this.form.submit()">
                <?php foreach (['BROUILLON','EMISE','IMPAYEE','PAYEE_PARTIELLE','PAYEE','ANNULEE'] as $s):
                      $sel = ($facture['statut']===$s)?'selected':''; ?>
                  <option <?= $sel ?> value="<?= h($s) ?>"><?= h($s) ?></option>
                <?php endforeach; ?>
              </select>
            </form>
          </div>
        </div>
      </div>

      <div class="col-lg-4">
        <div class="card shadow-sm">
          <div class="card-body">
            <h2 class="h6 mb-3">Récapitulatif</h2>
            <?php
              try {
                $r = db()->prepare("SELECT total_ht, tva, total_ttc, IFNULL(payement,0) AS payement FROM factures WHERE id=?");
                $r->execute([$facture['id']]); $tot = $r->fetch();
              } catch (\Throwable $e) {
                $r = db()->prepare("SELECT total_ht, tva, total_ttc, 0 AS payement FROM factures WHERE id=?");
                $r->execute([$facture['id']]); $tot = $r->fetch();
              }
            ?>
            <div class="d-flex justify-content-between"><span>Total HT :</span><strong><?= number_format((float)$tot['total_ht'], 2, '.', ' ') ?> MAD</strong></div>
            <div class="d-flex justify-content-between"><span>TVA :</span><strong><?= number_format((float)$tot['tva'], 2, '.', ' ') ?> MAD</strong></div>
            <hr>
            <div class="d-flex justify-content-between fs-5"><span>Total TTC :</span><strong><?= number_format((float)$tot['total_ttc'], 2, '.', ' ') ?> MAD</strong></div>
            <div class="d-flex justify-content-between mt-2"><span>Payé :</span><strong><?= number_format((float)$tot['payement'], 2, '.', ' ') ?> MAD</strong></div>
            <div class="d-flex justify-content-between"><span>Reste :</span><strong><?= number_format(max(0,(float)$tot['total_ttc']-(float)$tot['payement']), 2, '.', ' ') ?> MAD</strong></div>
          </div>
        </div>
      </div>
    </div>

    <!-- Modal paiement -->
    <div class="modal fade" id="paymentModal" tabindex="-1">
      <div class="modal-dialog">
        <form method="post" class="modal-content">
          <?= csrf_input() ?>
          <input type="hidden" name="act" value="save_payment">
          <input type="hidden" name="facture_id" value="<?= h($facture['id']) ?>">
          <div class="modal-header"><h5 class="modal-title">Saisir un paiement</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
          <div class="modal-body">
            <label class="form-label">Montant payé (MAD)</label>
            <input name="payement" type="number" step="0.01" class="form-control" value="">
            <div class="form-text">Le statut s'ajustera automatiquement.</div>
          </div>
          <div class="modal-footer"><button class="btn btn-primary">Enregistrer</button></div>
        </form>
      </div>
    </div>
    <?php
    return;
}

/* ================= Nouvelle facture : Sélection client + Actes ================= */
?>
<div class="d-flex align-items-center mb-3">
  <h1 class="h4 mb-0">Facturation</h1>
</div>

<?php if (isset($_GET['ok'])): ?>
  <div class="alert alert-success alert-dismissible fade show">
    Action réalisée avec succès.
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
  </div>
<?php endif; if (isset($_GET['err']) && $_GET['err']==='missing_payement_column'): ?>
  <div class="alert alert-warning alert-dismissible fade show">
    La colonne <code>payement</code> manque dans <code>factures</code> — ajoute-la si tu veux le suivi des impayés.
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
  </div>
<?php endif; ?>

<!-- Sélecteur client : typeahead sous le champ, mobile OK -->
<div class="card shadow-sm mb-3 position-relative" id="clientPickerCard">
  <div class="card-body">
    <h2 class="h6">1) Choisir le client</h2>
    <div class="row g-2 align-items-end">
      <div class="col-12">
        <label class="form-label">Recherche (nom, adresse, téléphone)</label>
        <input id="clientSearch" class="form-control" autocomplete="off" placeholder="Tapez pour rechercher...">
      </div>
      <div class="col-6 col-md-3">
        <div class="form-check form-switch">
          <input class="form-check-input" type="checkbox" id="gpsSwitch">
          <label class="form-check-label" for="gpsSwitch">Filtrer par proximité</label>
        </div>
      </div>
      <div class="col-6 col-md-3">
        <label class="form-label">Rayon</label>
        <select id="gpsRadius" class="form-select" disabled>
          <option value="1">1 km</option>
          <option value="5" selected>5 km</option>
          <option value="10">10 km</option>
          <option value="20">20 km</option>
        </select>
      </div>
      <div class="col-12 col-md-6 d-grid">
        <button id="useMyPos" class="btn btn-outline-primary" disabled>Utiliser ma position</button>
      </div>
    </div>

    <!-- Liste déroulante des résultats (injection JS) -->
    <div id="clientResults" class="list-group shadow-sm"
         style="display:none; position:absolute; left:1rem; right:1rem; z-index:1050; max-height:320px; overflow:auto; margin-top:.5rem;">
    </div>

    <?php if ($client_id>0):
      $cstmt = db()->prepare("SELECT prenom, nom, gsm, adresse FROM clients WHERE id=?");
      $cstmt->execute([$client_id]);
      $cinfo = $cstmt->fetch();
      if ($cinfo): ?>
      <div class="mt-3">
        <div><strong><?= h($cinfo['nom'].' '.$cinfo['prenom']) ?></strong></div>
        <div class="text-muted small"><?= h($cinfo['gsm'] ?: '—') ?> — <?= h($cinfo['adresse'] ?: '—') ?></div>
      </div>
    <?php endif; endif; ?>
  </div>
</div>


<?php
if ($client_id > 0):
  // Résumé client
  $cstmt = db()->prepare("SELECT prenom, nom, gsm, adresse FROM clients WHERE id=?");
  $cstmt->execute([$client_id]);
  $cinfo = $cstmt->fetch();
?>
<div class="card shadow-sm mb-3">
  <div class="card-body">
    <h2 class="h6 mb-2">Client sélectionné</h2>
    <div><strong><?= h($cinfo['nom'].' '.$cinfo['prenom']) ?></strong></div>
    <div class="text-muted small"><?= h($cinfo['gsm'] ?: '—') ?> — <?= h($cinfo['adresse'] ?: '—') ?></div>
  </div>
</div>

<div class="card shadow-sm">
  <div class="card-body">
    <h2 class="h6">2) Actes non facturés</h2>
    <?php
      // Consultations non facturées
      $consults = db()->prepare("
        SELECT c.id, c.motif, c.date_consult, p.nom AS animal, c.montant_ligne
        FROM consultations c JOIN patients p ON p.id=c.patient_id
        WHERE p.client_id=? AND (c.invoiced IS NULL OR c.invoiced=0)
        ORDER BY c.date_consult DESC");
      $consults->execute([$client_id]); $consults=$consults->fetchAll();

      // Analyses non facturées
      $analyses = db()->prepare("
        SELECT a.id, a.type_analyse, a.prix, co.date_consult
        FROM analyses a JOIN consultations co ON co.id=a.consultation_id JOIN patients p ON p.id=co.patient_id
        WHERE p.client_id=? AND (a.invoiced IS NULL OR a.invoiced=0)
        ORDER BY co.date_consult DESC");
      $analyses->execute([$client_id]); $analyses=$analyses->fetchAll();

      // Imageries non facturées
      $imgs = db()->prepare("
        SELECT i.id, i.type_imagerie, i.prix, co.date_consult
        FROM imageries i JOIN consultations co ON co.id=i.consultation_id JOIN patients p ON p.id=co.patient_id
        WHERE p.client_id=? AND (i.invoiced IS NULL OR i.invoiced=0)
        ORDER BY co.date_consult DESC");
      $imgs->execute([$client_id]); $imgs=$imgs->fetchAll();

      // Interventions non facturées
      $intervs = db()->prepare("
        SELECT it.id, it.type_acte, it.details, it.prix, it.date_intervention
        FROM interventions it JOIN patients p ON p.id=it.patient_id
        WHERE p.client_id=? AND (it.invoiced IS NULL OR it.invoiced=0)
        ORDER BY it.date_intervention DESC");
      $intervs->execute([$client_id]); $intervs=$intervs->fetchAll();
    ?>
    <form method="post">
      <?= csrf_input() ?>
      <input type="hidden" name="act" value="create_from_acts">
      <input type="hidden" name="client_id" value="<?= h($client_id) ?>">

      <div class="row g-3">
        <div class="col-md-6">
          <div class="card">
            <div class="card-header bg-light fw-bold">Consultations</div>
            <div class="card-body p-0">
              <div class="list-group list-group-flush">
                <?php if (!$consults): ?>
                  <div class="list-group-item text-muted">Aucune</div>
                <?php else: foreach ($consults as $c): ?>
                  <label class="list-group-item d-flex align-items-start gap-2">
                    <input class="form-check-input mt-1" type="checkbox" name="items[]" value="CONSULTATION:<?= h($c['id']) ?>">
                    <div>
                      <div><strong>Cas clinique: <?= h($c['motif']) ?></strong> — <?= h($c['animal']) ?> — <?= h(date('Y-m-d H:i', strtotime($c['date_consult']))) ?></div>
                      <div class="text-muted small">Montant: <?= number_format((float)$c['montant_ligne'], 2, '.', ' ') ?> MAD</div>
                    </div>
                  </label>
                <?php endforeach; endif; ?>
              </div>
            </div>
          </div>
        </div>

        <div class="col-md-6">
          <div class="card">
            <div class="card-header bg-light fw-bold">Analyses</div>
            <div class="card-body p-0">
              <div class="list-group list-group-flush">
                <?php if (!$analyses): ?>
                  <div class="list-group-item text-muted">Aucune</div>
                <?php else: foreach ($analyses as $a): ?>
                  <label class="list-group-item d-flex align-items-start gap-2">
                    <input class="form-check-input mt-1" type="checkbox" name="items[]" value="ANALYSE:<?= h($a['id']) ?>">
                    <div>
                      <div><strong><?= h($a['type_analyse']) ?></strong> — <?= h(date('Y-m-d H:i', strtotime($a['date_consult']))) ?></div>
                      <div class="text-muted small">Montant: <?= number_format((float)$a['prix'], 2, '.', ' ') ?> MAD</div>
                    </div>
                  </label>
                <?php endforeach; endif; ?>
              </div>
            </div>
          </div>
        </div>

        <div class="col-md-6">
          <div class="card">
            <div class="card-header bg-light fw-bold">Imagerie</div>
            <div class="card-body p-0">
              <div class="list-group list-group-flush">
                <?php if (!$imgs): ?>
                  <div class="list-group-item text-muted">Aucune</div>
                <?php else: foreach ($imgs as $i): ?>
                  <label class="list-group-item d-flex align-items-start gap-2">
                    <input class="form-check-input mt-1" type="checkbox" name="items[]" value="IMAGERIE:<?= h($i['id']) ?>">
                    <div>
                      <div><strong><?= h($i['type_imagerie']) ?></strong> — <?= h(date('Y-m-d H:i', strtotime($i['date_consult']))) ?></div>
                      <div class="text-muted small">Montant: <?= number_format((float)$i['prix'], 2, '.', ' ') ?> MAD</div>
                    </div>
                  </label>
                <?php endforeach; endif; ?>
              </div>
            </div>
          </div>
        </div>

        <div class="col-md-6">
          <div class="card">
            <div class="card-header bg-light fw-bold">Interventions</div>
            <div class="card-body p-0">
              <div class="list-group list-group-flush">
                <?php if (!$intervs): ?>
                  <div class="list-group-item text-muted">Aucune</div>
                <?php else: foreach ($intervs as $it): ?>
                  <label class="list-group-item d-flex align-items-start gap-2">
                    <input class="form-check-input mt-1" type="checkbox" name="items[]" value="INTERVENTION:<?= h($it['id']) ?>">
                    <div>
                      <div><strong><?= h($it['type_acte']) ?></strong> — <?= h(date('Y-m-d H:i', strtotime($it['date_intervention']))) ?></div>
                      <?php if (!empty($it['details'])): ?><div class="text-muted small"><?= nl2br(h($it['details'])) ?></div><?php endif; ?>
                      <div class="text-muted small">Montant: <?= number_format((float)$it['prix'], 2, '.', ' ') ?> MAD</div>
                    </div>
                  </label>
                <?php endforeach; endif; ?>
              </div>
            </div>
          </div>
        </div>
      </div>

      <div class="mt-3">
        <button class="btn btn-primary">Créer la facture</button>
      </div>
    </form>
  </div>
</div>

<!-- ================= Liste des factures du client sélectionné ================= -->
<?php
  // pagination basique
  [$limit, $offset, $pageNum] = paginate_params(20, 100);

  // Si pas de client sélectionné, ne pas afficher la liste
  if ($client_id > 0):
    // Essayer de récupérer payement dans le SELECT
    try {
      $count = db()->prepare("SELECT COUNT(*) FROM factures f WHERE f.client_id=?");
      $count->execute([$client_id]); $total = (int)$count->fetchColumn();

      $sql = "SELECT f.*, IFNULL(f.payement,0) AS payement
        FROM factures f
        WHERE f.client_id=:client_id
        ORDER BY f.date_facture DESC, f.id DESC
        LIMIT :limit OFFSET :offset";
$stmt = db()->prepare($sql);
$stmt->bindValue(':client_id', $client_id, PDO::PARAM_INT);
$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
      $stmt->execute();
      $rows = $stmt->fetchAll();

      // Total impayé = sum(max(total_ttc - payement, 0)) hors ANNULEE
      $sumQ = db()->prepare("SELECT SUM(GREATEST(f.total_ttc - IFNULL(f.payement,0), 0)) FROM factures f WHERE f.client_id=? AND f.statut<>'ANNULEE'");
      $sumQ->execute([$client_id]);
      $total_impaye = (float)$sumQ->fetchColumn();
    } catch (\Throwable $e) {
      // Colonne payement absente : fallback
      $count = db()->prepare("SELECT COUNT(*) FROM factures f WHERE f.client_id=?");
      $count->execute([$client_id]); $total = (int)$count->fetchColumn();

      $sql = "SELECT f.* FROM factures f
        WHERE f.client_id=:client_id
        ORDER BY f.date_facture DESC, f.id DESC
        LIMIT :limit OFFSET :offset";
$stmt = db()->prepare($sql);
$stmt->bindValue(':client_id', $client_id, PDO::PARAM_INT);
$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
      $stmt->execute();
      $rows = $stmt->fetchAll();

      // Sans payement, on considère tout le TTC comme impayé (hors ANNULEE)
      $sumQ = db()->prepare("SELECT SUM(f.total_ttc) FROM factures f WHERE f.client_id=? AND f.statut<>'ANNULEE'");
      $sumQ->execute([$client_id]);
      $total_impaye = (float)$sumQ->fetchColumn();
    }

    $pages = max(1, (int)ceil($total / $limit));
    $base = 'index.php?page=factures&client_id='.intval($client_id).($q!=='' ? '&q='.urlencode($q) : '');
?>
<div class="d-flex align-items-center mt-4 mb-2">
  <h2 class="h5 mb-0">Factures du client</h2>
</div>

<div class="card shadow-sm">
  <div class="table-responsive">
    <table class="table align-middle mb-0">
      <thead class="table-light">
        <tr>
          <th>#</th>
          <th>Date</th>
          <th>Statut</th>
          <th>Total TTC</th>
          <th>Paiement</th>
          <th style="width:180px">Actions</th>
        </tr>
      </thead>
      <tbody>
      <?php if (!$rows): ?>
        <tr><td colspan="6" class="text-center text-muted py-4">Aucune facture</td></tr>
      <?php else: foreach ($rows as $r): ?>
        <tr>
          <td>#<?= h($r['id']) ?></td>
          <td><?= h(date('d/m/Y H:i', strtotime($r['date_facture']))) ?></td>
          <td>
            <?php $badge = [
                'BROUILLON'=>'secondary','EMISE'=>'primary','IMPAYEE'=>'warning',
                'PAYEE_PARTIELLE'=>'info','PAYEE'=>'success','ANNULEE'=>'dark'
              ][$r['statut']] ?? 'secondary'; ?>
            <span class="badge bg-<?= $badge ?>"><?= h($r['statut']) ?></span>
          </td>
          <td><strong><?= number_format((float)$r['total_ttc'], 2, '.', ' ') ?> MAD</strong></td>
          <td>
            <form method="post" class="d-flex gap-1">
              <?= csrf_input() ?>
              <input type="hidden" name="act" value="save_payment_quick">
              <input type="hidden" name="facture_id" value="<?= h($r['id']) ?>">
              <input name="payement" type="number" step="0.01" class="form-control form-control-sm" style="max-width:120px"
                     value="<?= h(isset($r['payement']) ? $r['payement'] : '') ?>" placeholder="Montant">
              <button class="btn btn-sm btn-outline-success">OK</button>
            </form>
          </td>
          <td class="d-flex flex-wrap gap-1">
            <a class="btn btn-sm btn-outline-primary" href="index.php?page=factures&client_id=<?= intval($client_id) ?>&edit=<?= h($r['id']) ?>">Éditer</a>
            <form method="post" onsubmit="return confirm('Supprimer cette facture ?');">
              <?= csrf_input() ?>
              <input type="hidden" name="act" value="delete_facture">
              <input type="hidden" name="facture_id" value="<?= h($r['id']) ?>">
              <button class="btn btn-sm btn-outline-danger">Supprimer</button>
            </form>
          </td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>

  <div class="card-footer d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div class="text-muted"><?= $total ?> facture(s) — Page <?= $pageNum ?> / <?= $pages ?></div>
    <div class="fw-semibold">Total impayé (hors annulées) : <?= number_format($total_impaye, 2, '.', ' ') ?> MAD</div>
    <nav class="ms-auto">
      <ul class="pagination mb-0">
        <li class="page-item <?= $pageNum<=1?'disabled':'' ?>">
          <a class="page-link" href="<?= $base.'&p='.(max(1,$pageNum-1)) ?>">Précédent</a>
        </li>
        <li class="page-item <?= $pageNum>=$pages?'disabled':'' ?>">
          <a class="page-link" href="<?= $base.'&p='.(min($pages,$pageNum+1)) ?>">Suivant</a>
        </li>
      </ul>
    </nav>
  </div>
</div>
<?php endif; // fin liste factures client ?>
<?php else: // aucun client sélectionné ?>
  <div class="alert alert-info">Sélectionnez un client pour afficher ses actes non facturés et ses factures.</div>
<?php endif; ?>

<script>
// ===== Données clients exportées depuis PHP =====
const CLIENTS = <?php
  $all = $clients ?? db()->query("SELECT id, prenom, nom, gsm, adresse, gps_lat, gps_lng FROM clients ORDER BY nom, prenom")->fetchAll();
  echo json_encode($all, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
?>;

// ===== Références DOM =====
const card      = document.getElementById('clientPickerCard');
const inp       = document.getElementById('clientSearch');
const box       = document.getElementById('clientResults');
const gpsSwitch = document.getElementById('gpsSwitch');
const gpsRadius = document.getElementById('gpsRadius');
const useMyPos  = document.getElementById('useMyPos');

let userPos = null;
let currentIndex = -1;

// ===== Helpers =====
const norm = x => (x||'').toString().toLowerCase().trim();
const esc  = s => (s||'').replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]));
function haversine(lat1, lon1, lat2, lon2){
  const R=6371, toRad=x=>x*Math.PI/180;
  const dLat=toRad(lat2-lat1), dLon=toRad(lon2-lon1);
  const a=Math.sin(dLat/2)**2 + Math.cos(toRad(lat1))*Math.cos(toRad(lat2))*Math.sin(dLon/2)**2;
  return R*(2*Math.atan2(Math.sqrt(a), Math.sqrt(1-a)));
}
function itemHtml(c, distKm){
  const name = `${c.nom ?? ''} ${c.prenom ?? ''}`.trim();
  const phone = c.gsm ? ` — ${c.gsm}` : '';
  const addr  = c.adresse ? ` — ${c.adresse}` : '';
  const dist  = (typeof distKm==='number') ? ` — ~${distKm.toFixed(1)} km` : '';
  return `<div class="d-flex justify-content-between align-items-start">
            <div><strong>${esc(name)}</strong>${esc(phone)}${esc(addr)}</div>
            <div class="text-muted small">${dist}</div>
          </div>`;
}
function showBox(){ box.style.display='block'; }
function hideBox(){ box.style.display='none'; currentIndex=-1; }
function moveHighlight(dir){
  const items = box.querySelectorAll('.list-group-item');
  if (!items.length) return;
  currentIndex = (currentIndex + dir + items.length) % items.length;
  items.forEach((el,i)=> el.classList.toggle('active', i===currentIndex));
  items[currentIndex].scrollIntoView({block:'nearest'});
}
function goToHighlighted(){
  const el = box.querySelector('.list-group-item.active') || box.querySelector('.list-group-item');
  if (el) window.location.href = el.dataset.href;
}

// ===== Rendu filtré =====
function renderResults(){
  const q = norm(inp.value);
  const radiusKm = parseFloat(gpsRadius.value || '5');
  const wantGps  = gpsSwitch.checked;

  let list = [];
  for (const c of CLIENTS){
    // filtre texte
    const key = norm(`${c.nom||''} ${c.prenom||''} ${c.gsm||''} ${c.adresse||''}`);
    if (q && !key.includes(q)) continue;

    // filtre GPS si demandé
    let dist = null;
    if (wantGps){
      if (!userPos || c.gps_lat==null || c.gps_lng==null) continue;
      dist = haversine(userPos.lat, userPos.lng, parseFloat(c.gps_lat), parseFloat(c.gps_lng));
      if (!isFinite(dist) || dist > radiusKm) continue;
    }
    list.push({c, dist});
  }

  if (wantGps && userPos) list.sort((a,b)=>(a.dist??1e9)-(b.dist??1e9));

  // Build DOM
  box.innerHTML = '';
  const MAX = 50;
  const slice = list.slice(0, MAX);

  if (!slice.length){
    box.innerHTML = '<div class="list-group-item text-muted">Aucun résultat</div>';
  } else {
    for (const {c, dist} of slice){
      const a = document.createElement('a');
      a.href = '#';
      a.dataset.href = `index.php?page=factures&client_id=${encodeURIComponent(c.id)}`;
      a.className = 'list-group-item list-group-item-action';
      a.innerHTML = itemHtml(c, dist);
      a.addEventListener('click', (ev)=>{ ev.preventDefault(); window.location.href = a.dataset.href; }, {passive:false});
      a.addEventListener('touchstart', (ev)=>{ ev.preventDefault(); window.location.href = a.dataset.href; }, {passive:false});
      box.appendChild(a);
    }
    if (list.length > MAX){
      const more = document.createElement('div');
      more.className = 'list-group-item text-muted';
      more.textContent = `+ ${list.length - MAX} autres… affinez la recherche`;
      box.appendChild(more);
    }
  }
  currentIndex = -1;
  showBox();
}

// ===== Events =====
inp.addEventListener('input', renderResults);
inp.addEventListener('focus', renderResults);

gpsSwitch.addEventListener('change', ()=>{
  gpsRadius.disabled = !gpsSwitch.checked;
  useMyPos.disabled  = !gpsSwitch.checked;
  renderResults();
});
gpsRadius.addEventListener('change', renderResults);

useMyPos.addEventListener('click', (e)=>{
  e.preventDefault();
  if (!navigator.geolocation) { alert("Géolocalisation non supportée."); return; }
  navigator.geolocation.getCurrentPosition(
    (pos)=>{ userPos={lat:pos.coords.latitude,lng:pos.coords.longitude}; renderResults(); },
    ()=>alert("Impossible d'obtenir la position.")
  );
});

// Fermer la liste si clic hors carte
document.addEventListener('click', (e)=>{
  if (!card.contains(e.target)) hideBox();
});

// Navigation clavier
inp.addEventListener('keydown', (e)=>{
  if (box.style.display==='none') return;
  if (e.key==='ArrowDown'){ e.preventDefault(); moveHighlight(+1); }
  else if (e.key==='ArrowUp'){ e.preventDefault(); moveHighlight(-1); }
  else if (e.key==='Enter'){ e.preventDefault(); goToHighlighted(); }
  else if (e.key==='Escape'){ hideBox(); }
});

// Première peinture
document.addEventListener('DOMContentLoaded', ()=>{
  if (inp.value) renderResults();
});
</script>
