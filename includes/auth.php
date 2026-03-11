<?php
// includes/auth.php
require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_name(SESSION_NAME);

    // cookie_secure : HTTPS natif ou reverse proxy (X-Forwarded-Proto)
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $secure  = defined('COOKIE_SECURE') ? COOKIE_SECURE : $isHttps;

    session_start([
        'cookie_httponly' => true,
        'cookie_secure'   => $secure,
        'cookie_samesite' => 'Lax',
    ]);
}

function current_user(): ?array {
    return $_SESSION['user'] ?? null;
}

function is_logged_in(): bool {
    return !empty($_SESSION['user']);
}

function require_login(): void {
    if (!is_logged_in()) {
        redirect('index.php?page=login');
    }
}

function require_role(string $role): void {
    if (!is_logged_in() || ($_SESSION['user']['role'] ?? '') !== $role) {
        http_response_code(403);
        die('Accès refusé.');
    }
}

function auth_login(string $email, string $password): bool {
    $stmt = db()->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    $user = $stmt->fetch();
    if ($user && password_verify($password, $user['password_hash'])) {
        // Régénère l'ID de session pour prévenir la fixation de session
        session_regenerate_id(true);
        unset($user['password_hash']);
        $_SESSION['user'] = $user;
        return true;
    }
    return false;
}

function auth_logout(): void {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $p['path'], $p['domain'], $p['secure'], $p['httponly']
        );
    }
    session_destroy();
}
