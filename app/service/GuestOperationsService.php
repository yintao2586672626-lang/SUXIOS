<?php
declare(strict_types=1);

namespace app\service;

use DateTimeImmutable;
use InvalidArgumentException;
use RuntimeException;
use think\facade\Db;

/** Anonymous stay cohorts and manual guest cases. No guest identity or external execution. */
final class GuestOperationsService
{
    public const VERSION = 'guest_operations.v1';
    public const DEFINITION = 'period_repeat_completed_guest.v1';
    private const RECORDS = 'guest_operation_records';
    private const HEADS = 'guest_operation_heads';
    private const REQUESTS = 'guest_operation_requests';
    private const KINDS = ['stay_event', 'coverage', 'feedback', 'feedback_entry', 'room', 'stay_import'];

    public function importStays(int $tenantId, array $hotelIds, int $hotelId, int $actorId, array $input): array
    {
        $tenantId = $this->scope($tenantId, $hotelIds, $hotelId, $actorId);
        $this->only($input, ['idempotency_key', 'platform', 'source_reference', 'events', 'import_method']);
        $method = $input['import_method'] ?? 'manual_anonymous_import';
        if (!in_array($method, ['manual_anonymous_import', 'jd06_file_import'], true)) throw new InvalidArgumentException('import_method 无效');
        $platform = $this->platform($input['platform'] ?? '');
        $source = $this->text($input['source_reference'] ?? '', 180, 'source_reference');
        $events = $input['events'] ?? null;
        if (!is_array($events) || !array_is_list($events) || count($events) < 1 || count($events) > 500) {
            throw new InvalidArgumentException('events 必须为1至500条匿名入住事件');
        }
        $normalized = []; $keys = [];
        foreach ($events as $event) {
            if (!is_array($event)) throw new InvalidArgumentException('入住事件格式无效');
            $this->only($event, ['event_key', 'guest_hash', 'stay_date', 'status', 'expected_revision', 'correction_reason']);
            $key = $this->key($event['event_key'] ?? '');
            if (isset($keys[$key])) throw new InvalidArgumentException('同批次 event_key 重复');
            $keys[$key] = true;
            $hash = strtolower((string)($event['guest_hash'] ?? ''));
            if (!preg_match('/^[a-f0-9]{64}$/D', $hash)) throw new InvalidArgumentException('guest_hash 必须是来源预先匿名化的64位哈希，不接受姓名电话');
            $status = (string)($event['status'] ?? '');
            if (!in_array($status, ['completed', 'void'], true)) throw new InvalidArgumentException('status 必须为 completed 或 void');
            $normalized[] = [
                'key' => $platform . ':' . $key,
                'expected' => $this->revision($event['expected_revision'] ?? 0),
                'document' => [
                    'event_key' => $key, 'guest_hash' => $hash, 'stay_date' => $this->date($event['stay_date'] ?? ''),
                    'status' => $status, 'platform' => $platform, 'source_reference' => $source,
                    'source_method' => $method,
                    'correction_reason' => $this->optionalText($event['correction_reason'] ?? '', 300),
                ],
            ];
        }
        return $this->write($tenantId, $hotelId, $actorId, $input, 'stay_event', array_column($normalized, 'key'), function () use ($tenantId, $hotelId, $actorId, $normalized): array {
            $ids = [];
            foreach ($normalized as $event) {
                $current = $this->latest($tenantId, $hotelId, 'stay_event', $event['key']);
                if ($current && (new GuestStayEventDedupService())->identical($current['document'], $event['document'])) { $ids[] = $current['id']; continue; }
                if ($current && $event['document']['correction_reason'] === '') throw new InvalidArgumentException('更正已有入住事件必须填写 correction_reason');
                $ids[] = $this->append($tenantId, $hotelId, $actorId, 'stay_event', $event['key'], $event['expected'], $event['document'], $event['document']['stay_date'], $event['document']['platform']);
            }
            return $ids;
        });
    }

    public function saveCoverage(int $tenantId, array $hotelIds, int $hotelId, int $actorId, array $input): array
    {
        $tenantId = $this->scope($tenantId, $hotelIds, $hotelId, $actorId);
        $this->only($input, ['idempotency_key', 'platform', 'date_start', 'date_end', 'expected_guests', 'source_quality', 'source_reference', 'expected_revision']);
        [$start, $end] = $this->period($input['date_start'] ?? '', $input['date_end'] ?? '');
        $platform = $this->platform($input['platform'] ?? '');
        $quality = (string)($input['source_quality'] ?? '');
        if (!in_array($quality, ['complete', 'partial', 'unverified'], true)) throw new InvalidArgumentException('source_quality 无效');
        $expected = $input['expected_guests'] ?? null;
        if ($expected !== null && (!is_int($expected) || $expected < 0)) throw new InvalidArgumentException('expected_guests 必须是非负整数或 null');
        if ($quality === 'complete' && $expected === null) throw new InvalidArgumentException('完整来源必须声明同期唯一完成入住客人数 expected_guests');
        $document = [
            'platform' => $platform, 'date_start' => $start, 'date_end' => $end, 'expected_guests' => $expected,
            'source_quality' => $quality, 'source_reference' => $this->text($input['source_reference'] ?? '', 180, 'source_reference'),
            'source_method' => 'manual_coverage_declaration', 'metric_definition' => self::DEFINITION,
            'denominator_definition' => '同酒店、来源平台和时期内至少一次完成入住的唯一匿名客人',
            'claim_scope' => $platform === 'pms' ? 'declared_pms_guest_cohort' : 'declared_source_guest_cohort',
        ];
        $key = $platform . ':' . $start . ':' . $end;
        return $this->write($tenantId, $hotelId, $actorId, $input, 'coverage', [$key], fn(): array => [
            $this->append($tenantId, $hotelId, $actorId, 'coverage', $key, $this->revision($input['expected_revision'] ?? 0), $document, $end, $platform),
        ]);
    }

    public function saveFeedback(int $tenantId, array $hotelIds, int $hotelId, int $actorId, array $input): array
    {
        $tenantId = $this->scope($tenantId, $hotelIds, $hotelId, $actorId);
        $this->only($input, ['idempotency_key', 'case_key', 'expected_revision', 'incident_date', 'category', 'summary', 'owner_user_id', 'due_at', 'source_reference', 'evidence_refs', 'edit_reason']);
        $key = $this->key($input['case_key'] ?? '');
        $category = (string)($input['category'] ?? '');
        if (!in_array($category, ['feedback', 'complaint'], true)) throw new InvalidArgumentException('category 必须为 feedback 或 complaint');
        $owner = $input['owner_user_id'] ?? null;
        if (!is_int($owner) || $owner <= 0) throw new InvalidArgumentException('必须指定有效责任人');
        // Responsibility cannot be assigned to a user outside this hotel/tenant.
        $assignee = Db::name('users')->where('id', $owner)->where('tenant_id', $tenantId)->where('status', 1)->find();
        if (!$assignee || !$this->feedbackOwnerAllows($assignee, $hotelId, new HotelScopeService())) {
            throw new InvalidArgumentException('责任人必须拥有同租户酒店的运营权限');
        }
        $current = $this->latest($tenantId, $hotelId, 'feedback', $key);
        $existing = $current['document'] ?? [];
        $editReason = $this->optionalText($input['edit_reason'] ?? '', 300);
        $document = [
            'case_key' => $key, 'incident_date' => $this->date($input['incident_date'] ?? ''), 'category' => $category,
            'summary' => $this->text($input['summary'] ?? '', 1500, 'summary'), 'owner_user_id' => $owner,
            'due_at' => $this->datetime($input['due_at'] ?? ''), 'source_reference' => $this->text($input['source_reference'] ?? '', 180, 'source_reference'),
            'evidence_refs' => $this->evidence($input['evidence_refs'] ?? []), 'status' => $existing['status'] ?? 'open',
            'facts' => $existing['facts'] ?? [], 'edit_reason' => $editReason, 'source_method' => $existing['source_method'] ?? 'manual_guest_feedback',
        ] + array_intersect_key($existing, array_flip(['room_id', 'room_label', 'guest_submission']));
        if (substr($document['due_at'], 0, 10) < $document['incident_date']) throw new InvalidArgumentException('期限不能早于发生日期');
        return $this->write($tenantId, $hotelId, $actorId, $input, 'feedback', [$key], function () use ($tenantId, $hotelId, $actorId, $input, $document, $key, $current, $editReason): array {
            if ($current && $editReason === '') throw new InvalidArgumentException('编辑必须记录 edit_reason');
            if ($current && ($current['document']['status'] ?? '') === 'closed') throw new RuntimeException('已关闭记录不能编辑；请追加 reopen 事实后编辑', 409);
            foreach ($document['facts'] as $fact) {
                if (($fact['occurred_at'] ?? '') < $document['incident_date'] . ' 00:00:00') throw new InvalidArgumentException('发生日期不能晚于已保存的处理事实');
            }
            return [$this->append($tenantId, $hotelId, $actorId, 'feedback', $key, $this->revision($input['expected_revision'] ?? 0), $document, $document['incident_date'], 'manual')];
        });
    }

    public function appendFeedbackFact(int $tenantId, array $hotelIds, int $hotelId, int $actorId, string $caseKey, array $input): array
    {
        $tenantId = $this->scope($tenantId, $hotelIds, $hotelId, $actorId);
        $caseKey = $this->key($caseKey);
        $this->only($input, ['idempotency_key', 'expected_revision', 'action', 'occurred_at', 'note', 'evidence_refs', 'confirmation', 'confirmed_by_role']);
        $action = (string)($input['action'] ?? '');
        if (!in_array($action, ['handling', 'close', 'reopen'], true)) throw new InvalidArgumentException('处理事实 action 无效');
        $evidence = $this->evidence($input['evidence_refs'] ?? []);
        $role = (string)($input['confirmed_by_role'] ?? '');
        if ($action === 'close' && (($input['confirmation'] ?? false) !== true || $evidence === [] || !in_array($role, ['guest', 'manager'], true))) {
            throw new InvalidArgumentException('关闭必须有证据、明确人工确认及确认者角色 guest 或 manager');
        }
        $fact = [
            'action' => $action, 'occurred_at' => $this->datetime($input['occurred_at'] ?? ''),
            'note' => $this->text($input['note'] ?? '', 1500, 'note'), 'evidence_refs' => $evidence,
            'confirmation' => $action === 'close', 'confirmed_by_role' => $action === 'close' ? $role : null,
            'recorded_by' => $actorId, 'recorded_at' => $this->now(),
        ];
        return $this->write($tenantId, $hotelId, $actorId, $input, 'feedback', [$caseKey], function () use ($tenantId, $hotelId, $actorId, $caseKey, $input, $fact, $action): array {
            $current = $this->latest($tenantId, $hotelId, 'feedback', $caseKey);
            if (!$current) throw new RuntimeException('feedback_not_found', 404);
            $document = $current['document'];
            if (($document['status'] ?? '') === 'closed' && $action !== 'reopen') throw new RuntimeException('已关闭记录只能追加 reopen 事实', 409);
            if (($document['status'] ?? '') !== 'closed' && $action === 'reopen') throw new RuntimeException('只有已关闭记录可以重开', 409);
            if ($fact['occurred_at'] < $document['incident_date'] . ' 00:00:00') throw new InvalidArgumentException('处理事实不能早于发生日期');
            $document['facts'][] = $fact;
            $document['status'] = $action === 'close' ? 'closed' : ($action === 'reopen' ? 'open' : 'in_progress');
            return [$this->append($tenantId, $hotelId, $actorId, 'feedback', $caseKey, $this->revision($input['expected_revision'] ?? 0), $document, $document['incident_date'], 'manual')];
        });
    }

    public function saveEntry(int $tenantId, array $hotelIds, int $hotelId, int $actorId, array $input): array
    {
        $tenantId = $this->scope($tenantId, $hotelIds, $hotelId, $actorId);
        $this->only($input, ['idempotency_key', 'entry_key', 'expected_revision', 'room_label', 'label', 'enabled']);
        $key = $this->key($input['entry_key'] ?? '');
        if (strlen($key) > 32) throw new InvalidArgumentException('反馈入口键最多32位，保证标准二维码容量');
        if (!is_bool($input['enabled'] ?? null)) throw new InvalidArgumentException('enabled 必须是布尔值');
        $document = [
            'entry_key' => $key, 'room_label' => $this->text($input['room_label'] ?? '', 80, 'room_label'),
            'label' => $this->text($input['label'] ?? '', 120, 'label'), 'enabled' => $input['enabled'],
            'access_mode' => 'authenticated_staff_only', 'anonymous_submission_enabled' => false,
            'entry_path' => '/?page=operating-finance&workspace=guests&hotel_id=' . $hotelId . '&feedback_entry=' . rawurlencode($key),
            'source_method' => 'manual_feedback_entry_config',
        ];
        return $this->write($tenantId, $hotelId, $actorId, $input, 'feedback_entry', [$key], fn(): array => [
            $this->append($tenantId, $hotelId, $actorId, 'feedback_entry', $key, $this->revision($input['expected_revision'] ?? 0), $document, substr($this->now(), 0, 10), 'manual'),
        ]);
    }

    public function read(int $tenantId, array $hotelIds, int $hotelId, int $recordId): array
    {
        $tenantId = $this->scope($tenantId, $hotelIds, $hotelId);
        $row = Db::name(self::RECORDS)->where('id', $recordId)->where('tenant_id', $tenantId)->where('hotel_id', $hotelId)->find();
        if (!$row) throw new RuntimeException('guest_record_not_found', 404);
        if (!in_array($row['kind'], self::KINDS, true)) throw new RuntimeException('guest_record_not_found', 404);
        return $this->verify($row);
    }

    public function history(int $tenantId, array $hotelIds, int $hotelId, string $kind, string $key): array
    {
        $tenantId = $this->scope($tenantId, $hotelIds, $hotelId);
        if (!in_array($kind, self::KINDS, true)) throw new InvalidArgumentException('kind 无效');
        $rows = Db::name(self::RECORDS)->where('tenant_id', $tenantId)->where('hotel_id', $hotelId)->where('kind', $kind)->where('record_key', $key)->order('revision', 'desc')->select()->toArray();
        return array_map([$this, 'verify'], $rows);
    }

    public function overview(int $tenantId, array $hotelIds, int $hotelId, string $start, string $end, string $platform): array
    {
        $tenantId = $this->scope($tenantId, $hotelIds, $hotelId);
        [$start, $end] = $this->period($start, $end); $platform = $this->platform($platform);
        $stays = $this->currentRecords($tenantId, $hotelId, 'stay_event', $start, $end, $platform);
        $coverage = $this->latest($tenantId, $hotelId, 'coverage', $platform . ':' . $start . ':' . $end);
        $counts = [];
        foreach ($stays as $stay) if ($stay['document']['status'] === 'completed') {
            $hash = $stay['document']['guest_hash']; $counts[$hash] = ($counts[$hash] ?? 0) + 1;
        }
        $denominator = count($counts); $numerator = count(array_filter($counts, static fn(int $count): bool => $count >= 2));
        $gaps = [];
        if (!$coverage) $gaps[] = 'source_coverage_missing';
        else {
            if ($coverage['document']['source_quality'] !== 'complete') $gaps[] = 'source_coverage_' . $coverage['document']['source_quality'];
            if ($coverage['document']['expected_guests'] === null) $gaps[] = 'explicit_denominator_missing';
            elseif ($coverage['document']['expected_guests'] !== $denominator) $gaps[] = 'denominator_mismatch';
        }
        if ($denominator === 0) $gaps[] = 'denominator_zero';
        return [
            'contract_version' => self::VERSION, 'tenant_id' => $tenantId, 'hotel_id' => $hotelId,
            'date_start' => $start, 'date_end' => $end, 'platform' => $platform,
            'data_status' => $gaps === [] ? 'ready' : ($coverage ? 'partial' : 'unverified'),
            'repeat_guest' => [
                'definition' => self::DEFINITION, 'numerator' => $numerator, 'denominator' => $denominator,
                'observed_completed_stays' => array_sum($counts), 'rate' => $gaps === [] ? $numerator / $denominator : null,
                'data_gaps' => $gaps, 'coverage' => $coverage,
                'numerator_definition' => '同酒店、来源平台和时期内至少两次完成入住的唯一匿名客人',
                'denominator_definition' => '同期至少一次完成入住的唯一匿名客人；不计 void 事件',
                'evidence_boundary' => '来源人工声明与匿名导入；不证明全酒店、跨期终身复购或经营改善',
            ],
            'stay_events' => $stays,
            'stay_imports' => $this->currentRecords($tenantId, $hotelId, 'stay_import', $start, $end, $platform),
            'owners' => $this->feedbackOwners($tenantId, $hotelId),
            'feedback' => $this->currentRecords($tenantId, $hotelId, 'feedback', $start, $end),
            'feedback_entries' => $entries = $this->currentRecords($tenantId, $hotelId, 'feedback_entry'),
            'rooms' => (new GuestRoomRegistryService())->list($tenantId, $hotelId),
            'boundaries' => ['external_write_count' => 0, 'guest_identity_stored' => false, 'anonymous_submission_enabled' => (bool)array_filter($entries, static fn(array $entry): bool => ($entry['document']['access_mode'] ?? '') === 'guest_submission_only' && $entry['document']['enabled'])],
        ];
    }

    public function feedbackOwnerAvailable(int $tenantId, int $hotelId, int $ownerId): bool
    {
        $assignee = Db::name('users')->where('id', $ownerId)->where('tenant_id', $tenantId)->where('status', 1)
            ->field('id,tenant_id,status,hotel_id,role_id')->find();
        return $assignee && $this->feedbackOwnerAllows($assignee, $hotelId, new HotelScopeService());
    }

    private function feedbackOwners(int $tenantId, int $hotelId): array
    {
        $scope = new HotelScopeService(); $owners = [];
        $users = Db::name('users')->where('tenant_id', $tenantId)->where('status', 1)
            ->field('id,tenant_id,status,hotel_id,role_id,username,realname')->order('id')->select()->toArray();
        foreach ($users as $user) {
            if (!$this->feedbackOwnerAllows($user, $hotelId, $scope)) continue;
            $name = trim((string)($user['realname'] ?? '')) ?: trim((string)($user['username'] ?? ''));
            $owners[] = ['id' => (int)$user['id'], 'name' => $name ?: '用户' . $user['id']];
        }
        return $owners;
    }

    private function feedbackOwnerAllows(array $assignee, int $hotelId, HotelScopeService $scope): bool
    {
        $user = new \app\model\User($assignee);
        // Caller already verified the enabled hotel and this assignee's tenant/status.
        // A same-tenant admin can operate it without a cross-tenant model query as the viewing actor.
        return $user->isSuperAdmin() || (new PermissionService($scope))->authorize($user, 'operation.execute', $hotelId)['allowed'];
    }

    private function scope(int $tenantId, array $hotelIds, int $hotelId, ?int $actorId = null): int
    {
        if ($hotelId <= 0 || !in_array($hotelId, array_map('intval', $hotelIds), true)) throw new RuntimeException('guest_hotel_not_found', 404);
        if ($actorId !== null && $actorId <= 0) throw new RuntimeException('未登录', 401);
        $hotel = Db::name('hotels')->where('id', $hotelId)->where('status', 1)->find();
        if (!$hotel || (int)$hotel['tenant_id'] <= 0 || ($tenantId > 0 && $tenantId !== (int)$hotel['tenant_id'])) throw new RuntimeException('guest_hotel_not_found', 404);
        try {
            Db::name(self::RECORDS)->field('id,content_json,content_digest,revision')->where('id', 0)->find();
            Db::name(self::HEADS)->field('record_id,revision')->where('record_id', 0)->find();
            Db::name(self::REQUESTS)->field('record_ids_json,input_digest')->where('hotel_id', 0)->find();
        } catch (\Throwable $error) { throw new RuntimeException('guest_operations_storage_unavailable，请完成受控迁移或检查数据库连接', 503, $error); }
        return (int)$hotel['tenant_id'];
    }

    private function write(int $tenantId, int $hotelId, int $actorId, array $input, string $kind, array $recordKeys, callable $operation): array
    {
        $key = $this->key($input['idempotency_key'] ?? '');
        $digest = hash('sha256', $this->json($input));
        return Db::transaction(function () use ($tenantId, $hotelId, $actorId, $key, $digest, $kind, $recordKeys, $operation): array {
            $existing = Db::name(self::REQUESTS)->where('tenant_id', $tenantId)->where('hotel_id', $hotelId)->where('request_key', $key)->find();
            if ($existing) {
                if ((int)$existing['created_by'] !== $actorId || !hash_equals($existing['input_digest'], $digest)) throw new RuntimeException('guest_idempotency_conflict', 409);
                $ids = json_decode($existing['record_ids_json'], true, 512, JSON_THROW_ON_ERROR);
                $replay = true;
            } else {
                $ids = $operation(); $replay = false;
                Db::name(self::REQUESTS)->insert(['tenant_id' => $tenantId, 'hotel_id' => $hotelId, 'source_hotel_id' => $hotelId, 'request_key' => $key, 'input_digest' => $digest, 'record_ids_json' => $this->json($ids), 'created_by' => $actorId, 'created_at' => $this->now()]);
            }
            $records = array_map(fn(int $id): array => $this->read($tenantId, [$hotelId], $hotelId, $id), $ids);
            // Preserve old request digests while binding their saved results to this operation/path.
            if (count($records) !== count($recordKeys)) throw new RuntimeException('guest_idempotency_conflict', 409);
            foreach ($records as $index => $record) {
                if ($record['kind'] !== $kind || $record['record_key'] !== $recordKeys[$index]) throw new RuntimeException('guest_idempotency_conflict', 409);
            }
            return ['contract_version' => self::VERSION, 'tenant_id' => $tenantId, 'hotel_id' => $hotelId, 'persistence_status' => 'readback_verified', 'idempotent_replay' => $replay, 'records' => $records];
        });
    }

    private function append(int $tenantId, int $hotelId, int $actorId, string $kind, string $key, int $expected, array $document, string $date, string $platform): int
    {
        $current = $this->latest($tenantId, $hotelId, $kind, $key);
        if (($current['revision'] ?? 0) !== $expected) throw new RuntimeException('guest_revision_conflict，请刷新精确回读后重试', 409);
        $row = ['tenant_id' => $tenantId, 'hotel_id' => $hotelId, 'source_hotel_id' => $hotelId, 'kind' => $kind, 'record_key' => $key, 'revision' => $expected + 1, 'business_date' => $date, 'platform' => $platform, 'created_by' => $actorId, 'created_at' => $this->now(), 'document' => $document, 'contract_version' => self::VERSION];
        $content = $this->json($row);
        $id = (int)Db::name(self::RECORDS)->insertGetId(array_diff_key($row, ['document' => true, 'contract_version' => true]) + ['content_json' => $content, 'content_digest' => hash('sha256', $content)]);
        $where = Db::name(self::HEADS)->where('tenant_id', $tenantId)->where('hotel_id', $hotelId)->where('kind', $kind)->where('record_key', $key);
        if ($expected === 0) $where->insert(['tenant_id' => $tenantId, 'hotel_id' => $hotelId, 'source_hotel_id' => $hotelId, 'kind' => $kind, 'record_key' => $key, 'record_id' => $id, 'revision' => 1]);
        elseif ($where->where('revision', $expected)->update(['record_id' => $id, 'revision' => $expected + 1]) !== 1) throw new RuntimeException('guest_revision_conflict', 409);
        return $id;
    }

    private function latest(int $tenantId, int $hotelId, string $kind, string $key): ?array
    {
        $head = Db::name(self::HEADS)->where('tenant_id', $tenantId)->where('hotel_id', $hotelId)->where('kind', $kind)->where('record_key', $key)->find();
        if (!$head) return null;
        $record = $this->read($tenantId, [$hotelId], $hotelId, (int)$head['record_id']);
        if ($record['kind'] !== $kind || $record['record_key'] !== $key || $record['revision'] !== (int)$head['revision']) throw new RuntimeException('guest_head_readback_drift', 409);
        return $record;
    }

    private function currentRecords(int $tenantId, int $hotelId, string $kind, ?string $start = null, ?string $end = null, ?string $platform = null): array
    {
        $heads = Db::name(self::HEADS)->where('tenant_id', $tenantId)->where('hotel_id', $hotelId)->where('kind', $kind)
            ->field('record_id,record_key,revision')->select()->toArray();
        if (!$heads) return [];
        $rows = Db::name(self::RECORDS)->where('tenant_id', $tenantId)->where('hotel_id', $hotelId)->where('kind', $kind)
            ->whereIn('id', array_column($heads, 'record_id'))->order('id', 'desc')->select()->toArray();
        $records = array_map([$this, 'verify'], $rows); $byId = array_column($records, null, 'id');
        foreach ($heads as $head) {
            $record = $byId[(int)$head['record_id']] ?? null;
            if (!$record || $record['record_key'] !== $head['record_key'] || $record['revision'] !== (int)$head['revision']) {
                throw new RuntimeException('guest_head_readback_drift', 409);
            }
        }
        return array_values(array_filter($records, static fn(array $record): bool =>
            ($start === null || ($record['business_date'] >= $start && $record['business_date'] <= $end))
            && ($platform === null || $record['platform'] === $platform)));
    }

    private function verify(array $row): array
    {
        $content = (string)$row['content_json'];
        if (!hash_equals((string)$row['content_digest'], hash('sha256', $content))) throw new RuntimeException('guest_readback_digest_drift', 409);
        $payload = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        foreach (['tenant_id', 'revision', 'created_by'] as $field) if ((int)$row[$field] !== ($payload[$field] ?? null)) throw new RuntimeException('guest_readback_scope_drift', 409);
        $sourceHotel = (int)($row['source_hotel_id'] ?? $row['hotel_id']);
        if ($sourceHotel !== (int)($payload['hotel_id'] ?? 0) || $sourceHotel !== (int)($payload['source_hotel_id'] ?? $payload['hotel_id'] ?? 0)) throw new RuntimeException('guest_readback_source_scope_drift', 409);
        foreach (['kind', 'record_key', 'business_date', 'platform', 'created_at'] as $field) if ((string)$row[$field] !== ($payload[$field] ?? null)) throw new RuntimeException('guest_readback_scope_drift', 409);
        if (($payload['contract_version'] ?? '') !== self::VERSION) throw new RuntimeException('guest_contract_unsupported', 409);
        return array_replace($payload, [
            'hotel_id' => (int)$row['hotel_id'], 'source_hotel_id' => $sourceHotel,
            'scope' => ['tenant_id' => (int)$row['tenant_id'], 'hotel_id' => (int)$row['hotel_id']],
            'source_scope' => ['tenant_id' => (int)$payload['tenant_id'], 'hotel_id' => $sourceHotel],
            'id' => (int)$row['id'], 'content_digest' => $row['content_digest'], 'readback_verified' => true,
        ]);
    }

    private function only(array $input, array $allowed): void
    {
        if (array_diff(array_keys($input), $allowed)) throw new InvalidArgumentException('包含不支持的字段；不要提交姓名、电话或其他客人身份');
    }
    private function now(): string { return (new DateTimeImmutable('now', new \DateTimeZone('Asia/Shanghai')))->format('Y-m-d H:i:s'); }
    private function json(array $input): string
    {
        $sort = function (array $value) use (&$sort): array {
            if (!array_is_list($value)) ksort($value);
            foreach ($value as &$item) if (is_array($item)) $item = $sort($item);
            return $value;
        };
        return json_encode($sort($input), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
    private function key(mixed $value): string
    {
        $text = (string)$value;
        if (!preg_match('/^[A-Za-z0-9_.:-]{1,140}$/D', $text)) throw new InvalidArgumentException('记录键/幂等键必须为1至140位字母、数字、点、冒号、下划线或短横线');
        return $text;
    }
    private function revision(mixed $value): int
    {
        if (!is_int($value) || $value < 0) throw new InvalidArgumentException('expected_revision 必须为非负整数');
        return $value;
    }
    private function platform(mixed $value): string
    {
        if (!in_array($value, ['ctrip', 'meituan', 'pms', 'manual'], true)) throw new InvalidArgumentException('platform 无效');
        return $value;
    }
    private function date(mixed $value): string
    {
        $value = (string)$value; $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (!$date || $date->format('Y-m-d') !== $value) throw new InvalidArgumentException('日期必须为有效 YYYY-MM-DD');
        return $value;
    }
    private function datetime(mixed $value): string
    {
        $value = str_replace('T', ' ', (string)$value);
        if (strlen($value) === 16) $value .= ':00';
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value);
        if (!$date || $date->format('Y-m-d H:i:s') !== $value) throw new InvalidArgumentException('时间必须为有效上海时间 YYYY-MM-DD HH:mm:ss');
        return $value;
    }
    private function period(mixed $start, mixed $end): array
    {
        $start = $this->date($start); $end = $this->date($end);
        if ($start > $end) throw new InvalidArgumentException('时期起止日期颠倒');
        return [$start, $end];
    }
    private function text(mixed $value, int $max, string $field): string
    {
        $value = $this->optionalText($value, $max);
        if ($value === '') throw new InvalidArgumentException($field . ' 必填');
        return $value;
    }
    private function optionalText(mixed $value, int $max): string
    {
        if (!is_string($value)) throw new InvalidArgumentException('文本字段无效');
        $value = trim($value);
        if (mb_strlen($value) > $max || preg_match('/\b1[3-9]\d{9}\b|[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', $value)) throw new InvalidArgumentException('文本过长或包含客人联系方式，请先脱敏');
        return $value;
    }
    private function evidence(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value) || count($value) > 20) throw new InvalidArgumentException('evidence_refs 必须为最多20条脱敏证据引用数组');
        return array_values(array_unique(array_map(fn(mixed $item): string => $this->text($item, 180, 'evidence_ref'), $value)));
    }
}
