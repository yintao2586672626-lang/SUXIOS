ALTER TABLE `online_daily_data`
  ADD COLUMN `platform` varchar(20) DEFAULT NULL COMMENT 'OTA platform: Ctrip/Qunar' AFTER `data_type`,
  ADD COLUMN `compare_type` varchar(30) DEFAULT NULL COMMENT 'traffic compare type: self/competitor_avg' AFTER `platform`,
  ADD COLUMN `list_exposure` int(11) DEFAULT 0 COMMENT 'list exposure' AFTER `compare_type`,
  ADD COLUMN `detail_exposure` int(11) DEFAULT 0 COMMENT 'detail exposure' AFTER `list_exposure`,
  ADD COLUMN `flow_rate` decimal(10,2) DEFAULT 0.00 COMMENT 'detail/list conversion rate' AFTER `detail_exposure`,
  ADD COLUMN `order_filling_num` int(11) DEFAULT 0 COMMENT 'order filling visitors' AFTER `flow_rate`,
  ADD COLUMN `order_submit_num` int(11) DEFAULT 0 COMMENT 'order submit visitors' AFTER `order_filling_num`,
  ADD INDEX `idx_online_daily_traffic` (`source`, `data_type`, `platform`, `system_hotel_id`, `hotel_id`, `compare_type`, `data_date`);
