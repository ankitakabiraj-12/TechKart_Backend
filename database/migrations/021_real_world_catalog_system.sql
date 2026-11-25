-- ==============================================================================
-- TechKart Migration 021: Real-World Electronics Marketplace Transformation
-- Master Catalog, Brand, Variants, Specifications & Seller Product Listings
-- ==============================================================================

USE `techkart_db`;

-- 1. Brands Table
CREATE TABLE IF NOT EXISTS `brands` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `brand_name` VARCHAR(100) NOT NULL UNIQUE,
    `brand_slug` VARCHAR(120) NOT NULL UNIQUE,
    `official_website` VARCHAR(255) NULL,
    `brand_logo` VARCHAR(255) NULL,
    `verification_status` ENUM('verified', 'unverified', 'pending') NOT NULL DEFAULT 'verified',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_brand_slug` (`brand_slug`),
    INDEX `idx_brand_status` (`verification_status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Master Products Table
CREATE TABLE IF NOT EXISTS `master_products` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `brand_id` INT UNSIGNED NULL,
    `category_id` INT UNSIGNED NOT NULL,
    `subcategory_id` INT UNSIGNED NULL,
    `product_name` VARCHAR(255) NOT NULL,
    `product_slug` VARCHAR(255) NOT NULL UNIQUE,
    `model_number` VARCHAR(100) NULL,
    `manufacturer` VARCHAR(150) NULL,
    `product_description` LONGTEXT NULL,
    `country_of_origin` VARCHAR(100) NULL DEFAULT 'India',
    `warranty_information` VARCHAR(255) NULL,
    `gtin_upc_ean` VARCHAR(50) NULL,
    `product_status` ENUM('active', 'inactive', 'draft', 'archived') NOT NULL DEFAULT 'active',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT `fk_master_brand` FOREIGN KEY (`brand_id`) REFERENCES `brands` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_master_category` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_master_subcategory` FOREIGN KEY (`subcategory_id`) REFERENCES `categories` (`id`) ON DELETE SET NULL,
    INDEX `idx_master_prod_name` (`product_name`),
    INDEX `idx_master_model` (`model_number`),
    INDEX `idx_master_category` (`category_id`, `subcategory_id`),
    INDEX `idx_master_status` (`product_status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Product Variants Table
CREATE TABLE IF NOT EXISTS `product_variants` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `product_id` BIGINT UNSIGNED NOT NULL,
    `variant_name` VARCHAR(150) NOT NULL,
    `sku` VARCHAR(100) NOT NULL UNIQUE,
    `model_identifier` VARCHAR(100) NULL,
    `colour` VARCHAR(80) NULL,
    `ram` VARCHAR(50) NULL,
    `storage` VARCHAR(50) NULL,
    `configuration` VARCHAR(255) NULL,
    `variant_attributes` JSON NULL,
    `variant_status` ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT `fk_variant_product` FOREIGN KEY (`product_id`) REFERENCES `master_products` (`id`) ON DELETE CASCADE,
    INDEX `idx_variant_product` (`product_id`),
    INDEX `idx_variant_sku` (`sku`),
    INDEX `idx_variant_status` (`variant_status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Category Attributes Table (Specification Definition Engine)
CREATE TABLE IF NOT EXISTS `category_attributes` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `category_id` INT UNSIGNED NOT NULL,
    `subcategory_id` INT UNSIGNED NULL,
    `attribute_name` VARCHAR(100) NOT NULL,
    `attribute_key` VARCHAR(80) NOT NULL,
    `data_type` ENUM('text', 'select', 'number', 'boolean') NOT NULL DEFAULT 'text',
    `unit` VARCHAR(30) NULL,
    `is_required` TINYINT(1) NOT NULL DEFAULT 0,
    `display_order` INT UNSIGNED NOT NULL DEFAULT 1,
    `options_json` JSON NULL,
    `validation_rules` VARCHAR(255) NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_attr_category` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_attr_subcategory` FOREIGN KEY (`subcategory_id`) REFERENCES `categories` (`id`) ON DELETE SET NULL,
    UNIQUE KEY `uniq_cat_attr_key` (`category_id`, `subcategory_id`, `attribute_key`),
    INDEX `idx_attr_cat` (`category_id`, `display_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. Product Specifications Table
CREATE TABLE IF NOT EXISTS `product_specifications` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `product_id` BIGINT UNSIGNED NOT NULL,
    `variant_id` BIGINT UNSIGNED NULL,
    `attribute_id` INT UNSIGNED NOT NULL,
    `attribute_value` TEXT NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT `fk_spec_product` FOREIGN KEY (`product_id`) REFERENCES `master_products` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_spec_variant` FOREIGN KEY (`variant_id`) REFERENCES `product_variants` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_spec_attribute` FOREIGN KEY (`attribute_id`) REFERENCES `category_attributes` (`id`) ON DELETE CASCADE,
    INDEX `idx_spec_product` (`product_id`, `variant_id`),
    INDEX `idx_spec_attr` (`attribute_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. Seller Product Listings Table
CREATE TABLE IF NOT EXISTS `seller_product_listings` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `seller_id` BIGINT UNSIGNED NOT NULL,
    `product_id` BIGINT UNSIGNED NOT NULL,
    `variant_id` BIGINT UNSIGNED NOT NULL,
    `seller_sku` VARCHAR(100) NOT NULL,
    `mrp` DECIMAL(10, 2) NOT NULL,
    `selling_price` DECIMAL(10, 2) NOT NULL,
    `discount_amount` DECIMAL(10, 2) GENERATED ALWAYS AS (GREATEST(0, `mrp` - `selling_price`)) STORED,
    `discount_percentage` DECIMAL(5, 2) GENERATED ALWAYS AS (IF(`mrp` > 0, ROUND((GREATEST(0, `mrp` - `selling_price`) / `mrp`) * 100, 2), 0)) STORED,
    `stock_quantity` INT NOT NULL DEFAULT 0,
    `listing_status` ENUM('draft', 'submitted', 'under_review', 'approved', 'active', 'rejected', 'inactive', 'suppressed', 'out_of_stock') NOT NULL DEFAULT 'under_review',
    `dispatch_information` VARCHAR(255) NULL DEFAULT 'Dispatched in 24 hours via TechKart Express',
    `shipping_information` VARCHAR(255) NULL DEFAULT 'Free Express Delivery across India',
    `rejection_reason` TEXT NULL,
    `reviewed_by` BIGINT UNSIGNED NULL,
    `reviewed_at` DATETIME NULL,
    `published_at` DATETIME NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT `fk_listing_seller` FOREIGN KEY (`seller_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_listing_product` FOREIGN KEY (`product_id`) REFERENCES `master_products` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_listing_variant` FOREIGN KEY (`variant_id`) REFERENCES `product_variants` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_listing_reviewer` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
    UNIQUE KEY `uniq_seller_variant` (`seller_id`, `variant_id`),
    INDEX `idx_listing_status` (`listing_status`),
    INDEX `idx_listing_product_active` (`product_id`, `listing_status`, `selling_price`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7. Product Questions & Answers Table
CREATE TABLE IF NOT EXISTS `product_questions` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `product_id` BIGINT UNSIGNED NOT NULL,
    `customer_id` BIGINT UNSIGNED NOT NULL,
    `question` TEXT NOT NULL,
    `status` ENUM('approved', 'pending', 'rejected') NOT NULL DEFAULT 'approved',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_q_product` FOREIGN KEY (`product_id`) REFERENCES `master_products` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_q_customer` FOREIGN KEY (`customer_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    INDEX `idx_q_product` (`product_id`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `product_answers` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `question_id` BIGINT UNSIGNED NOT NULL,
    `user_id` BIGINT UNSIGNED NOT NULL,
    `is_seller_or_admin` TINYINT(1) NOT NULL DEFAULT 0,
    `answer` TEXT NOT NULL,
    `status` ENUM('approved', 'pending', 'rejected') NOT NULL DEFAULT 'approved',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_ans_question` FOREIGN KEY (`question_id`) REFERENCES `product_questions` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_ans_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    INDEX `idx_ans_question` (`question_id`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 8. Alter product_images, cart_items, wishlist_items, order_items to support master_product, variant_id, and listing_id safely
-- Use stored procedures / conditional alters to ensure idempotency

-- Check and add variant_id to product_images
SET @col_exists = 0;
SELECT COUNT(*) INTO @col_exists FROM information_schema.columns WHERE table_schema = 'techkart_db' AND table_name = 'product_images' AND column_name = 'variant_id';
SET @query = IF(@col_exists = 0, 'ALTER TABLE `product_images` ADD COLUMN `variant_id` BIGINT UNSIGNED NULL AFTER `product_id`, ADD COLUMN `image_type` ENUM(\'front\', \'back\', \'side\', \'angle\', \'lifestyle\') NULL DEFAULT \'front\', ADD COLUMN `image_source` VARCHAR(100) NULL DEFAULT \'official\', ADD COLUMN `verification_status` ENUM(\'verified\', \'pending\', \'rejected\') NOT NULL DEFAULT \'verified\', ADD CONSTRAINT `fk_img_variant` FOREIGN KEY (`variant_id`) REFERENCES `product_variants` (`id`) ON DELETE SET NULL', 'SELECT 1');
PREPARE stmt FROM @query;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Check and add variant_id & listing_id to cart_items
SET @col_exists = 0;
SELECT COUNT(*) INTO @col_exists FROM information_schema.columns WHERE table_schema = 'techkart_db' AND table_name = 'cart_items' AND column_name = 'variant_id';
SET @query = IF(@col_exists = 0, 'ALTER TABLE `cart_items` ADD COLUMN `variant_id` BIGINT UNSIGNED NULL AFTER `product_id`, ADD COLUMN `listing_id` BIGINT UNSIGNED NULL AFTER `variant_id`, ADD CONSTRAINT `fk_cart_variant` FOREIGN KEY (`variant_id`) REFERENCES `product_variants` (`id`) ON DELETE SET NULL, ADD CONSTRAINT `fk_cart_listing` FOREIGN KEY (`listing_id`) REFERENCES `seller_product_listings` (`id`) ON DELETE SET NULL', 'SELECT 1');
PREPARE stmt FROM @query;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Check and add variant_id to wishlist_items
SET @col_exists = 0;
SELECT COUNT(*) INTO @col_exists FROM information_schema.columns WHERE table_schema = 'techkart_db' AND table_name = 'wishlist_items' AND column_name = 'variant_id';
SET @query = IF(@col_exists = 0, 'ALTER TABLE `wishlist_items` ADD COLUMN `variant_id` BIGINT UNSIGNED NULL AFTER `product_id`, ADD CONSTRAINT `fk_wishlist_variant` FOREIGN KEY (`variant_id`) REFERENCES `product_variants` (`id`) ON DELETE SET NULL', 'SELECT 1');
PREPARE stmt FROM @query;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Check and add variant_id & listing_id to order_items
SET @col_exists = 0;
SELECT COUNT(*) INTO @col_exists FROM information_schema.columns WHERE table_schema = 'techkart_db' AND table_name = 'order_items' AND column_name = 'variant_id';
SET @query = IF(@col_exists = 0, 'ALTER TABLE `order_items` ADD COLUMN `variant_id` BIGINT UNSIGNED NULL AFTER `product_id`, ADD COLUMN `listing_id` BIGINT UNSIGNED NULL AFTER `variant_id`, ADD CONSTRAINT `fk_order_item_variant` FOREIGN KEY (`variant_id`) REFERENCES `product_variants` (`id`) ON DELETE SET NULL, ADD CONSTRAINT `fk_order_item_listing` FOREIGN KEY (`listing_id`) REFERENCES `seller_product_listings` (`id`) ON DELETE SET NULL', 'SELECT 1');
PREPARE stmt FROM @query;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Check and add master_product_id & variant_id to products (bridging table)
SET @col_exists = 0;
SELECT COUNT(*) INTO @col_exists FROM information_schema.columns WHERE table_schema = 'techkart_db' AND table_name = 'products' AND column_name = 'master_product_id';
SET @query = IF(@col_exists = 0, 'ALTER TABLE `products` ADD COLUMN `master_product_id` BIGINT UNSIGNED NULL AFTER `subcategory_id`, ADD COLUMN `variant_id` BIGINT UNSIGNED NULL AFTER `master_product_id`, ADD COLUMN `brand_id` INT UNSIGNED NULL AFTER `variant_id`, ADD CONSTRAINT `fk_products_master` FOREIGN KEY (`master_product_id`) REFERENCES `master_products` (`id`) ON DELETE SET NULL, ADD CONSTRAINT `fk_products_variant` FOREIGN KEY (`variant_id`) REFERENCES `product_variants` (`id`) ON DELETE SET NULL, ADD CONSTRAINT `fk_products_brand` FOREIGN KEY (`brand_id`) REFERENCES `brands` (`id`) ON DELETE SET NULL', 'SELECT 1');
PREPARE stmt FROM @query;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
