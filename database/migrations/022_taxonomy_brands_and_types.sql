-- ==============================================================================
-- TechKart Migration 022: Taxonomy Subcategory Brands & Product Types
-- Links subcategories to relevant brands, product types, and product families
-- ==============================================================================

USE `techkart_db`;

-- 1. Product Types / Verticals Table
CREATE TABLE IF NOT EXISTS `product_types` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `subcategory_id` INT UNSIGNED NOT NULL,
    `type_name` VARCHAR(150) NOT NULL,
    `type_slug` VARCHAR(150) NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_pt_subcategory` FOREIGN KEY (`subcategory_id`) REFERENCES `categories` (`id`) ON DELETE CASCADE,
    UNIQUE KEY `uniq_subcat_type` (`subcategory_id`, `type_slug`),
    INDEX `idx_pt_subcat` (`subcategory_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Subcategory Brands Association Table
CREATE TABLE IF NOT EXISTS `subcategory_brands` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `subcategory_id` INT UNSIGNED NOT NULL,
    `brand_id` INT UNSIGNED NOT NULL,
    `product_types` TEXT NULL,
    `product_families` TEXT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_scb_subcategory` FOREIGN KEY (`subcategory_id`) REFERENCES `categories` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_scb_brand` FOREIGN KEY (`brand_id`) REFERENCES `brands` (`id`) ON DELETE CASCADE,
    UNIQUE KEY `uniq_subcat_brand` (`subcategory_id`, `brand_id`),
    INDEX `idx_scb_subcat` (`subcategory_id`),
    INDEX `idx_scb_brand` (`brand_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Enhance master_products to support product_type_id and product_family
SET @col_exists = 0;
SELECT COUNT(*) INTO @col_exists FROM information_schema.columns WHERE table_schema = 'techkart_db' AND table_name = 'master_products' AND column_name = 'product_type_id';
SET @query = IF(@col_exists = 0, 'ALTER TABLE `master_products` ADD COLUMN `product_type_id` INT UNSIGNED NULL AFTER `subcategory_id`, ADD COLUMN `product_family` VARCHAR(150) NULL AFTER `product_type_id`, ADD COLUMN `product_series` VARCHAR(150) NULL AFTER `product_family`, ADD CONSTRAINT `fk_master_product_type` FOREIGN KEY (`product_type_id`) REFERENCES `product_types` (`id`) ON DELETE SET NULL', 'SELECT 1');
PREPARE stmt FROM @query;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 4. Enhance products table to support product_type_id and product_family
SET @col_exists = 0;
SELECT COUNT(*) INTO @col_exists FROM information_schema.columns WHERE table_schema = 'techkart_db' AND table_name = 'products' AND column_name = 'product_type_id';
SET @query = IF(@col_exists = 0, 'ALTER TABLE `products` ADD COLUMN `product_type_id` INT UNSIGNED NULL AFTER `brand_id`, ADD COLUMN `product_family` VARCHAR(150) NULL AFTER `product_type_id`, ADD COLUMN `product_series` VARCHAR(150) NULL AFTER `product_family`', 'SELECT 1');
PREPARE stmt FROM @query;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
