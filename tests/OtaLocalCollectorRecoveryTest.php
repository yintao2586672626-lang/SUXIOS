<?php
declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use Tests\Support\OtaLocalCollectorRealImportFixture;
use think\facade\Db;
use RuntimeException;

final class OtaLocalCollectorRecoveryTest extends TestCase
{
    private OtaLocalCollectorRealImportFixture $fixture;
    protected function setUp(): void { $this->fixture = new OtaLocalCollectorRealImportFixture(); }
    protected function tearDown(): void { $this->fixture->close(); }

    private function item(): array
    {
        $tasks = $this->fixture->service->status($this->fixture->actor())['tasks'];
        foreach ($tasks as $task) if ((int)$task['id'] === (int)$this->fixture->task['id']) return $task['recovery_item'];
        self::fail('Original task must remain visible in its owner scope.');
    }

    private function input(string $action = 'reconcile'): array
    {
        $item = $this->item();
        return ['scope' => $item['scope'], 'attempt' => $item['attempt'], 'result_hash' => $item['source_receipt']['result_hash'],
            'result_id' => $item['source_receipt']['result_id'], 'action' => $action];
    }

    private function recover(array $input): array
    {
        return $this->fixture->service->recoverCollectionTask($this->fixture->actor(), (int)$this->fixture->task['id'], $input);
    }

    public function testInterruptedUploadKeepsOriginalAttemptAndDoesNotScheduleAnotherCapture(): void
    {
        $envelope = $this->fixture->envelope($this->fixture->businessResult());
        Db::name('ota_local_collector_tasks')->where('id', $this->fixture->task['id'])->update(['lease_expires_at' => '2000-01-01 00:00:00']);
        $next = $this->fixture->service->nextTask($this->fixture->pair['device_public_id'], $this->fixture->pair['device_token']);
        self::assertNotSame((int)$this->fixture->task['id'], (int)($next['task']['id'] ?? 0));
        self::assertSame('result_unknown', Db::name('ota_local_collector_tasks')->where('id', $this->fixture->task['id'])->value('status'));
        $unknown = $this->recover($this->input());
        self::assertSame('unknown', $unknown['recovery']['state']);
        self::assertSame(['reconcile'], $unknown['recovery']['actions']);
        self::assertSame(0, Db::name('online_daily_data')->count());
        $resumed = $this->fixture->resume(array_intersect_key($envelope, array_flip(['result_id', 'result_hash', 'attempt'])));
        self::assertSame($envelope['attempt'], $resumed['attempt']);
        $saved = $this->fixture->submit(array_replace($envelope, ['lease_token' => $resumed['lease_token']]));
        self::assertSame('accepted', $saved['delivery']['status']);
        self::assertSame('partial', $this->item()['state'], 'A prior unknown check cannot hide a subsequently saved partial result.');
        self::assertSame(1, Db::name('online_daily_data')->count());
    }

    public function testSaveThenLostResponseReconcilesOriginalRowsWithoutImportingAgain(): void
    {
        $envelope = $this->fixture->envelope($this->fixture->businessResult());
        $saved = $this->fixture->submit($envelope);
        $checked = $this->recover($this->input());
        self::assertSame('verified', $checked['recovery']['last_check']['status']);
        self::assertSame($saved['summary']['deterministic_readback']['row_ids'], $checked['recovery']['last_check']['row_ids']);
        $replayed = $this->fixture->resume(array_intersect_key($envelope, array_flip(['result_id', 'result_hash', 'attempt'])));
        self::assertTrue($replayed['replayed']);
        self::assertTrue($replayed['reconciliation']['readback_verified']);
        self::assertSame(1, Db::name('platform_data_raw_records')->count());
        self::assertSame(1, Db::name('platform_data_sync_tasks')->count());
        $persisted = json_decode((string)Db::name('ota_local_collector_tasks')->where('id', $this->fixture->task['id'])->value('request_json'), true);
        self::assertSame($checked['recovery']['last_check'], $persisted['recovery_check']);
    }

    public function testPreviouslyAcceptedReceiptWithChangedRowScopeRemainsUnknownAndCannotBackfill(): void
    {
        $envelope = $this->fixture->envelope($this->fixture->businessResult());
        $this->fixture->submit($envelope);
        Db::name('online_daily_data')->where('system_hotel_id', 101)->update(['data_date' => '2026-09-02']);
        $replayed = $this->fixture->resume(array_intersect_key($envelope, array_flip(['result_id', 'result_hash', 'attempt'])));
        self::assertSame('result_unknown', $replayed['status']);
        self::assertFalse($replayed['reconciliation']['readback_verified']);
        $checked = $this->recover($this->input());
        self::assertSame('unknown', $checked['recovery']['state']);
        self::assertSame(['reconcile'], $checked['recovery']['actions']);
        self::assertSame(1, Db::name('platform_data_sync_tasks')->count());
        $this->expectException(RuntimeException::class);
        $this->expectExceptionCode(409);
        $this->recover($this->input('backfill'));
    }

    public function testPartialFailureCanCreateExactlyOneLinkedHistoricalGapTask(): void
    {
        Db::name('ota_local_collector_tasks')->where('id', $this->fixture->task['id'])->update(['max_attempts' => 1]);
        $this->fixture->submit($this->fixture->envelope($this->fixture->businessResult()));
        self::assertSame('partial', $this->item()['state']);
        $input = $this->input('backfill');
        $first = $this->recover($input);
        $second = $this->recover($input);
        $id = $first['recovery']['recovery_task_id'];
        self::assertGreaterThan(0, $id);
        self::assertSame($id, $second['recovery']['recovery_task_id']);
        $child = Db::name('ota_local_collector_tasks')->where('id', $id)->find();
        self::assertSame('2026-09-01', $child['data_date']);
        self::assertSame('meituan', $child['platform']);
        self::assertSame(101, (int)$child['system_hotel_id']);
        $request = json_decode($child['request_json'], true);
        self::assertSame((int)$this->fixture->task['id'], $request['recovery_source_task_id']);
        self::assertSame($this->item()['source_receipt'], $request['recovery_source_receipt']);
        self::assertSame(['traffic'], $request['sections']);
        self::assertSame(1, Db::name('online_daily_data')->count(), 'Queuing a recovery must not duplicate already-saved facts.');
    }

    public function testRecoveryRejectsChangedHotelTenantPlatformDateStoreTypeAttemptAndResult(): void
    {
        $this->fixture->envelope($this->fixture->businessResult());
        $input = $this->input();
        foreach (['tenant_id', 'system_hotel_id', 'platform', 'platform_hotel_id', 'business_date', 'data_type', 'account_id'] as $field) {
            $changed = $input;
            $changed['scope'][$field] = 'different';
            try { $this->recover($changed); self::fail('Changed scope must be rejected: ' . $field); }
            catch (RuntimeException $error) { self::assertSame(409, $error->getCode()); }
        }
        foreach (['attempt' => 999, 'result_hash' => str_repeat('a', 64)] as $field => $value) {
            try { $this->recover(array_replace($input, [$field => $value])); self::fail('Changed identity must be rejected.'); }
            catch (RuntimeException $error) { self::assertSame(409, $error->getCode()); }
        }
        self::assertSame(0, Db::name('online_daily_data')->count());
    }

    public function testChangedPlatformMappingCannotAcceptOriginalResult(): void
    {
        $envelope = $this->fixture->envelope($this->fixture->businessResult());
        Db::name('ota_local_collector_account_hotels')->where('system_hotel_id', 101)->update(['platform_hotel_id' => 'DIFFERENT-STORE']);
        self::assertSame('blocked', $this->item()['state']);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionCode(409);
        $this->fixture->submit($envelope);
    }

    public function testLegacySuccessWithoutEvidenceRemainsUnverified(): void
    {
        Db::name('ota_local_collector_tasks')->where('id', $this->fixture->task['id'])->update(['status' => 'success']);
        self::assertSame('unverified', $this->item()['state']);
        $checked = $this->recover($this->input());
        self::assertSame('unknown', $checked['recovery']['last_check']['status']);
        self::assertSame([], $checked['recovery']['last_check']['row_ids']);
        self::assertSame(0, Db::name('online_daily_data')->count());
    }

    public function testRedirectIsNotLoginExpiryAndRecoveryProbesOnlyOriginalScope(): void
    {
        Db::name('ota_local_collector_tasks')->where('id', $this->fixture->task['id'])->update([
            'status' => 'failed', 'error_code' => 'redirect_unverified', 'lease_token_hash' => '', 'finished_at' => date('Y-m-d H:i:s'),
        ]);
        $guide = $this->fixture->service->recoveryGuide('http_302', 'ctrip');
        self::assertSame('redirect_unverified', $guide['status']);
        self::assertFalse($guide['auto_retry']);
        $result = $this->recover($this->input('verify_session'));
        $child = Db::name('ota_local_collector_tasks')->where('id', $result['recovery']['recovery_task_id'])->find();
        self::assertSame('session_probe', $child['task_type']);
        $request = json_decode($child['request_json'], true);
        self::assertSame('2026-09-01', $request['resume_collections'][0]['data_date']);
        self::assertSame(101, (int)$child['system_hotel_id']);
        self::assertSame(0, Db::name('online_daily_data')->count());
        self::assertIsArray($request['resume_collections'][0]['request']['ordered_collection']);
        $lease = $this->fixture->service->nextTask($this->fixture->pair['device_public_id'], $this->fixture->pair['device_token'])['task'];
        self::assertSame((int)$child['id'], (int)$lease['id']);
        $result = $this->fixture->service->submitTaskResult($this->fixture->pair['device_public_id'], $this->fixture->pair['device_token'], (int)$lease['id'], [
            'lease_token' => $lease['lease_token'], 'success' => true, 'session_status' => 'current_session_verified',
        ]);
        self::assertNotEmpty($result['summary']['resumed_collection_task_ids'] ?? [], json_encode($result['summary'] ?? []));
        $resumed = Db::name('ota_local_collector_tasks')->where('id', $result['summary']['resumed_collection_task_ids'][0])->find();
        self::assertSame('2026-09-01', $resumed['data_date']);
        self::assertSame(101, (int)$resumed['system_hotel_id']);
    }

    public function testFailureEnvelopeHasDurableReceiptAndReplayDoesNotBecomeASuccess(): void
    {
        $envelope = $this->fixture->envelope(['success' => false, 'error_code' => 'network_error', 'error_summary' => 'Synthetic connection interrupted']);
        $first = $this->fixture->submit($envelope);
        self::assertSame('accepted', $first['delivery']['status']);
        self::assertSame('capture_failed', $first['delivery']['business_status']);
        self::assertNull($first['delivery']['saved_count']);
        self::assertFalse($first['delivery']['readback_verified']);
        $replay = $this->fixture->submit($envelope);
        self::assertSame('capture_failed', $replay['status']);
        self::assertSame('not_saved', $replay['reconciliation']['status']);
        self::assertSame(0, Db::name('online_daily_data')->count());
    }

    public function testChangedBusinessValueCannotPassOriginalReadback(): void
    {
        $envelope = $this->fixture->envelope($this->fixture->businessResult());
        $first = $this->fixture->submit($envelope);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $first['delivery']['rows_fingerprint']);
        Db::name('online_daily_data')->where('system_hotel_id', 101)->update(['amount' => 9999]);
        $replay = $this->fixture->submit($envelope);
        self::assertSame('unknown', $replay['reconciliation']['status']);
        self::assertSame('original_row_values_changed', $replay['reconciliation']['reason_code']);
    }

    public function testMissingOriginalEvidenceIsUnknownWithoutRepeatingASavedImport(): void
    {
        $envelope = $this->fixture->envelope($this->fixture->businessResult());
        $first = $this->fixture->submit($envelope);
        $receipt = $first['delivery'];
        $path = $this->fixture->root . '/evidence/12/' . $receipt['device_id'] . '/' . $receipt['task_id'] . '/1/' . $receipt['evidence']['result_hash'] . '.json';
        self::assertFileExists($path);
        file_put_contents($path, '{}'); // Only the current test's synthetic evidence directory.
        $replay = $this->fixture->submit($envelope);
        self::assertSame('result_unknown', $replay['status']);
        self::assertSame('original_evidence_unavailable', $replay['reconciliation']['reason_code']);
        self::assertSame(1, Db::name('platform_data_sync_tasks')->count());
    }

    public function testCompleteCoreHasSuccessWithoutPromotingAnotherPlatform(): void
    {
        $first = $this->fixture->submit($this->fixture->envelope($this->fixture->businessResult(true)));
        self::assertSame('success', $first['status'], json_encode(array_intersect_key($first, array_flip(['status', 'error_summary', 'error_code']))));
        self::assertSame('success', $this->item()['state']);
        self::assertFalse($first['summary']['canonical_history']['canonical_history_complete']);
    }

    public function testPartialThenCompleteRecoveryActuallySavesAndReadsTheNewAttempt(): void
    {
        $this->fixture->submit($this->fixture->envelope($this->fixture->businessResult()));
        self::assertSame('partial', $this->item()['state']);
        Db::name('ota_local_collector_tasks')->where('id', $this->fixture->task['id'])->update(['available_at' => '2000-01-01 00:00:00']);
        $lease = $this->fixture->service->nextTask($this->fixture->pair['device_public_id'], $this->fixture->pair['device_token'])['task'];
        self::assertSame((int)$this->fixture->task['id'], (int)$lease['id']);
        self::assertSame(2, (int)$lease['attempt']);
        $json = json_encode($this->fixture->businessResult(true), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $identity = ['result_id' => 'synthetic-recovery-result-2', 'result_hash' => hash('sha256', $json), 'attempt' => 2];
        $upload = $this->fixture->resume($identity);
        $result = $this->fixture->submit($identity + ['result_json' => $json, 'lease_token' => $upload['lease_token']]);
        self::assertSame('success', $result['status']);
        self::assertSame('recovered_success', $this->item()['state']);
        self::assertSame('verified', $this->recover($this->input())['recovery']['last_check']['status']);
        self::assertSame(2, Db::name('platform_data_sync_tasks')->count());
    }

    public function testAuthenticatedControllerReturnsRecoveryAndRejectsCrossScopePayload(): void
    {
        $this->fixture->submit($this->fixture->envelope($this->fixture->businessResult()));
        $app = \think\Container::getInstance();
        $app->bind(\app\service\OtaLocalCollectorService::class, fn() => $this->fixture->service);
        foreach ([false, true] as $crossScope) {
            $input = $this->input();
            if ($crossScope) $input['scope']['business_date'] = '2026-09-02';
            $request = (new \think\Request())->setMethod('POST')->withInput(json_encode($input));
            $request->user = $this->fixture->actor();
            $app->instance('request', $request);
            $response = (new \app\controller\ota\LocalCollectorController($app))->recover((int)$this->fixture->task['id']);
            $payload = json_decode($response->getContent(), true);
            self::assertSame($crossScope ? 409 : 200, $payload['code']);
            self::assertSame($crossScope ? 409 : 200, $response->getCode());
            if (!$crossScope) self::assertSame('verified', $payload['data']['recovery']['last_check']['status']);
        }
    }

    public function testReadOnlyHotelPermissionCannotQueueRecovery(): void
    {
        Db::name('ota_local_collector_tasks')->where('id', $this->fixture->task['id'])->update(['status' => 'failed']);
        Db::name('user_hotel_permissions')->where('hotel_id', 101)->update(['can_fetch_online_data' => 0]);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionCode(403);
        $this->recover($this->input('backfill'));
    }

    public function testExistingManualBackfillCannotBypassAnUnknownFailedUpload(): void
    {
        $this->fixture->envelope($this->fixture->businessResult());
        Db::name('ota_local_collector_tasks')->where('id', $this->fixture->task['id'])->update([
            'status' => 'failed', 'error_code' => 'upload_failed', 'finished_at' => date('Y-m-d H:i:s'),
        ]);
        $count = Db::name('ota_local_collector_tasks')->count();
        $response = $this->fixture->service->createTask($this->fixture->actor(), [
            'account_id' => $this->fixture->task['account_id'], 'system_hotel_id' => 101,
            'task_type' => 'backfill', 'data_type' => 'business', 'data_date' => '2026-09-01', 'force' => true,
        ]);
        self::assertSame((int)$this->fixture->task['id'], (int)$response['task']['id']);
        self::assertSame($count, Db::name('ota_local_collector_tasks')->count());
        self::assertSame('unknown', $this->item()['state']);
    }
}
