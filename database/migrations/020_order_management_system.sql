-- ============================================================
-- Migration 020: Order Management System Optimization & Indexing
-- ============================================================

USE `techkart_db`;

-- 1. Ensure indexing on orders table for customer lookup, status filtering and created_at sorting
SET @exist := (SELECT COUNT(*) FROM information_schema.statistics
               WHERE table_schema = DATABASE()
                 AND table_name = 'orders'
                 AND index_name = 'idx_orders_customer_status_created');
SET @sqlstmt := IF(@exist = 0, 'ALTER TABLE `orders` ADD INDEX `idx_orders_customer_status_created` (`customer_id`, `order_status`, `created_at`)', 'SELECT "Index already exists"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 2. Ensure indexing on order_items table for order_id and seller_id multi-seller item joins
SET @exist2 := (SELECT COUNT(*) FROM information_schema.statistics
                WHERE table_schema = DATABASE()
                  AND table_name = 'order_items'
                  AND index_name = 'idx_order_items_order_seller');
SET @sqlstmt2 := IF(@exist2 = 0, 'ALTER TABLE `order_items` ADD INDEX `idx_order_items_order_seller` (`order_id`, `seller_id`, `item_status`)', 'SELECT "Index already exists"');
PREPARE stmt2 FROM @sqlstmt2;
EXECUTE stmt2;
DEALLOCATE PREPARE stmt2;

-- 3. Ensure indexing on shipment_events for chronological order timeline
SET @exist3 := (SELECT COUNT(*) FROM information_schema.statistics
                WHERE table_schema = DATABASE()
                  AND table_name = 'shipment_events'
                  AND index_name = 'idx_events_order_time');
SET @sqlstmt3 := IF(@exist3 = 0, 'ALTER TABLE `shipment_events` ADD INDEX `idx_events_order_time` (`order_id`, `created_at`)', 'SELECT "Index already exists"');
PREPARE stmt3 FROM @sqlstmt3;
EXECUTE stmt3;
DEALLOCATE PREPARE stmt3;
