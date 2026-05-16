CREATE TABLE IF NOT EXISTS `transfer_records` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `record_type` VARCHAR(30) NOT NULL DEFAULT '' COMMENT 'pricing/timing/dashboard',
  `hotel_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '酒店ID',
  `hotel_name` VARCHAR(160) NOT NULL DEFAULT '' COMMENT '酒店名称',
  `source_date` DATE DEFAULT NULL COMMENT '取数日期',
  `input_json` JSON DEFAULT NULL COMMENT '输入字段',
  `result_json` JSON DEFAULT NULL COMMENT '计算结果',
  `snapshot_json` JSON DEFAULT NULL COMMENT '真实数据快照',
  `decision` VARCHAR(120) NOT NULL DEFAULT '' COMMENT '结论',
  `risk_level` VARCHAR(30) NOT NULL DEFAULT '' COMMENT '风险等级',
  `created_by` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '创建人',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_transfer_records_hotel_type` (`hotel_id`, `record_type`, `id`),
  KEY `idx_transfer_records_created_by` (`created_by`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='转让管理测算记录表';
