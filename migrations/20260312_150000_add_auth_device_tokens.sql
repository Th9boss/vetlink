CREATE TABLE IF NOT EXISTS `auth_device_tokens` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `selector` VARCHAR(24) NOT NULL,
  `token_hash` CHAR(64) NOT NULL,
  `device_label` VARCHAR(120) NULL DEFAULT NULL,
  `user_agent` VARCHAR(255) NULL DEFAULT NULL,
  `expires_at` DATETIME NOT NULL,
  `last_used_at` DATETIME NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_auth_device_selector` (`selector`),
  KEY `idx_auth_device_user_id` (`user_id`),
  KEY `idx_auth_device_expires_at` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
