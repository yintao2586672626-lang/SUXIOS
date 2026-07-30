-- Indexed content identity lets authenticated manual observations use an
-- exact InnoDB range lock for replay-safe insert/readback without deleting or
-- rewriting any historical competitor events.
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

ALTER TABLE `competitor_price_log`
  ADD INDEX IF NOT EXISTS `idx_competitor_observation_content`
    (`store_id`, `hotel_id`, `platform`, `content_hash`);
