<?php
// pages/login.php
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    if (auth_login($email, $password)) {
        redirect('index.php?page=dashboard');
    } else {
        $error = 'Identifiants invalides';
    }
}
?>
<style>
  .login-page {
    min-height: 100vh;
    display: flex;
    flex-direction: column;
    justify-content: center;
    align-items: center;
    background:#f8f9fa;
    padding:20px;
  }
  .login-box {
    width:100%;
    max-width:380px;
    border:1px solid #ddd;
    border-radius:.75rem;
    background:#fff;
    padding:2rem 1.5rem;
  }
  .login-logo {
    display:block;
    margin:0 auto 1rem;
    height:56px;
    width:56px;
    object-fit:contain;
    border-radius:.75rem;
  }
  .login-footer {
    margin-top:20px;
    font-size:.9rem;
    color:#666;
    text-align:center;
  }
</style>

<div class="login-page">
  <div class="login-box shadow-sm">
    <img src="assets/img/logo-square.png" alt="Logo" class="login-logo">
    <h1 class="h5 text-center mb-3">Connexion</h1>

    <?php if (!empty($error)): ?>
      <div class="alert alert-danger"><?= h($error) ?></div>
    <?php endif; ?>

    <form method="post">
      <?= csrf_input() ?>
      <div class="mb-3">
        <label class="form-label">Email</label>
        <input name="email" type="email" class="form-control" required>
      </div>
      <div class="mb-3">
        <label class="form-label">Mot de passe</label>
        <input name="password" type="password" class="form-control" required>
      </div>
      <button class="btn btn-primary w-100">Se connecter</button>
    </form>
  </div>

  <div class="login-footer">
    VETLINK — tous droits réservés — VET EXPERT • 
    <a href="https://vetex.ma" target="_blank" rel="noopener" class="text-decoration-none">vetex.ma</a> • 2025
  </div>
</div>
