-- ============================================================
-- Migration 019: Published At Timestamp & Auto-Approval Setting
-- Supports accurate "NEW" badge generation and sorting
-- ============================================================

USE `techkart_db`;

ALTER TABLE `products`
    ADD COLUMN `published_at` DATETIME NULL AFTER `status`,
    ADD INDEX `idx_prod_published_at` (`published_at`);

-- Populate published_at for existing published products based on created_at
UPDATE `products` SET `published_at` = `created_at` WHERE `status` = 'published' AND `published_at` IS NULL;
