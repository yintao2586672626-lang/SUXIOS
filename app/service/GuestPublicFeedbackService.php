<?php
declare(strict_types=1);
namespace app\service;

use DateTimeImmutable;
use InvalidArgumentException;
use RuntimeException;
use think\facade\Db;

/** Dedicated guest capabilities grant submission only, never staff/admin access. */
final class GuestPublicFeedbackService
{
    public function configure(int $tenant, array $ids, int $hotel, int $actor, array $input): array
    {
        $store = new GuestOperationRecordStore(); $tenant = $store->scope($tenant, $ids, $hotel, $actor);
        if (array_diff(array_keys($input), ['idempotency_key', 'room_ids', 'owner_user_id', 'label', 'enabled', 'rotate_tokens', 'expected_revisions'])) throw new InvalidArgumentException('公开入口字段无效');
        $roomIds = $input['room_ids'] ?? [];
        if (!is_array($roomIds) || !array_is_list($roomIds) || count($roomIds) < 1 || count($roomIds) > 100 || count(array_unique($roomIds)) !== count($roomIds)) throw new InvalidArgumentException('请选择1至100个不重复的实际房间');
        if (!is_string($input['label'] ?? null)) throw new InvalidArgumentException('入口名称必须为文本');
        $label = trim($input['label']);
        if ($label === '' || mb_strlen($label) > 100) throw new InvalidArgumentException('入口名称必须为1至100个字符');
        if (!is_bool($input['enabled'] ?? null) || !is_bool($input['rotate_tokens'] ?? null)) throw new InvalidArgumentException('启停和重新生成参数必须为布尔值');
        $owner = $input['owner_user_id'] ?? null;
        $overview = (new GuestOperationsService())->overview($tenant, [$hotel], $hotel, substr($store->now(), 0, 10), substr($store->now(), 0, 10), 'pms');
        if (!is_int($owner) || !in_array($owner, array_column($overview['owners'], 'id'), true)) throw new InvalidArgumentException('请选择本店有运营权限的有效责任人');
        $request = (string)($input['idempotency_key'] ?? '');
        if (!preg_match('/^[A-Za-z0-9_.:-]{1,140}$/D', $request)) throw new InvalidArgumentException('幂等键无效');
        $digest = hash('sha256', json_encode($input, JSON_THROW_ON_ERROR));
        return Db::transaction(function () use ($store, $tenant, $hotel, $actor, $input, $roomIds, $label, $owner, $request, $digest): array {
            $prior = Db::name('guest_operation_requests')->where('tenant_id', $tenant)->where('hotel_id', $hotel)->where('request_key', $request)->find();
            if ($prior) {
                if ((int)$prior['created_by'] !== $actor || !hash_equals($prior['input_digest'], $digest)) throw new RuntimeException('guest_idempotency_conflict', 409);
                $records = array_map(fn(int $id): array => $store->read($id), json_decode($prior['record_ids_json'], true, 512, JSON_THROW_ON_ERROR));
                foreach ($records as $record) if ($record['kind'] !== 'feedback_entry' || $record['tenant_id'] !== $tenant || $record['hotel_id'] !== $hotel) throw new RuntimeException('guest_idempotency_conflict', 409);
                return $store->receipt($tenant, $hotel, $records) + ['entry_links' => [], 'token_delivery' => 'already_issued_regenerate_if_lost'];
            }
            $records = []; $links = []; $rooms = new GuestRoomRegistryService();
            foreach ($roomIds as $roomId) {
                if (!is_int($roomId) || $roomId <= 0) throw new InvalidArgumentException('room_id 必须为实际房间ID');
                $room = $rooms->requireActive($tenant, $hotel, $roomId); $key = 'room-' . $roomId;
                Db::name('guest_operation_heads')->where('tenant_id', $tenant)->where('hotel_id', $hotel)->where('kind', 'feedback_entry')->where('record_key', $key)->lock(true)->find();
                $current = $store->latest($tenant, $hotel, 'feedback_entry', $key);
                if ($current && ($current['document']['access_mode'] ?? '') !== 'guest_submission_only') throw new RuntimeException('入口键已被旧员工入口使用，请选择其他房间', 409);
                $expected = $input['expected_revisions'][(string)$roomId] ?? 0;
                if (!is_int($expected) || $expected < 0 || $expected !== ($current['revision'] ?? 0)) throw new RuntimeException('guest_revision_conflict，请刷新入口版本', 409);
                $token = (!$current || $input['rotate_tokens']) ? bin2hex(random_bytes(32)) : null;
                $tokenDigest = $token ? hash('sha256', $token) : $current['document']['token_digest'];
                $document = ['entry_key' => $key, 'room_id' => $roomId, 'room_label' => $room['document']['room_number'], 'label' => $label, 'enabled' => $input['enabled'], 'owner_user_id' => $owner, 'access_mode' => 'guest_submission_only', 'anonymous_submission_enabled' => $input['enabled'], 'token_digest' => $tokenDigest, 'source_method' => 'staff_confirmed_public_room_entry'];
                $records[] = $store->append($tenant, $hotel, $actor, 'feedback_entry', $key, $expected, $document);
                if ($token) {
                    $store->append($tenant, $hotel, $actor, 'guest_public_pointer', $tokenDigest, 0, ['entry_key' => $key]);
                    $links[] = ['entry_key' => $key, 'room_id' => $roomId, 'room_label' => $document['room_label'], 'label' => $label, 'entry_path' => '/guest-feedback.html#' . $token];
                }
            }
            Db::name('guest_operation_requests')->insert(['tenant_id' => $tenant, 'hotel_id' => $hotel, 'source_hotel_id' => $hotel, 'request_key' => $request, 'input_digest' => $digest, 'record_ids_json' => json_encode(array_column($records, 'id'), JSON_THROW_ON_ERROR), 'created_by' => $actor, 'created_at' => $store->now()]);
            return $store->receipt($tenant, $hotel, $records) + ['entry_links' => $links, 'token_delivery' => $links ? 'issued_once' : 'existing_token_preserved'];
        });
    }
    public function entry(string $token): array
    {
        $entry = $this->resolve($token); $hotel = Db::name('hotels')->where('id', $entry['hotel_id'])->find();
        return ['label' => $entry['document']['label'], 'room_label' => $entry['document']['room_label'], 'hotel_name' => $hotel['name'], 'submission_enabled' => true, 'privacy_notice' => '请勿填写姓名、电话、证件号或其他身份信息；本入口只提交本房间反馈。'];
    }
    public function submit(string $token, string $clientAddress, array $input): array
    {
        if (array_diff(array_keys($input), ['request_key', 'summary', 'category'])) throw new InvalidArgumentException('只允许提交反馈类型和内容');
        if (!is_string($input['request_key'] ?? null) || !is_string($input['summary'] ?? null) || !is_string($input['category'] ?? 'feedback')) throw new InvalidArgumentException('反馈字段必须为文本');
        $request = $input['request_key'];
        if (!preg_match('/^[a-f0-9]{32}$/D', $request)) throw new InvalidArgumentException('提交标识无效，请刷新页面');
        $summary = trim((string)($input['summary'] ?? '')); $category = (string)($input['category'] ?? 'feedback');
        if ($summary === '' || mb_strlen($summary) > 1500 || preg_match('/\b1[3-9]\d{9}\b|\d{17}[\dXx]|[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', $summary)) throw new InvalidArgumentException('请填写1至1500字反馈，删除电话、证件号和邮箱');
        if (!in_array($category, ['feedback', 'complaint'], true)) throw new InvalidArgumentException('反馈类型无效');
        $store = new GuestOperationRecordStore();
        return Db::transaction(function () use ($token, $clientAddress, $request, $summary, $category, $store): array {
            $entry = $this->resolve($token); $tenant = $entry['tenant_id']; $hotel = $entry['hotel_id']; $document = $entry['document'];
            $key = 'guest-' . $request; $current = $store->latest($tenant, $hotel, 'feedback', $key);
            if ($current) {
                $original = $current['document']['guest_submission'] ?? [];
                if (($original['entry_key'] ?? '') !== $entry['record_key'] || !hash_equals($original['input_digest'] ?? '', hash('sha256', $category . '\n' . $summary))) throw new RuntimeException('提交标识冲突，请刷新页面', 409);
                return ['submission_status' => 'readback_verified', 'receipt' => $key, 'message' => '反馈已收取，请联系前台处理紧急事项'];
            }
            // Serialize per entry before checking/writing counters; MySQL lock also prevents parallel bypass.
            Db::name('guest_operation_heads')->where('tenant_id', $tenant)->where('hotel_id', $hotel)->where('kind', 'feedback_entry')->where('record_key', $entry['record_key'])->lock(true)->find();
            $entry = $this->resolve($token); $document = $entry['document'];
            $bucket = intdiv(time(), 900); $digest = $document['token_digest'];
            foreach (['client:' . hash('sha256', $digest . ':' . $clientAddress) => 5, 'entry:' . $digest => 30] as $rateKey => $limit) {
                $rateKey .= ':' . $bucket; $rate = $store->latest($tenant, $hotel, 'guest_rate_limit', $rateKey);
                $count = (int)($rate['document']['count'] ?? 0);
                if ($count >= $limit) throw new RuntimeException('提交过于频繁，请稍后重试或联系前台', 429);
                $store->append($tenant, $hotel, 0, 'guest_rate_limit', $rateKey, $rate['revision'] ?? 0, ['count' => $count + 1]);
            }
            $today = substr($store->now(), 0, 10);
            // Validate assigned owner at submission time; deactivated employees do not silently receive cases.
            $owners = (new GuestOperationsService())->overview($tenant, [$hotel], $hotel, $today, $today, 'pms')['owners'];
            if (!in_array($document['owner_user_id'], array_column($owners, 'id'), true)) throw new RuntimeException('反馈责任人暂不可用，请联系前台', 503);
            $case = ['case_key' => $key, 'incident_date' => $today, 'category' => $category, 'summary' => $summary, 'owner_user_id' => $document['owner_user_id'], 'due_at' => (new DateTimeImmutable($store->now()))->modify('+24 hours')->format('Y-m-d H:i:s'), 'source_reference' => 'guest_public_entry:' . $entry['record_key'], 'evidence_refs' => [], 'status' => 'open', 'facts' => [], 'edit_reason' => '', 'source_method' => 'guest_public_submission', 'room_id' => $document['room_id'], 'room_label' => $document['room_label'], 'guest_submission' => ['entry_key' => $entry['record_key'], 'input_digest' => hash('sha256', $category . '\n' . $summary), 'submitted_at' => $store->now()]];
            $saved = $store->append($tenant, $hotel, 0, 'feedback', $key, 0, $case, 'manual', $today);
            if (!$saved['readback_verified']) throw new RuntimeException('反馈保存回读失败', 503);
            return ['submission_status' => 'readback_verified', 'receipt' => $key, 'message' => '反馈已收取，请联系前台处理紧急事项'];
        });
    }
    private function resolve(string $token): array
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $token)) throw new RuntimeException('反馈入口不存在或已停用，请联系前台', 404);
        $digest = hash('sha256', $token); $store = new GuestOperationRecordStore();
        $heads = Db::name('guest_operation_heads')->where('kind', 'guest_public_pointer')->where('record_key', $digest)->select()->toArray();
        if (count($heads) !== 1) throw new RuntimeException('反馈入口不存在或已停用，请联系前台', 404);
        $head = $heads[0]; $pointer = $store->latest((int)$head['tenant_id'], (int)$head['hotel_id'], 'guest_public_pointer', $digest);
        $entry = $store->latest($pointer['tenant_id'], $pointer['hotel_id'], 'feedback_entry', $pointer['document']['entry_key']);
        $hotel = Db::name('hotels')->where('id', $pointer['hotel_id'])->where('tenant_id', $pointer['tenant_id'])->where('status', 1)->find();
        if (!$hotel || !$entry || ($entry['document']['access_mode'] ?? '') !== 'guest_submission_only' || ($entry['document']['enabled'] ?? false) !== true || !hash_equals($entry['document']['token_digest'] ?? '', $digest)) throw new RuntimeException('反馈入口不存在或已停用，请联系前台', 404);
        (new GuestRoomRegistryService())->requireActive($pointer['tenant_id'], $pointer['hotel_id'], (int)$entry['document']['room_id']);
        return $entry;
    }
}
