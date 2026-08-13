-- The radar seed uses descriptive stable type names longer than the original VARCHAR(50).
-- This compatibility migration sorts before the seed so strict SQL mode never truncates it.

ALTER TABLE `knowledge_chunks`
  MODIFY COLUMN `type` VARCHAR(80) DEFAULT NULL COMMENT '评论文本、指标等';
