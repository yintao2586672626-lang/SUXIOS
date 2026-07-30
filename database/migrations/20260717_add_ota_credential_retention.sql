ALTER TABLE `ota_credentials`
  ADD COLUMN IF NOT EXISTS `last_used_at` DATETIME NULL AFTER `rotated_at`,
  ADD COLUMN IF NOT EXISTS `revoked_at` DATETIME NULL AFTER `last_used_at`,
  ADD INDEX IF NOT EXISTS `idx_ota_credential_retention`
    (`credential_status`, `last_used_at`, `rotated_at`);

-- Historical revoke paths only changed the status. Keep the audit tombstone,
-- but never retain executable secret material after revocation.
UPDATE `ota_credentials`
SET `encrypted_payload` = '',
    `secret_mask` = '',
    `revoked_at` = COALESCE(`revoked_at`, `update_time`, `rotated_at`, `create_time`, NOW()),
    `update_time` = COALESCE(`update_time`, NOW())
WHERE `credential_status` = 'revoked'
  AND (
    `encrypted_payload` <> ''
    OR `secret_mask` <> ''
    OR `revoked_at` IS NULL
  );
