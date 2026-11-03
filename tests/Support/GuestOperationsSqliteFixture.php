<?php
declare(strict_types=1);
namespace Tests\Support;

use think\facade\Db;

/** Synthetic fixture only; callers must bind a unique temporary SQLite database first. */
final class GuestOperationsSqliteFixture
{
    public static function create(): void
    {
        foreach (['guest_operation_requests', 'guest_operation_heads', 'guest_operation_records', 'user_hotel_permissions', 'users', 'roles', 'hotels'] as $table) Db::execute('DROP TABLE IF EXISTS ' . $table);
        Db::execute('CREATE TABLE hotels(id INTEGER PRIMARY KEY, tenant_id INTEGER, status INTEGER, name TEXT)');
        Db::execute("INSERT INTO hotels VALUES(80,10,1,'Test Hotel'),(81,20,1,'Other Hotel'),(82,10,1,'Second Hotel')");
        Db::execute('CREATE TABLE users(id INTEGER PRIMARY KEY,tenant_id INTEGER,status INTEGER,hotel_id INTEGER,role_id INTEGER)');
        Db::execute('INSERT INTO users VALUES(11,10,1,80,1),(12,20,1,81,1),(13,10,1,80,3),(14,10,0,80,1),(15,10,1,80,2),(16,10,1,80,3)');
        Db::execute("ALTER TABLE users ADD COLUMN username TEXT DEFAULT ''");
        Db::execute("ALTER TABLE users ADD COLUMN realname TEXT DEFAULT ''");
        Db::execute("UPDATE users SET username='synthetic-' || id, realname=CASE WHEN id=11 THEN 'Synthetic owner' ELSE '' END");
        Db::execute('CREATE TABLE roles(id INTEGER PRIMARY KEY,status INTEGER,name TEXT,level INTEGER,permissions TEXT)');
        Db::execute("INSERT INTO roles VALUES(2,1,'hotel_manager',2,'[\"operation.view\",\"operation.execute\"]'),(3,1,'viewer',3,'[\"operation.view\",\"operation.execute\"]')");
        Db::execute('CREATE TABLE user_hotel_permissions(id INTEGER PRIMARY KEY,user_id INTEGER,hotel_id INTEGER,tenant_id INTEGER,status TEXT,can_view INTEGER,can_operation INTEGER)');
        Db::execute("INSERT INTO user_hotel_permissions VALUES(1,13,80,10,'active',1,0),(2,15,80,10,'active',1,1),(3,16,80,10,'active',1,1)");
        Db::execute('CREATE TABLE guest_operation_records(id INTEGER PRIMARY KEY AUTOINCREMENT,tenant_id INTEGER,hotel_id INTEGER,source_hotel_id INTEGER,kind TEXT,record_key TEXT,revision INTEGER,business_date TEXT,platform TEXT,content_json TEXT,content_digest TEXT,created_by INTEGER,created_at TEXT,UNIQUE(tenant_id,hotel_id,kind,record_key,revision))');
        Db::execute('CREATE TABLE guest_operation_heads(id INTEGER PRIMARY KEY AUTOINCREMENT,tenant_id INTEGER,hotel_id INTEGER,source_hotel_id INTEGER,kind TEXT,record_key TEXT,record_id INTEGER,revision INTEGER,UNIQUE(tenant_id,hotel_id,kind,record_key))');
        Db::execute('CREATE TABLE guest_operation_requests(id INTEGER PRIMARY KEY AUTOINCREMENT,tenant_id INTEGER,hotel_id INTEGER,source_hotel_id INTEGER,request_key TEXT,input_digest TEXT,record_ids_json TEXT,created_by INTEGER,created_at TEXT,UNIQUE(tenant_id,hotel_id,request_key))');
    }
}
