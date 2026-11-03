-- ============================================================
-- Migration 013: Device Tokens for FCM & Push Notifications
-- Supports user ownership, unique FCM tokens, platform, active status,
-- last used timestamp, and query performance indexes.
-- Idempotent creation preserving existing schema.
-- ============================================================

USE `techkart_db`;

CREATE TABLE IF NOT EXISTS `device_tokens` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` BIGINT UNSIGNED NOT NULL,
    `fcm_token` VARCHAR(255) NOT NULL,
    `platform` ENUM('android', 'ios', 'web') NOT NULL DEFAULT 'android',
    `device_id` VARCHAR(191) NULL,
    `device_model` VARCHAR(100) NULL,
    `app_version` VARCHAR(50) NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `last_used_at` DATETIME NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT `fk_device_tokens_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    UNIQUE KEY `uniq_device_tokens_fcm` (`fcm_token`),
    INDEX `idx_device_tokens_user_active` (`user_id`, `is_active`),
    INDEX `idx_device_tokens_platform` (`platform`),
    INDEX `idx_device_tokens_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
