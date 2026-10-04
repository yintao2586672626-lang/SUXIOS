<?php
declare(strict_types=1);
namespace Tests\Support;

use think\facade\Db;

final class OperatingWorkbenchSqliteFixture
{
    public static function create(): void
    {
        Db::execute('CREATE TABLE hotels (id INTEGER PRIMARY KEY,tenant_id INTEGER,name TEXT,status INTEGER)');
        Db::execute("INSERT INTO hotels VALUES(80,10,'synthetic A',1),(81,10,'synthetic B',1),(82,11,'foreign',1)");
        Db::execute('CREATE TABLE room_types(id INTEGER PRIMARY KEY,hotel_id INTEGER,name TEXT,is_enabled INTEGER)');
        Db::execute("INSERT INTO room_types VALUES(1,80,'synthetic room',1),(2,82,'foreign room',1)");
        Db::execute('CREATE TABLE hotel_business_workspace_snapshots (id INTEGER PRIMARY KEY AUTOINCREMENT,tenant_id INTEGER,hotel_id INTEGER,source_hotel_id INTEGER,owner_user_id INTEGER,kind TEXT,previous_id INTEGER,idempotency_key TEXT,payload_json TEXT,content_digest TEXT,created_by INTEGER,created_at TEXT,UNIQUE(tenant_id,hotel_id,owner_user_id,kind,idempotency_key))');
        Db::execute('CREATE TABLE monthly_tasks (id INTEGER PRIMARY KEY,tenant_id INTEGER,hotel_id INTEGER,year INTEGER,month INTEGER,status INTEGER,task_data TEXT)');
        Db::execute('CREATE TABLE daily_reports (id INTEGER PRIMARY KEY AUTOINCREMENT,tenant_id INTEGER,hotel_id INTEGER,report_date TEXT,status INTEGER,report_data TEXT)');
        Db::execute('CREATE TABLE operating_target_daily_records (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, hotel_id INTEGER, target_date TEXT, target_revenue NUMERIC, target_occupancy_rate_percent NUMERIC, target_revpar NUMERIC, actual_revenue NUMERIC, sold_room_nights INTEGER, sellable_room_nights INTEGER, fact_scope TEXT, source_type TEXT, source_reference TEXT, quality_status TEXT, quality_reason TEXT, fact_captured_at TEXT, calculation_status TEXT, gap_codes_json TEXT, calculation_json TEXT, report_status TEXT, created_by INTEGER, updated_by INTEGER, create_time TEXT, update_time TEXT, UNIQUE(tenant_id,hotel_id,target_date))');
        Db::execute('CREATE TABLE operating_target_daily_snapshots (id INTEGER PRIMARY KEY AUTOINCREMENT,record_id INTEGER,tenant_id INTEGER,hotel_id INTEGER,target_date TEXT,revision_no INTEGER,change_reason TEXT,snapshot_json TEXT,created_by INTEGER,create_time TEXT)');
        $shared = 'id INTEGER PRIMARY KEY AUTOINCREMENT,contract_version TEXT,tenant_id INTEGER,hotel_id INTEGER,source_hotel_id INTEGER,platform TEXT,fact_scope TEXT,stay_date TEXT,captured_at TEXT,source_method TEXT,source_ref_hash TEXT,on_books_room_nights REAL,on_books_room_revenue REAL,cumulative_cancel_room_nights REAL,gross_booking_room_nights REAL,quality_status TEXT,readback_verified INTEGER,idempotency_key TEXT,content_digest TEXT,created_by INTEGER,created_at TEXT';
        Db::execute('CREATE TABLE hotel_on_books_snapshots (' . $shared . ',UNIQUE(tenant_id,hotel_id,platform,stay_date,idempotency_key))');
        Db::execute('CREATE TABLE hotel_room_type_on_books_snapshots (' . $shared . ',room_type_id INTEGER,room_type_name TEXT,supersedes_snapshot_id INTEGER,UNIQUE(tenant_id,hotel_id,idempotency_key))');
    }
}
