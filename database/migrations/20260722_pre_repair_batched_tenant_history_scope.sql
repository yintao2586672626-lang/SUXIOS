-- Forward-only pre-repair migration. The filename intentionally sorts after
-- tenant foundation creation and before the immutable historical
-- 20260722_repair_remaining_tenant_history_scope.sql migration.
--
-- Fresh databases repair the rows here in bounded batches, making the legacy
-- unbounded repair a no-op. Existing databases that already registered the
-- legacy repair can safely apply this idempotent migration out of order.
-- Every successful batch commits, so a failed run resumes from remaining rows.

DELIMITER $$

DROP PROCEDURE IF EXISTS `suxios_backfill_tenant_scope_batched`$$

CREATE PROCEDURE `suxios_backfill_tenant_scope_batched`(
    IN p_target_table VARCHAR(64),
    IN p_target_scope_column VARCHAR(64),
    IN p_parent_table VARCHAR(64),
    IN p_parent_scope_column VARCHAR(64),
    IN p_batch_size INT
)
BEGIN
    DECLARE v_selected_rows INT DEFAULT 1;
    DECLARE v_last_id BIGINT DEFAULT -1;

    IF p_target_table IS NULL
        OR p_target_scope_column IS NULL
        OR p_parent_table IS NULL
        OR p_parent_scope_column IS NULL
        OR p_target_table NOT REGEXP '^[0-9A-Za-z_]+$'
        OR p_target_scope_column NOT REGEXP '^[0-9A-Za-z_]+$'
        OR p_parent_table NOT REGEXP '^[0-9A-Za-z_]+$'
        OR p_parent_scope_column NOT REGEXP '^[0-9A-Za-z_]+$'
    THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Unsafe tenant backfill identifier';
    END IF;

    IF p_batch_size IS NULL OR p_batch_size < 1 OR p_batch_size > 10000 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Tenant backfill batch size must be between 1 and 10000';
    END IF;

    DROP TEMPORARY TABLE IF EXISTS `suxios_tenant_scope_batch`;
    CREATE TEMPORARY TABLE `suxios_tenant_scope_batch` (
        `row_id` BIGINT NOT NULL,
        `parent_tenant_id` BIGINT UNSIGNED NOT NULL,
        PRIMARY KEY (`row_id`)
    ) ENGINE=InnoDB;

    WHILE v_selected_rows > 0 DO
        TRUNCATE TABLE `suxios_tenant_scope_batch`;

        SET @suxios_tenant_scope_insert_sql = CONCAT(
            'INSERT INTO `suxios_tenant_scope_batch` (`row_id`, `parent_tenant_id`) ',
            'SELECT target_row.`id`, parent_row.`tenant_id` ',
            'FROM `', p_target_table, '` target_row ',
            'INNER JOIN `', p_parent_table, '` parent_row ',
            'ON parent_row.`', p_parent_scope_column, '` = target_row.`', p_target_scope_column, '` ',
            'WHERE target_row.`id` > ', v_last_id, ' ',
            'AND parent_row.`tenant_id` IS NOT NULL ',
            'AND parent_row.`tenant_id` > 0 ',
            'AND (target_row.`tenant_id` IS NULL ',
            'OR target_row.`tenant_id` = 0 ',
            'OR target_row.`tenant_id` <> parent_row.`tenant_id`) ',
            'ORDER BY target_row.`id` ',
            'LIMIT ', p_batch_size
        );
        PREPARE suxios_tenant_scope_insert FROM @suxios_tenant_scope_insert_sql;
        EXECUTE suxios_tenant_scope_insert;
        SET v_selected_rows = ROW_COUNT();
        DEALLOCATE PREPARE suxios_tenant_scope_insert;

        IF v_selected_rows > 0 THEN
            SET @suxios_tenant_scope_update_sql = CONCAT(
                'UPDATE `', p_target_table, '` target_row ',
                'INNER JOIN `suxios_tenant_scope_batch` batch_row ',
                'ON batch_row.`row_id` = target_row.`id` ',
                'SET target_row.`tenant_id` = batch_row.`parent_tenant_id`'
            );
            PREPARE suxios_tenant_scope_update FROM @suxios_tenant_scope_update_sql;
            EXECUTE suxios_tenant_scope_update;
            DEALLOCATE PREPARE suxios_tenant_scope_update;

            SELECT MAX(`row_id`) INTO v_last_id FROM `suxios_tenant_scope_batch`;
            COMMIT;
        END IF;
    END WHILE;

    DROP TEMPORARY TABLE IF EXISTS `suxios_tenant_scope_batch`;
END$$

CALL `suxios_backfill_tenant_scope_batched`('agent_tasks', 'hotel_id', 'hotels', 'id', 1000)$$
CALL `suxios_backfill_tenant_scope_batched`('knowledge_categories', 'hotel_id', 'hotels', 'id', 1000)$$
CALL `suxios_backfill_tenant_scope_batched`('knowledge_base', 'hotel_id', 'hotels', 'id', 1000)$$
CALL `suxios_backfill_tenant_scope_batched`('room_types', 'hotel_id', 'hotels', 'id', 1000)$$
CALL `suxios_backfill_tenant_scope_batched`('devices', 'hotel_id', 'hotels', 'id', 1000)$$
CALL `suxios_backfill_tenant_scope_batched`('energy_consumption', 'hotel_id', 'hotels', 'id', 1000)$$
CALL `suxios_backfill_tenant_scope_batched`('competitor_analysis', 'hotel_id', 'hotels', 'id', 1000)$$
CALL `suxios_backfill_tenant_scope_batched`('agent_work_orders', 'hotel_id', 'hotels', 'id', 1000)$$
CALL `suxios_backfill_tenant_scope_batched`('agent_conversations', 'hotel_id', 'hotels', 'id', 1000)$$
CALL `suxios_backfill_tenant_scope_batched`('energy_benchmarks', 'hotel_id', 'hotels', 'id', 1000)$$
CALL `suxios_backfill_tenant_scope_batched`('energy_saving_suggestions', 'hotel_id', 'hotels', 'id', 1000)$$
CALL `suxios_backfill_tenant_scope_batched`('maintenance_plans', 'hotel_id', 'hotels', 'id', 1000)$$
CALL `suxios_backfill_tenant_scope_batched`('hotel_field_templates', 'hotel_id', 'hotels', 'id', 1000)$$
CALL `suxios_backfill_tenant_scope_batched`('competitor_hotel', 'store_id', 'hotels', 'id', 1000)$$
CALL `suxios_backfill_tenant_scope_batched`('competitor_price_log', 'store_id', 'hotels', 'id', 1000)$$
CALL `suxios_backfill_tenant_scope_batched`('opening_projects', 'hotel_id', 'hotels', 'id', 1000)$$
CALL `suxios_backfill_tenant_scope_batched`('operation_alerts', 'hotel_id', 'hotels', 'id', 1000)$$
CALL `suxios_backfill_tenant_scope_batched`('operation_action_tracks', 'hotel_id', 'hotels', 'id', 1000)$$
CALL `suxios_backfill_tenant_scope_batched`('operation_execution_intents', 'hotel_id', 'hotels', 'id', 1000)$$
CALL `suxios_backfill_tenant_scope_batched`('operation_execution_tasks', 'hotel_id', 'hotels', 'id', 1000)$$
CALL `suxios_backfill_tenant_scope_batched`('operation_execution_evidence', 'task_id', 'operation_execution_tasks', 'id', 1000)$$
CALL `suxios_backfill_tenant_scope_batched`('transfer_records', 'hotel_id', 'hotels', 'id', 1000)$$
CALL `suxios_backfill_tenant_scope_batched`('complaint_rooms', 'hotel_id', 'hotels', 'id', 1000)$$
CALL `suxios_backfill_tenant_scope_batched`('complaint_feedbacks', 'hotel_id', 'hotels', 'id', 1000)$$
CALL `suxios_backfill_tenant_scope_batched`('field_mappings', 'hotel_id', 'hotels', 'id', 1000)$$
CALL `suxios_backfill_tenant_scope_batched`('ai_model_call_logs', 'hotel_id', 'hotels', 'id', 1000)$$
CALL `suxios_backfill_tenant_scope_batched`('login_logs', 'user_id', 'users', 'id', 1000)$$
CALL `suxios_backfill_tenant_scope_batched`('quant_simulation_records', 'created_by', 'users', 'id', 1000)$$
CALL `suxios_backfill_tenant_scope_batched`('expansion_records', 'created_by', 'users', 'id', 1000)$$
CALL `suxios_backfill_tenant_scope_batched`('strategy_simulation_records', 'created_by', 'users', 'id', 1000)$$
CALL `suxios_backfill_tenant_scope_batched`('feasibility_reports', 'created_by', 'users', 'id', 1000)$$

DROP PROCEDURE IF EXISTS `suxios_backfill_tenant_scope_batched`$$

DELIMITER ;
