-- Persist model-call ownership before execution so duplicate or restarted
-- client_run_key requests cannot invoke the model outside an atomic claim.
ALTER TABLE `ai_evaluation_runs`
  ADD COLUMN IF NOT EXISTS `claim_token_hash` CHAR(64) DEFAULT NULL AFTER `status`,
  ADD COLUMN IF NOT EXISTS `lease_expires_at` DATETIME DEFAULT NULL AFTER `claim_token_hash`,
  ADD INDEX IF NOT EXISTS `idx_ai_evaluation_run_active_lease`
    (`status`, `lease_expires_at`, `id`);
