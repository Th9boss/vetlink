<?php
// includes/migrations.php

function migration_table_name(): string {
    return 'schema_migrations';
}

function migration_files_dir(): string {
    return dirname(__DIR__) . '/migrations';
}

function ensure_migrations_table(PDO $pdo): void {
    $sql = "CREATE TABLE IF NOT EXISTS `" . migration_table_name() . "` (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `migration` VARCHAR(190) NOT NULL,
        `applied_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uniq_migration` (`migration`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    $pdo->exec($sql);
}

function applied_migrations(PDO $pdo): array {
    $stmt = $pdo->query("SELECT migration FROM `" . migration_table_name() . "` ORDER BY migration ASC");
    $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_COLUMN) : [];
    return array_values(array_filter($rows, 'is_string'));
}

function migration_sql_files(): array {
    $dir = migration_files_dir();
    if (!is_dir($dir)) {
        return [];
    }

    $files = glob($dir . '/*.sql');
    if ($files === false) {
        return [];
    }

    sort($files, SORT_STRING);
    return $files;
}

function apply_sql_migration(PDO $pdo, string $file, string $name): void {
    $sql = file_get_contents($file);
    if ($sql === false) {
        throw new RuntimeException("Impossible de lire la migration: $name");
    }

    $sql = trim($sql);

    $pdo->beginTransaction();
    try {
        if ($sql !== '') {
            $pdo->exec($sql);
        }

        $stmt = $pdo->prepare("INSERT INTO `" . migration_table_name() . "` (migration) VALUES (?)");
        $stmt->execute([$name]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function pending_migration_names(PDO $pdo): array {
    ensure_migrations_table($pdo);
    $applied = array_flip(applied_migrations($pdo));
    $pending = [];

    foreach (migration_sql_files() as $file) {
        $name = basename($file);
        if (!isset($applied[$name])) {
            $pending[] = $name;
        }
    }

    return $pending;
}

function run_pending_migrations(PDO $pdo): array {
    static $ran = false;
    if ($ran) {
        return [];
    }
    $ran = true;

    ensure_migrations_table($pdo);

    $applied = array_flip(applied_migrations($pdo));
    $executed = [];

    foreach (migration_sql_files() as $file) {
        $name = basename($file);
        if (isset($applied[$name])) {
            continue;
        }
        apply_sql_migration($pdo, $file, $name);
        $executed[] = $name;
    }

    return $executed;
}
