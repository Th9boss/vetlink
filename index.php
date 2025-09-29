<?php
// index.php

ob_start(); // bufferise la sortie au cas où
if (isset($_GET['capture_upload'])) {
  require_once __DIR__ . '/includes/capture.php';
  exit;
}
require_once __DIR__ . '/includes/header.php';

// ==== TUNNEL GLOBAL POUR LE MODULE CAPTURE ====
// Répond immédiatement aux POST vers ?capture_upload=1 (avec ou sans autres query params)

$page = $_GET['page'] ?? (is_logged_in() ? 'dashboard' : 'login');

switch ($page) {
    case 'login':
        require __DIR__ . '/pages/login.php';
        break;

    case 'logout':
        auth_logout();
        redirect('index.php?page=login');
        break;

    // pages privées
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
       
case 'patient_view':
    require_login();
    require __DIR__ . '/features/patient_view.php';
    break;
case 'profil': require __DIR__.'/features/profil.php'; break;

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
case 'users': require __DIR__.'/features/users.php'; break;
case 'settings': require __DIR__.'/features/settings.php'; break;

    default:
        http_response_code(404);
        echo '<div class="alert alert-danger">Page introuvable.</div>';
        break;
}
ob_end_flush();
require_once __DIR__ . '/includes/footer.php';

