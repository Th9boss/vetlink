<?php
// index.php

ob_start();

// Bootstrap auth + helpers en tout premier (avant tout branchement)
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';

// ── Tunnel upload capture (AJAX uniquement, authentification requise) ──
if (isset($_GET['capture_upload'])) {
    require_login();
    require_once __DIR__ . '/includes/capture.php';
    exit;
}

$page = $_GET['page'] ?? (is_logged_in() ? 'dashboard' : 'login');

// ── Pages hors layout global (standalone) ────────────────────────────
if ($page === 'logout') {
    auth_logout();
    redirect('index.php?page=login');
}

if ($page === 'login') {
    require __DIR__ . '/pages/login.php';
    ob_end_flush();
    exit;
}

// ── Layout global (navbar + main + footer) ───────────────────────────
require_once __DIR__ . '/includes/header.php';

switch ($page) {
    case 'dashboard':
        require_login();
        require __DIR__ . '/pages/dashboard.php';
        break;

    case 'clients':
        require_login();
        require __DIR__ . '/features/clients.php';
        break;

    case 'patients':
        require_login();
        require __DIR__ . '/features/patients.php';
        break;

    case 'client_view':
        require_login();
        require __DIR__ . '/features/client_view.php';
        break;

    case 'patient_view':
        require_login();
        require __DIR__ . '/features/patient_view.php';
        break;

    case 'profil':
        require_login();
        require __DIR__ . '/features/profil.php';
        break;

    case 'consultation_edit':
        require_login();
        require __DIR__ . '/features/consultation_edit.php';
        break;

    case 'rappel':
        require_login();
        require __DIR__ . '/features/rappel.php';
        break;

    case 'factures':
        require_login();
        require __DIR__ . '/features/factures.php';
        break;

    case 'intervention':
        require_login();
        require __DIR__ . '/features/intervention.php';
        break;

    case 'users':
        require_login();
        require __DIR__ . '/features/users.php';
        break;

    case 'settings':
        require_login();
        require __DIR__ . '/features/settings.php';
        break;

    default:
        http_response_code(404);
        echo '<div class="alert alert-danger">Page introuvable.</div>';
        break;
}

ob_end_flush();
require_once __DIR__ . '/includes/footer.php';
