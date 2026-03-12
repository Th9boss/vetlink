<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

if (!is_logged_in()) {
    json_response(['ok' => false, 'auth' => false], 401);
}

$_SESSION['last_ping_at'] = time();
refresh_session_cookie();
json_response(['ok' => true, 'auth' => true, 'ts' => time()]);
