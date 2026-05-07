CREATE TABLE IF NOT EXISTS `complaint_rooms` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `hotel_id` int unsigned NOT NULL,
  `room_no` varchar(50) NOT NULL,
  `mp_token` varchar(32) DEFAULT NULL,
  `qr_content` text,
  `qr_path` varchar(255) DEFAULT '',
  `create_time` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_mp_token` (`mp_token`),
  KEY `idx_hotel_room` (`hotel_id`,`room_no`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `complaint_feedbacks` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `hotel_id` int unsigned NOT NULL,
  `room_id` int unsigned NOT NULL,
  `room_no` varchar(50) NOT NULL,
  `content` text NOT NULL,
  `contact` varchar(100) DEFAULT '',
  `status` tinyint unsigned NOT NULL DEFAULT 0,
  `create_time` datetime DEFAULT NULL,
  `update_time` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_hotel_status` (`hotel_id`,`status`),
  KEY `idx_room` (`room_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
