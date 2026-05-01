USE `asct`;

ALTER TABLE `users`
  ADD COLUMN `failed_login_attempts` TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER `requested_student_id`;

ALTER TABLE `users`
  ADD COLUMN `locked_until` DATETIME NULL AFTER `failed_login_attempts`;

ALTER TABLE `users`
  ADD INDEX `users_locked_until_index` (`locked_until`);

CREATE TABLE IF NOT EXISTS `login_rate_limits` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `scope` VARCHAR(80) NOT NULL,
  `rate_limit_key` CHAR(64) NOT NULL,
  `attempts` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `window_started_at` DATETIME NOT NULL,
  `blocked_until` DATETIME NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `login_rate_limits_scope_key_unique` (`scope`, `rate_limit_key`),
  KEY `login_rate_limits_blocked_until_index` (`blocked_until`),
  KEY `login_rate_limits_updated_at_index` (`updated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
