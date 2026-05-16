CREATE TABLE IF NOT EXISTS `quant_simulation_records` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `project_name` VARCHAR(120) NOT NULL DEFAULT '' COMMENT '模拟项目名称',
  `input_json` JSON DEFAULT NULL COMMENT '量化模拟输入',
  `result_json` JSON DEFAULT NULL COMMENT '基准情景结果',
  `scenarios_json` JSON DEFAULT NULL COMMENT '三情景结果',
  `risk_hints_json` JSON DEFAULT NULL COMMENT '风险提示',
  `monthly_net_cashflow` DECIMAL(14,2) NOT NULL DEFAULT 0 COMMENT '月净现金流',
  `payback_months` DECIMAL(10,2) DEFAULT NULL COMMENT '回本周期(月)',
  `risk_level` VARCHAR(30) NOT NULL DEFAULT '' COMMENT '风险等级',
  `created_by` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '创建人',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_quant_sim_created_by` (`created_by`, `id`),
  KEY `idx_quant_sim_risk_level` (`risk_level`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='筹建量化模拟记录表';
