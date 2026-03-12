<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    json_response(['ok' => false, 'message' => 'Méthode non autorisée.'], 405);
}

$raw = trim((string)($_POST['device_token'] ?? ''));
if ($raw === '') {
    json_response(['ok' => false, 'message' => 'Jeton appareil manquant.'], 400);
}

$newToken = restore_user_from_device_token_string($raw);
if ($newToken === null || !is_logged_in()) {
    json_response(['ok' => false, 'message' => 'Jeton appareil invalide.'], 401);
}

json_response([
    'ok' => true,
    'device_token' => $newToken,
    'redirect' => base_url('index.php?page=dashboard'),
]);
