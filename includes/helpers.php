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

function csrf_check(): void {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $ok = isset($_POST['csrf_token'], $_SESSION['csrf_token']) &&
              hash_equals($_SESSION['csrf_token'], $_POST['csrf_token']);
        if (!$ok) {
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
    $page  = max(1, (int)($_GET['page']  ?? 1));
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