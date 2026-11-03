-- Migration 016: Password reset OTP storage and password-change session invalidation
USE `techkart_db`;

SET @password_changed_column_exists = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'users'
      AND COLUMN_NAME = 'password_changed_at'
);

SET @password_changed_sql = IF(
    @password_changed_column_exists = 0,
    'ALTER TABLE `users` ADD COLUMN `password_changed_at` DATETIME NULL AFTER `password_hash`',
    'SELECT 1'
);

PREPARE password_changed_stmt FROM @password_changed_sql;
EXECUTE password_changed_stmt;
DEALLOCATE PREPARE password_changed_stmt;

CREATE TABLE IF NOT EXISTS `password_reset_otps` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` BIGINT UNSIGNED NOT NULL,
    `email` VARCHAR(191) NOT NULL,
    `otp_hash` CHAR(64) NOT NULL,
    `expires_at` DATETIME NOT NULL,
    `verified_at` DATETIME NULL,
    `reset_token_hash` CHAR(64) NULL,
    `reset_token_expires_at` DATETIME NULL,
    `consumed_at` DATETIME NULL,
    `attempts` TINYINT UNSIGNED NOT NULL DEFAULT 0,
    `request_ip` VARCHAR(45) NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_password_reset_email_created` (`email`, `created_at`),
    INDEX `idx_password_reset_user_consumed` (`user_id`, `consumed_at`),
    INDEX `idx_password_reset_token` (`reset_token_hash`),
    CONSTRAINT `fk_password_reset_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
