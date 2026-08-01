-- Keep online-history status separate from database persistence success.
--
-- A read-back row is not source-verified merely because JSON was stored.
-- "success" now requires explicit source validation plus hotel/platform/date,
-- trace, non-manual ingestion, and a precise collection timestamp. Other
-- read-back rows stay partial and remain visible.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

ALTER TABLE `online_daily_data`
  MODIFY COLUMN `history_status` VARCHAR(20)
    GENERATED ALWAYS AS (
      CASE
        WHEN LOWER(TRIM(COALESCE(`validation_status`, ''))) IN (
          'abnormal', 'invalid', 'failed', 'fail', 'error',
          'collection_failed', 'capture_failed', 'permission_denied',
          'binding_missing', 'mismatched', 'mismatch', 'login_required'
        ) THEN 'failed'
        WHEN LOWER(TRIM(COALESCE(`validation_status`, ''))) IN (
          'unverified', 'stale'
        ) THEN 'unverified'
        WHEN LOWER(TRIM(COALESCE(`validation_status`, ''))) IN (
          'warning', 'partial', 'partial_success'
        ) THEN 'partial'
        WHEN COALESCE(`readback_verified`, 0) <> 1 THEN 'unverified'
        WHEN COALESCE(`system_hotel_id`, 0) <= 0
          OR TRIM(COALESCE(CAST(`hotel_id` AS CHAR), '')) = ''
          OR COALESCE(
            NULLIF(TRIM(CAST(`platform` AS CHAR)), ''),
            NULLIF(TRIM(CAST(`source` AS CHAR)), ''),
            ''
          ) = ''
          OR `data_date` IS NULL
        THEN 'unverified'
        WHEN LOWER(TRIM(COALESCE(`ingestion_method`, ''))) IN (
          '', 'legacy', 'manual', 'manual_import', 'manual_override',
          'user_provided', 'user_provided_unverified', 'import_csv', 'import_json'
        )
          OR TRIM(COALESCE(`source_trace_id`, '')) = ''
          OR `snapshot_time` IS NULL
        THEN 'partial'
        WHEN LOWER(TRIM(COALESCE(`validation_status`, ''))) = 'verified'
        THEN 'success'
        ELSE 'partial'
      END
    ) STORED;
