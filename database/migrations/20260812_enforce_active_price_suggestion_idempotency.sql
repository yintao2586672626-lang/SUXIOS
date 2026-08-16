ALTER TABLE `price_suggestions`
  ADD COLUMN IF NOT EXISTS `active_dedupe_key` CHAR(64) DEFAULT NULL
    COMMENT 'unique hotel-room-date identity while the suggestion is pending' AFTER `reason`;

DELIMITER $$

DROP PROCEDURE IF EXISTS `suxios_validate_pending_price_suggestion_identity`$$

CREATE PROCEDURE `suxios_validate_pending_price_suggestion_identity`()
BEGIN
  IF EXISTS (
    SELECT 1
    FROM `price_suggestions`
    WHERE `status` = 1
      AND (
        `tenant_id` IS NULL OR `tenant_id` <= 0
        OR `hotel_id` IS NULL OR `hotel_id` <= 0
        OR `room_type_id` IS NULL OR `room_type_id` <= 0
        OR `suggestion_date` IS NULL
      )
    LIMIT 1
  ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Cannot add active price suggestion idempotency: pending identity is incomplete';
  END IF;

  IF EXISTS (
    SELECT 1
    FROM `price_suggestions`
    WHERE `status` = 1
    GROUP BY `tenant_id`, `hotel_id`, `room_type_id`, `suggestion_date`
    HAVING COUNT(*) > 1
    LIMIT 1
  ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Cannot add active price suggestion idempotency: duplicate pending identities exist';
  END IF;
END$$

CALL `suxios_validate_pending_price_suggestion_identity`()$$
DROP PROCEDURE IF EXISTS `suxios_validate_pending_price_suggestion_identity`$$

DELIMITER ;

UPDATE `price_suggestions`
SET `active_dedupe_key` = NULL
WHERE `status` IS NULL OR `status` <> 1;

UPDATE `price_suggestions`
SET `active_dedupe_key` = SHA2(CONCAT(
  'price_suggestion_pending_v1|',
  `tenant_id`, '|',
  `hotel_id`, '|',
  `room_type_id`, '|',
  DATE_FORMAT(`suggestion_date`, '%Y-%m-%d')
), 256)
WHERE `status` = 1;

ALTER TABLE `price_suggestions`
  ADD UNIQUE INDEX IF NOT EXISTS `uq_price_suggestions_active_dedupe` (`active_dedupe_key`);
