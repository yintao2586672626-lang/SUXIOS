<?php
declare(strict_types=1);

namespace app\controller;

use app\service\ExpansionService;
use app\service\OperationManagementService;
use InvalidArgumentException;
use RuntimeException;
use think\App;
use think\Response;
use think\facade\Db;
use Throwable;

class Expansion extends Base
{
    private ExpansionService $service;

    public function __construct(App $app, ?ExpansionService $service = null)
    {
        parent::__construct($app);
        $this->service = $service ?: new ExpansionService();
    }

    public function marketEvaluation(): Response
    {
        try {
            $this->ensureLogin();
            $input = $this->request->post();
            $result = $this->service->evaluateMarket($input);
            $result['record_id'] = $this->service->saveRecord('market', $input, $result, (int)($this->currentUser->id ?? 0));
            $result['project_readiness'] = $this->service->buildProjectReadiness('market', $input, $result);

            return $this->success($result, '市场规则初筛已生成（不等同投资结论）');
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (\Throwable $e) {
            return $this->error('市场评估生成失败: ' . $e->getMessage(), 500);
        }
    }

    public function benchmarkModel(): Response
    {
        try {
            $this->ensureLogin();
            $input = $this->request->post();
            $result = $this->service->buildBenchmarkModel($input);
            $result['record_id'] = $this->service->saveRecord('benchmark', $input, $result, (int)($this->currentUser->id ?? 0));
            $result['project_readiness'] = $this->service->buildProjectReadiness('benchmark', $input, $result);

            $message = ($result['source'] ?? '') === 'synthetic_rule_scenario'
                ? '情景标杆草案已生成（非真实竞品数据）'
                : '标杆选模已生成';

            return $this->success($result, $message);
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (\Throwable $e) {
            return $this->error('标杆选模生成失败: ' . $e->getMessage(), 500);
        }
    }

    public function collaborationEfficiency(): Response
    {
        try {
            $this->ensureLogin();
            $input = $this->request->post();
            $result = $this->service->improveCollaboration($input);
            $result['record_id'] = $this->service->saveRecord('collaboration', $input, $result, (int)($this->currentUser->id ?? 0));
            $result['project_readiness'] = $this->service->buildProjectReadiness('collaboration', $input, $result);

            return $this->success($result, '协同提效看板已生成');
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (\Throwable $e) {
            return $this->error('协同提效看板生成失败: ' . $e->getMessage(), 500);
        }
    }

    public function records(): Response
    {
        try {
            $this->ensureLogin();
            $list = $this->service->records((int)($this->currentUser->id ?? 0), $this->currentUser->isSuperAdmin());
            return $this->success(['list' => $list]);
        } catch (\Throwable $e) {
            return $this->error('获取扩张记录失败: ' . $e->getMessage(), 400);
        }
    }

    public function detail(int $id): Response
    {
        try {
            $this->ensureLogin();
            if ($id <= 0) {
                return $this->error('扩张记录ID无效', 422);
            }

            return $this->success($this->service->detail($id, (int)($this->currentUser->id ?? 0), $this->currentUser->isSuperAdmin()));
        } catch (\Throwable $e) {
            return $this->error('获取扩张记录详情失败: ' . $e->getMessage(), 400);
        }
    }

    public function createExecutionIntent(int $id): Response
    {
        try {
            $this->ensureLogin();
            if ($id <= 0) {
                return $this->error('expansion record id is invalid', 422);
            }

            $hotelId = (int)$this->request->param('hotel_id', 0);
            if ($hotelId <= 0) {
                return $this->error('hotel_id is required for expansion execution tracking', 422);
            }

            $permittedHotelIds = array_values(array_map('intval', $this->currentUser->getPermittedHotelIds()));
            if (empty($permittedHotelIds) || !in_array($hotelId, $permittedHotelIds, true)) {
                return $this->error('hotel_id is not permitted', 403);
            }

            $userId = (int)($this->currentUser->id ?? 0);
            $isSuperAdmin = $this->currentUser->isSuperAdmin();
            $dateOverrides = [
                'date_start' => (string)$this->request->param('date_start', ''),
                'date_end' => (string)$this->request->param('date_end', ''),
            ];

            // Prepare schema before the transaction: MySQL/MariaDB DDL may implicitly commit.
            $this->service->ensureTable();

            $result = Db::transaction(function () use ($id, $hotelId, $permittedHotelIds, $userId, $isSuperAdmin, $dateOverrides): array {
                $record = $this->service->detail($id, $userId, $isSuperAdmin, true);
                $operationService = new OperationManagementService();
                $linkedIntentId = (int)($record['execution_intent_id']
                    ?? $record['result']['operation_execution_intent_id']
                    ?? $record['result']['execution_intent_id']
                    ?? 0);
                if ($linkedIntentId > 0) {
                    $intent = $operationService->readExecutionIntent($linkedIntentId, $permittedHotelIds);
                    if ((int)($intent['hotel_id'] ?? 0) !== $hotelId) {
                        return [
                            'conflict' => true,
                            'linked_hotel_id' => (int)($intent['hotel_id'] ?? 0),
                        ];
                    }

                    return [
                        'execution_intent' => $intent,
                        'record' => $record,
                        'idempotent_replay' => true,
                    ];
                }

                $input = $this->service->buildExecutionIntentInput($record, $hotelId, $dateOverrides);
                $intent = $operationService->createExecutionIntent($permittedHotelIds, $hotelId, $input, $userId, true);
                $updatedRecord = $this->service->attachExecutionTracking($id, $userId, $isSuperAdmin, [
                    'execution_intent_id' => (int)($intent['id'] ?? 0),
                    'hotel_id' => $hotelId,
                    'status' => (string)($intent['status'] ?? ''),
                ]);

                return [
                    'execution_intent' => $intent,
                    'record' => $updatedRecord,
                    'idempotent_replay' => false,
                ];
            });

            if (($result['conflict'] ?? false) === true) {
                return $this->error('expansion record is already linked to an execution intent for a different hotel', 409);
            }

            return $this->success(
                $result,
                ($result['idempotent_replay'] ?? false) ? 'execution intent already linked' : 'execution intent created'
            );
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (RuntimeException $e) {
            $status = in_array((int)$e->getCode(), [409, 500], true) ? (int)$e->getCode() : 404;
            return $this->error($e->getMessage(), $status);
        } catch (Throwable $e) {
            return $this->error('create expansion execution intent failed: ' . $e->getMessage(), 500);
        }
    }

    public function archive(int $id): Response
    {
        try {
            $this->ensureLogin();
            if ($id <= 0) {
                return $this->error('扩张记录ID无效', 422);
            }

            $archived = $this->service->archive($id, (int)($this->currentUser->id ?? 0), $this->currentUser->isSuperAdmin());
            if (!$archived) {
                return $this->error('扩张记录不存在或无权归档', 404);
            }

            return $this->success(['id' => $id], '扩张记录已归档');
        } catch (\Throwable $e) {
            return $this->error('扩张记录归档失败: ' . $e->getMessage(), 400);
        }
    }

    public function clearMarketEvaluation(): Response
    {
        try {
            $this->ensureLogin();
            $archivedCount = $this->service->archiveByType('market', (int)($this->currentUser->id ?? 0), $this->currentUser->isSuperAdmin());

            return $this->success(['archived_count' => $archivedCount], '市场评估历史已清空');
        } catch (\Throwable $e) {
            return $this->error('市场评估历史清空失败: ' . $e->getMessage(), 400);
        }
    }

    public function clearRecords(): Response
    {
        try {
            $this->ensureLogin();
            $archivedCount = $this->service->archiveByTypes(['market', 'benchmark', 'collaboration'], (int)($this->currentUser->id ?? 0), $this->currentUser->isSuperAdmin());

            return $this->success(['archived_count' => $archivedCount], '扩张历史数据已清空');
        } catch (\Throwable $e) {
            return $this->error('扩张历史数据清空失败: ' . $e->getMessage(), 400);
        }
    }

    private function ensureLogin(): void
    {
        if (!$this->currentUser) {
            throw new RuntimeException('请先登录');
        }
    }
}
