-- ============================================================
-- Migration 005: Customer Product Reviews & Helpful Ratings Schema
-- Supports authenticated review submission, verified purchase badge,
-- duplicate prevention, edit/delete, helpful upvotes, and rating summaries
-- ============================================================

USE `techkart_db`;

-- 1. Product Reviews Table
CREATE TABLE IF NOT EXISTS `product_reviews` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `product_id` BIGINT UNSIGNED NOT NULL,
    `customer_id` BIGINT UNSIGNED NOT NULL,
    `rating` TINYINT UNSIGNED NOT NULL,
    `title` VARCHAR(255) NOT NULL,
    `review_text` TEXT NOT NULL,
    `standout_tags` JSON NULL,
    `photos` JSON NULL,
    `variant_text` VARCHAR(150) NULL,
    `would_recommend` TINYINT(1) NOT NULL DEFAULT 1,
    `is_verified_purchase` TINYINT(1) NOT NULL DEFAULT 0,
    `helpful_count` INT UNSIGNED NOT NULL DEFAULT 0,
    `status` ENUM('approved', 'pending', 'rejected') NOT NULL DEFAULT 'approved',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT `fk_reviews_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_reviews_customer` FOREIGN KEY (`customer_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    UNIQUE KEY `uniq_product_customer_review` (`product_id`, `customer_id`),
    INDEX `idx_reviews_product_status` (`product_id`, `status`),
    INDEX `idx_reviews_rating` (`rating`),
    INDEX `idx_reviews_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Review Helpful Votes Tracking Table
CREATE TABLE IF NOT EXISTS `review_helpful_votes` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `review_id` BIGINT UNSIGNED NOT NULL,
    `customer_id` BIGINT UNSIGNED NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_votes_review` FOREIGN KEY (`review_id`) REFERENCES `product_reviews` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_votes_customer` FOREIGN KEY (`customer_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    UNIQUE KEY `uniq_review_customer_vote` (`review_id`, `customer_id`),
    INDEX `idx_votes_review` (`review_id`),
    INDEX `idx_votes_customer` (`customer_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
