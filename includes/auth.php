<?php
// includes/auth.php
require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_name(SESSION_NAME);
    session_start([
        'cookie_httponly' => true,
        'cookie_secure'   => isset($_SERVER['HTTPS']),
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
        unset($user['password_hash']);
        $_SESSION['user'] = $user;
        return true;
    }
    return false;
}

function auth_logout(): void {
    $_SESSION = [];
    session_destroy();
}
