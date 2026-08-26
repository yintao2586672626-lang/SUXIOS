-- Recover normalized WeCom callbacks after a worker dies between archive and
-- terminal readback. Claim tokens are opaque hashes and never contain message
-- or credential material.
ALTER TABLE `wecom_inbound_events`
  ADD COLUMN IF NOT EXISTS `processing_claim_token` CHAR(64) DEFAULT NULL AFTER `processing_status`,
  ADD COLUMN IF NOT EXISTS `processing_lease_expires_at` DATETIME DEFAULT NULL AFTER `processing_claim_token`;

