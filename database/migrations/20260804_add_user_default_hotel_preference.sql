-- Keep the user's navigation preference separate from users.hotel_id, which
-- remains a legacy authorization/ownership binding used by hotel scope logic.
ALTER TABLE `users`
  ADD COLUMN IF NOT EXISTS `default_hotel_id` BIGINT UNSIGNED NULL AFTER `hotel_id`,
  ADD INDEX IF NOT EXISTS `idx_users_default_hotel_id` (`default_hotel_id`);

-- Preserve the effective default shown by older releases. Runtime reads still
-- validate that this hotel is enabled and currently authorized for the user.
UPDATE `users`
SET `default_hotel_id` = `hotel_id`
WHERE `default_hotel_id` IS NULL
  AND `hotel_id` IS NOT NULL;
