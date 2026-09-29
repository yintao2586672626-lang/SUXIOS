<?php
declare(strict_types=1);

namespace app\controller;

use app\service\OperationManagementService;
use app\service\RevenueResearchExecutionArtifactService;
use app\service\RevenueResearchService;
use InvalidArgumentException;
use RuntimeException;
use think\facade\Db;
use think\Response;
use Throwable;

class RevenueResearch extends Base
{
    public function run(): Response
    {
        $data = $this->requestData();
        $productKey = trim((string)($data['product_key'] ?? ''));
        $modelKey = trim((string)($data['model_key'] ?? 'deepseek_chat'));
        $hotelIdRaw = trim((string)($data['hotel_id'] ?? ''));
        $hotelId = $hotelIdRaw !== '' ? (int)$hotelIdRaw : null;

        if ($productKey === '') {
            return $this->error('product_key 不能为空', 422);
        }

        try {
            $result = (new RevenueResearchService())->run($productKey, $modelKey, $this->currentUser, $hotelId);
            $message = ($result['status'] ?? '') === 'done'
                ? 'OTA渠道经营预测已生成'
                : '数据不足，未生成可用经营预测';

            return $this->success($result, $message);
        } catch (RuntimeException $e) {
            $code = $e->getCode();
            if ($code < 400 || $code > 599) {
                $code = 500;
            }
            return $this->error($e->getMessage(), $code);
        } catch (\Throwable $e) {
            return $this->error('经营预测失败：' . $e->getMessage(), 500);
        }
    }

    public function createExecutionIntent(): Response
    {
        try {
            $data = $this->requestData();
            $artifactId = is_scalar($data['research_artifact_id'] ?? null)
                ? trim((string)$data['research_artifact_id'])
                : '';
            if ($artifactId === '') {
                throw new InvalidArgumentException('research_artifact_id is required; rerun revenue research');
            }
            $hotelCandidate = (int)($data['hotel_id'] ?? 0);
            [$hotelIds, $hotelId] = $this->resolveExecutionHotelScope($hotelCandidate);

            if ($hotelId === null || $hotelId <= 0) {
                throw new InvalidArgumentException('hotel_id is required for revenue research execution intent');
            }
            if (($denied = $this->hotelCapabilityDeniedResponse(
                $hotelId,
                'operation.execute',
                'operation.execute permission is required for this hotel'
            )) !== null) {
                return $denied;
            }

            $actorId = (int)($this->currentUser->id ?? 0);
            $artifactService = $this->executionArtifactService();
            $researchService = $this->executionResearchService();
            $operationService = $this->executionOperationService();
            $intent = $artifactService->resolveExecutionIntent(
                $artifactId,
                $actorId,
                $hotelId,
                fn(string $key): ?array => $operationService->readExecutionIntentByIdempotencyKey($key, $hotelIds),
                function (array $research, string $key, array $provenance) use ($researchService, $operationService, $hotelIds, $hotelId, $actorId): array {
                    $intentInput = $researchService->buildReadyExecutionIntentInput($research, ['hotel_id' => $hotelId]);
                    $researchService->assertNoDuplicateExecutionIntent($intentInput, $this->existingExecutionIntentRows($intentInput, $hotelId));
                    $intentInput['evidence']['revenue_research_artifact'] = $provenance;
                    return $operationService->createExecutionIntent($hotelIds, $hotelId, $intentInput, $actorId, false, $key, true);
                }
            );

            return $this->success([
                'execution_intent' => $intent,
                'source_module' => 'revenue_research',
                'metric_scope' => 'ota_channel',
                'source_policy' => 'revenue_research_output_to_operation_execution_intent',
                'next_action' => (string)($intent['status'] ?? '') === 'blocked'
                    ? 'resolve_research_data_gaps'
                    : 'review_and_approve_execution_intent',
            ], 'execution intent created');
        } catch (Throwable $e) {
            return $this->error($this->safeErrorMessage($e, 'revenue research execution intent create failed'), $this->statusCode($e));
        }
    }

    protected function executionArtifactService(): RevenueResearchExecutionArtifactService
    {
        return new RevenueResearchExecutionArtifactService();
    }

    protected function executionResearchService(): RevenueResearchService
    {
        return new RevenueResearchService();
    }

    protected function executionOperationService(): OperationManagementService
    {
        return new OperationManagementService();
    }

    /**
     * @param array<string, mixed> $intentInput
     * @return array<int, array<string, mixed>>
     */
    private function existingExecutionIntentRows(array $intentInput, int $hotelId): array
    {
        $sourceModule = trim((string)($intentInput['source_module'] ?? ''));
        $sourceRecordId = (int)($intentInput['source_record_id'] ?? 0);
        if ($sourceModule === '' || $sourceRecordId <= 0 || $hotelId <= 0) {
            return [];
        }

        $tenantId = (int)Db::name('hotels')->where('id', $hotelId)->value('tenant_id');
        if ($tenantId <= 0) {
            throw new RuntimeException('current hotel tenant is required for revenue research intent linkage', 422);
        }
        $today = (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Shanghai')))->format('Y-m-d');

        return Db::name('operation_execution_intents')
            ->where('source_module', $sourceModule)
            ->where('source_record_id', $sourceRecordId)
            ->where('hotel_id', $hotelId)
            ->where('tenant_id', $tenantId)
            ->where('date_end', '>=', $today)
            ->whereNull('deleted_at')
            ->field('id,source_module,source_record_id,hotel_id,date_end,status,deleted_at')
            ->order('id', 'desc')
            ->select()
            ->toArray();
    }

    /**
     * @return array{0:array<int, int>, 1:?int}
     */
    private function resolveExecutionHotelScope(int $inputHotelId = 0): array
    {
        if (!$this->currentUser) {
            throw new RuntimeException('not logged in', 401);
        }

        $permitted = array_values(array_map('intval', $this->currentUser->getPermittedHotelIds()));
        if (empty($permitted)) {
            throw new RuntimeException('no permitted hotel', 403);
        }

        if ($inputHotelId > 0) {
            if (!in_array($inputHotelId, $permitted, true)) {
                throw new RuntimeException('hotel_id is not permitted', 403);
            }
            return [[$inputHotelId], $inputHotelId];
        }

        if (count($permitted) === 1) {
            return [$permitted, $permitted[0]];
        }

        throw new InvalidArgumentException('hotel_id is required for revenue research execution intent');
    }

    private function statusCode(Throwable $e): int
    {
        if ($e instanceof InvalidArgumentException) {
            return 422;
        }

        $code = (int)$e->getCode();
        if ($code >= 400 && $code <= 599) {
            return $code;
        }

        $message = $e->getMessage();
        if (str_contains($message, 'not logged in')) {
            return 401;
        }
        if (str_contains($message, 'permitted') || str_contains($message, 'no permitted hotel')) {
            return 403;
        }

        return 500;
    }

    private function safeErrorMessage(Throwable $e, string $fallback): string
    {
        $message = trim($e->getMessage());
        return $message !== '' ? $message : $fallback;
    }
}
