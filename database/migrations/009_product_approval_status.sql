-- ============================================================
-- Migration 009: Product Approval Status & Admin Review Workflow
-- Persists approval statuses: draft, pending_review, approved, rejected, published
-- Persists review notes, reviewed_by, reviewed_at for seller feedback
-- ============================================================

USE `techkart_db`;

-- Alter products status enum and add review feedback columns
ALTER TABLE `products`
    MODIFY COLUMN `status` ENUM('draft', 'pending_review', 'approved', 'rejected', 'published', 'archived') NOT NULL DEFAULT 'pending_review',
    ADD COLUMN `review_notes` TEXT NULL AFTER `status`,
    ADD COLUMN `reviewed_by` BIGINT UNSIGNED NULL AFTER `review_notes`,
    ADD COLUMN `reviewed_at` DATETIME NULL AFTER `reviewed_by`,
    ADD INDEX `idx_prod_status` (`status`),
    ADD CONSTRAINT `fk_product_reviewer` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;
