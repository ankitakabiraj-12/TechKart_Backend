-- Migration 017: Registration OTP staging table
USE `techkart_db`;

CREATE TABLE IF NOT EXISTS `registration_otps` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `full_name` VARCHAR(191) NOT NULL,
    `email` VARCHAR(191) NOT NULL,
    `phone` VARCHAR(30) NULL,
    `gender` ENUM('male', 'female', 'other') NULL,
    `password_hash` VARCHAR(255) NOT NULL,
    `otp_hash` CHAR(64) NOT NULL,
    `expires_at` DATETIME NOT NULL,
    `verified_at` DATETIME NULL,
    `consumed_at` DATETIME NULL,
    `attempts` TINYINT UNSIGNED NOT NULL DEFAULT 0,
    `request_ip` VARCHAR(45) NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_reg_otp_email_created` (`email`, `created_at`),
    INDEX `idx_reg_otp_phone_created` (`phone`, `created_at`),
    INDEX `idx_reg_otp_consumed` (`consumed_at`, `expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
