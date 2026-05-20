-- SuXi OS full database initialization entry.
-- Run from the HOTEL project root:
-- mysql -u root hotelx < database/init_full.sql

SET NAMES utf8mb4;
SET time_zone = '+08:00';

SOURCE ./hotelx_dump.sql;
SOURCE ./database/login_logs.sql;
SOURCE ./database/complaint_tables.sql;
SOURCE ./database/update_system_config.sql;
SOURCE ./database/migrations/20250402_create_agent_tables.sql;
SOURCE ./database/migrations/20250402_enhance_agent_tables.sql;
SOURCE ./database/migrations/20260509_create_strategy_simulation_tables.sql;
SOURCE ./database/migrations/20260511_add_ota_traffic_fields.sql;
SOURCE ./database/migrations/20260511_create_ai_model_configs.sql;
SOURCE ./database/migrations/20260511_create_missing_business_tables.sql;
SOURCE ./database/migrations/20260517_add_international_ota_report_fields.sql;
SOURCE ./database/migrations/20260516_create_opening_management_tables.sql;
SOURCE ./database/migrations/20260518_add_opening_task_progress.sql;
SOURCE ./database/migrations/20260516_create_operation_management_tables.sql;
SOURCE ./database/migrations/20260517_create_quant_simulation_records.sql;
SOURCE ./database/migrations/20260517_create_expansion_records.sql;
SOURCE ./database/migrations/20260517_create_transfer_records.sql;
SOURCE ./database/migrations/20260518_create_knowledge_center_tables.sql;
SOURCE ./database/migrations/20260519_seed_ctrip_browser_capture_knowledge.sql;
SOURCE ./database/migrations/20260519_seed_meituan_browser_capture_knowledge.sql;
SOURCE ./database/migrations/20260520_seed_ota_platform_field_inventory_knowledge.sql;
SOURCE ./database/migrations/20260520_seed_ota_standard_metrics_knowledge.sql;
SOURCE ./database/migrations/20260520_seed_ota_data_product_matrix_knowledge.sql;
SOURCE ./database/migrations/20260520_seed_ota_data_architecture_governance_knowledge.sql;
SOURCE ./database/migrations/20260520_seed_ota_manual_auto_collection_strategy_knowledge.sql;
SOURCE ./database/migrations/20260520_seed_ota_data_operation_reference_knowledge.sql;
