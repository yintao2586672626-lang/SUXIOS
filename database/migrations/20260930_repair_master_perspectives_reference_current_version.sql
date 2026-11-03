-- Correct the missing version pointer from the immutable 20260820_b seed.
-- Only the original global, active, reviewed package is eligible. Existing
-- nonzero pointers, retired/quarantined material and unrelated sources remain unchanged.
UPDATE `knowledge_units`
SET `current_chunk_id` = COALESCE((
    SELECT MAX(`chunk`.`chunk_id`)
    FROM `knowledge_chunks` AS `chunk`
    WHERE `chunk`.`unit_id` = `knowledge_units`.`unit_id`
      AND `chunk`.`created_by` = 0
      AND COALESCE(`chunk`.`lifecycle_status`, 'active') = 'active'
      AND JSON_VALID(`chunk`.`content`) = 1
      AND JSON_UNQUOTE(JSON_EXTRACT(CASE WHEN JSON_VALID(`chunk`.`content`) = 1 THEN `chunk`.`content` ELSE '{}' END, '$.seed_owner')) = 'suxios.master_perspectives_multi_lens_knowledge'
      AND JSON_UNQUOTE(JSON_EXTRACT(CASE WHEN JSON_VALID(`chunk`.`content`) = 1 THEN `chunk`.`content` ELSE '{}' END, '$.seed_version')) = '2026-08-20.1'
      AND JSON_UNQUOTE(JSON_EXTRACT(CASE WHEN JSON_VALID(`chunk`.`content`) = 1 THEN `chunk`.`content` ELSE '{}' END, '$.seed_key')) = CONCAT('hotel_operating_multi_lens_review:', `chunk`.`type`)
      AND LOWER(JSON_UNQUOTE(JSON_EXTRACT(CASE WHEN JSON_VALID(`chunk`.`content`) = 1 THEN `chunk`.`content` ELSE '{}' END, '$.source_manifest.sha256'))) = '32c06de45983119efd6f7cfa9b1e8ca5ce59f8a4e5339267dc383a5fc0ee3970'
      AND COALESCE(JSON_UNQUOTE(JSON_EXTRACT(CASE WHEN JSON_VALID(`chunk`.`content`) = 1 THEN `chunk`.`content` ELSE '{}' END, '$.lifecycle_status')), 'active') = 'active'
      AND COALESCE(JSON_UNQUOTE(JSON_EXTRACT(CASE WHEN JSON_VALID(`chunk`.`content`) = 1 THEN `chunk`.`content` ELSE '{}' END, '$.entry.disposition')), '') <> 'reject_or_quarantine'
), `current_chunk_id`)
WHERE `hotel_id` = 0
  AND `created_by` = 0
  AND `source` = 'revenue_operations_decision_support'
  AND `name` = '酒店经营多视角审视与反证方法'
  AND `status` = 'done'
  AND COALESCE(`lifecycle_status`, 'active') = 'active'
  AND COALESCE(`current_chunk_id`, 0) = 0;
