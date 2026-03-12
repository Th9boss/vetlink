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
        'cookie_path'     => '/',
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

function device_token_lifetime(): int {
    return defined('DEVICE_TOKEN_LIFETIME') ? max(0, (int)DEVICE_TOKEN_LIFETIME) : 315360000;
}

function session_cookie_params_for_app(): array {
    $p = session_get_cookie_params();
    $opts = [
        'expires'  => time() + remember_token_lifetime(),
        'path'     => '/',
        'secure'   => (bool)$p['secure'],
        'httponly' => true,
        'samesite' => $p['samesite'] ?: 'Lax',
    ];
    if (!empty($p['domain'])) {
        $opts['domain'] = $p['domain'];
    }
    return $opts;
}

function clear_remember_cookie(): void {
    $opts = [
        'expires'  => time() - 42000,
        'path'     => '/',
        'secure'   => (bool)(session_get_cookie_params()['secure'] ?? false),
        'httponly' => true,
        'samesite' => session_get_cookie_params()['samesite'] ?? 'Lax',
    ];
    $domain = session_get_cookie_params()['domain'] ?? '';
    if ($domain !== '') {
        $opts['domain'] = $domain;
    }
    setcookie(remember_cookie_name(), '', $opts);
}

function delete_remember_tokens_for_user(int $userId): void {
    if ($userId <= 0) return;
    $stmt = db()->prepare('DELETE FROM auth_remember_tokens WHERE user_id = ?');
    $stmt->execute([$userId]);
}

function delete_device_tokens_for_user(int $userId): void {
    if ($userId <= 0) return;
    $stmt = db()->prepare('DELETE FROM auth_device_tokens WHERE user_id = ?');
    $stmt->execute([$userId]);
}

function refresh_session_cookie(): void {
    if (session_status() !== PHP_SESSION_ACTIVE || session_id() === '') {
        return;
    }

    $p = session_get_cookie_params();
    $opts = [
        'expires'  => time() + (defined('SESSION_LIFETIME') ? max(0, (int)SESSION_LIFETIME) : 315360000),
        'path'     => '/',
        'secure'   => (bool)$p['secure'],
        'httponly' => (bool)$p['httponly'],
        'samesite' => $p['samesite'] ?: 'Lax',
    ];
    if (!empty($p['domain'])) {
        $opts['domain'] = $p['domain'];
    }
    setcookie(session_name(), session_id(), $opts);
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

function issue_device_token(int $userId, ?string $deviceLabel = null): string {
    if ($userId <= 0) {
        throw new RuntimeException('Invalid user id for device token.');
    }

    $selector = bin2hex(random_bytes(9));
    $validator = bin2hex(random_bytes(32));
    $hash = hash('sha256', $validator);
    $expiresAt = date('Y-m-d H:i:s', time() + device_token_lifetime());

    $stmt = db()->prepare('
        INSERT INTO auth_device_tokens (user_id, selector, token_hash, device_label, user_agent, expires_at, last_used_at)
        VALUES (?, ?, ?, ?, ?, ?, NOW())
    ');
    $stmt->execute([
        $userId,
        $selector,
        $hash,
        $deviceLabel !== null ? substr($deviceLabel, 0, 120) : null,
        substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
        $expiresAt,
    ]);

    return $selector . ':' . $validator;
}

function pending_device_token(): ?string {
    $token = $_SESSION['pending_device_token'] ?? null;
    return is_string($token) && $token !== '' ? $token : null;
}

function consume_pending_device_token(): ?string {
    $token = pending_device_token();
    unset($_SESSION['pending_device_token']);
    return $token;
}

function restore_user_from_device_token_string(string $raw): ?string {
    if ($raw === '' || !str_contains($raw, ':')) {
        return null;
    }

    [$selector, $validator] = explode(':', $raw, 2);
    if ($selector === '' || $validator === '') {
        return null;
    }

    $stmt = db()->prepare('
        SELECT dt.id AS device_token_id, dt.user_id AS device_user_id, dt.token_hash, u.*
        FROM auth_device_tokens dt
        INNER JOIN users u ON u.id = dt.user_id
        WHERE dt.selector = ?
          AND dt.expires_at > NOW()
        LIMIT 1
    ');
    $stmt->execute([$selector]);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }

    $expected = (string)($row['token_hash'] ?? '');
    $actual = hash('sha256', $validator);
    if (!hash_equals($expected, $actual)) {
        $delete = db()->prepare('DELETE FROM auth_device_tokens WHERE selector = ?');
        $delete->execute([$selector]);
        return null;
    }

    unset($row['password_hash'], $row['token_hash'], $row['device_token_id'], $row['device_user_id']);
    $_SESSION['user'] = $row;
    session_regenerate_id(true);
    refresh_session_cookie();
    issue_remember_cookie((int)$row['id']);

    $newToken = issue_device_token((int)$row['id'], 'pwa');
    $_SESSION['pending_device_token'] = $newToken;

    $rotate = db()->prepare('UPDATE auth_device_tokens SET last_used_at = NOW(), expires_at = ? WHERE selector = ?');
    $rotate->execute([date('Y-m-d H:i:s', time() + device_token_lifetime()), $selector]);

    return $newToken;
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
    refresh_session_cookie();
    issue_remember_cookie((int)$row['id']);
}

restore_user_from_remember_cookie();

if (!empty($_SESSION['user'])) {
    refresh_session_cookie();
    $rememberRefreshedAt = (int)($_SESSION['remember_refreshed_at'] ?? 0);
    if ($rememberRefreshedAt < (time() - 3600)) {
        issue_remember_cookie((int)($_SESSION['user']['id'] ?? 0));
        $_SESSION['remember_refreshed_at'] = time();
    }
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
        refresh_session_cookie();
        unset($user['password_hash']);
        $_SESSION['user'] = $user;
        issue_remember_cookie((int)$user['id']);
        $_SESSION['pending_device_token'] = issue_device_token((int)$user['id'], 'pwa');
        $_SESSION['remember_refreshed_at'] = time();
        return true;
    }
    return false;
}

function auth_logout(): void {
    $userId = (int)($_SESSION['user']['id'] ?? 0);
    if ($userId > 0) {
        delete_remember_tokens_for_user($userId);
        delete_device_tokens_for_user($userId);
    }
    clear_remember_cookie();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        $opts = [
            'expires'  => time() - 42000,
            'path'     => '/',
            'secure'   => (bool)$p['secure'],
            'httponly' => (bool)$p['httponly'],
            'samesite' => $p['samesite'] ?: 'Lax',
        ];
        if (!empty($p['domain'])) {
            $opts['domain'] = $p['domain'];
        }
        setcookie(session_name(), '', $opts);
    }
    session_destroy();
}
