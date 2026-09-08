-- The original seed's descriptive source identifier is 51 characters while
-- knowledge_units.source is VARCHAR(50). Keep the registered seed immutable
-- and move this unit to a shorter, exact, stable source identity.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

UPDATE `knowledge_units`
SET
  `source` = 'user_meituan_traffic_self_check_screenshot',
  `updated_at` = NOW()
WHERE `stable_key` = 'global:meituan_traffic_self_check_reference';
