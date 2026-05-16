CREATE TABLE IF NOT EXISTS `expansion_records` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `record_type` VARCHAR(30) NOT NULL DEFAULT '' COMMENT 'market/benchmark/collaboration',
  `project_name` VARCHAR(160) NOT NULL DEFAULT '' COMMENT '项目或记录名称',
  `city_area` VARCHAR(160) NOT NULL DEFAULT '' COMMENT '城市/区域',
  `input_json` JSON DEFAULT NULL COMMENT '输入字段',
  `result_json` JSON DEFAULT NULL COMMENT '计算结果',
  `decision` VARCHAR(120) NOT NULL DEFAULT '' COMMENT '结论',
  `risk_level` VARCHAR(30) NOT NULL DEFAULT '' COMMENT '风险等级',
  `created_by` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '创建人',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_expansion_records_type_user` (`record_type`, `created_by`, `id`),
  KEY `idx_expansion_records_city_area` (`city_area`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='扩张管理结果记录表';
