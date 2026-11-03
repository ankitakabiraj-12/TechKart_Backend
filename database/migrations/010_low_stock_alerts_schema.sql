-- ============================================================
-- Migration 010: Low-Stock Thresholds & Persistent Inventory Alerts
-- Supports seller-scoped low-stock thresholds, alert logs, read state,
-- and dashboard notification badges.
-- ============================================================

USE `techkart_db`;

-- 1. Add low_stock_threshold to products table (default 5)
ALTER TABLE `products`
    ADD COLUMN `low_stock_threshold` INT NOT NULL DEFAULT 5 AFTER `stock_quantity`;

-- 2. Inventory Alerts Table
CREATE TABLE IF NOT EXISTS `inventory_alerts` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `seller_id` BIGINT UNSIGNED NOT NULL,
    `product_id` BIGINT UNSIGNED NOT NULL,
    `alert_type` ENUM('low_stock', 'out_of_stock', 'restock_recommendation') NOT NULL DEFAULT 'low_stock',
    `severity` ENUM('warning', 'critical') NOT NULL DEFAULT 'warning',
    `current_stock` INT NOT NULL DEFAULT 0,
    `threshold` INT NOT NULL DEFAULT 5,
    `title` VARCHAR(150) NOT NULL,
    `message` TEXT NOT NULL,
    `is_read` TINYINT(1) NOT NULL DEFAULT 0,
    `read_at` DATETIME NULL,
    `is_dismissed` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT `fk_invalert_seller` FOREIGN KEY (`seller_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_invalert_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE,
    INDEX `idx_invalert_seller_read` (`seller_id`, `is_read`, `is_dismissed`),
    INDEX `idx_invalert_product` (`product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
