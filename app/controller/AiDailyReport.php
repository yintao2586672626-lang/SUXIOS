<?php
declare(strict_types=1);

namespace app\controller;

use app\service\AiDailyReportService;
use app\service\AiDailyReportPresentationArtifactService;
use app\service\AiDailyReportPresentationSpecService;
use app\service\AiReportGenerationTaskService;
use app\service\ApiExceptionMapper;
use app\service\OtaCompetitionAnalysisBundleService;
use app\service\P0OtaDownstreamGateService;
use think\Response;
use Throwable;

class AiDailyReport extends Base
{
    private const API_BUSINESS_EXCEPTIONS = [
        'not logged in' => 401,
        'no permitted hotel' => 403,
        'hotel_id is not permitted' => 403,
        'flagship_generation_requires_admin' => 403,
        'AI daily report not found' => 404,
        'presentation spec stale; refresh the report and retry' => 409,
    ];

    private AiDailyReportService $service;
    private AiDailyReportPresentationArtifactService $presentationArtifactService;
    private AiDailyReportPresentationSpecService $presentationSpecService;
    private AiReportGenerationTaskService $taskService;

    public function __construct(\think\App $app)
    {
        parent::__construct($app);
        $this->service = new AiDailyReportService();
        $this->presentationSpecService = new AiDailyReportPresentationSpecService();
        $this->presentationArtifactService = new AiDailyReportPresentationArtifactService();
        $this->taskService = new AiReportGenerationTaskService();
    }

    public function index(): Response
    {
        try {
            [$hotelIds, $hotelId] = $this->resolveHotelScope((int)$this->request->param('hotel_id', 0));
            return $this->success($this->service->list($hotelIds, $hotelId, $this->request->get()));
        } catch (Throwable $e) {
            return ApiExceptionMapper::response($e, 'AI daily reports query failed', self::API_BUSINESS_EXCEPTIONS);
        }
    }

    public function latest(): Response
    {
        try {
            [$hotelIds, $hotelId] = $this->resolveHotelScope((int)$this->request->param('hotel_id', 0));
            return $this->success($this->service->latest($hotelIds, $hotelId));
        } catch (Throwable $e) {
            return ApiExceptionMapper::response($e, 'AI daily report query failed', self::API_BUSINESS_EXCEPTIONS);
        }
    }

    public function read(int $id): Response
    {
        try {
            [$hotelIds] = $this->resolveHotelScope();
            $report = $this->service->read($id, $hotelIds);
            if (!$report) {
                return $this->error('AI daily report not found', 404);
            }

            return $this->success($report);
        } catch (Throwable $e) {
            return ApiExceptionMapper::response($e, 'AI daily report read failed', self::API_BUSINESS_EXCEPTIONS);
        }
    }

    public function generate(): Response
    {
        try {
            $input = $this->requestData();
            [$hotelIds, $hotelId] = $this->resolveHotelScope((int)($input['hotel_id'] ?? 0));
            $date = trim((string)($input['report_date'] ?? $input['date'] ?? ''));
            if ($date === '') {
                $date = date('Y-m-d', strtotime('-1 day'));
            }
            $userId = (int)($this->currentUser->id ?? 0);
            $edition = OtaCompetitionAnalysisBundleService::normalizeEdition($input['edition'] ?? 'lite');
            $isAdmin = (bool)$this->currentUser->isSuperAdmin();
            OtaCompetitionAnalysisBundleService::assertGenerationAllowed($edition, $isAdmin);
            $options = [
                'model_key' => (string)($input['model_key'] ?? ''),
                'use_llm' => array_key_exists('use_llm', $input) ? $input['use_llm'] : true,
                'edition' => $edition,
                'actor_is_admin' => $isAdmin,
            ];
            $background = array_key_exists('background', $input)
                && filter_var($input['background'], FILTER_VALIDATE_BOOL);
            if (OtaCompetitionAnalysisBundleService::editionRequiresAdmin($edition)) {
                $background = false;
            }
            $p0Gate = (new P0OtaDownstreamGateService())->resolveRuntime(
                $date,
                $hotelId !== null ? (int)$hotelId : null,
                null,
                ['ctrip', 'meituan']
            );
            if (($p0Gate['status'] ?? '') !== 'ready') {
                return $this->error(
                    '昨日双 OTA 数据尚未通过真实 P0 verifier；本次不生成正式日报。',
                    409,
                    [
                        'status' => 'blocked_by_p0_ota_gate',
                        'formal_report_generated' => false,
                        'target_date' => $date,
                        'hotel_id' => $hotelId,
                        'p0_downstream_gate' => $p0Gate,
                    ]
                );
            }
            if ($background) {
                return $this->success($this->taskService->enqueue(
                    $hotelIds,
                    (int)$hotelId,
                    $date,
                    $userId,
                    $options
                ));
            }

            return $this->success($this->service->generate($hotelIds, $hotelId, $date, $userId, $options));
        } catch (Throwable $e) {
            return ApiExceptionMapper::response($e, 'AI daily report generate failed', self::API_BUSINESS_EXCEPTIONS);
        }
    }

    public function generationTask(string $taskId): Response
    {
        try {
            [$hotelIds] = $this->resolveHotelScope();
            $task = $this->taskService->readPublicTask($taskId, $hotelIds);
            if (!is_array($task)) {
                return $this->error('AI report generation task not found', 404);
            }
            return $this->success($task);
        } catch (Throwable $e) {
            return ApiExceptionMapper::response($e, 'AI report task query failed', self::API_BUSINESS_EXCEPTIONS);
        }
    }

    public function recordHumanJudgment(int $id): Response
    {
        try {
            if ($id <= 0) {
                return $this->error('AI daily report is invalid', 422);
            }
            [$hotelIds] = $this->resolveHotelScope();
            $userId = (int)($this->currentUser->id ?? 0);
            $userLabel = (string)($this->currentUser->username ?? $this->currentUser->name ?? '');

            return $this->success($this->service->recordHumanJudgment(
                $id,
                $hotelIds,
                $userId,
                $this->requestData(),
                $userLabel
            ));
        } catch (Throwable $e) {
            return ApiExceptionMapper::response($e, 'AI daily report judgment save failed', self::API_BUSINESS_EXCEPTIONS);
        }
    }

    public function savePresentationSpec(int $id): Response
    {
        try {
            if ($id <= 0) {
                return $this->error('AI daily report is invalid', 422);
            }
            [$hotelIds] = $this->resolveHotelScope();
            $report = $this->service->read($id, $hotelIds);
            $hotelId = (int)($report['hotel_id'] ?? 0);
            if (!is_array($report) || $hotelId <= 0) {
                return $this->error('AI daily report not found', 404);
            }
            if (($denied = $this->hotelCapabilityDeniedResponse(
                $hotelId,
                'report.export',
                'report.export permission is required for this hotel'
            )) !== null) {
                return $denied;
            }
            $input = $this->requestData();
            $audience = trim((string)($input['audience'] ?? 'owner'));
            $userId = (int)($this->currentUser->id ?? 0);

            return $this->success($this->presentationSpecService->saveAndReadback(
                $report,
                $audience,
                $userId
            ));
        } catch (Throwable $e) {
            return ApiExceptionMapper::response($e, 'AI daily report presentation spec save failed', self::API_BUSINESS_EXCEPTIONS);
        }
    }

    public function presentationSpec(int $id): Response
    {
        try {
            if ($id <= 0) {
                return $this->error('AI daily report is invalid', 422);
            }
            [$hotelIds] = $this->resolveHotelScope();
            $report = $this->service->read($id, $hotelIds);
            $hotelId = (int)($report['hotel_id'] ?? 0);
            if (!is_array($report) || $hotelId <= 0) {
                return $this->error('AI daily report not found', 404);
            }
            if (($denied = $this->hotelCapabilityDeniedResponse(
                $hotelId,
                'report.export',
                'report.export permission is required for this hotel'
            )) !== null) {
                return $denied;
            }
            $tenantId = $this->presentationSpecService->resolveTenantScope($report);
            $audience = trim((string)$this->request->param('audience', 'owner'));
            $stored = $this->presentationSpecService->readLatest(
                $id,
                $hotelIds,
                $tenantId,
                $audience
            );
            if (!is_array($stored)) {
                return $this->error('AI daily report presentation spec not found', 404);
            }

            return $this->success($stored);
        } catch (Throwable $e) {
            return ApiExceptionMapper::response($e, 'AI daily report presentation spec read failed', self::API_BUSINESS_EXCEPTIONS);
        }
    }

    public function savePresentationArtifact(int $id): Response
    {
        try {
            if ($id <= 0) {
                return $this->error('AI daily report is invalid', 422);
            }
            [$hotelIds] = $this->resolveHotelScope();
            $report = $this->service->read($id, $hotelIds);
            $hotelId = (int)($report['hotel_id'] ?? 0);
            if (!is_array($report) || $hotelId <= 0) {
                return $this->error('AI daily report not found', 404);
            }
            if (($denied = $this->hotelCapabilityDeniedResponse(
                $hotelId,
                'report.export',
                'report.export permission is required for this hotel'
            )) !== null) {
                return $denied;
            }

            $input = $this->requestData();
            $audience = trim((string)($input['audience'] ?? 'owner'));
            $expectedSpecId = (int)($input['presentation_spec_id'] ?? 0);
            $expectedSpecFingerprint = strtolower(trim((string)($input['expected_spec_fingerprint'] ?? '')));
            if ($expectedSpecId <= 0 || preg_match('/^[a-f0-9]{64}$/', $expectedSpecFingerprint) !== 1) {
                throw new \InvalidArgumentException('verified presentation spec identity is required');
            }
            $userId = (int)($this->currentUser->id ?? 0);
            $storedSpec = $this->presentationSpecService->saveAndReadback($report, $audience, $userId);
            if ((int)($storedSpec['record_id'] ?? 0) !== $expectedSpecId
                || !hash_equals(
                    $expectedSpecFingerprint,
                    strtolower((string)($storedSpec['spec_fingerprint'] ?? ''))
                )
            ) {
                throw new \RuntimeException('presentation spec stale; refresh the report and retry');
            }

            return $this->success($this->presentationArtifactService->saveAndReadback(
                $storedSpec,
                $userId,
                true
            ));
        } catch (Throwable $e) {
            return ApiExceptionMapper::response($e, 'AI daily report presentation artifact save failed', self::API_BUSINESS_EXCEPTIONS);
        }
    }

    public function presentationArtifact(int $id): Response
    {
        try {
            if ($id <= 0) {
                return $this->error('AI daily report is invalid', 422);
            }
            [$hotelIds] = $this->resolveHotelScope();
            $report = $this->service->read($id, $hotelIds);
            $hotelId = (int)($report['hotel_id'] ?? 0);
            if (!is_array($report) || $hotelId <= 0) {
                return $this->error('AI daily report not found', 404);
            }
            if (($denied = $this->hotelCapabilityDeniedResponse(
                $hotelId,
                'report.export',
                'report.export permission is required for this hotel'
            )) !== null) {
                return $denied;
            }

            $tenantId = $this->presentationSpecService->resolveTenantScope($report);
            $audience = trim((string)$this->request->param('audience', 'owner'));
            $includeBundle = filter_var(
                $this->request->param('include_bundle', false),
                FILTER_VALIDATE_BOOL
            );
            $currentSpec = $this->presentationSpecService->readLatest(
                $id,
                $hotelIds,
                $tenantId,
                $audience
            );
            if (!is_array($currentSpec)) {
                return $this->error('AI daily report presentation spec not found', 404);
            }
            $currentSpecId = (int)($currentSpec['record_id'] ?? 0);
            $currentSpecFingerprint = strtolower(trim((string)($currentSpec['spec_fingerprint'] ?? '')));
            $stored = $this->presentationArtifactService->readLatest(
                $id,
                $hotelIds,
                $tenantId,
                $audience,
                $includeBundle,
                $currentSpecId,
                $currentSpecFingerprint
            );
            if (!is_array($stored)) {
                return $this->error('AI daily report presentation artifact not found', 404);
            }

            return $this->success($stored);
        } catch (Throwable $e) {
            return ApiExceptionMapper::response($e, 'AI daily report presentation artifact read failed', self::API_BUSINESS_EXCEPTIONS);
        }
    }

    public function presentationArtifactById(int $id, int $artifactId): Response
    {
        try {
            if ($id <= 0 || $artifactId <= 0) {
                return $this->error('AI daily report presentation artifact is invalid', 422);
            }
            [$hotelIds] = $this->resolveHotelScope();
            $report = $this->service->read($id, $hotelIds);
            $hotelId = (int)($report['hotel_id'] ?? 0);
            if (!is_array($report) || $hotelId <= 0) {
                return $this->error('AI daily report not found', 404);
            }
            if (($denied = $this->hotelCapabilityDeniedResponse(
                $hotelId,
                'report.export',
                'report.export permission is required for this hotel'
            )) !== null) {
                return $denied;
            }

            $tenantId = $this->presentationSpecService->resolveTenantScope($report);
            $includeBundle = filter_var(
                $this->request->param('include_bundle', false),
                FILTER_VALIDATE_BOOL
            );
            $stored = $this->presentationArtifactService->readExact(
                $id,
                $artifactId,
                $hotelIds,
                $tenantId,
                $includeBundle
            );
            if (!is_array($stored)) {
                return $this->error('AI daily report presentation artifact not found', 404);
            }

            return $this->success($stored);
        } catch (Throwable $e) {
            return ApiExceptionMapper::response($e, 'AI daily report presentation artifact read failed', self::API_BUSINESS_EXCEPTIONS);
        }
    }

    public function createExecutionIntent(int $id, int $actionIndex): Response
    {
        try {
            if ($id <= 0 || $actionIndex < 0) {
                return $this->error('AI daily report action is invalid', 422);
            }

            [$hotelIds] = $this->resolveHotelScope();
            $userId = (int)($this->currentUser->id ?? 0);
            $report = $this->service->read($id, $hotelIds);
            $hotelId = (int)($report['hotel_id'] ?? 0);
            if (!is_array($report) || $hotelId <= 0) {
                return $this->error('AI daily report not found', 404);
            }
            if (($denied = $this->hotelCapabilityDeniedResponse(
                $hotelId,
                'operation.execute',
                'operation.execute permission is required for this hotel'
            )) !== null) {
                return $denied;
            }

            return $this->success($this->service->createExecutionIntentFromAction($id, $actionIndex, $hotelIds, $userId));
        } catch (Throwable $e) {
            return ApiExceptionMapper::response($e, 'AI daily report action create failed', self::API_BUSINESS_EXCEPTIONS);
        }
    }

    private function resolveHotelScope(int $inputHotelId = 0): array
    {
        if (!$this->currentUser) {
            throw new \RuntimeException('not logged in');
        }

        $hotelId = $inputHotelId > 0 ? $inputHotelId : (int)$this->request->param('hotel_id', 0);
        $permitted = array_values(array_map('intval', $this->currentUser->getPermittedHotelIds()));
        if (empty($permitted)) {
            throw new \RuntimeException('no permitted hotel');
        }

        if ($hotelId > 0) {
            if (!in_array($hotelId, $permitted, true)) {
                throw new \RuntimeException('hotel_id is not permitted');
            }
            return [[$hotelId], $hotelId];
        }

        return [$permitted, count($permitted) === 1 ? $permitted[0] : null];
    }

}
