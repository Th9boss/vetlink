<?php
// includes/db.php
require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/migrations.php';

function db_connect_pdo(array $options = []): PDO {
    $hosts = [DB_HOST];
    if (DB_HOST === 'localhost') {
        $hosts[] = '127.0.0.1';
    }

    $last = null;
    foreach (array_values(array_unique($hosts)) as $host) {
        $dsn = 'mysql:host=' . $host . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
        try {
            return new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            $last = $e;
        }
    }

    throw $last ?? new PDOException('DB connection failed.');
}

function db(): PDO {
    static $pdo = null;
    if ($pdo !== null) return $pdo;

    $options = [
        PDO::ATTR_ERRMODE            => (APP_ENV === 'dev' ? PDO::ERRMODE_EXCEPTION : PDO::ERRMODE_SILENT),
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];

    try {
        $pdo = db_connect_pdo($options);
        if (AUTO_MIGRATE) {
            run_pending_migrations($pdo);
        }
        return $pdo;
    } catch (PDOException $e) {
        if (APP_ENV === 'dev' || (defined('DEBUG') && DEBUG)) {
            die('DB connection failed: ' . $e->getMessage());
        }
        http_response_code(500);
        die('Service indisponible.');
    }
}
