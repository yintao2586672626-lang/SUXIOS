INSERT INTO `report_configs` (`report_type`, `field_name`, `display_name`, `field_type`, `category`, `unit`, `options`, `sort_order`, `is_required`, `status`, `create_time`, `update_time`)
SELECT 'daily', 'booking_revenue', CONVERT(0x426F6F6B696E672E636F6DE688BFE8B4B9E694B6E585A5 USING utf8mb4), 'number', CONVERT(0xE4BA8CE38081E7BABFE4B88A4F5441E695B0E68DAEE5A1ABE58699 USING utf8mb4), CONVERT(0xE58583 USING utf8mb4), NULL, 17, 0, 1, NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM `report_configs` WHERE `report_type` = 'daily' AND `field_name` = 'booking_revenue');

INSERT INTO `report_configs` (`report_type`, `field_name`, `display_name`, `field_type`, `category`, `unit`, `options`, `sort_order`, `is_required`, `status`, `create_time`, `update_time`)
SELECT 'daily', 'booking_rooms', CONVERT(0x426F6F6B696E672E636F6DE587BAE7A79FE997B4E5A49C USING utf8mb4), 'number', CONVERT(0xE4BA8CE38081E7BABFE4B88A4F5441E695B0E68DAEE5A1ABE58699 USING utf8mb4), CONVERT(0xE997B4 USING utf8mb4), NULL, 18, 0, 1, NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM `report_configs` WHERE `report_type` = 'daily' AND `field_name` = 'booking_rooms');

INSERT INTO `report_configs` (`report_type`, `field_name`, `display_name`, `field_type`, `category`, `unit`, `options`, `sort_order`, `is_required`, `status`, `create_time`, `update_time`)
SELECT 'daily', 'agoda_revenue', CONVERT(0x41676F6461E688BFE8B4B9E694B6E585A5 USING utf8mb4), 'number', CONVERT(0xE4BA8CE38081E7BABFE4B88A4F5441E695B0E68DAEE5A1ABE58699 USING utf8mb4), CONVERT(0xE58583 USING utf8mb4), NULL, 19, 0, 1, NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM `report_configs` WHERE `report_type` = 'daily' AND `field_name` = 'agoda_revenue');

INSERT INTO `report_configs` (`report_type`, `field_name`, `display_name`, `field_type`, `category`, `unit`, `options`, `sort_order`, `is_required`, `status`, `create_time`, `update_time`)
SELECT 'daily', 'agoda_rooms', CONVERT(0x41676F6461E587BAE7A79FE997B4E5A49C USING utf8mb4), 'number', CONVERT(0xE4BA8CE38081E7BABFE4B88A4F5441E695B0E68DAEE5A1ABE58699 USING utf8mb4), CONVERT(0xE997B4 USING utf8mb4), NULL, 20, 0, 1, NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM `report_configs` WHERE `report_type` = 'daily' AND `field_name` = 'agoda_rooms');

INSERT INTO `report_configs` (`report_type`, `field_name`, `display_name`, `field_type`, `category`, `unit`, `options`, `sort_order`, `is_required`, `status`, `create_time`, `update_time`)
SELECT 'daily', 'expedia_revenue', CONVERT(0x45787065646961E688BFE8B4B9E694B6E585A5 USING utf8mb4), 'number', CONVERT(0xE4BA8CE38081E7BABFE4B88A4F5441E695B0E68DAEE5A1ABE58699 USING utf8mb4), CONVERT(0xE58583 USING utf8mb4), NULL, 21, 0, 1, NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM `report_configs` WHERE `report_type` = 'daily' AND `field_name` = 'expedia_revenue');

INSERT INTO `report_configs` (`report_type`, `field_name`, `display_name`, `field_type`, `category`, `unit`, `options`, `sort_order`, `is_required`, `status`, `create_time`, `update_time`)
SELECT 'daily', 'expedia_rooms', CONVERT(0x45787065646961E587BAE7A79FE997B4E5A49C USING utf8mb4), 'number', CONVERT(0xE4BA8CE38081E7BABFE4B88A4F5441E695B0E68DAEE5A1ABE58699 USING utf8mb4), CONVERT(0xE997B4 USING utf8mb4), NULL, 22, 0, 1, NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM `report_configs` WHERE `report_type` = 'daily' AND `field_name` = 'expedia_rooms');
