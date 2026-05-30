ALTER TABLE `knowledge_units`
  ADD COLUMN IF NOT EXISTS `created_by` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'creator user id' AFTER `tags`,
  ADD INDEX IF NOT EXISTS `idx_knowledge_units_created_by` (`created_by`, `unit_id`);

ALTER TABLE `knowledge_chunks`
  ADD COLUMN IF NOT EXISTS `created_by` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'creator user id' AFTER `content`,
  ADD INDEX IF NOT EXISTS `idx_knowledge_chunks_created_by` (`created_by`, `chunk_id`);
