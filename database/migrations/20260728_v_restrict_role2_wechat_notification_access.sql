-- Correct the broad role_id=2 grant from the immutable u migration.
--
-- Keep report.fill only for the enabled built-in beta_user role that still
-- matches the complete legacy default capability set. For a customized,
-- restricted role_id=2, remove only a report.fill value whose row timestamp
-- proves it was written by the u migration. A capability that existed before
-- u, or a role edited after u, is deliberately preserved.

UPDATE `roles` r
INNER JOIN `schema_versions` sv
  ON sv.`migration` = '20260728_u_grant_role2_wechat_notification_access.sql'
SET r.`permissions` = JSON_REMOVE(
  r.`permissions`,
  JSON_UNQUOTE(JSON_SEARCH(r.`permissions`, 'one', 'report.fill', NULL, '$[*]'))
)
WHERE r.`id` = 2
  AND JSON_VALID(r.`permissions`) = 1
  AND JSON_TYPE(r.`permissions`) = 'ARRAY'
  AND JSON_CONTAINS(r.`permissions`, JSON_QUOTE('report.fill'), '$') = 1
  AND r.`update_time` IS NOT NULL
  AND r.`update_time` BETWEEN DATE_SUB(sv.`executed_at`, INTERVAL 30 SECOND) AND sv.`executed_at`
  AND NOT (
    r.`name` = 'beta_user'
    AND r.`level` = 2
    AND r.`status` = 1
    AND JSON_LENGTH(r.`permissions`) = 15
    AND JSON_CONTAINS(r.`permissions`, JSON_QUOTE('dashboard.view'), '$') = 1
    AND JSON_CONTAINS(r.`permissions`, JSON_QUOTE('hotel.create'), '$') = 1
    AND JSON_CONTAINS(r.`permissions`, JSON_QUOTE('hotel.view'), '$') = 1
    AND JSON_CONTAINS(r.`permissions`, JSON_QUOTE('hotel.update'), '$') = 1
    AND JSON_CONTAINS(r.`permissions`, JSON_QUOTE('ota.view'), '$') = 1
    AND JSON_CONTAINS(r.`permissions`, JSON_QUOTE('ota.collect'), '$') = 1
    AND JSON_CONTAINS(r.`permissions`, JSON_QUOTE('report.view'), '$') = 1
    AND JSON_CONTAINS(r.`permissions`, JSON_QUOTE('report.export'), '$') = 1
    AND JSON_CONTAINS(r.`permissions`, JSON_QUOTE('ai.view'), '$') = 1
    AND JSON_CONTAINS(r.`permissions`, JSON_QUOTE('ai.execute'), '$') = 1
    AND JSON_CONTAINS(r.`permissions`, JSON_QUOTE('operation.view'), '$') = 1
    AND JSON_CONTAINS(r.`permissions`, JSON_QUOTE('operation.execute'), '$') = 1
    AND JSON_CONTAINS(r.`permissions`, JSON_QUOTE('investment.view'), '$') = 1
    AND JSON_CONTAINS(r.`permissions`, JSON_QUOTE('investment.simulate'), '$') = 1
  );
