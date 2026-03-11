<?php
declare(strict_types=1);

require_once __DIR__ . '/config/env.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/migrations.php';

try {
    $pdo = db_connect_pdo([
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $pendingBefore = pending_migration_names($pdo);
    $executed = run_pending_migrations($pdo);
    $pendingAfter = pending_migration_names($pdo);

    echo "AUTO_MIGRATE=" . (AUTO_MIGRATE ? 'true' : 'false') . PHP_EOL;
    echo "Pending before: " . count($pendingBefore) . PHP_EOL;

    if ($executed) {
        echo "Applied migrations:" . PHP_EOL;
        foreach ($executed as $name) {
            echo " - " . $name . PHP_EOL;
        }
    } else {
        echo "No migrations were applied." . PHP_EOL;
    }

    echo "Pending after: " . count($pendingAfter) . PHP_EOL;
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, '[up.php] Migration failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
