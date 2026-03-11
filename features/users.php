<?php
// features/users.php
// Gestion des utilisateurs (ADMIN uniquement) : liste, création, édition, reset mot de passe.

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';

require_login();
require_role('ADMIN'); // garde stricte
csrf_check();

// --- Helpers ---
function valid_role(string $role): bool {
  return in_array($role, ['ADMIN','DOCTOR'], true);
}

// --- Actions POST ---
$action = $_POST['action'] ?? '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

  // Création d'un utilisateur
  if ($action === 'create') {
    $prenom = trim($_POST['prenom'] ?? '');
    $nom    = trim($_POST['nom'] ?? '');
    $email  = trim($_POST['email'] ?? '');
    $gsm    = trim($_POST['gsm'] ?? '');
    $role   = trim($_POST['role'] ?? 'DOCTOR');
    $pass   = (string)($_POST['password'] ?? '');
    $pass2  = (string)($_POST['password_confirm'] ?? '');

    $errors = [];
    if ($prenom === '' || $nom === '') $errors[] = "Prénom et nom sont obligatoires.";
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = "Email invalide.";
    if (!valid_role($role)) $errors[] = "Rôle invalide.";
    if (strlen($pass) < 8) $errors[] = "Mot de passe trop court (>= 8 caractères).";
    if ($pass !== $pass2) $errors[] = "Les mots de passe ne correspondent pas.";

    if (!$errors) {
      try {
        $stmt = db()->prepare("INSERT INTO users (role, prenom, nom, email, gsm, password_hash) 
                               VALUES (:role, :prenom, :nom, :email, :gsm, :hash)");
        $stmt->execute([
          ':role'  => $role,
          ':prenom'=> $prenom,
          ':nom'   => $nom,
          ':email' => $email,
          ':gsm'   => $gsm !== '' ? $gsm : null,
          ':hash'  => password_hash($pass, PASSWORD_BCRYPT),
        ]);
        redirect("index.php?page=users&created=1");
      } catch (PDOException $e) {
        if ($e->getCode() === '23000') {
          $errors[] = "Cet email est déjà utilisé.";
        } else {
          $errors[] = "Erreur SQL: ".$e->getMessage();
        }
      }
    }
  }

  // Mise à jour des infos de base et du rôle
  if ($action === 'update') {
    $id     = (int)($_POST['id'] ?? 0);
    $prenom = trim($_POST['prenom'] ?? '');
    $nom    = trim($_POST['nom'] ?? '');
    $email  = trim($_POST['email'] ?? '');
    $gsm    = trim($_POST['gsm'] ?? '');
    $role   = trim($_POST['role'] ?? 'DOCTOR');

    $errors = [];
    if ($id <= 0) $errors[] = "Utilisateur invalide.";
    if ($prenom === '' || $nom === '') $errors[] = "Prénom et nom sont obligatoires.";
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = "Email invalide.";
    if (!valid_role($role)) $errors[] = "Rôle invalide.";

    if (!$errors) {
      try {
        $stmt = db()->prepare("UPDATE users SET prenom=:prenom, nom=:nom, email=:email, gsm=:gsm, role=:role WHERE id=:id");
        $stmt->execute([
          ':prenom'=>$prenom, ':nom'=>$nom, ':email'=>$email,
          ':gsm'=>$gsm !== '' ? $gsm : null, ':role'=>$role, ':id'=>$id
        ]);
        redirect("index.php?page=users&updated=1");
      } catch (PDOException $e) {
        if ($e->getCode() === '23000') {
          $errors[] = "Cet email est déjà utilisé.";
        } else {
          $errors[] = "Erreur SQL: ".$e->getMessage();
        }
      }
    }
  }

  // Reset mot de passe
  if ($action === 'reset_password') {
    $id   = (int)($_POST['id'] ?? 0);
    $pass = (string)($_POST['new_password'] ?? '');
    $pass2= (string)($_POST['new_password_confirm'] ?? '');

    $errors = [];
    if ($id <= 0) $errors[] = "Utilisateur invalide.";
    if (strlen($pass) < 8) $errors[] = "Mot de passe trop court (>= 8 caractères).";
    if ($pass !== $pass2) $errors[] = "Les mots de passe ne correspondent pas.";

    if (!$errors) {
      $stmt = db()->prepare("UPDATE users SET password_hash=:hash WHERE id=:id");
      $stmt->execute([':hash'=>password_hash($pass, PASSWORD_BCRYPT), ':id'=>$id]);
      redirect("index.php?page=users&pwd=1");
    }
  }
}

// --- Data ---
$users = db()->query("SELECT id, role, prenom, nom, email, gsm, created_at FROM users ORDER BY created_at DESC")->fetchAll(PDO::FETCH_ASSOC);

// --- Vue ---
?>
<div class="container py-3">
  <h1 class="h4 mb-3">Utilisateurs</h1>

  <?php if (!empty($_GET['created'])): ?><div class="alert alert-success py-2">Utilisateur créé.</div><?php endif; ?>
  <?php if (!empty($_GET['updated'])): ?><div class="alert alert-success py-2">Utilisateur mis à jour.</div><?php endif; ?>
  <?php if (!empty($_GET['pwd'])):     ?><div class="alert alert-success py-2">Mot de passe réinitialisé.</div><?php endif; ?>

  <?php if (!empty($errors ?? [])): ?>
    <div class="alert alert-danger">
      <ul class="mb-0"><?php foreach (($errors ?? []) as $err): ?><li><?= h($err) ?></li><?php endforeach; ?></ul>
    </div>
  <?php endif; ?>

  <!-- Création -->
  <div class="card mb-4">
    <div class="card-header">Créer un utilisateur</div>
    <div class="card-body">
      <form method="post" class="row g-3">
        <?= csrf_input() ?>
        <input type="hidden" name="action" value="create">
        <div class="col-md-3">
          <label class="form-label">Prénom</label>
          <input name="prenom" class="form-control" required>
        </div>
        <div class="col-md-3">
          <label class="form-label">Nom</label>
          <input name="nom" class="form-control" required>
        </div>
        <div class="col-md-3">
          <label class="form-label">Email</label>
          <input type="email" name="email" class="form-control" required>
        </div>
        <div class="col-md-3">
          <label class="form-label">Téléphone</label>
          <input name="gsm" class="form-control">
        </div>
        <div class="col-md-3">
          <label class="form-label">Rôle</label>
          <select name="role" class="form-select">
            <option value="DOCTOR">Docteur</option>
            <option value="ADMIN">Admin</option>
          </select>
        </div>
        <div class="col-md-3">
          <label class="form-label">Mot de passe</label>
          <input type="password" name="password" class="form-control" required minlength="8">
        </div>
        <div class="col-md-3">
          <label class="form-label">Confirmation</label>
          <input type="password" name="password_confirm" class="form-control" required minlength="8">
        </div>
        <div class="col-md-3 d-flex align-items-end justify-content-end">
          <button class="btn btn-primary">Créer</button>
        </div>
      </form>
      <div class="form-text mt-2">
        Astuce: l’email doit être unique. En cas de doublon, un message d’erreur s’affiche.
      </div>
    </div>
  </div>

  <!-- Liste / édition inline -->
  <div class="table-responsive">
    <table class="table align-middle">
      <thead class="table-light">
        <tr>
          <th>#</th>
          <th>Nom</th>
          <th>Email</th>
          <th>GSM</th>
          <th>Rôle</th>
          <th>Créé le</th>
          <th class="text-end">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$users): ?>
          <tr><td colspan="7" class="text-center text-muted py-4">Aucun utilisateur</td></tr>
        <?php endif; ?>

        <?php foreach ($users as $u): ?>
          <tr>
            <td><?= (int)$u['id'] ?></td>
            <td><?= h($u['prenom'].' '.$u['nom']) ?></td>
            <td><?= h($u['email']) ?></td>
            <td><?= h($u['gsm'] ?? '') ?></td>
            <td>
              <span class="badge <?= $u['role']==='ADMIN'?'bg-danger':'bg-secondary' ?>">
                <?= h($u['role']) ?>
              </span>
            </td>
            <td><?= h($u['created_at']) ?></td>
            <td class="text-end">
              <button class="btn btn-sm btn-outline-primary" data-bs-toggle="collapse" data-bs-target="#uedit<?= (int)$u['id'] ?>">Modifier</button>
              <button class="btn btn-sm btn-outline-warning" data-bs-toggle="collapse" data-bs-target="#upwd<?= (int)$u['id'] ?>">Reset mot de passe</button>
            </td>
          </tr>

          <!-- Ligne d'édition -->
          <tr class="collapse" id="uedit<?= (int)$u['id'] ?>">
            <td colspan="7">
              <form method="post" class="row g-2">
                <?= csrf_input() ?>
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                <div class="col-md-2">
                  <label class="form-label">Prénom</label>
                  <input name="prenom" class="form-control" value="<?= h($u['prenom']) ?>" required>
                </div>
                <div class="col-md-2">
                  <label class="form-label">Nom</label>
                  <input name="nom" class="form-control" value="<?= h($u['nom']) ?>" required>
                </div>
                <div class="col-md-3">
                  <label class="form-label">Email</label>
                  <input type="email" name="email" class="form-control" value="<?= h($u['email']) ?>" required>
                </div>
                <div class="col-md-2">
                  <label class="form-label">Téléphone</label>
                  <input name="gsm" class="form-control" value="<?= h($u['gsm'] ?? '') ?>">
                </div>
                <div class="col-md-2">
                  <label class="form-label">Rôle</label>
                  <select name="role" class="form-select">
                    <option value="DOCTOR" <?= $u['role']==='DOCTOR'?'selected':''; ?>>Docteur</option>
                    <option value="ADMIN"  <?= $u['role']==='ADMIN'?'selected':''; ?>>Admin</option>
                  </select>
                </div>
                <div class="col-md-1 d-flex align-items-end justify-content-end">
                  <button class="btn btn-primary btn-sm">Enregistrer</button>
                </div>
              </form>
            </td>
          </tr>

          <!-- Ligne reset mot de passe -->
          <tr class="collapse" id="upwd<?= (int)$u['id'] ?>">
            <td colspan="7">
              <form method="post" class="row g-2">
                <?= csrf_input() ?>
                <input type="hidden" name="action" value="reset_password">
                <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                <div class="col-md-3">
                  <label class="form-label">Nouveau mot de passe</label>
                  <input type="password" name="new_password" class="form-control" minlength="8" required>
                </div>
                <div class="col-md-3">
                  <label class="form-label">Confirmation</label>
                  <input type="password" name="new_password_confirm" class="form-control" minlength="8" required>
                </div>
                <div class="col-md-2 d-flex align-items-end">
                  <button class="btn btn-warning btn-sm">Réinitialiser</button>
                </div>
                <div class="col-md-4 d-flex align-items-end">
                  <span class="text-muted small">Longueur minimale 8 caractères.</span>
                </div>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
