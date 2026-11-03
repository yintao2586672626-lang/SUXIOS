#!/usr/bin/env php
<?php
declare(strict_types=1);

use think\App;
use think\facade\Db;

require dirname(__DIR__) . '/vendor/autoload.php';
const EXPECTED_PACK_SHA256 = '2fa0b8e94ea32baa54eab1b3d46fa0ea013fbe61e08eb09388914ef5f1439733';
$projectRoot = dirname(__DIR__);
foreach (spl_autoload_functions() ?: [] as $autoloadFunction) {
    $loader = is_array($autoloadFunction) ? ($autoloadFunction[0] ?? null) : null;
    if ($loader instanceof \Composer\Autoload\ClassLoader) {
        $loader->setPsr4('app\\', [$projectRoot . DIRECTORY_SEPARATOR . 'app']);
    }
}
(new App($projectRoot))->initialize();

function encodeJson(mixed $value): string
{
    return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
}

function contentDigest(array $value): string
{
    return (new \app\service\KnowledgeContentDigestService())->digest($value);
}

function failIf(bool $condition, string $code): void
{
    if ($condition) {
        throw new RuntimeException($code);
    }
}

try {
    $options = getopt('', ['persist', 'upgrade', 'source:']);
    $packPath = $projectRoot . '/docs/knowledge/qiansu-talent-growth/absorption-pack.json';
    $sourcePath = trim((string)($options['source'] ?? ''));
    if ($sourcePath === '') {
        $sourcePath = $projectRoot . '/output/source-archives/qiansu-talent-growth-2a779872.zip';
    }
    failIf(!is_file($packPath) || !is_file($sourcePath), 'source_or_pack_missing');
    failIf(!hash_equals(EXPECTED_PACK_SHA256, (string)hash_file('sha256', $packPath)), 'pack_hash_mismatch');
    $pack = json_decode((string)file_get_contents($packPath), true, 512, JSON_THROW_ON_ERROR);
    failIf(!is_array($pack) || (int)($pack['schema_version'] ?? 0) !== 1, 'pack_schema_invalid');
    $source = (array)($pack['source'] ?? []);
    $sourceHash = strtolower((string)($source['sha256'] ?? ''));
    failIf(!preg_match('/^[a-f0-9]{64}$/D', $sourceHash), 'source_hash_invalid');
    failIf((int)($source['bytes'] ?? 0) !== filesize($sourcePath), 'source_size_mismatch');
    failIf(!hash_equals($sourceHash, (string)hash_file('sha256', $sourcePath)), 'source_hash_mismatch');
    failIf(($pack['boundary']['reference_only'] ?? null) !== true
        || ($pack['boundary']['decision_safe'] ?? null) !== false
        || ($pack['boundary']['task_draft_safe'] ?? null) !== false
        || ($pack['boundary']['external_write_authorized'] ?? null) !== false,
        'unsafe_boundary');
    $entries = array_values((array)($pack['entries'] ?? []));
    failIf(count($entries) < 1, 'entries_missing');
    $expected = [];
    foreach ($entries as $entry) {
        failIf(!is_array($entry), 'entry_invalid');
        $key = (string)($entry['key'] ?? '');
        failIf(!preg_match('/^[a-z][a-z0-9_]{4,80}$/D', $key) || isset($expected[$key]), 'entry_key_invalid');
        failIf(!in_array($entry['disposition'] ?? '', ['store_only', 'absorption_candidate', 'reject_or_quarantine'], true), 'entry_disposition_invalid');
        $expected[$key] = [
            'seed_owner' => (string)$pack['seed_owner'],
            'seed_key' => $key,
            'seed_version' => '2026-09-26.' . substr($sourceHash, 0, 12),
            'module_id' => $key,
            'module_name' => '黔宿人才成长机制参考',
            'title' => (string)$entry['title'],
            'summary' => (string)$entry['contract'],
            'scope' => 'external_product_reference_only',
            'evidence_level' => 'user_provided_source_static_reviewed',
            'evidence_grade' => $entry['disposition'] === 'reject_or_quarantine' ? 'D' : 'C',
            'content_type' => 'reference_knowledge',
            'reviewed_at' => '2026-09-26 00:00:00',
            'review_due_at' => '2026-12-26 00:00:00',
            'lifecycle_status' => 'active',
            'source' => $source,
            'reference_only' => true,
            'decision_safe' => false,
            'task_draft_safe' => false,
            'contains_current_hotel_fact' => false,
            'contains_current_ota_fact' => false,
            'external_write_authorized' => false,
            'knowledge_scope' => 'global_product_reference',
            'blocked_uses' => ['operation_task_creation', 'operation_execution', 'automatic_ota_write'],
            'boundaries' => [(string)$entry['boundary']],
            'source_refs' => array_map(
                static fn(string $path): string => 'user-provided://部署包.zip#sha256=' . $sourceHash . '&path=' . $path,
                array_values((array)($entry['source_files'] ?? []))
            ),
            'boundary' => $pack['boundary'],
            'entry' => $entry,
            'text' => (string)($entry['contract'] ?? ''),
        ];
    }

    $summary = [
        'status' => 'validated',
        'source_sha256' => $sourceHash,
        'entry_count' => count($entries),
        'dispositions' => array_count_values(array_column($entries, 'disposition')),
        'business_flow_reproduced' => true,
        'business_flow_reproduced_scope' => 'training_cycle_real_services_in_isolated_sqlite_only',
        'functional_integration_claimed' => false,
        'local_implementation' => 'coaching_and_reference_knowledge_verified_with_synthetic_data;local_mysql_schema_activated_and_readback_verified;account_and_field_unverified',
    ];
    if (!array_key_exists('persist', $options)) {
        echo encodeJson($summary) . PHP_EOL;
        exit(0);
    }

    $allowUpgrade = array_key_exists('upgrade', $options);
    $result = Db::transaction(static function () use ($pack, $expected, $summary, $allowUpgrade): array {
        $stableKey = (string)$pack['stable_key'];
        $now = date('Y-m-d H:i:s');
        $unit = Db::name('knowledge_units')->where('stable_key', $stableKey)->lock(true)->find();
        $created = false;
        if (!is_array($unit)) {
            $unitId = (int)Db::name('knowledge_units')->insertGetId([
                'hotel_id' => 0,
                'stable_key' => $stableKey,
                'current_chunk_id' => null,
                'name' => (string)$pack['title'],
                'source' => 'qiansu_source_review',
                'status' => 'done',
                'lifecycle_status' => 'active',
                'lifecycle_reason' => 'user_provided_source_reviewed_reference_only',
                'reviewed_at' => $now,
                'review_due_at' => '2026-12-26 00:00:00',
                'known_knowns' => encodeJson(['source_archive_hash_verified', 'source_code_read', 'no_current_hotel_facts']),
                'known_unknowns' => encodeJson($pack['known_unknowns']),
                'truth_profile_version' => '2026-09-26.1',
                'description' => (string)$pack['description'],
                'tags' => encodeJson(['黔宿', '人才成长', '源码参考', '未验证', 'reference_only']),
                'created_by' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            failIf($unitId <= 0, 'unit_insert_failed');
            $created = true;
        } else {
            $unitId = (int)$unit['unit_id'];
            failIf((string)$unit['source'] !== 'qiansu_source_review'
                || (string)$unit['name'] !== (string)$pack['title']
                || (int)$unit['hotel_id'] !== 0
                || (int)$unit['created_by'] !== 0,
                'stable_key_owned_by_other_content');
        }

        $allRows = Db::name('knowledge_chunks')->where('unit_id', $unitId)->lock(true)->select()->toArray();
        if ($allowUpgrade) {
            foreach ($allRows as $historical) {
                if ((string)$historical['lifecycle_status'] !== 'superseded') {
                    continue;
                }
                $historicalContent = json_decode((string)$historical['content'], true, 512, JSON_THROW_ON_ERROR);
                failIf(($historicalContent['seed_owner'] ?? null) !== $pack['seed_owner'], 'history_owner_mismatch');
                if (($historicalContent['lifecycle_status'] ?? '') !== 'superseded') {
                    $historicalContent['lifecycle_status'] = 'superseded';
                    Db::name('knowledge_chunks')->where('chunk_id', (int)$historical['chunk_id'])->update([
                        'content' => encodeJson($historicalContent),
                        'content_digest' => contentDigest($historicalContent),
                    ]);
                }
            }
        }
        $rows = array_values(array_filter($allRows, static fn(array $row): bool => (string)$row['lifecycle_status'] === 'active'));
        $upgraded = false;
        if ($created) {
            foreach ($expected as $content) {
                $encoded = encodeJson($content);
                Db::name('knowledge_chunks')->insert([
                    'unit_id' => $unitId,
                    'version_no' => 1,
                    'lifecycle_status' => 'active',
                    'content_digest' => contentDigest($content),
                    'superseded_by_chunk_id' => null,
                    'published_at' => $now,
                    'retired_at' => null,
                    'type' => 'qiansu_talent_growth_reference',
                    'content' => $encoded,
                    'created_by' => 0,
                    'created_at' => $now,
                ]);
            }
            $rows = Db::name('knowledge_chunks')->where('unit_id', $unitId)->lock(true)->select()->toArray();
        } elseif ($allowUpgrade) {
            failIf(count($rows) !== count($expected), 'upgrade_active_count_mismatch');
            $oldByKey = [];
            foreach ($rows as $row) {
                $oldContent = json_decode((string)$row['content'], true, 512, JSON_THROW_ON_ERROR);
                $oldKey = (string)($oldContent['seed_key'] ?? '');
                failIf(!isset($expected[$oldKey]) || isset($oldByKey[$oldKey])
                    || ($oldContent['seed_owner'] ?? null) !== $pack['seed_owner']
                    || ($oldContent['source']['sha256'] ?? null) !== $pack['source']['sha256'],
                    'upgrade_owner_or_source_mismatch');
                $oldByKey[$oldKey] = $row;
            }
            $needsUpgrade = false;
            foreach ($expected as $key => $content) {
                $oldContent = json_decode((string)$oldByKey[$key]['content'], true, 512, JSON_THROW_ON_ERROR);
                if (contentDigest($oldContent) !== contentDigest($content)) {
                    $needsUpgrade = true;
                }
            }
            if ($needsUpgrade) {
                foreach ($expected as $key => $content) {
                    $old = $oldByKey[$key];
                    $newId = (int)Db::name('knowledge_chunks')->insertGetId([
                        'unit_id' => $unitId,
                        'version_no' => (int)$old['version_no'] + 1,
                        'lifecycle_status' => 'active',
                        'content_digest' => contentDigest($content),
                        'superseded_by_chunk_id' => null,
                        'published_at' => $now,
                        'retired_at' => null,
                        'type' => 'qiansu_talent_growth_reference',
                        'content' => encodeJson($content),
                        'created_by' => 0,
                        'created_at' => $now,
                    ]);
                    failIf($newId <= 0, 'upgrade_insert_failed');
                    $oldContent = json_decode((string)$old['content'], true, 512, JSON_THROW_ON_ERROR);
                    $oldContent['lifecycle_status'] = 'superseded';
                    Db::name('knowledge_chunks')->where('chunk_id', (int)$old['chunk_id'])->update([
                        'lifecycle_status' => 'superseded',
                        'superseded_by_chunk_id' => $newId,
                        'retired_at' => $now,
                        'content' => encodeJson($oldContent),
                        'content_digest' => contentDigest($oldContent),
                    ]);
                }
                $rows = Db::name('knowledge_chunks')->where('unit_id', $unitId)
                    ->where('lifecycle_status', 'active')->lock(true)->select()->toArray();
                $upgraded = true;
            }
        }
        failIf(count($rows) !== count($expected), 'chunk_count_mismatch');
        $readback = [];
        foreach ($rows as $row) {
            $content = json_decode((string)$row['content'], true, 512, JSON_THROW_ON_ERROR);
            $key = (string)($content['seed_key'] ?? '');
            failIf(!isset($expected[$key]) || isset($readback[$key]), 'chunk_key_mismatch');
            failIf(($content['seed_owner'] ?? null) !== $pack['seed_owner']
                || (string)$row['type'] !== 'qiansu_talent_growth_reference'
                || (string)$row['lifecycle_status'] !== 'active'
                || contentDigest($content) !== contentDigest($expected[$key])
                || (string)$row['content_digest'] !== contentDigest($expected[$key])
                || ($content['decision_safe'] ?? null) !== false
                || ($content['task_draft_safe'] ?? null) !== false
                || ($content['external_write_authorized'] ?? null) !== false,
                'chunk_readback_mismatch');
            $readback[$key] = (int)$row['chunk_id'];
        }
        ksort($readback);
        $currentChunkId = $readback['source_to_reviewed_candidate'] ?? reset($readback);
        failIf(!is_int($currentChunkId) || $currentChunkId <= 0, 'current_chunk_missing');
        if ($created || $upgraded) {
            Db::name('knowledge_units')->where('unit_id', $unitId)->update(['current_chunk_id' => $currentChunkId]);
        }
        $unitReadback = Db::name('knowledge_units')->where('unit_id', $unitId)->find();
        failIf(!is_array($unitReadback)
            || (string)$unitReadback['stable_key'] !== $stableKey
            || (int)$unitReadback['current_chunk_id'] !== $currentChunkId
            || (string)$unitReadback['lifecycle_status'] !== 'active',
            'unit_readback_mismatch');
        return $summary + [
            'status' => 'success',
            'persisted' => true,
            'created' => $created,
            'upgraded' => $upgraded,
            'unit_id' => $unitId,
            'current_chunk_id' => $currentChunkId,
            'chunk_ids' => $readback,
            'readback_verified' => true,
        ];
    });
    $result['status'] = 'success';
    echo encodeJson($result) . PHP_EOL;
} catch (Throwable $exception) {
    fwrite(STDERR, encodeJson([
        'status' => 'failed',
        'reason' => preg_replace('/[^a-zA-Z0-9:_-]+/', '_', $exception->getMessage()),
    ]) . PHP_EOL);
    exit(2);
}
