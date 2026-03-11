<?php
// config/env.php — chargement de la configuration depuis .env

(function () {
    $envFile = dirname(__DIR__) . '/.env';
    if (!is_file($envFile)) {
        die('Fichier .env introuvable. Copiez .env.example en .env et configurez-le.');
    }
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        if (!str_contains($line, '='))          continue;
        [$key, $val] = explode('=', $line, 2);
        $key = trim($key);
        $val = trim($val, " \t\"'");
        if ($key !== '' && !array_key_exists($key, $_ENV)) {
            $_ENV[$key] = $val;
        }
    }
})();

// ── Timezone ───────────────────────────────────────────────
date_default_timezone_set($_ENV['TZ'] ?? 'Africa/Casablanca');

// ── Mode application ───────────────────────────────────────
$_appEnv = $_ENV['APP_ENV'] ?? 'prod';
if (!defined('APP_ENV')) define('APP_ENV', $_appEnv);
$_debug = filter_var($_ENV['DEBUG'] ?? 'false', FILTER_VALIDATE_BOOLEAN);
if (!defined('DEBUG')) define('DEBUG', $_debug);

if ($_appEnv === 'dev' || $_debug) {
    ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', 0);
    ini_set('display_startup_errors', 0);
    error_reporting(0);
}

// ── Constantes applicatives ────────────────────────────────
if (!defined('SESSION_NAME'))  define('SESSION_NAME',  $_ENV['SESSION_NAME']  ?? 'VETLINKSESSID');
if (!defined('BASE_URL'))      define('BASE_URL',      $_ENV['BASE_URL']      ?? '/');
if (!defined('SITE_NAME'))     define('SITE_NAME',     $_ENV['SITE_NAME']     ?? 'VETLINK');
if (!defined('COOKIE_SECURE')) define('COOKIE_SECURE', filter_var($_ENV['COOKIE_SECURE'] ?? 'true', FILTER_VALIDATE_BOOLEAN));
if (!defined('AUTO_MIGRATE'))  define('AUTO_MIGRATE',  filter_var($_ENV['AUTO_MIGRATE'] ?? 'true', FILTER_VALIDATE_BOOLEAN));

// ── Base de données ────────────────────────────────────────
if (!defined('DB_HOST'))    define('DB_HOST',    $_ENV['DB_HOST']    ?? 'localhost');
if (!defined('DB_NAME'))    define('DB_NAME',    $_ENV['DB_NAME']    ?? '');
if (!defined('DB_USER'))    define('DB_USER',    $_ENV['DB_USER']    ?? '');
if (!defined('DB_PASS'))    define('DB_PASS',    $_ENV['DB_PASS']    ?? '');
if (!defined('DB_CHARSET')) define('DB_CHARSET', $_ENV['DB_CHARSET'] ?? 'utf8mb4');

// ── En-têtes de sécurité HTTP ──────────────────────────────
// Envoyés une seule fois, dès le boot, avant tout output.
if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('X-XSS-Protection: 1; mode=block');
    // CSP : autorise Bootstrap CDN + inline scripts/styles (requis par l'appli)
    header(
        "Content-Security-Policy: " .
        "default-src 'self'; " .
        "script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://cdn.tailwindcss.com https://unpkg.com; " .
        "style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://unpkg.com; " .
        "font-src 'self' https://cdn.jsdelivr.net; " .
        "img-src 'self' data: blob: https:; " .
        "connect-src 'self' https://cdn.jsdelivr.net https://unpkg.com; " .
        "frame-src 'self'; " .
        "object-src 'none';"
    );
}
