-- Allow authenticated encryption envelopes without VARCHAR truncation.
-- Apply this schema migration before running: php think migrate:sensitive-storage --execute
ALTER TABLE `competitor_wechat_robot`
    MODIFY COLUMN `webhook` TEXT NOT NULL COMMENT 'AES-256-GCM protected enterprise WeChat webhook';
