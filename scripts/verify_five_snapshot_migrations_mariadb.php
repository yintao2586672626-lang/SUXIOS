<?php
declare(strict_types=1);
use app\service\DatabaseMigrationExecutionGuard;
use app\service\SchemaVersionService;
require dirname(__DIR__) . '/vendor/autoload.php';
$root = dirname(__DIR__);
$files = ['20261002_create_ai_report_presentation_reviews.sql','20261002_create_operating_evidence_snapshots.sql','20261002_create_room_type_on_books_snapshots.sql'];
$config = SchemaVersionService::databaseConfigFromEnvironment($root);
if (!in_array($config['hostname'] ?? '', ['127.0.0.1','localhost'], true) || (int)($config['hostport'] ?? 0) !== 3306) throw new RuntimeException('local_database_required');
$database = 'suxios_five_snapshot_test_' . gmdate('YmdHis') . '_' . bin2hex(random_bytes(5));
if (!preg_match('/^suxios_five_snapshot_test_[0-9]{14}_[a-f0-9]{10}$/D', $database)) throw new RuntimeException('invalid_test_database');
DatabaseMigrationExecutionGuard::assertAllowed($root, $database, 'mysql', ['SUXI_E2E_DB_OVERRIDE'=>'1','SUXI_E2E_DB_NAME'=>$database]);
$serverConfig=$config; $serverConfig['database']='information_schema';
$server=SchemaVersionService::createPdo($serverConfig);
$exists=$server->prepare('SELECT COUNT(*) FROM SCHEMATA WHERE SCHEMA_NAME=?');$exists->execute([$database]);
if ((int)$exists->fetchColumn() !== 0) throw new RuntimeException('test_database_already_exists');
$created=false; $error=null; $proof=[];
try {
    $server->exec('CREATE DATABASE `'.$database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');$created=true;
    $config['database']=$database;$pdo=SchemaVersionService::createPdo($config);
    if ($pdo->query('SELECT DATABASE()')->fetchColumn() !== $database) throw new RuntimeException('test_database_identity_mismatch');
    $hashes=[];$count=0;
    foreach($files as $file) {
        $path=$root.'/database/migrations/'.$file;$hashes[$file]=hash_file('sha256',$path);
        foreach(SchemaVersionService::splitSqlStatements((string)file_get_contents($path)) as $sql){
            if(!preg_match('/^CREATE (?:TABLE|TRIGGER) IF NOT EXISTS `/i',$sql))throw new RuntimeException('unexpected_test_ddl');
            $pdo->exec($sql);$count++;
        }
    }
    $row=['contract_version'=>'TEST-ONLY','tenant_id'=>900001,'hotel_id'=>900002,'source_hotel_id'=>900002,'platform'=>'ctrip','fact_scope'=>'ota_channel','stay_date'=>'2026-10-03','captured_at'=>'2026-10-02 09:00:00.123456','source_method'=>'manual','source_ref_hash'=>hash('sha256','source'),'room_type_id'=>1,'room_type_name'=>'TEST-ONLY-room','on_books_room_nights'=>2,'on_books_room_revenue'=>null,'cumulative_cancel_room_nights'=>null,'gross_booking_room_nights'=>null,'quality_status'=>'manual','readback_verified'=>1,'supersedes_snapshot_id'=>null,'idempotency_key'=>hash('sha256','request'),'content_digest'=>hash('sha256','content'),'created_by'=>900003];
    $query=$pdo->prepare('INSERT INTO hotel_room_type_on_books_snapshots (`'.implode('`,`',array_keys($row)).'`) VALUES ('.implode(',',array_fill(0,count($row),'?')).')');$query->execute(array_values($row));$id=(int)$pdo->lastInsertId();
    $reject=static function(string $sql,array $params=[])use($pdo):void{
        try{$pdo->prepare($sql)->execute($params);}catch(PDOException $e){if($e->getCode()==='45000')return;throw $e;}
        throw new RuntimeException('immutable_snapshot_mutation_was_allowed');
    };
    $reject('UPDATE hotel_room_type_on_books_snapshots SET on_books_room_nights=3 WHERE id=?',[$id]);
    $reject('DELETE FROM hotel_room_type_on_books_snapshots WHERE id=?',[$id]);
    $pdo->exec('SET @suxi_cloud_hotel_id_migration=1');
    $pdo->prepare('UPDATE hotel_room_type_on_books_snapshots SET hotel_id=900004 WHERE id=?')->execute([$id]);
    $before=$pdo->query('SELECT * FROM hotel_room_type_on_books_snapshots WHERE id='.$id)->fetch(PDO::FETCH_ASSOC);
    $changes=['on_books_room_nights'=>3,'room_type_name'=>'test-only-room','tenant_id'=>900008,'id'=>9999,'contract_version'=>'OTHER','source_hotel_id'=>900008,'platform'=>'meituan','fact_scope'=>'other','stay_date'=>'2026-10-04','captured_at'=>'2026-10-02 09:00:00.123457','source_method'=>'other','source_ref_hash'=>hash('sha256','other'),'room_type_id'=>2,'on_books_room_revenue'=>0,'cumulative_cancel_room_nights'=>0,'gross_booking_room_nights'=>0,'quality_status'=>'other','readback_verified'=>0,'supersedes_snapshot_id'=>9,'idempotency_key'=>hash('sha256','other-request'),'content_digest'=>hash('sha256','other-content'),'created_by'=>900009,'created_at'=>'2026-10-01 00:00:00.000000'];
    foreach($changes as $column=>$value)$reject('UPDATE hotel_room_type_on_books_snapshots SET hotel_id=900005, `'.$column.'`=? WHERE id=?',[$value,$id]);
    $after=$pdo->query('SELECT * FROM hotel_room_type_on_books_snapshots WHERE id='.$id)->fetch(PDO::FETCH_ASSOC);
    if($before!==$after || $after['on_books_room_revenue']!==null || (int)$after['source_hotel_id']!==900002)throw new RuntimeException('exact_readback_failed');
    foreach($hashes as $file=>$hash)if(!hash_equals($hash,(string)hash_file('sha256',$root.'/database/migrations/'.$file)))throw new RuntimeException('test_source_changed');
    $proof=['status'=>'passed','environment'=>'isolated_mariadb','shared_hotelx_written'=>false,'ddl_statements'=>$count,'source_sha256'=>$hashes,'normal_update_delete_rejected'=>true,'hotel_only_rename_allowed'=>true,'other_column_changes_rejected'=>count($changes),'exact_readback'=>true,'unknown_revenue_stays_null'=>true];
}catch(Throwable $e){$error=$e instanceof PDOException?'test_database_operation_failed':$e->getMessage();}
finally{if($created){$pdo=null;$server->exec('DROP DATABASE `'.$database.'`');$exists->execute([$database]);$proof['temporary_database_removed']=(int)$exists->fetchColumn()===0;}}
if($error!==null){fwrite(STDERR,$error.PHP_EOL);exit(1);}
file_put_contents($root.'/output/validation/five-enhancements-20261002/mariadb-migration-check.json',json_encode($proof,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR).PHP_EOL);
echo json_encode($proof,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),PHP_EOL;
