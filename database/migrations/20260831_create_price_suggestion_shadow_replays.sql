-- Historical, read-only replay ledger for saved Revenue AI price suggestions.
--
-- A replay freezes the suggestion's original as-of inputs, reruns the existing
-- deterministic pricing service, and observes finalized same-room Ctrip facts.
-- It never changes the source suggestion, creates an approval/task, or writes
-- any OTA/PMS state. New evidence creates a new append-only version.
CREATE TABLE IF NOT EXISTS `price_suggestion_shadow_replays` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` INT UNSIGNED NOT NULL COMMENT 'authoritative tenant resolved from hotel',
  `hotel_id` INT UNSIGNED NOT NULL COMMENT 'SUXIOS system hotel id',
  `price_suggestion_id` INT UNSIGNED NOT NULL COMMENT 'saved advisory suggestion under replay',
  `room_type_id` INT UNSIGNED NOT NULL COMMENT 'SUXIOS room type id frozen by the suggestion',
  `platform` VARCHAR(40) NOT NULL DEFAULT 'ctrip',
  `target_stay_date` DATE NOT NULL,
  `as_of_at` DATETIME NOT NULL COMMENT 'original suggestion creation time; must precede target stay date',
  `contract_version` VARCHAR(64) NOT NULL DEFAULT 'price_suggestion_shadow_replay.v1',
  `model_version` VARCHAR(64) NOT NULL,
  `input_snapshot_json` JSON NOT NULL,
  `input_digest` CHAR(64) NOT NULL,
  `recommendation_snapshot_json` JSON NOT NULL,
  `recommendation_digest` CHAR(64) NOT NULL,
  `actual_snapshot_json` JSON NOT NULL,
  `actual_digest` CHAR(64) NOT NULL,
  `recommendation_direction` VARCHAR(24) NOT NULL,
  `observed_direction` VARCHAR(24) NOT NULL,
  `verdict` VARCHAR(32) NOT NULL COMMENT 'direction_aligned/direction_opposed/indeterminate',
  `verdict_reason` VARCHAR(160) NOT NULL,
  `causality_claimed` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
  `external_write_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `content_digest` CHAR(64) NOT NULL,
  `created_by` BIGINT UNSIGNED NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_price_shadow_replay_content` (`tenant_id`, `hotel_id`, `price_suggestion_id`, `content_digest`),
  KEY `idx_price_shadow_replay_suggestion` (`tenant_id`, `hotel_id`, `price_suggestion_id`, `id`),
  KEY `idx_price_shadow_replay_room_date` (`tenant_id`, `hotel_id`, `room_type_id`, `platform`, `target_stay_date`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='append-only historical pricing shadow replay ledger';

CREATE TRIGGER IF NOT EXISTS `trg_price_shadow_replay_no_update`
BEFORE UPDATE ON `price_suggestion_shadow_replays`
FOR EACH ROW
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'price suggestion shadow replay is append-only';

CREATE TRIGGER IF NOT EXISTS `trg_price_shadow_replay_no_delete`
BEFORE DELETE ON `price_suggestion_shadow_replays`
FOR EACH ROW
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'price suggestion shadow replay is append-only';
