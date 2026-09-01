#!/usr/bin/env php
<?php
declare(strict_types=1);

use app\service\KnowledgeDecisionGateService;
use app\service\OperatingQuestionKnowledgeRetrievalService;
use think\App;
use think\facade\Db;

require dirname(__DIR__) . '/vendor/autoload.php';
(new App())->initialize();

/** @return array<string,mixed> */
function decodeAiAgentLearningContent(mixed $value): array
{
    if (is_array($value)) {
        return $value;
    }
    if (!is_string($value) || trim($value) === '') {
        return [];
    }
    $decoded = json_decode($value, true);
    return is_array($decoded) ? $decoded : [];
}

$root = dirname(__DIR__);
$unitName = 'AI Agent 学习路线与最小构成（截图参考）';
$stableKey = 'global:ai_agent_learning_reference';
$seedOwner = 'suxios.ai_agent_learning_reference';
$seedVersion = '2026-09-01.1';
$expectedSources = [
    'ai-agent-90-day-roadmap-visible-reference.png'
        => '001E8A67BC2C150E9EBC8844D86EC66653EFEAA8577815C8548BF447A5D1680E',
    'ai-agent-zero-to-one-concept-visible-reference.png'
        => 'D3357D19A625B3092CBDABEFE9B4CE57EB1B312818D77F9B3315FDAB34DCF728',
];
$expectedTypes = [
    'ai_agent_roadmap_visible_source_index',
    'ai_agent_visible_concept_map',
    'suxios_agent_contract_candidate',
];
$errors = [];
$summary = [];

try {
    foreach ($expectedSources as $file => $expectedHash) {
        $path = $root . '/docs/knowledge/ai-agent-learning-reference/sources/' . $file;
        if (!is_file($path)) {
            $errors[] = 'source_file_missing:' . $file;
            continue;
        }
        if (strtoupper((string)hash_file('sha256', $path)) !== $expectedHash) {
            $errors[] = 'source_hash_mismatch:' . $file;
        }
    }

    $manifestPath = $root . '/docs/knowledge/ai-agent-learning-reference/source-manifest.json';
    $referencePath = $root . '/docs/knowledge/ai-agent-learning-reference/reference-pack.json';
    $manifest = is_file($manifestPath)
        ? json_decode((string)file_get_contents($manifestPath), true)
        : null;
    $reference = is_file($referencePath)
        ? json_decode((string)file_get_contents($referencePath), true)
        : null;
    if (!is_array($manifest)
        || ($manifest['task_mode'] ?? '') !== 'classify'
        || ($manifest['executed_path'] ?? '') !== 'storage_only_reference_closure'
        || ($manifest['disposition'] ?? '') !== 'absorption_candidate'
        || ($manifest['maturity'] ?? '') !== 'understood_visible_structure'
        || ($manifest['mapping_status'] ?? '') !== 'unverified'
        || ($manifest['gates']['mechanism'] ?? '') !== 'partial'
        || ($manifest['gates']['value'] ?? '') !== 'pass'
        || ($manifest['gates']['reproduction'] ?? '') !== 'fail'
        || count((array)($manifest['sources'] ?? [])) !== 2
    ) {
        $errors[] = 'source_manifest_contract_mismatch';
    }
    if (!is_array($reference)
        || ($reference['usage_policy'] ?? '') !== 'reference_only'
        || ($reference['external_write_authorized'] ?? null) !== false
        || ($reference['contains_current_hotel_fact'] ?? null) !== false
        || ($reference['contains_current_ota_fact'] ?? null) !== false
        || count((array)($reference['visible_roadmap'] ?? [])) !== 18
        || ($reference['suxios_candidate_contract']['evidence_status'] ?? '')
            !== 'source_inspired_candidate_not_source_reproduced'
    ) {
        $errors[] = 'reference_pack_contract_mismatch';
    }

    $units = Db::name('knowledge_units')
        ->where('stable_key', $stableKey)
        ->order('unit_id', 'asc')
        ->select()
        ->toArray();
    if (count($units) !== 1) {
        $errors[] = 'unit_count:' . count($units);
    }
    $unit = $units[0] ?? [];
    $unitId = (int)($unit['unit_id'] ?? 0);
    if ($unitId <= 0
        || (int)($unit['hotel_id'] ?? -1) !== 0
        || (int)($unit['created_by'] ?? -1) !== 0
        || (string)($unit['name'] ?? '') !== $unitName
        || (string)($unit['status'] ?? '') !== 'done'
        || (string)($unit['lifecycle_status'] ?? '') !== 'active'
        || (string)($unit['truth_profile_version'] ?? '') !== $seedVersion
    ) {
        $errors[] = 'unit_contract_incomplete';
    }

    $rows = $unitId > 0
        ? Db::name('knowledge_chunks')
            ->where('unit_id', $unitId)
            ->order('chunk_id', 'asc')
            ->select()
            ->toArray()
        : [];
    $seeded = [];
    $seedKeyCounts = [];
    $gate = new KnowledgeDecisionGateService();
    foreach ($rows as $row) {
        $content = decodeAiAgentLearningContent($row['content'] ?? null);
        if ((string)($content['seed_owner'] ?? '') !== $seedOwner) {
            continue;
        }
        $type = (string)($row['type'] ?? '');
        $seedKey = (string)($content['seed_key'] ?? '');
        $seedKeyCounts[$seedKey] = ($seedKeyCounts[$seedKey] ?? 0) + 1;
        if (isset($seeded[$type])) {
            $errors[] = 'duplicate_seeded_type:' . $type;
        }
        $seeded[$type] = $content;

        $assessment = $gate->assess($unit, $content, '2026-09-01 12:00:00');
        if (($assessment['status'] ?? '') !== KnowledgeDecisionGateService::STATUS_REFERENCE_ONLY
            || ($assessment['retrieval_safe'] ?? false) !== true
            || ($assessment['decision_safe'] ?? true) !== false
            || ($assessment['task_draft_safe'] ?? true) !== false
        ) {
            $errors[] = 'knowledge_gate_mismatch:' . $type;
        }
        if (($content['decision_safe'] ?? null) !== false
            || ($content['task_draft_safe'] ?? null) !== false
            || ($content['contains_current_hotel_fact'] ?? null) !== false
            || ($content['contains_current_ota_fact'] ?? null) !== false
            || ($content['external_write_authorized'] ?? null) !== false
            || ($content['seed_version'] ?? '') !== $seedVersion
            || ($content['platforms'] ?? null) !== []
        ) {
            $errors[] = 'truth_or_execution_boundary_mismatch:' . $type;
        }
        $digest = strtoupper(trim((string)($row['content_digest'] ?? '')));
        if (preg_match('/^[A-F0-9]{64}$/D', $digest) !== 1) {
            $errors[] = 'content_digest_missing_or_invalid:' . $type;
        }
        $sourceManifest = is_array($content['source_manifest'] ?? null)
            ? $content['source_manifest']
            : [];
        $documents = is_array($sourceManifest['sources'] ?? null)
            ? $sourceManifest['sources']
            : [];
        $manifestHashes = array_values(array_filter(array_map(
            static fn(mixed $document): string => is_array($document)
                ? strtoupper(trim((string)($document['sha256'] ?? '')))
                : '',
            $documents
        )));
        foreach ($expectedSources as $expectedHash) {
            if (!in_array($expectedHash, $manifestHashes, true)) {
                $errors[] = 'source_manifest_hash_missing:' . $type . ':' . $expectedHash;
            }
        }
    }

    foreach ($expectedTypes as $type) {
        if (!isset($seeded[$type])) {
            $errors[] = 'seeded_chunk_missing:' . $type;
        }
        $expectedSeedKey = 'ai_agent_learning_reference:' . $type;
        if (($seedKeyCounts[$expectedSeedKey] ?? 0) !== 1) {
            $errors[] = 'seed_key_multiplicity:' . $expectedSeedKey;
        }
    }
    if (count($seeded) !== count($expectedTypes)) {
        $errors[] = 'seeded_type_count:' . count($seeded);
    }

    $roadmap = $seeded['ai_agent_roadmap_visible_source_index'] ?? [];
    $roadmapDays = array_map(
        static fn(mixed $item): int => is_array($item) ? (int)($item['day'] ?? 0) : 0,
        (array)($roadmap['visible_milestones'] ?? [])
    );
    if ($roadmapDays !== [1, 3, 5, 7, 9, 11, 13, 19, 24, 30, 35, 40, 43, 45, 47, 55, 70, 90]
        || ($roadmap['disposition'] ?? '') !== 'store_only'
    ) {
        $errors[] = 'roadmap_visible_index_mismatch';
    }

    $concept = $seeded['ai_agent_visible_concept_map'] ?? [];
    if (($concept['visible_sequence'] ?? null)
            !== ['LLM', 'LLM API', 'Context', 'Tool Calling', 'Agent Loop']
        || ($concept['visible_agent_loop_wording'] ?? null) !== ['思考', '行动', '观察']
        || count((array)($concept['visible_supporting_branches'] ?? [])) !== 3
    ) {
        $errors[] = 'agent_visible_concept_map_mismatch';
    }

    $candidate = $seeded['suxios_agent_contract_candidate'] ?? [];
    if (($candidate['gates']['mechanism'] ?? '') !== 'partial'
        || ($candidate['gates']['value'] ?? '') !== 'pass'
        || ($candidate['gates']['reproduction'] ?? '') !== 'fail'
        || ($candidate['future_reproduction_contract']['evidence_status'] ?? '')
            !== 'future_golden_sample_contract_not_source_reproduction_evidence'
    ) {
        $errors[] = 'candidate_gate_mismatch';
    }

    $mirrors = Db::name('knowledge_base')
        ->where('tenant_id', 0)
        ->where('hotel_id', 0)
        ->where('title', $unitName)
        ->where('is_enabled', 1)
        ->select()
        ->toArray();
    if (count($mirrors) !== 1) {
        $errors[] = 'knowledge_base_mirror_count:' . count($mirrors);
    }

    $retrieval = (new OperatingQuestionKnowledgeRetrievalService())->retrieve(
        80,
        1,
        'all_ota',
        'AI Agent LLM API Context Tool Calling Agent Loop MCP Sub-Agent Skill Memory 学习路线'
    );
    $matches = array_values(array_filter(
        (array)($retrieval['items'] ?? []),
        static fn(mixed $item): bool => is_array($item)
            && (string)($item['name'] ?? '') === $unitName
            && (string)($item['usage_policy'] ?? '') === 'reference_only'
            && ($item['platforms'] ?? null) === []
    ));
    if ($matches === []) {
        $errors[] = 'reference_retrieval_missing';
    }

    $summary = [
        'unit_id' => $unitId,
        'unit_readback_count' => count($units),
        'seeded_chunk_count' => count($seeded),
        'chunk_types' => array_keys($seeded),
        'source_hashes' => array_values($expectedSources),
        'knowledge_base_mirror_count' => count($mirrors),
        'retrieval_status' => (string)($retrieval['status'] ?? ''),
        'reference_match_count' => count($matches),
    ];
} catch (Throwable $exception) {
    $errors[] = 'exception:' . $exception->getMessage();
}

echo json_encode([
    'status' => $errors === [] ? 'ok' : 'failed',
    'knowledge_unit' => $unitName,
    'summary' => $summary,
    'errors' => $errors,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;
exit($errors === [] ? 0 : 1);
