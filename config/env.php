<?php
// config/env.php — chargement de la configuration depuis .env

(function () {
    $root = dirname(__DIR__);
    $envFiles = [
        $root . '/.env',
        $root . '/.env.local',
    ];

    $loaded = false;
    foreach ($envFiles as $envFile) {
        if (!is_file($envFile)) {
            continue;
        }

        $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            continue;
        }

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') continue;
            if (!str_contains($line, '=')) continue;
            [$key, $val] = explode('=', $line, 2);
            $key = trim($key);
            $val = trim($val, " \t\"'");
            if ($key !== '') {
                $_ENV[$key] = $val;
            }
        }

        $loaded = true;
    }

    if (!$loaded) {
        die('Fichier .env introuvable. Copiez .env.example en .env ou créez .env.local et configurez-le.');
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
if (!defined('SESSION_LIFETIME')) define('SESSION_LIFETIME', max(0, (int)($_ENV['SESSION_LIFETIME'] ?? 315360000)));
if (!defined('REMEMBER_TOKEN_LIFETIME')) define('REMEMBER_TOKEN_LIFETIME', max(0, (int)($_ENV['REMEMBER_TOKEN_LIFETIME'] ?? (defined('SESSION_LIFETIME') ? SESSION_LIFETIME : 315360000))));
if (!defined('DEVICE_TOKEN_LIFETIME')) define('DEVICE_TOKEN_LIFETIME', max(0, (int)($_ENV['DEVICE_TOKEN_LIFETIME'] ?? (defined('SESSION_LIFETIME') ? SESSION_LIFETIME : 315360000))));
if (!defined('BASE_URL'))      define('BASE_URL',      $_ENV['BASE_URL']      ?? '/');
if (!defined('SITE_NAME'))     define('SITE_NAME',     $_ENV['SITE_NAME']     ?? 'VETLINK');
if (!defined('COOKIE_SECURE')) define('COOKIE_SECURE', filter_var($_ENV['COOKIE_SECURE'] ?? 'true', FILTER_VALIDATE_BOOLEAN));
if (!defined('AUTO_MIGRATE'))  define('AUTO_MIGRATE',  filter_var($_ENV['AUTO_MIGRATE'] ?? 'true', FILTER_VALIDATE_BOOLEAN));

// ── Base de données ────────────────────────────────────────
if (!defined('DB_HOST'))    define('DB_HOST',    $_ENV['DB_HOST']    ?? 'localhost');
if (!defined('DB_PORT'))    define('DB_PORT',    $_ENV['DB_PORT']    ?? '3306');
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
