CREATE TABLE IF NOT EXISTS `knowledge_units` (
  `unit_id` INT NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(255) NOT NULL,
  `source` VARCHAR(50) DEFAULT NULL,
  `status` ENUM('pending','done','error') NOT NULL DEFAULT 'pending',
  `description` TEXT DEFAULT NULL,
  `tags` JSON DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`unit_id`),
  KEY `idx_knowledge_units_status` (`status`),
  KEY `idx_knowledge_units_source` (`source`),
  KEY `idx_knowledge_units_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='智能知识中枢知识单元';

CREATE TABLE IF NOT EXISTS `knowledge_chunks` (
  `chunk_id` INT NOT NULL AUTO_INCREMENT,
  `unit_id` INT NOT NULL,
  `type` VARCHAR(50) DEFAULT NULL COMMENT '评论文本、指标等',
  `content` JSON DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`chunk_id`),
  KEY `idx_knowledge_chunks_unit_id` (`unit_id`),
  KEY `idx_knowledge_chunks_type` (`type`),
  CONSTRAINT `fk_knowledge_chunks_unit_id`
    FOREIGN KEY (`unit_id`) REFERENCES `knowledge_units` (`unit_id`)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='智能知识中枢知识片段';
