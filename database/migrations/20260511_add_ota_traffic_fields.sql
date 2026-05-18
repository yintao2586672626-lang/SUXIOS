ALTER TABLE `online_daily_data`
  ADD COLUMN IF NOT EXISTS `platform` varchar(20) DEFAULT NULL COMMENT 'OTA platform: Ctrip/Qunar' AFTER `data_type`,
  ADD COLUMN IF NOT EXISTS `compare_type` varchar(30) DEFAULT NULL COMMENT 'traffic compare type: self/competitor_avg' AFTER `platform`,
  ADD COLUMN IF NOT EXISTS `list_exposure` int(11) DEFAULT 0 COMMENT 'list exposure' AFTER `compare_type`,
  ADD COLUMN IF NOT EXISTS `detail_exposure` int(11) DEFAULT 0 COMMENT 'detail exposure' AFTER `list_exposure`,
  ADD COLUMN IF NOT EXISTS `flow_rate` decimal(10,2) DEFAULT 0.00 COMMENT 'detail/list conversion rate' AFTER `detail_exposure`,
  ADD COLUMN IF NOT EXISTS `order_filling_num` int(11) DEFAULT 0 COMMENT 'order filling visitors' AFTER `flow_rate`,
  ADD COLUMN IF NOT EXISTS `order_submit_num` int(11) DEFAULT 0 COMMENT 'order submit visitors' AFTER `order_filling_num`,
  ADD INDEX IF NOT EXISTS `idx_online_daily_traffic` (`source`, `data_type`, `platform`, `system_hotel_id`, `hotel_id`, `compare_type`, `data_date`);
