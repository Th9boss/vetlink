<?php
// includes/auth.php
require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_name(SESSION_NAME);
    $lifetime = defined('SESSION_LIFETIME') ? max(0, (int)SESSION_LIFETIME) : 315360000;

    // cookie_secure : HTTPS natif ou reverse proxy (X-Forwarded-Proto)
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $secure  = defined('COOKIE_SECURE') ? COOKIE_SECURE : $isHttps;

    ini_set('session.gc_maxlifetime', (string)$lifetime);
    ini_set('session.cookie_lifetime', (string)$lifetime);
    ini_set('session.use_strict_mode', '1');

    session_start([
        'cookie_lifetime' => $lifetime,
        'cookie_path'     => base_path(),
        'cookie_httponly' => true,
        'cookie_secure'   => $secure,
        'cookie_samesite' => 'Lax',
    ]);
}

function remember_cookie_name(): string {
    return SESSION_NAME . '_REMEMBER';
}

function remember_token_lifetime(): int {
    return defined('REMEMBER_TOKEN_LIFETIME') ? max(0, (int)REMEMBER_TOKEN_LIFETIME) : 315360000;
}

function session_cookie_params_for_app(): array {
    $p = session_get_cookie_params();
    return [
        'expires'  => time() + remember_token_lifetime(),
        'path'     => $p['path'] ?: base_path(),
        'domain'   => $p['domain'] ?: '',
        'secure'   => (bool)$p['secure'],
        'httponly' => true,
        'samesite' => $p['samesite'] ?: 'Lax',
    ];
}

function clear_remember_cookie(): void {
    setcookie(remember_cookie_name(), '', [
        'expires'  => time() - 42000,
        'path'     => base_path(),
        'domain'   => '',
        'secure'   => (bool)(session_get_cookie_params()['secure'] ?? false),
        'httponly' => true,
        'samesite' => session_get_cookie_params()['samesite'] ?? 'Lax',
    ]);
}

function delete_remember_tokens_for_user(int $userId): void {
    if ($userId <= 0) return;
    $stmt = db()->prepare('DELETE FROM auth_remember_tokens WHERE user_id = ?');
    $stmt->execute([$userId]);
}

function issue_remember_cookie(int $userId): void {
    if ($userId <= 0) return;

    delete_remember_tokens_for_user($userId);

    $selector = bin2hex(random_bytes(9));
    $validator = bin2hex(random_bytes(32));
    $hash = hash('sha256', $validator);
    $expiresAt = date('Y-m-d H:i:s', time() + remember_token_lifetime());

    $stmt = db()->prepare('
        INSERT INTO auth_remember_tokens (user_id, selector, token_hash, expires_at, last_used_at, user_agent)
        VALUES (?, ?, ?, ?, NOW(), ?)
    ');
    $stmt->execute([
        $userId,
        $selector,
        $hash,
        $expiresAt,
        substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
    ]);

    $cookieValue = $selector . ':' . $validator;
    setcookie(remember_cookie_name(), $cookieValue, session_cookie_params_for_app());
}

function restore_user_from_remember_cookie(): void {
    if (!empty($_SESSION['user'])) {
        return;
    }

    $raw = (string)($_COOKIE[remember_cookie_name()] ?? '');
    if ($raw === '' || !str_contains($raw, ':')) {
        return;
    }

    [$selector, $validator] = explode(':', $raw, 2);
    if ($selector === '' || $validator === '') {
        clear_remember_cookie();
        return;
    }

    $stmt = db()->prepare('
        SELECT rt.*, u.*
        FROM auth_remember_tokens rt
        INNER JOIN users u ON u.id = rt.user_id
        WHERE rt.selector = ?
          AND rt.expires_at > NOW()
        LIMIT 1
    ');
    $stmt->execute([$selector]);
    $row = $stmt->fetch();
    if (!$row) {
        clear_remember_cookie();
        return;
    }

    $expected = (string)($row['token_hash'] ?? '');
    $actual = hash('sha256', $validator);
    if (!hash_equals($expected, $actual)) {
        $delete = db()->prepare('DELETE FROM auth_remember_tokens WHERE selector = ?');
        $delete->execute([$selector]);
        clear_remember_cookie();
        return;
    }

    unset($row['password_hash'], $row['token_hash'], $row['selector'], $row['expires_at'], $row['last_used_at'], $row['created_at'], $row['user_agent']);
    $_SESSION['user'] = $row;
    session_regenerate_id(true);
    issue_remember_cookie((int)$row['id']);
}

restore_user_from_remember_cookie();

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
        $p = session_get_cookie_params();
        setcookie(session_name(), session_id(), [
            'expires'  => time() + (defined('SESSION_LIFETIME') ? max(0, (int)SESSION_LIFETIME) : 315360000),
            'path'     => $p['path'] ?: base_path(),
            'domain'   => $p['domain'] ?: '',
            'secure'   => (bool)$p['secure'],
            'httponly' => (bool)$p['httponly'],
            'samesite' => $p['samesite'] ?: 'Lax',
        ]);
        unset($user['password_hash']);
        $_SESSION['user'] = $user;
        issue_remember_cookie((int)$user['id']);
        return true;
    }
    return false;
}

function auth_logout(): void {
    $userId = (int)($_SESSION['user']['id'] ?? 0);
    if ($userId > 0) {
        delete_remember_tokens_for_user($userId);
    }
    clear_remember_cookie();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires'  => time() - 42000,
            'path'     => $p['path'] ?: base_path(),
            'domain'   => $p['domain'] ?: '',
            'secure'   => (bool)$p['secure'],
            'httponly' => (bool)$p['httponly'],
            'samesite' => $p['samesite'] ?: 'Lax',
        ]);
    }
    session_destroy();
}
