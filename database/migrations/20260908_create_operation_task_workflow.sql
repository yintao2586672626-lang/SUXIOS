-- L06: append-only local workflow metadata; existing intent/task IDs remain authoritative.
CREATE TABLE IF NOT EXISTS `operation_task_workflow_events` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `tenant_id` BIGINT UNSIGNED NOT NULL,
    `hotel_id` BIGINT UNSIGNED NOT NULL,
    `task_id` BIGINT UNSIGNED NOT NULL,
    `version_no` INT UNSIGNED NOT NULL,
    `request_id` VARCHAR(120) NOT NULL,
    `payload_json` LONGTEXT NOT NULL,
    `content_digest` CHAR(64) NOT NULL,
    `created_at` DATETIME NOT NULL,
    UNIQUE KEY uq_task_workflow_version (tenant_id, hotel_id, task_id, version_no),
    UNIQUE KEY uq_task_workflow_request (tenant_id, hotel_id, task_id, request_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `operation_task_workflow_proposals` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `tenant_id` BIGINT UNSIGNED NOT NULL,
    `hotel_id` BIGINT UNSIGNED NOT NULL,
    `recommendation_id` VARCHAR(120) NOT NULL,
    `intent_id` BIGINT UNSIGNED NOT NULL,
    `request_digest` CHAR(64) NOT NULL,
    `proposal_json` LONGTEXT NOT NULL,
    `created_at` DATETIME NOT NULL,
    UNIQUE KEY uq_workflow_recommendation (tenant_id, hotel_id, recommendation_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
