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
  <meta name="application-name" content="VETLINK">
  <meta name="theme-color" content="#0b3d5c">
  <meta name="mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-status-bar-style" content="default">
  <meta name="apple-mobile-web-app-title" content="VETLINK">
  <meta property="og:type" content="website">
  <meta property="og:site_name" content="VETLINK">
  <meta property="og:title" content="VETLINK">
  <meta property="og:description" content="Application de gestion veterinaire VETLINK.">
  <meta property="og:url" content="<?= h(absolute_url('index.php?page=' . urlencode((string)$current))) ?>">
  <meta property="og:image" content="<?= h(absolute_url('assets/pwa/1024X1024.png')) ?>">
  <meta property="og:image:type" content="image/png">
  <meta property="og:image:width" content="1024">
  <meta property="og:image:height" content="1024">
  <meta name="twitter:card" content="summary_large_image">
  <meta name="twitter:title" content="VETLINK">
  <meta name="twitter:description" content="Application de gestion veterinaire VETLINK.">
  <meta name="twitter:image" content="<?= h(absolute_url('assets/pwa/1024X1024.png')) ?>">
  <link rel="manifest" href="<?= h(base_url('manifest.php')) ?>">
  <link rel="icon" type="image/png" sizes="192x192" href="<?= h(base_url('assets/pwa/192x192.png')) ?>">
  <link rel="apple-touch-icon" sizes="152x152" href="<?= h(base_url('assets/pwa/152X152.png')) ?>">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
        integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH"
        crossorigin="anonymous">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
  <link href="assets/css/app.css" rel="stylesheet">
  <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
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
    .pwa-install-btn {
      display: none;
      align-items: center;
      justify-content: center;
      gap: .55rem;
      width: 100%;
      border: 1px solid rgba(255,255,255,.24);
      border-radius: 16px;
      padding: .85rem 1rem;
      background: linear-gradient(180deg, #2e8cff 0%, #0a66ff 100%);
      color: #fff;
      font-weight: 600;
      text-decoration: none;
      box-shadow: 0 14px 28px rgba(10,102,255,.26);
    }
    .pwa-install-btn.is-visible { display: inline-flex; }
    @media (display-mode: standalone) {
      .pwa-install-btn { display: none !important; }
      #iosPwaModal { display: none !important; }
    }
    body.is-standalone .pwa-install-btn,
    body.is-standalone #iosPwaModal {
      display: none !important;
    }
    .pwa-install-btn:hover { color: #fff; }
    #iosPwaModal {
      display: none;
      position: fixed;
      inset: 0;
      z-index: 4000;
      padding: 20px;
      background: rgba(8, 16, 32, .42);
      overflow: auto;
    }
    #iosPwaModal.is-open {
      display: flex;
      align-items: center;
      justify-content: center;
    }
    #iosPwaModal { backdrop-filter: blur(10px); }
    .ios-pwa-box {
      width: min(92vw, 430px);
      max-height: 92vh;
      overflow: auto;
      margin: auto;
      border-radius: 30px;
      border: 1px solid rgba(255,255,255,.55);
      background: linear-gradient(180deg, rgba(255,255,255,.9) 0%, rgba(243,247,252,.88) 100%);
      box-shadow: 0 32px 80px rgba(7, 19, 43, .26);
      backdrop-filter: blur(16px);
    }
    .ios-pwa-gif {
      display: block;
      width: min(100%, 240px);
      max-height: min(56vh, 420px);
      object-fit: contain;
      margin: 0 auto;
      border-radius: 28px;
      border: 1px solid #d9e2f0;
      background: #fff;
    }
    @media (max-width: 380px) {
      .ios-pwa-gif {
        width: min(100%, 200px);
        max-height: 48vh;
      }
    }
    @media (min-width: 992px) {
      .navbar-pro .navbar-nav .nav-link { padding:.5rem .75rem; border-radius:.5rem; }
      .navbar-pro .navbar-nav .nav-link.active { background:#ffffff1a; }
    }
  </style>
</head>
<body class="bg-light">
<script>window.VETLINK_BASE_URL = <?= json_encode(base_url(), JSON_UNESCAPED_SLASHES) ?>;</script>
<script>
  (function () {
    var standalone = window.matchMedia && window.matchMedia('(display-mode: standalone)').matches;
    if (!standalone && window.navigator && window.navigator.standalone === true) {
      standalone = true;
    }
    if (standalone) {
      document.body.classList.add('is-standalone');
    }
  })();
</script>

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
         tabindex="-1" id="offcanvasNav" data-bs-scroll="false" data-bs-backdrop="true">
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
          <div class="mt-2">
            <button type="button" id="pwaInstallBtn" class="pwa-install-btn is-visible" onclick="return window.__vetlinkHandleInstall ? window.__vetlinkHandleInstall(event) : false;">
              <i class="bi bi-phone"></i>
              <span>Installer l'application</span>
            </button>
          </div>
        <?php endif; ?>
      </div>
    </div>

  </div>
</nav>

<?php if (is_logged_in()): ?>
  <div id="iosPwaModal" aria-hidden="true">
    <div class="ios-pwa-box p-4 p-md-5">
      <div class="d-flex align-items-start justify-content-between gap-3 mb-3">
        <div>
          <div class="text-uppercase small fw-semibold text-primary">VETLINK</div>
          <h2 class="h5 mb-1">Installer l'application sur iPhone</h2>
          <p class="text-muted small mb-0">Suivez l'animation pour ajouter VETLINK a l'ecran d'accueil.</p>
        </div>
        <button type="button" id="iosPwaCloseBtn" class="btn btn-sm btn-primary" onclick="var m=document.getElementById('iosPwaModal');if(m){m.classList.remove('is-open');m.setAttribute('aria-hidden','true');document.body.style.overflow='';}return false;">Fermer</button>
      </div>
      <img id="iosPwaGif" data-src="<?= h(base_url('assets/pwa/howto_pwa.gif')) ?>" alt="Tutoriel installation PWA iPhone" class="ios-pwa-gif">
    </div>
  </div>
  <script>
    (function () {
      var modal = document.getElementById('iosPwaModal');
      if (!modal) return;
      modal.addEventListener('click', function (event) {
        if (event.target !== modal) return;
        modal.classList.remove('is-open');
        modal.setAttribute('aria-hidden', 'true');
        document.body.style.overflow = '';
      });
    })();
  </script>
  <script>
    (function () {
      var deferredPrompt = null;

      function isStandalone() {
        return (window.matchMedia && window.matchMedia('(display-mode: standalone)').matches) || window.navigator.standalone === true;
      }

      function isAndroid() {
        return /Android/i.test(window.navigator.userAgent || '');
      }

      function isIos() {
        var ua = window.navigator.userAgent || '';
        return /iPad|iPhone|iPod/.test(ua) || (ua.includes('Mac') && 'ontouchend' in document);
      }

      function openIosModal() {
        var modal = document.getElementById('iosPwaModal');
        var gif = document.getElementById('iosPwaGif');
        if (gif && !gif.getAttribute('src')) {
          gif.setAttribute('src', gif.getAttribute('data-src') || '');
        }
        if (!modal) return false;
        modal.classList.add('is-open');
        modal.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';
        return false;
      }

      window.__vetlinkHandleInstall = async function (event) {
        if (event) {
          event.preventDefault();
          event.stopPropagation();
        }

        if (isStandalone()) {
          return false;
        }

        var offcanvasEl = document.getElementById('offcanvasNav');
        if (offcanvasEl && window.bootstrap && window.bootstrap.Offcanvas) {
          var instance = window.bootstrap.Offcanvas.getInstance(offcanvasEl);
          if (instance) {
            instance.hide();
          }
        }

        if (isAndroid() && deferredPrompt) {
          try {
            deferredPrompt.prompt();
            await deferredPrompt.userChoice;
          } catch (_) {
          }
          deferredPrompt = null;
          return false;
        }

        if (isIos()) {
          window.setTimeout(openIosModal, 220);
        }

        return false;
      };

      window.addEventListener('beforeinstallprompt', function (event) {
        event.preventDefault();
        deferredPrompt = event;
      });

      window.addEventListener('appinstalled', function () {
        var btn = document.getElementById('pwaInstallBtn');
        if (btn) {
          btn.classList.remove('is-visible');
        }
        deferredPrompt = null;
      });
    })();
  </script>
<?php endif; ?>

<main class="container py-4">
