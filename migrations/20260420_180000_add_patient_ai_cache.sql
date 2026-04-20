SET @analyses_updated_at_exists := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'analyses'
    AND COLUMN_NAME = 'updated_at'
);
SET @analyses_sql := IF(
  @analyses_updated_at_exists = 0,
  'ALTER TABLE `analyses` ADD COLUMN `updated_at` DATETIME NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
  'SELECT 1'
);
PREPARE analyses_stmt FROM @analyses_sql;
EXECUTE analyses_stmt;
DEALLOCATE PREPARE analyses_stmt;

SET @imageries_updated_at_exists := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'imageries'
    AND COLUMN_NAME = 'updated_at'
);
SET @imageries_sql := IF(
  @imageries_updated_at_exists = 0,
  'ALTER TABLE `imageries` ADD COLUMN `updated_at` DATETIME NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
  'SELECT 1'
);
PREPARE imageries_stmt FROM @imageries_sql;
EXECUTE imageries_stmt;
DEALLOCATE PREPARE imageries_stmt;

UPDATE `analyses`
SET `updated_at` = COALESCE(`updated_at`, `created_at`, NOW())
WHERE `updated_at` IS NULL;

UPDATE `imageries`
SET `updated_at` = COALESCE(`updated_at`, `created_at`, NOW())
WHERE `updated_at` IS NULL;

CREATE TABLE IF NOT EXISTS `patient_ai_cache` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `patient_id` BIGINT UNSIGNED NOT NULL,
  `scope` ENUM('latest','history') NOT NULL,
  `source_stamp` DATETIME NOT NULL,
  `source_hash` CHAR(64) NOT NULL,
  `summary` MEDIUMTEXT NOT NULL,
  `provider` VARCHAR(50) NOT NULL DEFAULT 'deepseek',
  `model` VARCHAR(120) DEFAULT NULL,
  `cached_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `refreshed_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_patient_ai_cache_scope` (`patient_id`, `scope`),
  KEY `idx_patient_ai_cache_lookup` (`patient_id`, `scope`, `source_stamp`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
