<?php
// includes/db.php
require_once __DIR__ . '/../config/env.php';

function db(): PDO {
    static $pdo = null;
    if ($pdo !== null) return $pdo;

    $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
    $options = [
        PDO::ATTR_ERRMODE            => (APP_ENV === 'dev' ? PDO::ERRMODE_EXCEPTION : PDO::ERRMODE_SILENT),
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];

    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        return $pdo;
    } catch (PDOException $e) {
        if (APP_ENV === 'dev') {
            die('DB connection failed: ' . $e->getMessage());
        }
        http_response_code(500);
        die('Service indisponible.');
    }
}
