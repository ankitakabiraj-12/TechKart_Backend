-- ============================================================
-- Migration 008: Buddy Seller Applications, Escrow, Bank & Audit Logs
-- Supports realistic demo records and live persistence for Seller/Admin
-- ============================================================

USE `techkart_db`;

-- 1. Seller Applications Table
CREATE TABLE IF NOT EXISTS `seller_applications` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `app_number` VARCHAR(50) NOT NULL UNIQUE,
    `business_name` VARCHAR(150) NOT NULL,
    `applicant_name` VARCHAR(120) NOT NULL,
    `email` VARCHAR(191) NOT NULL,
    `phone` VARCHAR(25) NOT NULL,
    `category` VARCHAR(100) NOT NULL DEFAULT 'Electronics & Gadgets',
    `trade_license` VARCHAR(100) NULL,
    `tin_number` VARCHAR(100) NULL,
    `status` ENUM('pending', 'under_review', 'escalated', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
    `sla_deadline` DATETIME NULL,
    `notes` TEXT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_app_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Audit Logs Table (Admin & System security tracking)
CREATE TABLE IF NOT EXISTS `audit_logs` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `actor_id` BIGINT UNSIGNED NULL,
    `actor_role` VARCHAR(50) NOT NULL DEFAULT 'admin',
    `actor_name` VARCHAR(120) NOT NULL DEFAULT 'System Admin',
    `action` VARCHAR(100) NOT NULL,
    `target_type` VARCHAR(50) NOT NULL,
    `target_id` VARCHAR(100) NULL,
    `ip_address` VARCHAR(45) NOT NULL DEFAULT '127.0.0.1',
    `details` JSON NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_audit_actor` (`actor_id`),
    INDEX `idx_audit_action` (`action`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Seller Financial Settlement & Bank Records
CREATE TABLE IF NOT EXISTS `seller_settlements` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `seller_id` BIGINT UNSIGNED NOT NULL,
    `settlement_ref` VARCHAR(50) NOT NULL UNIQUE,
    `period_start` DATE NOT NULL,
    `period_end` DATE NOT NULL,
    `gross_sales` DECIMAL(12, 2) NOT NULL DEFAULT 0.00,
    `platform_commission` DECIMAL(12, 2) NOT NULL DEFAULT 0.00,
    `payment_processing_fee` DECIMAL(12, 2) NOT NULL DEFAULT 0.00,
    `net_payout` DECIMAL(12, 2) NOT NULL DEFAULT 0.00,
    `bank_name` VARCHAR(100) NOT NULL DEFAULT 'BRAC Bank',
    `bank_account_masked` VARCHAR(50) NOT NULL DEFAULT 'BRAC Bank ●●●● 4921',
    `status` ENUM('pending', 'processing', 'completed', 'held') NOT NULL DEFAULT 'completed',
    `payout_date` DATETIME NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_settlement_seller` FOREIGN KEY (`seller_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
