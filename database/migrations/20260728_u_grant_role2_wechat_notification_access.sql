-- Keep the default hotel operator role aligned with the hotel-level
-- can_fill_daily_report grant used by the operations automation center.
-- Preserve every existing/custom role capability and leave role_id=3 unchanged.

UPDATE `roles`
SET `permissions` = JSON_ARRAY_APPEND(`permissions`, '$', 'report.fill')
WHERE `id` = 2
  AND JSON_VALID(`permissions`) = 1
  AND JSON_TYPE(`permissions`) = 'ARRAY'
  AND JSON_CONTAINS(`permissions`, JSON_QUOTE('report.fill'), '$') = 0;
