<?php
declare(strict_types=1);

namespace app\controller;

use app\service\TemporalInsightService;
use app\service\TemporalForecastTrialService;
use InvalidArgumentException;
use RuntimeException;
use think\App;
use think\Response;

final class TemporalInsight extends Base
{
    private TemporalInsightService $service;
    private TemporalForecastTrialService $trialService;

    public function __construct(
        App $app,
        ?TemporalInsightService $service = null,
        ?TemporalForecastTrialService $trialService = null
    )
    {
        parent::__construct($app);
        $this->service = $service ?: new TemporalInsightService();
        $this->trialService = $trialService ?: new TemporalForecastTrialService($this->service);
    }

    public function overview(): Response
    {
        try {
            $historyDays = (int)$this->request->get('history_days', 30);
            $futureDays = (int)$this->request->get('future_days', 7);
            $asOfDate = trim((string)$this->request->get('as_of_date', ''));
            $data = $this->service->overview(
                $this->resolveHotelIds(),
                $historyDays,
                $futureDays,
                $asOfDate !== '' ? $asOfDate : null
            );
            return $this->success($data, '统一时间视角获取成功');
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (\Throwable $e) {
            return $this->error('统一时间视角获取失败：' . $e->getMessage(), 500);
        }
    }

    public function generateForecast(): Response
    {
        try {
            $payload = $this->requestData();
            $hotelId = (int)($payload['hotel_id'] ?? $this->request->param('hotel_id', 0));
            if (!$this->canAccessHotel($hotelId)) {
                return $this->error('无权为该酒店生成预测版本。', 403);
            }
            $futureDays = (int)($payload['future_days'] ?? 7);
            $asOfDate = trim((string)($payload['as_of_date'] ?? ''));
            $userId = (int)($this->currentUser->id ?? 0);
            $data = $this->service->generateForecast(
                $hotelId,
                $userId,
                $asOfDate !== '' ? $asOfDate : null,
                $futureDays
            );
            $message = ($data['status'] ?? '') === 'generated'
                ? '预测版本已保存并回读'
                : '历史样本不足，未生成预测版本';
            return $this->success($data, $message);
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (RuntimeException $e) {
            $code = (int)$e->getCode();
            return $this->error($e->getMessage(), $code >= 400 && $code <= 499 ? $code : 500);
        } catch (\Throwable $e) {
            return $this->error('预测版本生成失败：' . $e->getMessage(), 500);
        }
    }

    public function createOperationReviewIntent(int $id): Response
    {
        try {
            if ($id <= 0 || !$this->currentUser) {
                return $this->error('预测点无效或尚未登录。', 422);
            }
            $permittedIds = $this->currentUser->isSuperAdmin()
                ? []
                : array_values(array_unique(array_filter(
                    array_map('intval', $this->currentUser->getPermittedHotelIds()),
                    static fn(int $hotelId): bool => $hotelId > 0
                )));
            if (!$this->currentUser->isSuperAdmin() && $permittedIds === []) {
                return $this->error('暂无可送入运营审核的酒店。', 403);
            }

            return $this->success(
                $this->service->createOperationReviewIntent(
                    $id,
                    $permittedIds,
                    (int)($this->currentUser->id ?? 0)
                ),
                '预测运营建议已进入人工审核，尚未生成运营任务'
            );
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (RuntimeException $e) {
            $code = (int)$e->getCode();
            return $this->error($e->getMessage(), $code >= 400 && $code <= 499 ? $code : 500);
        } catch (\Throwable $e) {
            return $this->error('预测运营建议送审失败：' . $e->getMessage(), 500);
        }
    }

    public function trialList(): Response
    {
        try {
            $hotelId = (int)$this->request->get('hotel_id', 0);
            if (!$this->canAccessHotel($hotelId)) {
                return $this->error('无权查看该酒店的预测试运营。', 403);
            }
            return $this->success([
                'list' => $this->trialService->listTrials(
                    $hotelId,
                    (int)$this->request->get('limit', 20)
                ),
                'hotel_id' => $hotelId,
                'metric_scope' => 'ota_channel',
                'automatic_price_write' => false,
            ], '预测试运营列表获取成功');
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (RuntimeException $e) {
            $code = (int)$e->getCode();
            return $this->error($e->getMessage(), $code >= 400 && $code <= 499 ? $code : 500);
        } catch (\Throwable $e) {
            return $this->error('预测试运营列表获取失败：' . $e->getMessage(), 500);
        }
    }

    public function createTrial(): Response
    {
        try {
            $payload = $this->requestData();
            $hotelId = (int)($payload['hotel_id'] ?? 0);
            if (!$this->canAccessHotel($hotelId)) {
                return $this->error('无权为该酒店创建预测试运营。', 403);
            }
            $result = $this->trialService->createTrial(
                $hotelId,
                trim((string)($payload['forecast_run_id'] ?? '')),
                (int)($this->currentUser->id ?? 0)
            );
            return $this->success($result, ($result['idempotent_replay'] ?? false)
                ? '已回读同一预测试运营批次'
                : '14 天限定试运营已保存并精确回读');
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (RuntimeException $e) {
            $code = (int)$e->getCode();
            return $this->error($e->getMessage(), $code >= 400 && $code <= 499 ? $code : 500);
        } catch (\Throwable $e) {
            return $this->error('预测试运营创建失败：' . $e->getMessage(), 500);
        }
    }

    public function trialDetail(int $id): Response
    {
        try {
            $hotelId = (int)$this->request->get('hotel_id', 0);
            if (!$this->canAccessHotel($hotelId)) {
                return $this->error('无权读取该酒店的预测试运营。', 403);
            }
            return $this->success(
                $this->trialService->readTrial($id, $hotelId),
                '预测试运营精确回读成功'
            );
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 404);
        } catch (RuntimeException $e) {
            $code = (int)$e->getCode();
            return $this->error($e->getMessage(), $code >= 400 && $code <= 499 ? $code : 500);
        } catch (\Throwable $e) {
            return $this->error('预测试运营读取失败：' . $e->getMessage(), 500);
        }
    }

    public function createTrialOperationIntent(int $id): Response
    {
        try {
            $payload = $this->requestData();
            $hotelId = (int)($payload['hotel_id'] ?? 0);
            if (!$this->canAccessHotel($hotelId)) {
                return $this->error('无权送审该酒店的预测试运营。', 403);
            }
            return $this->success(
                $this->trialService->createOperationReviewIntent(
                    $id,
                    $hotelId,
                    (int)($this->currentUser->id ?? 0)
                ),
                '预测试运营已进入人工审批，尚未生成运营任务'
            );
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (RuntimeException $e) {
            $code = (int)$e->getCode();
            return $this->error($e->getMessage(), $code >= 400 && $code <= 499 ? $code : 500);
        } catch (\Throwable $e) {
            return $this->error('预测试运营送审失败：' . $e->getMessage(), 500);
        }
    }

    public function refreshTrialActuals(int $id): Response
    {
        try {
            $payload = $this->requestData();
            $hotelId = (int)($payload['hotel_id'] ?? 0);
            if (!$this->canAccessHotel($hotelId)) {
                return $this->error('无权刷新该酒店的到期实际值。', 403);
            }
            return $this->success(
                $this->trialService->refreshActuals($id, $hotelId),
                '到期实际值已按来源精确回读'
            );
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (RuntimeException $e) {
            $code = (int)$e->getCode();
            return $this->error($e->getMessage(), $code >= 400 && $code <= 499 ? $code : 500);
        } catch (\Throwable $e) {
            return $this->error('到期实际值回读失败：' . $e->getMessage(), 500);
        }
    }

    public function finalizeTrialReview(int $id): Response
    {
        try {
            $payload = $this->requestData();
            $hotelId = (int)($payload['hotel_id'] ?? 0);
            if (!$this->canAccessHotel($hotelId)) {
                return $this->error('无权复盘该酒店的预测试运营。', 403);
            }
            return $this->success(
                $this->trialService->finalizeReview(
                    $id,
                    $hotelId,
                    (int)($this->currentUser->id ?? 0),
                    trim((string)($payload['decision'] ?? '')),
                    trim((string)($payload['note'] ?? ''))
                ),
                '预测试运营最终复盘已保存并回读'
            );
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (RuntimeException $e) {
            $code = (int)$e->getCode();
            return $this->error($e->getMessage(), $code >= 400 && $code <= 499 ? $code : 500);
        } catch (\Throwable $e) {
            return $this->error('预测试运营最终复盘失败：' . $e->getMessage(), 500);
        }
    }

    /** @return array<int, int> */
    private function resolveHotelIds(): array
    {
        if (!$this->currentUser) {
            return [0];
        }

        $hotelId = (int)$this->request->get('hotel_id', 0);
        $permittedIds = array_values(array_unique(array_filter(
            array_map('intval', $this->currentUser->getPermittedHotelIds()),
            static fn(int $id): bool => $id > 0
        )));
        if ($hotelId > 0) {
            if ($this->currentUser->isSuperAdmin() || in_array($hotelId, $permittedIds, true)) {
                return [$hotelId];
            }
            return [0];
        }
        if ($this->currentUser->isSuperAdmin()) {
            return [];
        }
        return $permittedIds !== [] ? $permittedIds : [0];
    }

    private function canAccessHotel(int $hotelId): bool
    {
        if ($hotelId <= 0 || !$this->currentUser) {
            return false;
        }
        if ($this->currentUser->isSuperAdmin()) {
            return true;
        }
        return in_array($hotelId, array_map('intval', $this->currentUser->getPermittedHotelIds()), true);
    }
}
