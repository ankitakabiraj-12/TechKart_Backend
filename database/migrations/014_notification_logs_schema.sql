-- ============================================================
-- Migration 014: Notification Logs & Push Deduplication Ledger
-- Tracks targeted push alerts dispatched to users (orders, returns, payouts).
-- Enforces event-level idempotency via unique composite key:
-- (user_id, event_type, entity_type, entity_id).
-- ============================================================

USE `techkart_db`;

CREATE TABLE IF NOT EXISTS `notification_logs` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` BIGINT UNSIGNED NOT NULL,
    `event_type` VARCHAR(50) NOT NULL,
    `entity_type` VARCHAR(50) NOT NULL,
    `entity_id` VARCHAR(100) NOT NULL,
    `title` VARCHAR(191) NOT NULL,
    `body` TEXT NOT NULL,
    `fcm_status` VARCHAR(50) NOT NULL DEFAULT 'dispatched',
    `fcm_message_id` VARCHAR(255) NULL,
    `error_message` TEXT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_notif_log_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    UNIQUE KEY `uniq_notif_event` (`user_id`, `event_type`, `entity_type`, `entity_id`),
    INDEX `idx_notif_user` (`user_id`),
    INDEX `idx_notif_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
