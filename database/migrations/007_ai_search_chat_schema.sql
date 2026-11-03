-- =========================================================================
-- TechKart Migration 007: AI Chat & Visual Search Persistence
-- =========================================================================

CREATE TABLE IF NOT EXISTS `ai_chat_sessions` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `session_id` VARCHAR(64) NOT NULL UNIQUE,
    `user_id` BIGINT UNSIGNED NULL,
    `title` VARCHAR(255) NOT NULL,
    `category` VARCHAR(50) NOT NULL DEFAULT 'Recommendations',
    `language` VARCHAR(20) NOT NULL DEFAULT 'en',
    `message_count` INT NOT NULL DEFAULT 0,
    `last_message` TEXT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_ai_chat_user` (`user_id`),
    INDEX `idx_ai_chat_category` (`category`),
    INDEX `idx_ai_chat_session_id` (`session_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ai_chat_messages` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `session_id` VARCHAR(64) NOT NULL,
    `sender` ENUM('user', 'assistant') NOT NULL,
    `message` TEXT NOT NULL,
    `metadata` LONGTEXT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_ai_msg_session` (`session_id`),
    FOREIGN KEY (`session_id`) REFERENCES `ai_chat_sessions`(`session_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ai_visual_searches` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` BIGINT UNSIGNED NULL,
    `query_image_path` VARCHAR(255) NULL,
    `detected_category` VARCHAR(100) NULL,
    `detected_attributes` LONGTEXT NULL,
    `matched_product_id` BIGINT UNSIGNED NULL,
    `match_confidence` DECIMAL(5,2) NOT NULL DEFAULT 95.00,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_visual_user` (`user_id`),
    INDEX `idx_visual_matched_prod` (`matched_product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
