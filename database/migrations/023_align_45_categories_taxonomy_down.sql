-- ==========================================================================
-- Migration 023 Down: Rollback 45-Category Taxonomy Alignment
-- ==========================================================================

UPDATE `categories` c JOIN `_backup_categories_taxonomy_023` b ON c.id = b.id SET c.parent_id = b.parent_id, c.name = b.name, c.slug = b.slug, c.display_order = b.display_order, c.is_active = b.is_active;
UPDATE `products` p JOIN `_backup_products_taxonomy_023` b ON p.id = b.id SET p.category_id = b.category_id, p.subcategory_id = b.subcategory_id;
DROP TABLE IF EXISTS `_backup_categories_taxonomy_023`;
DROP TABLE IF EXISTS `_backup_products_taxonomy_023`;

