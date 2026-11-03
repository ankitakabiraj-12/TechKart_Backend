-- ============================================================
-- Migration 011: Review Moderation Status & Seller Response Schema
-- Adds seller reply, reply timestamp, moderation state ('approved', 'pending', 'rejected', 'flagged', 'hidden'),
-- and administrator moderation metadata.
-- ============================================================

USE `techkart_db`;

-- 1. Extend product_reviews table
ALTER TABLE `product_reviews`
    MODIFY COLUMN `status` ENUM('approved', 'pending', 'rejected', 'flagged', 'hidden') NOT NULL DEFAULT 'approved',
    ADD COLUMN `seller_reply` TEXT NULL AFTER `review_text`,
    ADD COLUMN `replied_at` DATETIME NULL AFTER `seller_reply`,
    ADD COLUMN `moderated_by` BIGINT UNSIGNED NULL AFTER `replied_at`,
    ADD COLUMN `moderation_reason` VARCHAR(255) NULL AFTER `moderated_by`,
    ADD COLUMN `moderated_at` DATETIME NULL AFTER `moderation_reason`,
    ADD CONSTRAINT `fk_reviews_moderator` FOREIGN KEY (`moderated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;
