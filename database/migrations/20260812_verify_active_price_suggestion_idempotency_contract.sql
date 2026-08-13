DELIMITER $$

DROP PROCEDURE IF EXISTS `suxios_verify_active_price_suggestion_idempotency_contract`$$

CREATE PROCEDURE `suxios_verify_active_price_suggestion_idempotency_contract`()
BEGIN
  DECLARE contract_column_count INT DEFAULT 0;
  DECLARE named_index_row_count INT DEFAULT 0;
  DECLARE exact_unique_index_row_count INT DEFAULT 0;

  SELECT COUNT(*)
    INTO contract_column_count
  FROM `information_schema`.`columns`
  WHERE `table_schema` = DATABASE()
    AND `table_name` = 'price_suggestions'
    AND `column_name` = 'active_dedupe_key'
    AND LOWER(`data_type`) = 'char'
    AND `character_maximum_length` = 64
    AND `is_nullable` = 'YES';

  IF contract_column_count <> 1 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Price suggestion idempotency contract invalid: active_dedupe_key must be nullable CHAR(64)';
  END IF;

  SELECT COUNT(*)
    INTO named_index_row_count
  FROM `information_schema`.`statistics`
  WHERE `table_schema` = DATABASE()
    AND `table_name` = 'price_suggestions'
    AND `index_name` = 'uq_price_suggestions_active_dedupe';

  SELECT COUNT(*)
    INTO exact_unique_index_row_count
  FROM `information_schema`.`statistics`
  WHERE `table_schema` = DATABASE()
    AND `table_name` = 'price_suggestions'
    AND `index_name` = 'uq_price_suggestions_active_dedupe'
    AND `non_unique` = 0
    AND `seq_in_index` = 1
    AND `column_name` = 'active_dedupe_key'
    AND `sub_part` IS NULL;

  IF named_index_row_count <> 1 OR exact_unique_index_row_count <> 1 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Price suggestion idempotency contract invalid: exact unique active_dedupe_key index required';
  END IF;

  IF EXISTS (
    SELECT 1
    FROM `price_suggestions`
    WHERE `status` = 1
      AND (
        `tenant_id` IS NULL OR `tenant_id` <= 0
        OR `hotel_id` IS NULL OR `hotel_id` <= 0
        OR `room_type_id` IS NULL OR `room_type_id` <= 0
        OR `suggestion_date` IS NULL
        OR `active_dedupe_key` IS NULL
        OR `active_dedupe_key` <> SHA2(CONCAT(
          'price_suggestion_pending_v1|',
          `tenant_id`, '|',
          `hotel_id`, '|',
          `room_type_id`, '|',
          DATE_FORMAT(`suggestion_date`, '%Y-%m-%d')
        ), 256)
      )
    LIMIT 1
  ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Price suggestion idempotency contract invalid: pending key backfill mismatch';
  END IF;

  IF EXISTS (
    SELECT 1
    FROM `price_suggestions`
    WHERE (`status` IS NULL OR `status` <> 1)
      AND `active_dedupe_key` IS NOT NULL
    LIMIT 1
  ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Price suggestion idempotency contract invalid: terminal row retains active key';
  END IF;

  IF EXISTS (
    SELECT 1
    FROM `price_suggestions`
    WHERE `active_dedupe_key` IS NOT NULL
    GROUP BY `active_dedupe_key`
    HAVING COUNT(*) > 1
    LIMIT 1
  ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Price suggestion idempotency contract invalid: duplicate active keys exist';
  END IF;
END$$

CALL `suxios_verify_active_price_suggestion_idempotency_contract`()$$
DROP PROCEDURE IF EXISTS `suxios_verify_active_price_suggestion_idempotency_contract`$$

DELIMITER ;
