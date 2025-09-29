<?php
// includes/header.php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/helpers.php';

$current = $_GET['page'] ?? (is_logged_in() ? 'dashboard' : 'login');
function nav_active(string $slug, string $current): string {
  return $slug === $current ? 'active' : '';
}

$navLinks = [
  ['slug'=>'dashboard','label'=>'Tableau de bord','icon'=>'bi-speedometer2'],
  ['slug'=>'profil','label'=>'Profil','icon'=>'bi-person-circle'],
  ['slug'=>'clients','label'=>'Clients','icon'=>'bi-people'],
  ['slug'=>'patients','label'=>'Patients','icon'=>'bi-heart-pulse'],
  ['slug'=>'factures','label'=>'Factures','icon'=>'bi-receipt'],
];
$me = current_user();
if ($me && ($me['role'] ?? '') === 'ADMIN') {
  $navLinks[] = ['slug'=>'users','label'=>'Utilisateurs','icon'=>'bi-people'];
  $navLinks[] = ['slug'=>'settings','label'=>'Paramètres','icon'=>'bi-gear'];
}
?>
<!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <title><?= h(SITE_NAME) ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="theme-color" content="#0b3d5c">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
  <link href="assets/css/app.css" rel="stylesheet">
  <style>
    .navbar-pro {
      background: linear-gradient(135deg, #0b3d5c 0%, #0e6ba8 100%);
      box-shadow: 0 8px 24px rgba(0,0,0,.08);
    }
    .nav-brand img {
      height: 36px;
      width: auto;
      object-fit: contain;
      display: block;
    }
    .navbar-pro .nav-link { color: rgba(255,255,255,.9); }
    .navbar-pro .nav-link:hover, .navbar-pro .nav-link.active { color:#fff; }
    .navbar-pro .btn-outline-light { --bs-btn-border-color:#ffffff66; --bs-btn-color:#fff; }
    .offcanvas-end.custom-offcanvas { width: 320px; background:#0b3d5c; color:#fff; }
    .offcanvas-end.custom-offcanvas .offcanvas-header { border-bottom:1px solid rgba(255,255,255,.15); }
    .offcanvas-end.custom-offcanvas .nav-link { color: rgba(255,255,255,.85); }
    .offcanvas-end.custom-offcanvas .nav-link:hover { color:#fff; }
    @media (min-width: 992px) {
      .navbar-pro .navbar-nav .nav-link { padding:.5rem .75rem; border-radius:.5rem; }
      .navbar-pro .navbar-nav .nav-link.active { background:#ffffff1a; }
    }
  </style>
</head>
<body class="bg-light">

<nav class="navbar navbar-expand-lg navbar-pro sticky-top">
  <div class="container-fluid">

    <!-- Brand uniquement logo -->
    <a class="navbar-brand nav-brand" href="index.php">
      <img src="assets/img/logo-w.png" alt="Logo">
    </a>

    <!-- Desktop nav -->
    <div class="d-none d-lg-flex align-items-center gap-2 ms-auto">
      <?php if (is_logged_in()): ?>
        <ul class="navbar-nav me-2">
          <?php foreach ($navLinks as $ln): ?>
            <li class="nav-item">
              <a class="nav-link <?= nav_active($ln['slug'], $current) ?>"
                 href="index.php?page=<?= h($ln['slug']) ?>">
                <i class="bi <?= h($ln['icon']) ?> me-1"></i> <?= h($ln['label']) ?>
              </a>
            </li>
          <?php endforeach; ?>
        </ul>
        <a class="btn btn-sm btn-outline-light" href="index.php?page=logout">
          <i class="bi bi-box-arrow-right me-1"></i> Déconnexion
        </a>
      <?php else: ?>
        <a class="btn btn-sm btn-outline-light ms-auto" href="index.php?page=login">
          <i class="bi bi-box-arrow-in-right me-1"></i> Connexion
        </a>
      <?php endif; ?>
    </div>

    <!-- Mobile toggler -->
    <button class="navbar-toggler border-0 text-white d-lg-none" type="button"
            data-bs-toggle="offcanvas" data-bs-target="#offcanvasNav" aria-controls="offcanvasNav">
      <span class="bi bi-list" style="font-size:1.8rem;"></span>
    </button>

    <!-- Offcanvas (mobile) -->
    <div class="offcanvas offcanvas-end custom-offcanvas d-lg-none"
         tabindex="-1" id="offcanvasNav" data-bs-scroll="true">
      <div class="offcanvas-header">
        <h5 class="offcanvas-title nav-brand">
          <img src="assets/img/logo-w.png" alt="Logo">
        </h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="offcanvas"></button>
      </div>
      <div class="offcanvas-body d-flex flex-column">
        <ul class="navbar-nav flex-grow-1">
          <?php if (is_logged_in()): ?>
            <?php foreach ($navLinks as $ln): ?>
              <li class="nav-item">
                <a class="nav-link <?= nav_active($ln['slug'], $current) ?>"
                   href="index.php?page=<?= h($ln['slug']) ?>">
                  <i class="bi <?= h($ln['icon']) ?> me-1"></i> <?= h($ln['label']) ?>
                </a>
              </li>
            <?php endforeach; ?>
          <?php else: ?>
            <li class="nav-item">
              <a class="nav-link <?= nav_active('login', $current) ?>"
                 href="index.php?page=login">
                <i class="bi bi-box-arrow-in-right me-1"></i> Connexion
              </a>
            </li>
          <?php endif; ?>
        </ul>
        <?php if (is_logged_in()): ?>
          <div class="mt-3">
            <a class="btn btn-light w-100" href="index.php?page=logout">
              <i class="bi bi-box-arrow-right me-1"></i> Déconnexion
            </a>
          </div>
        <?php endif; ?>
      </div>
    </div>

  </div>
</nav>

<main class="container py-4">
