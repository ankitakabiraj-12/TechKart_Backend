-- Migration 015: Add gender column to users table (MySQL 8+ / MariaDB compatible)
USE `techkart_db`;

SET @gender_column_exists = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'users'
      AND COLUMN_NAME = 'gender'
);

SET @gender_sql = IF(
    @gender_column_exists = 0,
    'ALTER TABLE `users` ADD COLUMN `gender` ENUM(''male'', ''female'', ''other'') NULL AFTER `phone`',
    'SELECT 1'
);

PREPARE gender_stmt FROM @gender_sql;
EXECUTE gender_stmt;
DEALLOCATE PREPARE gender_stmt;
