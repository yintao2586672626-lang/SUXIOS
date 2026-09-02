<?php
declare(strict_types=1);

namespace app\controller\concern;

use app\model\OperationLog;
use app\service\CtripCompetitionCirclePersistenceService;
use app\service\CtripManualFetchRequestService;
use app\service\ManualOnlineFetchTaskService;
use app\service\OtaFailureNotificationService;
use think\Response;

trait CtripManualFetchExecutionConcern
{
    private function executeCtripManualFetch(
        array $requestData,
        array $credentialPayload,
        int $systemHotelId
    ): Response {
        $url = CtripManualFetchRequestService::normalizeBusinessReportUrl((string)($requestData['url'] ?? ''));
        $nodeId = CtripManualFetchRequestService::normalizeNodeId((string)($requestData['node_id'] ?? $requestData['nodeId'] ?? ''));
        $cookies = trim((string)($credentialPayload['cookies'] ?? $credentialPayload['cookie'] ?? ''));
        $authDataStr = $credentialPayload['auth_data'] ?? $credentialPayload['authData'] ?? '';
        $startDate = $requestData['start_date'] ?? '';
        $endDate = $requestData['end_date'] ?? '';
        $autoSave = $this->isTruthyRequestValue($requestData['auto_save'] ?? true);
        $backgroundRequested = $this->isTruthyRequestValue($requestData['async'] ?? $requestData['background'] ?? false)
            && !$this->isTruthyRequestValue($requestData['background_task'] ?? false);

        if (empty($cookies)) {
            return json(['code' => 409, 'message' => 'OTA 凭据缺少登录 Cookies', 'data' => null], 409);
        }

        $authData = [];
        if (!empty($authDataStr)) {
            if (is_string($authDataStr)) {
                $authData = json_decode($authDataStr, true) ?: [];
            } elseif (is_array($authDataStr)) {
                $authData = $authDataStr;
            }
        }

        try {
            try {
                $dateRangePlan = CtripManualFetchRequestService::normalizeDateRange($startDate, $endDate);
            } catch (\InvalidArgumentException $e) {
                return json(['code' => 400, 'message' => '日期范围无效', 'data' => null], 400);
            }
            $startDate = $dateRangePlan['start_date'];
            $endDate = $dateRangePlan['end_date'];
            $startTimestamp = $dateRangePlan['start_timestamp'];
            $endTimestamp = $dateRangePlan['end_timestamp'];

            if ($startTimestamp === false || $endTimestamp === false || $startTimestamp > $endTimestamp) {
                return json(['code' => 400, 'message' => '日期范围无效', 'data' => null], 400);
            }

            if ($backgroundRequested && $systemHotelId) {
                $manualFetchTaskService = new ManualOnlineFetchTaskService();
                $task = $manualFetchTaskService->createTask('ctrip', (int)$systemHotelId, $startDate, $endDate, $requestData, [
                    'authorization' => trim((string)$this->request->header('Authorization', '')),
                    'api_url' => rtrim($this->request->domain(), '/') . '/api/online-data/fetch-ctrip',
                    'user_id' => (int)($this->currentUser->id ?? 0),
                ]);
                if (!empty($task) && $manualFetchTaskService->launchTask($task)) {
                    return json([
                        'code' => 200,
                        'message' => '携程手动获取已提交后台执行，完成后会更新数据列表和通知',
                        'data' => [
                            'status' => 'running',
                            'task_id' => $task['task_id'] ?? '',
                            'platform' => 'ctrip',
                            'async' => true,
                            'saved_count' => 0,
                            'request_start_date' => $startDate,
                            'request_end_date' => $endDate,
                        ],
                    ]);
                }
            }

            $dateResults = [];
            $responseData = null;
            $rawResponse = '';
            $savedCount = 0;
            $processedCount = 0;
            $readbackCount = 0;
            $readbackVerified = true;
            $insertedCount = 0;
            $updatedCount = 0;
            $skippedCount = 0;
            $competitionDataSourceId = 0;
            $competitionSyncTaskId = 0;
            $competitionPersistence = null;

            for ($timestamp = $startTimestamp; $timestamp <= $endTimestamp; $timestamp = strtotime('+1 day', $timestamp)) {
                $currentDate = date('Y-m-d', $timestamp);
                $postData = CtripManualFetchRequestService::buildDailyPostData($nodeId, $currentDate);

                // 发送请求
                $result = $this->sendHttpRequest($url, $postData, $cookies, $authData);

                if (!$result['success']) {
                    $this->recordCookieAlert('ctrip', 'fetch-ctrip', (string)($result['error'] ?? ''), $systemHotelId ? (int)$systemHotelId : null);
                    return json([
                        'code' => 500,
                        'message' => $currentDate . ' 请求失败: ' . ($result['error'] ?? '请求失败'),
                        'data' => ['raw_response' => $result['raw'] ?? '']
                    ], 500);
                }

                $dayResponseData = $result['data'];

                // 检查携程API返回的错误
                if (is_array($dayResponseData)) {
                    if (isset($dayResponseData['error'])) {
                        $errorMsg = $dayResponseData['error_description'] ?? $dayResponseData['error'];
                        return json([
                            'code' => 400,
                            'message' => $currentDate . ' 携程API错误: ' . $errorMsg,
                            'data' => ['raw_response' => $result['raw']]
                        ], 400);
                    }
                    if (isset($dayResponseData['code']) && $dayResponseData['code'] != 0 && $dayResponseData['code'] != 200) {
                        $errorMsg = $dayResponseData['message'] ?? $dayResponseData['msg'] ?? '未知错误';
                        return json([
                            'code' => 400,
                            'message' => $currentDate . ' 携程API返回错误: ' . $errorMsg,
                            'data' => ['raw_response' => $result['raw']]
                        ], 400);
                    }
                }

                $responseData = $dayResponseData;
                $rawResponse = $result['raw'];
                $responseDateEvidence = CtripManualFetchRequestService::extractResponseDateEvidence($dayResponseData);
                $responseDates = array_values(array_unique(array_column($responseDateEvidence, 'date')));
                $dateResults[] = [
                    'date' => $currentDate,
                    'data' => $dayResponseData,
                    'saved_count' => 0,
                    'fingerprint' => $this->buildCtripBusinessFingerprint($dayResponseData),
                    'response_dates' => $responseDates,
                    'response_date_evidence' => $responseDateEvidence,
                    'date_verification' => CtripManualFetchRequestService::verifyResponseBusinessDate(
                        $currentDate,
                        $responseDates
                    ),
                ];
            }

            if (CtripManualFetchRequestService::hasRepeatedMultiDayFingerprint($startDate, $endDate, $dateResults)) {
                return json([
                    'code' => 422,
                    'message' => '携程多日请求返回了同一份经营数据，系统已取消保存，避免把昨天数据按天数写入。请改为单日获取，或确认携程后台该账号是否支持历史日期。',
                    'data' => [
                        'date_results' => $dateResults,
                        'saved_count' => 0,
                        'fetched_at' => date('Y-m-d H:i:s'),
                        'request_start_date' => $startDate,
                        'request_end_date' => $endDate,
                    ],
                ], 422);
            }

            $displayHotels = $this->buildCtripBusinessDisplayHotels(['date_results' => $dateResults]);
            $displaySummary = $this->buildCtripBusinessDisplaySummary($displayHotels);
            $qunarVisitorQuality = $this->ctripBusinessQunarVisitorQuality($displayHotels);
            $qunarVisitorGap = $autoSave
                && $qunarVisitorQuality['row_count'] > 0
                && $qunarVisitorQuality['visitor_total'] <= 0;
            $dateVerifications = array_values(array_filter(array_map(
                static fn(array $dateResult): array => is_array($dateResult['date_verification'] ?? null)
                    ? $dateResult['date_verification']
                    : [],
                $dateResults
            )));
            $dateVerificationFailures = array_values(array_filter(
                $dateVerifications,
                static fn(array $verification): bool => ($verification['verified'] ?? false) !== true
            ));
            $verifiedSourceDates = array_values(array_unique(array_filter(array_map(
                static fn(array $verification): string => trim((string)($verification['source_business_date'] ?? '')),
                $dateVerifications
            ))));
            sort($verifiedSourceDates);
            $sourceBusinessDate = count($verifiedSourceDates) === 1
                ? $verifiedSourceDates[0]
                : (count($verifiedSourceDates) > 1
                    ? $verifiedSourceDates[0] . ' 至 ' . $verifiedSourceDates[count($verifiedSourceDates) - 1]
                    : null);
            $responseDateStatus = $dateVerificationFailures === [] && $dateVerifications !== []
                ? 'verified'
                : (string)($dateVerificationFailures[0]['status'] ?? 'target_date_unverified');

            if ($autoSave && $dateVerificationFailures !== []) {
                $firstFailure = $dateVerificationFailures[0];
                $reason = (string)($firstFailure['reason'] ?? 'response_business_date_unverified');
                $returnedDate = trim((string)($firstFailure['source_business_date'] ?? ''));
                $message = $reason === 'response_business_date_mismatch' && $returnedDate !== ''
                    ? "携程请求日期 {$firstFailure['requested_date']} 与平台返回业务日 {$returnedDate} 不一致；本次仅展示响应，未入库。"
                    : '携程返回了竞争圈数据，但响应未提供唯一且与请求一致的业务日期；本次仅展示响应，未入库。';
                return json([
                    'code' => 422,
                    'message' => $message,
                    'data' => array_merge([
                        'reason' => $reason,
                        'status' => $responseDateStatus,
                        'data' => $responseData,
                        'date_results' => $dateResults,
                        'raw_response' => $rawResponse,
                        'saved_count' => 0,
                        'fetched_at' => date('Y-m-d H:i:s'),
                        'request_start_date' => $startDate,
                        'request_end_date' => $endDate,
                        'source_business_date' => $sourceBusinessDate,
                        'response_date_status' => $responseDateStatus,
                        'response_date_verifications' => $dateVerifications,
                        'display_hotels' => $displayHotels,
                        'display_hotel_count' => count($displayHotels),
                        'display_summary' => $displaySummary,
                        'save_status' => 'target_date_unverified',
                    ], $this->buildCtripPersistenceState(true, 0, true)),
                ], 422);
            }

            $identityCheck = null;
            if ($autoSave) {
                if ($systemHotelId) {
                    $identityCheck = $this->validateCtripManualBusinessHotelIdentity($dateResults, (int)$systemHotelId, $requestData);
                } else {
                    $identityCheck = $this->resolveCtripManualBusinessHotelIdentityFromResponse($dateResults, $requestData);
                    if (!empty($identityCheck['ok']) && !empty($identityCheck['target_system_hotel_id'])) {
                        $systemHotelId = $this->resolveOnlineDataSystemHotelId((int)$identityCheck['target_system_hotel_id']);
                    }
                }

                if (empty($identityCheck['ok'])) {
                    $payload = array_merge([
                        'data' => $responseData,
                        'date_results' => $dateResults,
                        'raw_response' => $rawResponse,
                        'saved_count' => 0,
                        'request_start_date' => $startDate,
                        'request_end_date' => $endDate,
                        'identity_check' => $identityCheck,
                        'display_hotels' => $displayHotels,
                        'display_hotel_count' => count($displayHotels),
                        'display_summary' => $displaySummary,
                        'save_status' => 'blocked',
                    ], $this->buildCtripPersistenceState(true, 0, true));
                    $responseCode = 422;
                    return json([
                        'code' => $responseCode,
                        'message' => (string)($identityCheck['message'] ?? '携程返回酒店身份未能自动匹配本系统门店，已获取但未入库。'),
                        'data' => array_merge([
                            'reason' => (string)($identityCheck['status'] ?? 'ctrip_hotel_identity_blocked'),
                        ], $payload),
                    ], $responseCode);
                }
            }

            $fetchedAt = date('Y-m-d H:i:s');
            $selfHotelIds = $this->ctripCompetitionSelfHotelIds($identityCheck);
            $displayHotels = $this->tagCtripCompetitionDisplayRoles(
                $displayHotels,
                $selfHotelIds,
                $systemHotelId
            );

            if ($autoSave && $systemHotelId) {
                $competitionPersistence = new CtripCompetitionCirclePersistenceService();
                $competitionDataSourceId = $competitionPersistence->resolveOrCreateDataSource(
                    (int)$systemHotelId,
                    (int)($this->currentUser->id ?? 0),
                    [
                        'platform_hotel_id' => $selfHotelIds[0] ?? '',
                        'config_id' => (string)($requestData['config_id'] ?? ''),
                    ]
                );
                $competitionSyncTaskId = $competitionPersistence->startSyncTask(
                    $competitionDataSourceId,
                    (int)$systemHotelId,
                    (int)($this->currentUser->id ?? 0)
                );
            }

            try {
                foreach ($dateResults as &$dateResult) {
                    if (!$autoSave || !$systemHotelId || !$competitionPersistence) {
                        continue;
                    }
                    $traceId = CtripCompetitionCirclePersistenceService::buildCaptureTraceId([
                        'data_source_id' => $competitionDataSourceId,
                        'sync_task_id' => $competitionSyncTaskId,
                        'system_hotel_id' => (int)$systemHotelId,
                        'data_date' => (string)$dateResult['date'],
                        'fingerprint' => (string)($dateResult['fingerprint'] ?? ''),
                    ]);
                    $saveResult = $competitionPersistence->persistRows(
                        $this->extractCtripBusinessDataList($dateResult['data']),
                        (string)$dateResult['date'],
                        (int)$systemHotelId,
                        [
                            'self_hotel_ids' => $selfHotelIds,
                            'fetched_at' => $fetchedAt,
                            'data_source_id' => $competitionDataSourceId,
                            'sync_task_id' => $competitionSyncTaskId,
                            'source_trace_id' => $traceId,
                            'ingestion_method' => CtripCompetitionCirclePersistenceService::INGESTION_METHOD,
                            'requested_business_date' => (string)$dateResult['date'],
                            'source_business_date' => (string)($dateResult['date_verification']['source_business_date'] ?? ''),
                            'response_dates' => (array)($dateResult['date_verification']['response_dates'] ?? []),
                            'response_date_evidence' => (array)($dateResult['response_date_evidence'] ?? []),
                            'date_verification_status' => (string)($dateResult['date_verification']['status'] ?? 'target_date_unverified'),
                        ]
                    );
                    $dateProcessedCount = max(0, (int)($saveResult['processed_count'] ?? $saveResult['saved_count'] ?? 0));
                    $dateReadbackCount = max(0, (int)($saveResult['readback_count'] ?? 0));
                    $dateReadbackVerified = !empty($saveResult['readback_verified']);
                    $dateResult['processed_count'] = $dateProcessedCount;
                    $dateResult['saved_count'] = $dateReadbackCount;
                    $dateResult['inserted_count'] = (int)$saveResult['inserted_count'];
                    $dateResult['updated_count'] = (int)$saveResult['updated_count'];
                    $dateResult['skipped_count'] = (int)($saveResult['skipped_count'] ?? 0);
                    $dateResult['readback_count'] = $dateReadbackCount;
                    $dateResult['readback_verified'] = $dateReadbackVerified;
                    $dateResult['readback_reason'] = (string)($saveResult['readback_reason'] ?? '');
                    $dateResult['source_trace_id'] = $traceId;
                    $processedCount += $dateProcessedCount;
                    $readbackCount += $dateReadbackCount;
                    $readbackVerified = $readbackVerified && $dateReadbackVerified;
                    $savedCount += $dateReadbackCount;
                    $insertedCount += $dateResult['inserted_count'];
                    $updatedCount += $dateResult['updated_count'];
                    $skippedCount += $dateResult['skipped_count'];
                }
                unset($dateResult);

                if ($competitionPersistence && $competitionSyncTaskId > 0) {
                    if ($processedCount > 0 && $readbackVerified && $readbackCount === $processedCount) {
                        $competitionPersistence->finishSyncTask(
                            $competitionSyncTaskId,
                            $competitionDataSourceId,
                            [
                                'processed_count' => $processedCount,
                                'saved_count' => $savedCount,
                                'readback_count' => $readbackCount,
                                'readback_verified' => true,
                                'inserted_count' => $insertedCount,
                                'updated_count' => $updatedCount,
                                'skipped_count' => $skippedCount,
                                'date_count' => count($dateResults),
                                'self_hotel_ids' => $selfHotelIds,
                            ]
                        );
                    } else {
                        $competitionPersistence->failSyncTask(
                            $competitionSyncTaskId,
                            $competitionDataSourceId,
                            'competition_circle_readback_failed'
                        );
                    }
                }
            } catch (\Throwable $e) {
                if ($competitionPersistence && $competitionSyncTaskId > 0) {
                    $competitionPersistence->failSyncTask(
                        $competitionSyncTaskId,
                        $competitionDataSourceId,
                        'competition_circle_persistence_failed'
                    );
                }
                throw $e;
            }

            $displayDataDate = $startDate === $endDate ? $startDate : $startDate . ' 至 ' . $endDate;
            $persistenceState = $this->buildCtripPersistenceState(
                $autoSave,
                $processedCount,
                false,
                $readbackCount,
                $readbackVerified
            );
            $persistenceOutcome = $this->buildCtripManualFetchPersistenceOutcome($autoSave, $persistenceState);
            $earlyMorningFallback = null;
            if ($startDate === $endDate && (int)$systemHotelId > 0) {
                $earlyMorningResult = $this->hydrateCtripEarlyMorningTrafficFallback(
                    $displayHotels,
                    [
                        'data_date' => $endDate,
                        'update_time' => $fetchedAt,
                    ],
                    (string)$systemHotelId,
                    $this->currentUser,
                    $this->getOnlineDailyDataColumns(),
                    $endDate
                );
                $displayHotels = $earlyMorningResult['display_hotels'];
                $earlyMorningFallback = $earlyMorningResult['fallback'];
                if ($earlyMorningFallback !== null) {
                    $displaySummary = $this->buildCtripBusinessDisplaySummary($displayHotels);
                    $displaySummary['early_morning_fallback'] = $earlyMorningFallback;
                    $displaySummary['source_notice'] = (string)($earlyMorningFallback['message'] ?? $displaySummary['source_notice']);
                }
            }
            if ($systemHotelId > 0 && $persistenceState['persisted']) {
                $this->updateCtripLatestFetchStatus($systemHotelId, $fetchedAt, $displayDataDate, $savedCount);
                if (!$this->isTruthyRequestValue($requestData['background_task'] ?? false)) {
                    try {
                        (new OtaFailureNotificationService())->recordCollectionOutcome([
                            'hotel_id' => (int)$systemHotelId,
                            'platform' => 'ctrip',
                            'success' => true,
                            'saved_count' => (int)$persistenceState['saved_count'],
                            'data_date' => $displayDataDate,
                            'actor_user_id' => (int)($this->currentUser->id ?? 0),
                        ]);
                    } catch (\Throwable $e) {
                        \think\facade\Log::warning('Synchronous OTA reminder resolution failed', [
                            'hotel_id' => (int)$systemHotelId,
                            'platform' => 'ctrip',
                            'exception_type' => get_debug_type($e),
                        ]);
                    }
                }
            }
            if ($this->isTruthyRequestValue($requestData['background_task'] ?? false) && $systemHotelId) {
                $this->recordAutoFetchNotification((int)$systemHotelId, $persistenceState['persisted'], '携程手动获取完成', $displayDataDate, [
                    'saved_count' => $savedCount,
                    'platform_results' => [
                        ['platform' => 'ctrip', 'success' => $persistenceState['persisted'], 'saved_count' => $savedCount],
                    ],
                ], 'manual_fetch');
            }
            if ($this->currentUser && isset($this->currentUser->id)) {
                OperationLog::record(
                    'online_data',
                    'fetch_ctrip',
                    "获取携程线上数据: {$savedCount}条",
                    $this->currentUser->id,
                    $systemHotelId > 0 ? $systemHotelId : null
                );
            }

            return json([
                'code' => $persistenceOutcome['code'],
                'message' => $persistenceOutcome['message'] !== ''
                    ? $persistenceOutcome['message']
                    : (!$autoSave
                    ? '临时 Cookie 查询成功；结果仅本页展示，未保存 Cookie、未创建门店、未入库。'
                    : (!empty($identityCheck['warning']) && !empty($identityCheck['message'])
                        ? (string)$identityCheck['message'] . $this->ctripCompetitionSaveSummaryText($insertedCount, $updatedCount)
                        : ($qunarVisitorGap
                            ? '携程数据已获取；去哪儿访客为 0 仅作为字段缺口提示，不阻断携程竞争圈获取和入库。' . $this->ctripCompetitionSaveSummaryText($insertedCount, $updatedCount)
                            : '获取成功' . $this->ctripCompetitionSaveSummaryText($insertedCount, $updatedCount)))),
                'data' => array_merge([
                    'data' => $responseData,
                    'date_results' => $dateResults,
                    'raw_response' => $rawResponse,
                    'saved_count' => $savedCount,
                    'inserted_count' => $insertedCount,
                    'updated_count' => $updatedCount,
                    'skipped_count' => $skippedCount,
                    'data_source_id' => $competitionDataSourceId ?: null,
                    'sync_task_id' => $competitionSyncTaskId ?: null,
                    'fetched_at' => $fetchedAt,
                    'request_start_date' => $startDate,
                    'request_end_date' => $endDate,
                    'source_business_date' => $sourceBusinessDate,
                    'response_date_status' => $responseDateStatus,
                    'response_date_verifications' => $dateVerifications,
                    'identity_check' => $identityCheck,
                    'display_hotels' => $displayHotels,
                    'display_hotel_count' => count($displayHotels),
                    'display_summary' => $displaySummary,
                    'early_morning_fallback' => $earlyMorningFallback,
                    'qunar_visitor_quality' => $qunarVisitorQuality,
                    'save_status' => $persistenceOutcome['save_status'] !== ''
                        ? $persistenceOutcome['save_status']
                        : ($qunarVisitorGap
                        ? ($autoSave
                            ? ($savedCount > 0 ? 'saved_with_qunar_visitor_gap' : 'no_saved_with_qunar_visitor_gap')
                            : 'display_only')
                        : ($autoSave ? 'saved_or_empty' : 'display_only')),
                    'save_operation' => $insertedCount > 0 && $updatedCount > 0
                        ? 'inserted_and_updated'
                        : ($insertedCount > 0 ? 'inserted' : ($updatedCount > 0 ? 'updated' : 'none')),
                ], $persistenceState)
            ], $persistenceOutcome['http_status']);
        } catch (\DomainException) {
            return json([
                'code' => 409,
                'message' => '检测到旧版携程明文凭据；请先完成 Task6 迁移再执行采集',
                'data' => ['reason' => 'legacy_credential_requires_task6'],
            ], 409);
        } catch (\Throwable $e) {
            \think\facade\Log::error('Ctrip manual fetch failed.', [
                'exception_type' => get_debug_type($e),
            ]);
            return json(['code' => 500, 'message' => '请求异常', 'data' => null], 500);
        }
    }
}
