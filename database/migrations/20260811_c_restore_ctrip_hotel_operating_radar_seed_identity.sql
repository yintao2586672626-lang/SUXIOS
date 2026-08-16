-- After the compatibility expansion, keep the radar journey chunk on the original stable key.
-- This makes replaying the complete migration sequence idempotent: the main seed can match the
-- existing row instead of inserting a second row after the historical VARCHAR(50) repair.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

SET @ctrip_radar_restore_unit_id := (
  SELECT `unit_id` FROM `knowledge_units`
  WHERE `name` = '携程酒店经营雷达图（规划期）五维知识合同'
    AND `source` = 'revenue_operations_decision_support'
  ORDER BY `unit_id` ASC LIMIT 1
);

UPDATE `knowledge_chunks`
SET
  `type` = 'ctrip_radar_user_journey_and_platform_focus_reference',
  `content` = JSON_SET(
    CASE WHEN JSON_VALID(`content`) = 1 THEN `content` ELSE JSON_OBJECT() END,
    '$.content_key', 'ctrip_hotel_operating_radar:ctrip_radar_user_journey_and_platform_focus_reference',
    '$.seed_key', 'ctrip_hotel_operating_radar:ctrip_radar_user_journey_and_platform_focus_reference'
  )
WHERE `unit_id` = @ctrip_radar_restore_unit_id
  AND JSON_UNQUOTE(JSON_EXTRACT(
    CASE WHEN JSON_VALID(`content`) = 1 THEN `content` ELSE JSON_OBJECT() END,
    '$.seed_owner'
  )) = 'suxios.ctrip_hotel_operating_radar_knowledge'
  AND (
    `type` IN (
      'ctrip_radar_user_journey_reference',
      'ctrip_radar_user_journey_and_platform_focus_refere',
      'ctrip_radar_user_journey_and_platform_focus_reference'
    )
    OR JSON_UNQUOTE(JSON_EXTRACT(
      CASE WHEN JSON_VALID(`content`) = 1 THEN `content` ELSE JSON_OBJECT() END,
      '$.seed_key'
    )) IN (
      'ctrip_hotel_operating_radar:ctrip_radar_user_journey_reference',
      'ctrip_hotel_operating_radar:ctrip_radar_user_journey_and_platform_focus_reference'
    )
  );
