-- Repair legacy users that have valid hotel grants but no user-level tenant
-- context. Only deterministic, single-tenant grants are eligible. Users whose
-- active grants span multiple tenants remain unchanged and fail closed.

START TRANSACTION;

UPDATE `user_hotel_permissions` permission_row
INNER JOIN `hotels` hotel ON hotel.`id` = permission_row.`hotel_id`
SET permission_row.`tenant_id` = hotel.`tenant_id`
WHERE hotel.`tenant_id` IS NOT NULL
  AND hotel.`tenant_id` > 0
  AND (
    permission_row.`tenant_id` IS NULL
    OR permission_row.`tenant_id` = 0
    OR permission_row.`tenant_id` <> hotel.`tenant_id`
  );

UPDATE `users` user_row
INNER JOIN (
  SELECT
    permission_row.`user_id`,
    MIN(hotel.`tenant_id`) AS `tenant_id`,
    MIN(hotel.`id`) AS `single_hotel_id`,
    COUNT(DISTINCT hotel.`id`) AS `hotel_count`
  FROM `user_hotel_permissions` permission_row
  INNER JOIN `hotels` hotel ON hotel.`id` = permission_row.`hotel_id`
  WHERE hotel.`status` = 1
    AND hotel.`tenant_id` IS NOT NULL
    AND hotel.`tenant_id` > 0
    AND permission_row.`status` IN ('active', '1')
    AND (
      permission_row.`expires_at` IS NULL
      OR permission_row.`expires_at` > NOW()
    )
  GROUP BY permission_row.`user_id`
  HAVING COUNT(DISTINCT hotel.`tenant_id`) = 1
) resolved_scope ON resolved_scope.`user_id` = user_row.`id`
SET
  user_row.`tenant_id` = resolved_scope.`tenant_id`,
  user_row.`hotel_id` = CASE
    WHEN (user_row.`hotel_id` IS NULL OR user_row.`hotel_id` = 0)
      AND resolved_scope.`hotel_count` = 1
      THEN resolved_scope.`single_hotel_id`
    ELSE user_row.`hotel_id`
  END
WHERE user_row.`tenant_id` IS NULL OR user_row.`tenant_id` = 0;

COMMIT;
