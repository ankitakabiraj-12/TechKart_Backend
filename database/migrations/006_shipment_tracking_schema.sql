-- ============================================================
-- Migration 006: Live Order Tracking, Courier Updates & Shipment Events
-- ============================================================

USE `techkart_db`;

-- 1. Alter orders table to store primary courier & tracking metadata
ALTER TABLE `orders`
    ADD COLUMN IF NOT EXISTS `tracking_number` VARCHAR(100) NULL AFTER `order_status`,
    ADD COLUMN IF NOT EXISTS `courier_name` VARCHAR(100) NULL DEFAULT 'Apex Courier Express' AFTER `tracking_number`,
    ADD COLUMN IF NOT EXISTS `courier_phone` VARCHAR(30) NULL DEFAULT '+880 1700-112233' AFTER `courier_name`,
    ADD COLUMN IF NOT EXISTS `estimated_delivery` VARCHAR(100) NULL AFTER `courier_phone`,
    ADD COLUMN IF NOT EXISTS `estimated_time` VARCHAR(100) NULL AFTER `estimated_delivery`,
    ADD COLUMN IF NOT EXISTS `current_location` VARCHAR(255) NULL AFTER `estimated_time`,
    ADD COLUMN IF NOT EXISTS `next_stop` VARCHAR(255) NULL AFTER `current_location`;

-- Add index on tracking_number if not already exists
SET @exist := (SELECT COUNT(*) FROM information_schema.statistics
               WHERE table_schema = DATABASE()
                 AND table_name = 'orders'
                 AND index_name = 'idx_orders_tracking_number');
SET @sqlstmt := IF(@exist = 0, 'ALTER TABLE `orders` ADD INDEX `idx_orders_tracking_number` (`tracking_number`)', 'SELECT "Index already exists"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 2. Create shipment_events table for persisted real-time timeline checkpoints
CREATE TABLE IF NOT EXISTS `shipment_events` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `order_id` BIGINT UNSIGNED NOT NULL,
    `status` VARCHAR(50) NOT NULL,
    `title` VARCHAR(255) NOT NULL,
    `description` TEXT NOT NULL,
    `location` VARCHAR(255) NULL,
    `tag_label` VARCHAR(100) NULL,
    `actor_role` ENUM('system', 'seller', 'admin', 'courier') NOT NULL DEFAULT 'system',
    `actor_id` BIGINT UNSIGNED NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_shipment_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE,
    INDEX `idx_shipment_order_created` (`order_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
