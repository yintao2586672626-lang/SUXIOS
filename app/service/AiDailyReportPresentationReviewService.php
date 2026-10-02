<?php
declare(strict_types=1);

namespace app\service;

use InvalidArgumentException;
use RuntimeException;
use think\facade\Db;

/** Report-content review, deliberately separate from operating decisions and approvals. */
final class AiDailyReportPresentationReviewService
{
    public const TABLE = 'ai_report_presentation_reviews';
    public const CONTRACT = 'ai_report_presentation_review.v1';
    private const SPEC_TABLE = 'ai_report_presentation_specs';

    public function readForSpec(array $storedSpec, array $permittedHotelIds): array
    {
        $this->assertSpec($storedSpec, $permittedHotelIds);
        $pending = $this->pending($storedSpec);
        $row = Db::name(self::TABLE)->where('tenant_id', $storedSpec['tenant_id'])->where('hotel_id', $storedSpec['hotel_id'])
            ->where('report_id', $storedSpec['report_id'])->where('presentation_spec_id', $storedSpec['record_id'])
            ->where('spec_fingerprint', $storedSpec['spec_fingerprint'])->order('id', 'desc')->find();
        return $row ? $this->hydrate($row, $pending) : $pending + ['review_id' => null, 'readback_verified' => false, 'external_write_count' => 0];
    }

    public function saveAndReadback(array $storedSpec, array $permittedHotelIds, array $input, int $userId): array
    {
        $this->assertSpec($storedSpec, $permittedHotelIds);
        if ($userId <= 0) throw new InvalidArgumentException('presentation_review_actor_required');
        $expected = trim((string)($input['expected_review_fingerprint'] ?? ''));
        if (!preg_match('/^[a-f0-9]{64}$/D', $expected)) throw new InvalidArgumentException('presentation_review_expected_fingerprint_required');
        $changes = $input['decisions'] ?? null;
        if (!is_array($changes) || !array_is_list($changes) || $changes === []) throw new InvalidArgumentException('presentation_review_decisions_required');
        $requestKey = trim((string)($input['idempotency_key'] ?? ''));
        if ($requestKey === '') $requestKey = $this->hash(['spec' => $storedSpec['spec_fingerprint'], 'base' => $expected, 'changes' => $changes, 'user' => $userId]);
        if (!preg_match('/^[A-Za-z0-9_-]{8,100}$/D', $requestKey)) throw new InvalidArgumentException('presentation_review_idempotency_key_invalid');
        $requestKey = hash('sha256', $requestKey);
        $requestHash = $this->hash(['spec' => $storedSpec['spec_fingerprint'], 'base' => $expected, 'changes' => $changes, 'user' => $userId]);
        return Db::transaction(function () use ($storedSpec, $permittedHotelIds, $expected, $changes, $requestKey, $requestHash, $userId): array {
            // Shared with artifact creation to bind formal exports to the latest saved review.
            Db::name(self::SPEC_TABLE)->where('id', $storedSpec['record_id'])->lock(true)->find();
            $replay = Db::name(self::TABLE)->where('tenant_id', $storedSpec['tenant_id'])->where('hotel_id', $storedSpec['hotel_id'])
                ->where('presentation_spec_id', $storedSpec['record_id'])->where('request_key', $requestKey)->lock(true)->find();
            if ($replay) {
                if (!hash_equals((string)$replay['request_hash'], $requestHash)) throw new RuntimeException('presentation_review_idempotency_conflict', 409);
                return $this->hydrate($replay, $this->pending($storedSpec)) + ['idempotent' => true];
            }
            $current = $this->readForSpec($storedSpec, $permittedHotelIds);
            if (!hash_equals($current['review_fingerprint'], $expected)) throw new RuntimeException('presentation_review_stale', 409);
            $items = $current['items'];
            $indices = array_flip(array_column($items, 'id'));
            $seen = [];
            foreach ($changes as $change) {
                if (!is_array($change)) throw new InvalidArgumentException('presentation_review_item_invalid');
                $id = trim((string)($change['id'] ?? ''));
                if (!isset($indices[$id]) || isset($seen[$id])) throw new InvalidArgumentException('presentation_review_item_unknown_or_duplicate');
                $seen[$id] = true;
                $decision = trim((string)($change['decision'] ?? 'pending'));
                if (!in_array($decision, ['pending', 'confirmed', 'gap_acknowledged', 'needs_revision'], true)) throw new InvalidArgumentException('presentation_review_decision_invalid');
                $item = $items[$indices[$id]];
                if ($item['is_evidence_gap'] && $decision === 'confirmed') throw new InvalidArgumentException('presentation_review_cannot_promote_gap_to_fact');
                if (!$item['is_evidence_gap'] && $decision === 'gap_acknowledged') throw new InvalidArgumentException('presentation_review_gap_acknowledgement_not_applicable');
                $note = trim((string)($change['note'] ?? ''));
                if (mb_strlen($note) > 1000 || ($decision === 'needs_revision' && $note === '')) throw new InvalidArgumentException('presentation_review_note_invalid');
                $items[$indices[$id]]['decision'] = $decision;
                $items[$indices[$id]]['note'] = $note;
            }
            $payload = $this->pending($storedSpec);
            $payload['items'] = $items;
            $payload['status'] = $this->status($items);
            $payload['pending_item_count'] = count(array_filter($items, static fn(array $item): bool => $item['decision'] === 'pending'));
            $payload['revision_item_count'] = count(array_filter($items, static fn(array $item): bool => $item['decision'] === 'needs_revision'));
            $payload['reviewed_by'] = $userId;
            $payload['reviewed_at'] = date('Y-m-d H:i:s');
            $payload['base_review_fingerprint'] = $expected;
            unset($payload['review_fingerprint']);
            $fingerprint = $this->hash($payload);
            $id = (int)Db::name(self::TABLE)->insertGetId([
                'tenant_id' => $storedSpec['tenant_id'], 'hotel_id' => $storedSpec['hotel_id'], 'report_id' => $storedSpec['report_id'],
                'presentation_spec_id' => $storedSpec['record_id'], 'spec_fingerprint' => $storedSpec['spec_fingerprint'],
                'review_fingerprint' => $fingerprint, 'review_json' => $this->json($payload), 'review_status' => $payload['status'],
                'request_key' => $requestKey, 'request_hash' => $requestHash, 'created_by' => $userId,
            ]);
            $row = Db::name(self::TABLE)->where('id', $id)->where('tenant_id', $storedSpec['tenant_id'])->where('hotel_id', $storedSpec['hotel_id'])->find();
            if (!$row) throw new RuntimeException('presentation_review_readback_missing');
            $saved = $this->hydrate($row, $this->pending($storedSpec));
            if (!hash_equals($fingerprint, $saved['review_fingerprint'])) throw new RuntimeException('presentation_review_readback_mismatch');
            return $saved + ['idempotent' => false];
        });
    }

    /** Exact historical review for immutable artifact lineage; it never applies it to another spec. */
    public function readSnapshotForSpec(array $storedSpec, array $permittedHotelIds, ?int $reviewId): array
    {
        $this->assertSpec($storedSpec, $permittedHotelIds);
        $pending = $this->pending($storedSpec);
        if ($reviewId === null) return $pending + ['review_id' => null, 'readback_verified' => false, 'external_write_count' => 0];
        $row = Db::name(self::TABLE)->where('id', $reviewId)->where('tenant_id', $storedSpec['tenant_id'])
            ->where('hotel_id', $storedSpec['hotel_id'])->where('report_id', $storedSpec['report_id'])
            ->where('presentation_spec_id', $storedSpec['record_id'])->where('spec_fingerprint', $storedSpec['spec_fingerprint'])->find();
        if (!$row) throw new RuntimeException('presentation_review_artifact_lineage_missing');
        return $this->hydrate($row, $pending);
    }

    public function exportContext(array $review, string $mode): array
    {
        if (!in_array($mode, ['draft', 'formal'], true)) throw new InvalidArgumentException('presentation_review_export_mode_invalid');
        if ($mode === 'formal' && ($review['status'] !== 'reviewed' || ($review['readback_verified'] ?? false) !== true
            || ($review['pending_item_count'] ?? 1) !== 0 || ($review['revision_item_count'] ?? 1) !== 0)) throw new RuntimeException('presentation_review_required_before_formal_export', 409);
        // The renderer consumes a separate review snapshot; the immutable spec and its pending QA remain untouched.
        $items = $review['items'];
        if ($review['audience'] === 'training') foreach ($items as &$item) $item['note'] = $item['note'] === '' ? '' : '[内部复核备注未导出]';
        unset($item);
        return ['contract_version' => self::CONTRACT, 'export_mode' => $mode, 'status' => $review['status'],
            'review_id' => $review['review_id'], 'review_fingerprint' => $review['review_fingerprint'],
            'spec_fingerprint' => $review['spec_fingerprint'], 'required_items_fingerprint' => $review['required_items_fingerprint'],
            'source_evidence_fingerprint' => $review['source_evidence_fingerprint'], 'pending_item_count' => $review['pending_item_count'],
            'revision_item_count' => $review['revision_item_count'], 'reviewed_at' => $review['reviewed_at'],
            'reviewed_by' => $review['audience'] === 'training' ? null : $review['reviewed_by'],
            'items' => $items, 'review_notes_redacted' => $review['audience'] === 'training', 'readback_verified' => $review['readback_verified'],
            'operating_approval_granted' => false, 'external_write_authorized' => false];
    }

    private function pending(array $stored): array
    {
        $spec = $stored['spec'];
        $items = [];
        foreach ([
            'scope' => '酒店、租户、平台、日期和指标范围', 'sources' => '来源引用与缺失/未核验边界',
            'html' => '已打开HTML逐页核对版面与内容', 'pptx' => '已打开PPTX逐页核对版面与内容',
            'parity' => 'HTML与PPTX、来源台账和当前规格一致',
        ] as $id => $label) $items[] = ['id' => 'check:' . $id, 'label' => $label, 'statement' => '', 'class' => 'REPORT_REVIEW',
            'source_refs' => [], 'is_evidence_gap' => false, 'decision' => 'pending', 'note' => ''];
        foreach ($spec['evidence_ledger'] as $evidence) {
            $class = (string)$evidence['class'];
            $items[] = ['id' => 'evidence:' . $evidence['id'], 'label' => (string)($evidence['label'] ?? $evidence['id']),
                'statement' => (string)($evidence['statement'] ?? ''), 'class' => $class,
                'source_refs' => array_values((array)($evidence['source_refs'] ?? [])),
                'is_evidence_gap' => in_array($class, ['UNKNOWN', 'MOCK'], true), 'decision' => 'pending', 'note' => ''];
        }
        $payload = ['contract_version' => self::CONTRACT, 'tenant_id' => (int)$stored['tenant_id'], 'hotel_id' => (int)$stored['hotel_id'],
            'report_id' => (int)$stored['report_id'], 'presentation_spec_id' => (int)$stored['record_id'], 'audience' => (string)$stored['audience'],
            'spec_fingerprint' => (string)$stored['spec_fingerprint'], 'required_items_fingerprint' => $this->hash($items),
            'source_evidence_fingerprint' => $this->hash(['source_report' => $spec['source_report'], 'ledger' => $spec['evidence_ledger']]),
            'status' => 'pending', 'items' => $items, 'pending_item_count' => count($items), 'revision_item_count' => 0,
            'reviewed_by' => null, 'reviewed_at' => null, 'base_review_fingerprint' => null,
            'operating_approval_granted' => false, 'external_write_authorized' => false];
        return $payload + ['review_fingerprint' => $this->hash($payload)];
    }

    private function status(array $items): string
    {
        $decisions = array_column($items, 'decision');
        if (in_array('needs_revision', $decisions, true)) return 'needs_revision';
        return in_array('pending', $decisions, true) ? 'pending' : 'reviewed';
    }

    private function hydrate(array $row, array $pending): array
    {
        $payload = is_array($row['review_json']) ? $row['review_json'] : json_decode((string)$row['review_json'], true);
        if (!is_array($payload) || !hash_equals((string)$row['review_fingerprint'], $this->hash($payload))) throw new RuntimeException('presentation_review_content_digest_mismatch');
        foreach (['tenant_id', 'hotel_id', 'report_id', 'presentation_spec_id', 'spec_fingerprint', 'required_items_fingerprint', 'source_evidence_fingerprint', 'audience'] as $field) {
            if (($payload[$field] ?? null) !== $pending[$field]) throw new RuntimeException('presentation_review_scope_or_source_mismatch');
        }
        $items = $payload['items'] ?? [];
        $unreviewed = array_map(static function (array $item): array { $item['decision'] = 'pending'; $item['note'] = ''; return $item; }, $items);
        if ($this->hash($unreviewed) !== $pending['required_items_fingerprint'] || $this->status($items) !== ($payload['status'] ?? null)
            || (string)$row['review_status'] !== $payload['status']
            || ($payload['pending_item_count'] ?? -1) !== count(array_filter($items, static fn(array $i): bool => $i['decision'] === 'pending'))
            || ($payload['revision_item_count'] ?? -1) !== count(array_filter($items, static fn(array $i): bool => $i['decision'] === 'needs_revision'))
            || (int)($payload['reviewed_by'] ?? 0) !== (int)$row['created_by']) throw new RuntimeException('presentation_review_items_mismatch');
        return $payload + ['review_id' => (int)$row['id'], 'review_fingerprint' => (string)$row['review_fingerprint'], 'readback_verified' => true, 'external_write_count' => 0];
    }

    private function assertSpec(array $stored, array $permitted): void
    {
        if (($stored['readback_verified'] ?? false) !== true || (int)($stored['tenant_id'] ?? 0) <= 0
            || !in_array((int)($stored['hotel_id'] ?? 0), array_map('intval', $permitted), true)) throw new RuntimeException('presentation_review_hotel_not_permitted', 403);
        if (!Db::name('hotels')->where('id', (int)$stored['hotel_id'])->where('tenant_id', (int)$stored['tenant_id'])->find()) {
            throw new RuntimeException('presentation_review_hotel_not_permitted', 403);
        }
        $row = Db::name(self::SPEC_TABLE)->where('id', (int)($stored['record_id'] ?? 0))
            ->where('tenant_id', $stored['tenant_id'])->where('hotel_id', $stored['hotel_id'])->where('report_id', $stored['report_id'])
            ->where('spec_fingerprint', $stored['spec_fingerprint'])->find();
        $spec = $stored['spec'] ?? null;
        if (!$row || !is_array($spec)) throw new RuntimeException('presentation_review_saved_spec_required');
        $raw = is_array($row['spec_json']) ? $row['spec_json'] : json_decode((string)$row['spec_json'], true);
        $without = $spec; unset($without['spec_fingerprint']);
        if ($this->json($raw) !== $this->json($spec) || !hash_equals((string)$stored['spec_fingerprint'], $this->hash($without))
            || ($spec['deck']['audience'] ?? '') !== $stored['audience']) throw new RuntimeException('presentation_review_spec_fingerprint_mismatch');
    }

    private function hash(mixed $value): string { return hash('sha256', $this->json($value)); }
    private function json(mixed $value): string
    {
        $sort = function (mixed $item) use (&$sort): mixed {
            if (!is_array($item)) return $item;
            if (!array_is_list($item)) ksort($item, SORT_STRING);
            return array_map($sort, $item);
        };
        return json_encode($sort($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
    }
}
