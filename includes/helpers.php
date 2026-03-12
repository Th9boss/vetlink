<?php
// includes/helpers.php
require_once __DIR__ . '/../config/env.php';

function h($v): string {
  return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function redirect(string $url): void {
    header('Location: ' . $url);
    exit;
}

function base_path(): string {
    $base = trim((string)BASE_URL);
    if ($base === '') {
        return '/';
    }

    if (preg_match('#^https?://#i', $base)) {
        $path = (string)parse_url($base, PHP_URL_PATH);
        $base = $path !== '' ? $path : '/';
    } elseif ($base[0] !== '/') {
        $slashPos = strpos($base, '/');
        $base = $slashPos === false ? '/' : '/' . ltrim(substr($base, $slashPos), '/');
    }

    $base = '/' . trim($base, '/');
    return $base === '/' ? '/' : $base . '/';
}

function base_url(string $path = ''): string {
    $base = base_path();
    if ($path === '') {
        return $base;
    }
    return $base . ltrim($path, '/');
}

function app_origin(): string {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['SERVER_PORT'] ?? '') === '443')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $scheme = $https ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return $scheme . '://' . $host;
}

function absolute_url(string $path = ''): string {
    return app_origin() . base_url($path);
}

function asset_url(string $path): string {
    $relative = ltrim($path, '/');
    $file = dirname(__DIR__) . '/' . $relative;
    $url = base_url($relative);
    $version = is_file($file) ? (string)filemtime($file) : (string)time();
    return $url . (str_contains($url, '?') ? '&' : '?') . 'v=' . rawurlencode($version);
}

// ===== CSRF =====
function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_input(): string {
    return '<input type="hidden" name="csrf_token" value="'.h(csrf_token()).'">';
}

function csrf_is_valid(): bool {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        return true;
    }

    return isset($_POST['csrf_token'], $_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $_POST['csrf_token']);
}

function csrf_check(): void {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!csrf_is_valid()) {
            http_response_code(403);
            die('CSRF token invalide.');
        }
    }
}

// ===== JSON API =====
function json_response($data, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// Pagination util simple
function paginate_params(int $defaultLimit = 20, int $maxLimit = 100): array {
    $page  = max(1, (int)($_GET['p'] ?? 1));
    $limit = min($maxLimit, max(1, (int)($_GET['limit'] ?? $defaultLimit)));
    $offset = ($page - 1) * $limit;
    return [$limit, $offset, $page];
}
if (!function_exists('e')) {
    /**
     * Echappe une chaîne pour affichage HTML (sécurité XSS).
     */
    function e(?string $str): string {
        return htmlspecialchars((string)$str, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
